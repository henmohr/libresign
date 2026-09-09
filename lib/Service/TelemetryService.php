<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\TelemetryMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\Util;

/** @psalm-import-type LibresignTelemetrySettings from \OCA\Libresign\ResponseDefinitions */
class TelemetryService {
	public const REPORT_INTERVAL = 7 * 24 * 60 * 60;

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IAppManager $appManager,
		private IUserManager $userManager,
		private TelemetryMapper $mapper,
		private IClientService $clientService,
		private ISecureRandom $random,
		private ITimeFactory $time,
	) {
	}

	/** @return LibresignTelemetrySettings */
	public function getSettings(): array {
		return [
			'enabled' => $this->isEnabled(),
			'url' => $this->appConfig->getValueString(Application::APP_ID, 'telemetry_url'),
			'lastSent' => $this->appConfig->getValueInt(Application::APP_ID, 'telemetry_last_sent'),
		];
	}

	public function isEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'telemetry_enabled', false);
	}

	public function setSettings(bool $enabled, string $url): void {
		$url = trim($url);
		if ($enabled || $url !== '') {
			$this->validateUrl($url);
		}
		// Persist consent last so partially saved settings never enable reporting.
		$this->appConfig->setValueBool(Application::APP_ID, 'telemetry_enabled', false);
		if ($url !== $this->appConfig->getValueString(Application::APP_ID, 'telemetry_url')) {
			$this->appConfig->deleteKey(Application::APP_ID, 'telemetry_instance_hash');
			$this->appConfig->deleteKey(Application::APP_ID, 'telemetry_last_sent');
		}
		$this->appConfig->setValueString(Application::APP_ID, 'telemetry_url', $url);
		if ($enabled) {
			$this->getInstanceHash();
		} else {
			$this->appConfig->deleteKey(Application::APP_ID, 'telemetry_instance_hash');
		}
		$this->appConfig->setValueBool(Application::APP_ID, 'telemetry_enabled', $enabled);
	}

	private function validateUrl(string $url): void {
		$parts = parse_url($url);
		if (!filter_var($url, FILTER_VALIDATE_URL)
			|| $parts === false
			|| ($parts['scheme'] ?? '') !== 'https'
			|| empty($parts['host'])
			|| isset($parts['user']) || isset($parts['pass'])
			|| isset($parts['query']) || isset($parts['fragment'])
		) {
			throw new \InvalidArgumentException('invalid_url');
		}
	}

	private function getInstanceHash(): string {
		$hash = $this->appConfig->getValueString(Application::APP_ID, 'telemetry_instance_hash');
		if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
			$hash = hash('sha256', $this->random->generate(64));
			$this->appConfig->setValueString(Application::APP_ID, 'telemetry_instance_hash', $hash);
		}
		return $hash;
	}

	/**
	 * Best effort at the telemetry boundary: errors must not escape into app workflows.
	 * Never log exceptions here: HTTP errors can contain URLs, payloads or proxy credentials.
	 */
	public function recordCancelledFlow(): void {
		try {
			if ($this->isEnabled()) {
				$this->mapper->incrementCancelledFlows();
			}
		} catch (\Throwable) {
			// Dropping a metric is preferable to interrupting a cancellation.
		}
	}

	/** @return 'sent'|'disabled'|'not_due'|'failed' */
	public function sendReport(bool $manual = false): string {
		try {
			if (!$this->isEnabled()) {
				return 'disabled';
			}
			$settings = $this->getSettings();
			if (!$manual && $settings['lastSent'] > $this->time->getTime() - self::REPORT_INTERVAL) {
				return 'not_due';
			}
			$this->validateUrl($settings['url']);
			$response = $this->clientService->newClient()->post($settings['url'], [
				'json' => $this->buildPayload(),
				'headers' => ['User-Agent' => 'LibreSign-Telemetry/1'],
				'timeout' => 10,
				'connect_timeout' => 5,
				'allow_redirects' => false,
				'cookies' => false,
			]);
			if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
				return 'failed';
			}
			$this->appConfig->setValueInt(Application::APP_ID, 'telemetry_last_sent', $this->time->getTime());
			return 'sent';
		} catch (\Throwable) {
			// The next scheduled run may retry; no retries in the signing request path.
			return 'failed';
		}
	}

	/** @return array<string, mixed> */
	private function buildPayload(): array {
		$totalUsers = $this->userManager->countUsersTotal();
		$database = $this->config->getSystemValueString('dbtype');
		return [
			'schema_version' => 1,
			'instance_hash' => $this->getInstanceHash(),
			'versions' => [
				'libresign' => $this->normalizeVersion($this->appManager->getAppVersion(Application::APP_ID)),
				'nextcloud' => implode('.', Util::getVersion()),
				'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
			],
			'users' => [
				'total' => $totalUsers === false ? null : $totalUsers,
				'active_30_days' => $this->mapper->getActiveUsers($this->time->getTime() - 30 * 86400),
			],
			'stored_usage' => $this->mapper->getUsage(),
			'cancelled_flows_while_enabled' => $this->mapper->getCancelledFlows(),
			'environment' => [
				'database' => in_array($database, ['mysql', 'pgsql', 'sqlite3', 'oci'], true) ? $database : 'unknown',
				'web_server' => $this->normalizeWebServer($this->appConfig->getValueString(Application::APP_ID, 'telemetry_web_server', 'unknown')),
			],
		];
	}

	private function normalizeVersion(string $version): string {
		return preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?/', $version, $matches) ? $matches[0] : 'unknown';
	}

	public function rememberWebServer(string $server): void {
		if (!$this->isEnabled()) {
			return;
		}
		$this->appConfig->setValueString(Application::APP_ID, 'telemetry_web_server', $this->normalizeWebServer($server));
	}

	private function normalizeWebServer(string $server): string {
		$server = strtolower($server);
		$family = 'unknown';
		foreach (['apache', 'nginx', 'caddy', 'litespeed', 'microsoft-iis'] as $candidate) {
			if (str_contains($server, $candidate)) {
				$family = $candidate;
				break;
			}
		}
		return $family;
	}
}

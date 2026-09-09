<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use DateTimeImmutable;
use OCA\Libresign\Vendor\LibreCode\UsageStatistics\Client;
use OCA\Libresign\Vendor\LibreCode\UsageStatistics\ConsentState;
use OCA\Libresign\Vendor\LibreCode\UsageStatistics\Endpoint;
use OCA\Libresign\Vendor\LibreCode\UsageStatistics\InstallationId;
use OCA\Libresign\Vendor\LibreCode\UsageStatistics\Metric;
use OCA\Libresign\Vendor\LibreCode\UsageStatistics\Report;
use OCA\Libresign\Vendor\LibreCode\UsageStatistics\ReportingPeriod;
use OCA\Libresign\Vendor\LibreCode\UsageStatistics\Transport\TransportInterface;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\TelemetryMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
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
		private ISecureRandom $random,
		private ITimeFactory $time,
		private TransportInterface $transport,
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
			$client = new Client($this->transport, new Endpoint($settings['url']), 10.0);
			$client->submit($this->buildReport(), ConsentState::Enabled);
			$this->appConfig->setValueInt(Application::APP_ID, 'telemetry_last_sent', $this->time->getTime());
			return 'sent';
		} catch (\Throwable) {
			// The next scheduled run may retry; no retries in the signing request path.
			return 'failed';
		}
	}

	private function buildReport(): Report {
		$totalUsers = $this->userManager->countUsersTotal();
		$database = $this->config->getSystemValueString('dbtype');
		$usage = $this->mapper->getUsage();
		return new Report(
			application: Application::APP_ID,
			installationId: (string) InstallationId::derive(Application::APP_ID, $this->getInstanceHash()),
			schemaVersion: 1,
			period: ReportingPeriod::monthContaining(new DateTimeImmutable('@' . $this->time->getTime())),
			metrics: [
				Metric::string('environment', 'libresign_version', $this->normalizeVersion($this->appManager->getAppVersion(Application::APP_ID))),
				Metric::string('environment', 'nextcloud_version', implode('.', Util::getVersion())),
				Metric::string('environment', 'php_version', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION),
				Metric::string('environment', 'database', in_array($database, ['mysql', 'pgsql', 'sqlite3', 'oci'], true) ? $database : 'unknown'),
				Metric::string('environment', 'web_server', $this->normalizeWebServer($this->appConfig->getValueString(Application::APP_ID, 'telemetry_web_server', 'unknown'))),
				Metric::integer('users', 'total', $totalUsers === false ? 0 : $totalUsers),
				Metric::integer('users', 'active_30_days', $this->mapper->getActiveUsers($this->time->getTime() - 30 * 86400)),
				Metric::integer('usage', 'files', $usage['files']),
				Metric::integer('usage', 'envelopes', $usage['envelopes']),
				Metric::integer('usage', 'sequential_flows', $usage['sequential_flows']),
				Metric::integer('usage', 'signatures', $usage['signatures']),
				Metric::integer('usage', 'signed_documents', $usage['signed_documents']),
				Metric::integer('usage', 'completed_flows', $usage['completed_flows']),
				Metric::integer('usage', 'cancelled_flows', $this->mapper->getCancelledFlows()),
			],
		);
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

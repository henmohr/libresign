<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Db\TelemetryMapper;
use OCA\Libresign\Service\TelemetryService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\Util;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TelemetryServiceTest extends TestCase {
	private TelemetryService $service;
	private IAppConfig&MockObject $appConfig;
	private TelemetryMapper&MockObject $mapper;
	private IClientService&MockObject $clients;
	private IClient&MockObject $client;
	private IUserManager&MockObject $users;
	private ISecureRandom&MockObject $random;
	private array $settings;
	private const NOW = 1788825600;

	protected function setUp(): void {
		$this->settings = [];
		$this->appConfig = $this->createMock(IAppConfig::class);
		foreach (['Bool' => false, 'String' => '', 'Int' => 0] as $type => $fallback) {
			$this->appConfig->method('getValue' . $type)->willReturnCallback(fn ($app, $key, $default = null) => $this->settings[$key] ?? $default ?? $fallback);
			$this->appConfig->method('setValue' . $type)->willReturnCallback(function ($app, $key, $value): bool {
				$this->settings[$key] = $value;
				return true;
			});
		}
		$this->appConfig->method('deleteKey')->willReturnCallback(function ($app, $key): void {
			unset($this->settings[$key]);
		});
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('dbtype')->willReturn('pgsql');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('15.0.0-dev.custom-private-build');
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('countUsersTotal')->willReturn(25);
		$this->mapper = $this->createMock(TelemetryMapper::class);
		$this->clients = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$this->clients->method('newClient')->willReturn($this->client);
		$this->random = $this->createMock(ISecureRandom::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->service = new TelemetryService($this->appConfig, $config, $apps, $this->users, $this->mapper, $this->clients, $this->random, $time);
	}

	public function testDisabledByDefaultDoesNotCollectOrSendEvenManually(): void {
		$this->mapper->expects(self::never())->method('getUsage');
		$this->mapper->expects(self::never())->method('incrementCancelledFlows');
		$this->users->expects(self::never())->method('countUsersTotal');
		$this->clients->expects(self::never())->method('newClient');
		$this->random->expects(self::never())->method('generate');
		self::assertSame(['enabled' => false, 'url' => '', 'lastSent' => 0], $this->service->getSettings());
		self::assertSame('disabled', $this->service->sendReport());
		self::assertSame('disabled', $this->service->sendReport(true));
		$this->service->recordCancelledFlow();
	}

	#[DataProvider('invalidUrls')]
	public function testRejectsUnsafeReceiversBeforeChangingConsent(string $url): void {
		$this->appConfig->expects(self::never())->method('setValueBool');
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setSettings(true, $url);
	}

	public static function invalidUrls(): array {
		return array_map(static fn ($url) => [$url], [
			'', 'http://example.com', 'file:///etc/passwd', 'https://user:password@example.com',
			'https://example.com?token=secret', 'https://example.com#secret', '/relative',
		]);
	}

	public function testIdentifierIsRandomStableAndDeletedOnOptOut(): void {
		$this->random->expects(self::exactly(2))->method('generate')->with(64)->willReturnOnConsecutiveCalls('random-one', 'random-two');
		$this->service->setSettings(true, 'https://example.com/report');
		self::assertSame(hash('sha256', 'random-one'), $this->settings['telemetry_instance_hash']);
		$this->service->setSettings(true, 'https://example.com/report');
		self::assertSame(hash('sha256', 'random-one'), $this->settings['telemetry_instance_hash']);
		$this->service->setSettings(false, 'https://example.com/report');
		self::assertArrayNotHasKey('telemetry_instance_hash', $this->settings);
		$this->service->setSettings(true, 'https://example.com/report');
		self::assertSame(hash('sha256', 'random-two'), $this->settings['telemetry_instance_hash']);
	}

	public function testChangingReceiverRotatesIdentifierAndClearsDeliveryDate(): void {
		$this->enable();
		$this->settings['telemetry_last_sent'] = self::NOW;
		$this->random->method('generate')->willReturn('new-random');
		$this->service->setSettings(true, 'https://other.example.com/report');
		self::assertSame(hash('sha256', 'new-random'), $this->settings['telemetry_instance_hash']);
		self::assertSame(0, $this->service->getSettings()['lastSent']);
	}

	public function testSendsOnlyAllowlistedAggregateDataWithBoundedTransport(): void {
		$this->enable();
		$this->settings['telemetry_web_server'] = 'nginx/1.20 private-host admin@example.com';
		$usage = ['files' => 10, 'envelopes' => 2, 'sequential_flows' => 3, 'signatures' => 20, 'completed_flows' => 4];
		$this->mapper->method('getUsage')->willReturn($usage);
		$this->mapper->method('getCancelledFlows')->willReturn(7);
		$this->mapper->expects(self::once())->method('getActiveUsers')->with(self::NOW - 30 * 86400)->willReturn(12);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(204);
		$this->client->expects(self::once())->method('post')->with('https://example.com/report', self::callback(function (array $options) use ($usage): bool {
			self::assertSame([
				'schema_version' => 1,
				'instance_hash' => str_repeat('a', 64),
				'versions' => ['libresign' => '15.0.0', 'nextcloud' => implode('.', Util::getVersion()), 'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION],
				'users' => ['total' => 25, 'active_30_days' => 12],
				'stored_usage' => $usage,
				'cancelled_flows_while_enabled' => 7,
				'environment' => ['database' => 'pgsql', 'web_server' => 'nginx'],
			], $options['json']);
			self::assertSame(['User-Agent' => 'LibreSign-Telemetry/1'], $options['headers']);
			self::assertSame(10, $options['timeout']);
			self::assertSame(5, $options['connect_timeout']);
			self::assertFalse($options['allow_redirects']);
			self::assertFalse($options['cookies']);
			return true;
		}))->willReturn($response);
		self::assertSame('sent', $this->service->sendReport());
		self::assertSame(self::NOW, $this->settings['telemetry_last_sent']);
	}

	public function testWeeklyScheduleSkipsRecentlySentReports(): void {
		$this->enable();
		$this->settings['telemetry_last_sent'] = self::NOW - TelemetryService::REPORT_INTERVAL + 1;
		$this->clients->expects(self::never())->method('newClient');
		$this->mapper->expects(self::never())->method('getUsage');
		self::assertSame('not_due', $this->service->sendReport());
	}

	public function testManualSendBypassesWeeklySchedule(): void {
		$this->enable();
		$this->settings['telemetry_last_sent'] = self::NOW;
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$this->client->expects(self::once())->method('post')->willReturn($response);
		self::assertSame('sent', $this->service->sendReport(true));
	}

	#[DataProvider('failedStatusCodes')]
	public function testNonSuccessResponsesNeverAdvanceDeliveryDate(int $status): void {
		$this->enable();
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$this->client->method('post')->willReturn($response);
		self::assertSame('failed', $this->service->sendReport());
		self::assertArrayNotHasKey('telemetry_last_sent', $this->settings);
	}

	public static function failedStatusCodes(): array {
		return [[302], [400], [503]];
	}

	public function testNetworkFailureNeverLeaksExceptionDetails(): void {
		$this->enable();
		$this->client->method('post')->willThrowException(new \RuntimeException('secret token in remote response'));
		self::assertSame('failed', $this->service->sendReport());
		self::assertArrayNotHasKey('telemetry_last_sent', $this->settings);
	}

	public function testCollectionFailureNeverReachesTheNetwork(): void {
		$this->enable();
		$this->mapper->method('getActiveUsers')->willThrowException(new \RuntimeException('database failure'));
		$this->client->expects(self::never())->method('post');
		self::assertSame('failed', $this->service->sendReport());
	}

	public function testMalformedStoredUrlCannotBypassValidation(): void {
		$this->enable();
		$this->settings['telemetry_url'] = 'http://example.com';
		$this->clients->expects(self::never())->method('newClient');
		self::assertSame('failed', $this->service->sendReport());
	}

	public function testCounterFailureCannotInterruptCancellation(): void {
		$this->enable();
		$this->mapper->expects(self::once())->method('incrementCancelledFlows')->willThrowException(new \RuntimeException('database failure'));
		$this->service->recordCancelledFlow();
	}

	public function testServerInformationIsNormalizedBeforeStorage(): void {
		$this->enable();
		$this->service->rememberWebServer('Apache/2.4.62 (Debian) private-host');
		self::assertSame('apache', $this->settings['telemetry_web_server']);
		$this->service->rememberWebServer('private-host');
		self::assertSame('unknown', $this->settings['telemetry_web_server']);
	}

	private function enable(): void {
		$this->settings = ['telemetry_enabled' => true, 'telemetry_url' => 'https://example.com/report', 'telemetry_instance_hash' => str_repeat('a', 64)];
	}
}

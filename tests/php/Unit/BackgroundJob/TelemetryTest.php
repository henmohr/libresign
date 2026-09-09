<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\BackgroundJob;

use OCA\Libresign\BackgroundJob\Telemetry;
use OCA\Libresign\Service\TelemetryService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class TelemetryTest extends TestCase {
	public function testWeeklyJobUsesScheduledSend(): void {
		$service = $this->createMock(TelemetryService::class);
		$service->expects(self::once())->method('sendReport')->with(false)->willReturn('disabled');
		$job = new Telemetry($this->createMock(ITimeFactory::class), $service);
		self::assertSame(TelemetryService::REPORT_INTERVAL, $job->getInterval());
		(new \ReflectionMethod($job, 'run'))->invoke($job, []);
	}
}

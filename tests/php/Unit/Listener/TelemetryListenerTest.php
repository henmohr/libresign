<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Listener;

use OCA\Libresign\Events\SigningFlowCancelledEvent;
use OCA\Libresign\Listener\TelemetryListener;
use OCA\Libresign\Service\TelemetryService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

class TelemetryListenerTest extends TestCase {
	public function testOnlyWholeFlowCancellationsAreCounted(): void {
		$service = $this->createMock(TelemetryService::class);
		$service->expects(self::once())->method('recordCancelledFlow');
		$listener = new TelemetryListener($service);
		$listener->handle(new Event());
		$listener->handle(new SigningFlowCancelledEvent());
	}
}

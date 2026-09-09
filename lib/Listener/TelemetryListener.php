<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Listener;

use OCA\Libresign\Events\SigningFlowCancelledEvent;
use OCA\Libresign\Service\TelemetryService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @template-implements IEventListener<SigningFlowCancelledEvent> */
class TelemetryListener implements IEventListener {
	public function __construct(
		private TelemetryService $telemetryService,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof SigningFlowCancelledEvent) {
			$this->telemetryService->recordCancelledFlow();
		}
	}
}

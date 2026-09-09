<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\BackgroundJob;

use OCA\Libresign\Service\TelemetryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

class Telemetry extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private TelemetryService $telemetryService,
	) {
		parent::__construct($time);
		$this->setInterval(TelemetryService::REPORT_INTERVAL);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$this->telemetryService->sendReport();
	}
}

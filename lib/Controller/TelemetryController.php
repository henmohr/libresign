<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Middleware\Attribute\RequireManager;
use OCA\Libresign\Service\TelemetryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** @psalm-import-type LibresignTelemetrySettings from \OCA\Libresign\ResponseDefinitions */
class TelemetryController extends AEnvironmentAwareController {
	public function __construct(
		IRequest $request,
		private TelemetryService $telemetryService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Get telemetry settings
	 *
	 * @return DataResponse<Http::STATUS_OK, LibresignTelemetrySettings, array{}>
	 *
	 * 200: Settings
	 */
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/admin/telemetry', requirements: ['apiVersion' => '(v1)'])]
	#[RequireManager]
	public function getSettings(): DataResponse {
		return new DataResponse($this->telemetryService->getSettings());
	}

	/**
	 * Configure optional telemetry
	 *
	 * @param bool $enabled Whether the administrator consents to reporting
	 * @param string $url HTTPS report receiver without credentials, query or fragment
	 * @return DataResponse<Http::STATUS_OK, LibresignTelemetrySettings, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: 'invalid_url'}, array{}>
	 *
	 * 200: Saved
	 * 400: Invalid receiver URL
	 */
	#[ApiRoute(verb: 'PUT', url: '/api/{apiVersion}/admin/telemetry', requirements: ['apiVersion' => '(v1)'])]
	#[RequireManager]
	public function setSettings(bool $enabled, string $url): DataResponse {
		try {
			$this->telemetryService->setSettings($enabled, $url);
		} catch (\InvalidArgumentException) {
			return new DataResponse(['error' => 'invalid_url'], Http::STATUS_BAD_REQUEST);
		}
		$this->telemetryService->rememberWebServer((string)($this->request->server['SERVER_SOFTWARE'] ?? ''));
		return new DataResponse($this->telemetryService->getSettings());
	}

	/**
	 * Send a telemetry report now
	 *
	 * @return DataResponse<Http::STATUS_OK, array{status: 'sent'|'disabled'|'not_due'|'failed'}, array{}>
	 *
	 * 200: Report result; failures never include remote response data
	 */
	#[UserRateLimit(limit: 1, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/admin/telemetry/report', requirements: ['apiVersion' => '(v1)'])]
	#[RequireManager]
	public function sendReport(): DataResponse {
		return new DataResponse(['status' => $this->telemetryService->sendReport(true)]);
	}
}

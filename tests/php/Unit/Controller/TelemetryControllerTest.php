<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Controller\TelemetryController;
use OCA\Libresign\Middleware\Attribute\RequireManager;
use OCA\Libresign\Service\TelemetryService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class TelemetryControllerTest extends TestCase {
	public function testAllRoutesRequireAdminAndCsrfProtection(): void {
		foreach (['getSettings', 'setSettings', 'sendReport'] as $method) {
			$reflection = new \ReflectionMethod(TelemetryController::class, $method);
			foreach ([NoAdminRequired::class, NoCSRFRequired::class, PublicPage::class] as $attribute) {
				self::assertSame([], $reflection->getAttributes($attribute));
			}
			self::assertCount(1, $reflection->getAttributes(RequireManager::class));
		}
	}

	public function testInvalidUrlIsRejectedWithoutReflectingUserInput(): void {
		$service = $this->createMock(TelemetryService::class);
		$service->method('setSettings')->willThrowException(new \InvalidArgumentException('secret'));
		$controller = new TelemetryController($this->createMock(IRequest::class), $service);
		$response = $controller->setSettings(true, 'http://example.com');
		self::assertSame(400, $response->getStatus());
		self::assertSame(['error' => 'invalid_url'], $response->getData());
	}

	public function testManualSendDelegatesConsentCheckToService(): void {
		$service = $this->createMock(TelemetryService::class);
		$service->expects(self::once())->method('sendReport')->with(true)->willReturn('disabled');
		$controller = new TelemetryController($this->createMock(IRequest::class), $service);
		self::assertSame(['status' => 'disabled'], $controller->sendReport()->getData());
	}
}

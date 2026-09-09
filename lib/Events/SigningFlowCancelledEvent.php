<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Events;

use OCP\EventDispatcher\Event;

/** A whole root flow was cancelled, as opposed to removing one signer or identity method. */
class SigningFlowCancelledEvent extends Event {
}

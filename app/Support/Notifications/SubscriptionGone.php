<?php

namespace App\Support\Notifications;

use RuntimeException;

/**
 * The browser has thrown this subscription away.
 *
 * Cleared its data, uninstalled, revoked permission — the push service answers
 * 404 or 410 and will keep doing so. Distinct from an ordinary failure because
 * the right response is to forget the channel, not to retry it.
 */
class SubscriptionGone extends RuntimeException {}

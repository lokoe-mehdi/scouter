<?php

namespace App\Gsc;

/**
 * The Google grant itself is broken (revoked refresh token, missing secret,
 * property no longer readable by the connected account).
 *
 * Distinguished from a plain RuntimeException because it is the ONE class of
 * failure that retrying on a short loop cannot fix: it needs a human to
 * reconnect. The runner stops the run immediately, flags the connector `error`
 * with a user-facing message, and arms a long backoff so we still recover by
 * ourselves if access comes back.
 *
 * @package    Scouter
 * @subpackage Gsc
 */
class GscAuthException extends \RuntimeException
{
}

<?php

namespace App\Mail\Middleware;

use App\Support\SmtpFailure;

/**
 * Permanent SMTP (554 spam, bad mailbox) must not burn queue retries.
 * Transient 451/421 Hostinger rate limits keep the default backoff.
 */
class SkipRetryOnPermanentSmtpFailure
{
    public function handle(object $job, callable $next): mixed
    {
        try {
            return $next($job);
        } catch (\Throwable $e) {
            if (SmtpFailure::isPermanent($e) && method_exists($job, 'fail')) {
                $job->fail($e);

                return null;
            }

            throw $e;
        }
    }
}

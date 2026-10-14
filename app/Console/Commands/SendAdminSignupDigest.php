<?php

namespace App\Console\Commands;

use App\Support\AdminSignupDigest;
use Illuminate\Console\Command;

class SendAdminSignupDigest extends Command
{
    protected $signature = 'emails:send-admin-signup-digest';

    protected $description = 'Send the pending admin new-user digest (if any signups are waiting)';

    public function handle(AdminSignupDigest $digest): int
    {
        if (! AdminSignupDigest::enabled()) {
            $this->warn('Admin signup digest is disabled (ADMIN_SIGNUP_DIGEST=false).');

            return self::SUCCESS;
        }

        $pending = count($digest->pendingIds());
        $sent = $digest->flush();
        $this->info('Flushed '.$pending.' pending signup(s); sent '.$sent.' digest mail(s).');

        return self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Support\AdminSignupDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAdminSignupDigestJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'admin-signup-digest';
    }

    public function handle(AdminSignupDigest $digest): void
    {
        $digest->flush();
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\EmailNotificationService;
use Illuminate\Console\Command;

class SendEmailDigests extends Command
{
    protected $signature = 'emails:send-digests {--type=weekly : weekly|monthly}';

    protected $description = 'Send weekly activity or monthly spending summary emails to advertisers';

    public function handle(EmailNotificationService $emails): int
    {
        $type = $this->option('type');
        $advertiserRole = Role::where('name', 'advertiser')->first();
        if (!$advertiserRole) {
            $this->warn('No advertiser role found.');
            return self::SUCCESS;
        }

        $users = User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $advertiserRole->id))
            ->whereNotNull('email')
            ->get();

        $sent = 0;
        foreach ($users as $user) {
            if ($type === 'monthly') {
                $from = now()->subMonth()->startOfMonth();
                $to = now()->subMonth()->endOfMonth();
                $orders = Order::query()
                    ->where('user_id', $user->id)
                    ->where('payment_status', 'paid')
                    ->whereBetween('created_at', [$from, $to])
                    ->get();

                if ($orders->isEmpty()) {
                    continue;
                }

                $emails->sendMonthlySummary($user, [
                    'month_key' => $from->format('Y-m'),
                    'month_label' => $from->format('F Y'),
                    'spend' => round((float) $orders->sum('total_amount'), 2),
                    'orders' => $orders->count(),
                    'aov' => round((float) $orders->avg('total_amount'), 2),
                ]);
                $sent++;
            } else {
                $from = now()->subWeek()->startOfWeek();
                $to = now()->subWeek()->endOfWeek();
                $orders = Order::query()
                    ->where('user_id', $user->id)
                    ->whereBetween('created_at', [$from, $to])
                    ->get();

                if ($orders->isEmpty()) {
                    continue;
                }

                $emails->sendWeeklySummary($user, [
                    'week_key' => $from->format('o-\WW'),
                    'orders' => $orders->count(),
                    'spend' => round((float) $orders->where('payment_status', 'paid')->sum('total_amount'), 2),
                    'completed' => $orders->where('status', 'completed')->count(),
                ]);
                $sent++;
            }
        }

        $this->info("Queued {$sent} {$type} digest email(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use App\Listeners\HandleOrderBillingDocuments;
use App\Listeners\SendOrderLifecycleEmails;
use App\Listeners\SendTrustpilotReviewOnOrderCompleted;
use App\Models\Order;
use App\Models\Project;
use App\Models\User;
use App\Services\EmailNotificationService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Email logging: LogSentEmail is auto-discovered (MessageSent)

        // Gap-fill: welcome + admin new-user (HTTP only — skips seeders/artisan)
        User::created(function (User $user) {
            if (app()->runningInConsole()) {
                return;
            }
            $emails = app(EmailNotificationService::class);
            $emails->sendWelcome($user);
            $emails->notifyAdminsNewUser($user);
        });

        // Order lifecycle emails → Advertiser + Publisher + Marketing + Admin
        Order::created(function (Order $order) {
            app(SendOrderLifecycleEmails::class)->created($order);
            app(HandleOrderBillingDocuments::class)->created($order);
        });

        Order::updated(function (Order $order) {
            app(SendOrderLifecycleEmails::class)->updated($order);
            app(HandleOrderBillingDocuments::class)->updated($order);
            app(SendTrustpilotReviewOnOrderCompleted::class)->handle($order);
        });

        View::composer('*', function ($view) {

            if (auth()->check()) {

                $projects = Project::where('user_id', auth()->id())
                    ->latest()
                    ->get();

            } else {
                $projects = collect();
            }

            $view->with('sidebarProjects', $projects);
        });
    }
}
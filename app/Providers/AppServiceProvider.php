<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Mail\Events\MessageSent;
use App\Listeners\LogSentEmail;
use App\Listeners\SendTrustpilotReviewOnOrderCompleted;
use App\Models\Order;
use App\Models\Project;
use App\Models\User;
use App\Services\EmailNotificationService;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('apple', \SocialiteProviders\Apple\Provider::class);
        });

        // Non-invasive email logging for Admin Email Center (does not alter send call sites)
        Event::listen(MessageSent::class, LogSentEmail::class);

        // Gap-fill: welcome + admin new-user (HTTP only — skips seeders/artisan)
        User::created(function (User $user) {
            if (app()->runningInConsole()) {
                return;
            }
            $emails = app(EmailNotificationService::class);
            $emails->sendWelcome($user);
            $emails->notifyAdminsNewUser($user);
        });

        // Trustpilot after order completion (observes model updates only)
        Order::updated(function (Order $order) {
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
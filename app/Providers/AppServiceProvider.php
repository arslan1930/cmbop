<?php

namespace App\Providers;

use App\Listeners\HandleOrderBillingDocuments;
use App\Listeners\SendOrderLifecycleEmails;
use App\Listeners\SendTrustpilotReviewOnOrderCompleted;
use App\Models\Blog;
use App\Models\Order;
use App\Models\Project;
use App\Models\User;
use App\Services\EmailNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Gap-fill: welcome + admin new-user (HTTP only — skips seeders/artisan)
        // afterCommit so signup transaction is never blocked by mail/SMTP.
        User::created(function (User $user) {
            if (app()->runningInConsole()) {
                return;
            }

            $userId = $user->id;

            $run = function () use ($userId) {
                try {
                    $fresh = User::find($userId);
                    if (! $fresh) {
                        return;
                    }
                    $emails = app(EmailNotificationService::class);
                    $emails->sendWelcome($fresh);
                    $emails->notifyAdminsNewUser($fresh);
                } catch (\Throwable $e) {
                    Log::warning('Post-registration email hooks failed', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            };

            if (DB::transactionLevel() > 0) {
                DB::afterCommit($run);
            } else {
                $run();
            }
        });

        // Order lifecycle emails — listeners themselves defer to afterCommit
        Order::created(function (Order $order) {
            try {
                app(SendOrderLifecycleEmails::class)->created($order);
            } catch (\Throwable $e) {
                Log::warning('Order created notification hook failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                app(HandleOrderBillingDocuments::class)->created($order);
            } catch (\Throwable $e) {
                Log::warning('Order created billing hook failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        Order::updated(function (Order $order) {
            try {
                app(SendOrderLifecycleEmails::class)->updated($order);
            } catch (\Throwable $e) {
                Log::warning('Order updated notification hook failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                app(HandleOrderBillingDocuments::class)->updated($order);
            } catch (\Throwable $e) {
                Log::warning('Order updated billing hook failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                app(SendTrustpilotReviewOnOrderCompleted::class)->handle($order);
            } catch (\Throwable $e) {
                Log::warning('Trustpilot review hook failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
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

        // Recent published posts for the public footer "Latest Updates" section
        View::composer('components.footer', function ($view) {
            $posts = collect();

            try {
                if (Schema::hasTable('blogs')) {
                    $posts = Blog::published()
                        ->orderByDesc('published_at')
                        ->limit(4)
                        ->get(['id', 'title', 'slug', 'published_at', 'created_at']);
                }
            } catch (\Throwable) {
                $posts = collect();
            }

            $view->with('footerRecentBlogs', $posts);
        });
    }
}

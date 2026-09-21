<?php

namespace App\Providers;

use App\Listeners\HandleOrderBillingDocuments;
use App\Listeners\IssueDepositReceipt;
use App\Listeners\SendOrderLifecycleEmails;
use App\Listeners\SendTrustpilotReviewOnOrderCompleted;
use App\Listeners\StampEmailLogFailedJobUuid;
use App\Models\Blog;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\Project;
use App\Models\User;
use App\Services\CartPricingService;
use App\Services\EmailNotificationService;
use App\Services\Wallet\WelcomeBonusService;
use App\Support\BillingCustomerMailSuppressor;
use App\Support\MarketingOpsQueues;
use App\Support\OrderLifecycleMailSuppressor;
use App\Support\PublicStorageLink;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrderLifecycleMailSuppressor::class);
        $this->app->singleton(BillingCustomerMailSuppressor::class);

        // Blade views call these helpers on every form page. Composer "files"
        // autoload is enough after dump-autoload, but a deploy that only
        // synced PHP without regenerating the classmap leaves My Sites (and
        // every other old_text() form) as Call to undefined function — and
        // catalog eye-reveal refresh as Call to undefined function safe_external_url()
        // / site_description_excerpt().
        foreach ([
            app_path('Helpers/LanguageHelper.php'),
            app_path('Helpers/FormHelper.php'),
            app_path('Helpers/UrlHelper.php'),
            app_path('Helpers/SiteDescriptionHelper.php'),
            app_path('Helpers/MoneyHelper.php'),
        ] as $helper) {
            if (is_file($helper)) {
                require_once $helper;
            }
        }
    }

    public function boot(): void
    {
        $this->assertConfiguredMediaPath();

        // {{ $array }} compiles to htmlspecialchars() and 500s. Flatten first.
        // Drop stale compiled views that still call e() directly — Hostinger
        // zip deploys often keep compiled PHP newer than the Blade source.
        Blade::setEchoFormat('blade_e(%s)');
        $this->forgetStaleBladeEchoCache();

        // App shells use Bootstrap, not Tailwind. Laravel's default Tailwind
        // pagination SVGs render as giant arrows when w-5/h-5/hidden utilities
        // are missing — switch to Bootstrap 5 views sitewide (catalog + admin).
        Paginator::useBootstrapFive();

        // event:cache can drop discovered listeners; stamp the failed job UUID
        // so Email Center retry does not attach the wrong SendQueuedMailable.
        Queue::failing(function (JobFailed $event) {
            app(StampEmailLogFailedJobUuid::class)->handle($event);
        });

        // Register flood control must use the same place key as the €20 claim.
        // Default throttle:N,M follows Request::ip() / X-Forwarded-For.
        RateLimiter::for('register', function (Request $request) {
            $place = app(WelcomeBonusService::class)->placeKey($request);

            return Limit::perMinute(5)->by('register-http:'.$place);
        });

        // Flood cap on top of LoginController's email+IP / IP buckets.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(60)->by('login-http:'.$request->ip());
        });

        // Shared with register / profile / reset. Keep min 8 only — mixedCase
        // or numbers would reject the password123 fixtures used in register tests.
        Password::defaults(static fn () => Password::min(8));

        RateLimiter::for('password-email', function (Request $request) {
            return Limit::perMinutes(10, 5)
                ->by('forgot-http:'.$request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'status' => 'error',
                        'message' => function_exists('user_message')
                            ? user_message('password.throttled', 'Too many attempts. Please try again later.')
                            : 'Too many attempts. Please try again later.',
                    ], 429, $headers);
                });
        });

        RateLimiter::for('password-update', function (Request $request) {
            return Limit::perMinutes(10, 5)
                ->by('reset-http:'.$request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'status' => 'error',
                        'message' => function_exists('user_message')
                            ? user_message('password.reset_throttled', 'Too many attempts. Try again later.')
                            : 'Too many attempts. Try again later.',
                    ], 429, $headers);
                });
        });

        // Authenticated users hitting /login or /register go to their role dashboard.
        RedirectIfAuthenticated::redirectUsing(function () {
            $user = Auth::user();
            if ($user && method_exists($user, 'getDashboardRoute')) {
                return $user->getDashboardRoute();
            }

            return '/';
        });

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

        // Wallet top-ups settle through several paths (admin approval, Stripe
        // webhooks, saved cards); hook the model so each one issues a receipt.
        DepositRequest::created(function (DepositRequest $deposit) {
            try {
                app(IssueDepositReceipt::class)->created($deposit);
            } catch (\Throwable $e) {
                Log::warning('Deposit created receipt hook failed', [
                    'deposit_request_id' => $deposit->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        DepositRequest::updated(function (DepositRequest $deposit) {
            try {
                app(IssueDepositReceipt::class)->updated($deposit);
            } catch (\Throwable $e) {
                Log::warning('Deposit updated receipt hook failed', [
                    'deposit_request_id' => $deposit->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        View::composer('*', function ($view) {
            try {
                if (auth()->check()) {
                    $projects = Project::where('user_id', auth()->id())
                        ->latest()
                        ->get();
                } else {
                    $projects = collect();
                }
            } catch (\Throwable $e) {
                Log::warning('sidebarProjects composer failed', ['error' => $e->getMessage()]);
                $projects = collect();
            }

            $view->with('sidebarProjects', $projects);
        });

        // Recent published posts for the public footer "Latest Updates" section
        View::composer('components.footer', function ($view) {
            $posts = collect();

            try {
                if (Schema::hasTable('blogs')) {
                    $locale = public_locale();
                    $posts = Blog::published();
                    if (method_exists(Blog::class, 'scopeWithoutLegacyRedirects')) {
                        $posts = $posts->withoutLegacyRedirects();
                    }
                    $posts = $posts
                        ->withPublishedLocale($locale)
                        ->orderByDesc('published_at')
                        ->limit(4)
                        ->get();

                    $posts->transform(
                        fn (Blog $post) => $post->applyPublishedLocale($locale)
                    );
                }
            } catch (\Throwable) {
                $posts = collect();
            }

            $view->with('footerRecentBlogs', $posts);
        });

        View::composer('marketing.layouts.app', function ($view) {
            $ready = 0;
            $bulk = 0;

            try {
                $ready = MarketingOpsQueues::sitesReadyForStaffCount();
                $bulk = MarketingOpsQueues::bulkWaitingOnMarketerCount();
            } catch (\Throwable $e) {
                Log::warning('Marketing sidebar queue badges failed', ['error' => $e->getMessage()]);
            }

            $view->with([
                'mktReadySiteCount' => $ready,
                'mktBulkWaitingCount' => $bulk,
            ]);
        });

        View::composer('advertiser.layouts.app', function ($view) {
            $sessionCart = [];
            try {
                $rawCart = session('cart', []);
                $sessionCart = is_array($rawCart) ? array_values($rawCart) : [];
            } catch (\Throwable $e) {
                Log::warning('Advertiser header cart session unreadable', ['error' => $e->getMessage()]);
            }

            $pruned = [
                'cart' => $sessionCart,
                'removed_inactive' => [],
                'removed_owned' => [],
            ];

            try {
                if (auth()->check()) {
                    $pruned = app(CartPricingService::class)
                        ->syncAdvertiserSessionCart(auth()->user());
                }
            } catch (\Throwable $e) {
                Log::warning('Advertiser cart prune composer failed', ['error' => $e->getMessage()]);
            }

            $headerCart = is_array($pruned['cart'] ?? null) ? $pruned['cart'] : [];

            $view->with([
                'headerCart' => $headerCart,
                'ssrCartRemovedInactive' => is_array($pruned['removed_inactive'] ?? null) ? $pruned['removed_inactive'] : [],
                'ssrCartRemovedOwned' => is_array($pruned['removed_owned'] ?? null) ? $pruned['removed_owned'] : [],
            ]);
        });
    }

    private function forgetStaleBladeEchoCache(): void
    {
        $dir = storage_path('framework/views');
        $marker = $dir.DIRECTORY_SEPARATOR.'.blade-e-v1';
        if (! is_dir($dir) || is_file($marker)) {
            return;
        }

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            @unlink($file);
        }

        @file_put_contents($marker, '1');
    }

    /**
     * When MEDIA_PATH is set (Hostinger durable media), fail loudly if the
     * directory is missing or not writable so uploads do not silently die.
     * Also warn when public/storage does not resolve to MEDIA_PATH (blank
     * admin previews after a "successful" upload).
     * Unset MEDIA_PATH keeps the default storage/app/public (local/CI).
     */
    private function assertConfiguredMediaPath(): void
    {
        $configured = config('filesystems.media_path');
        if (! is_string($configured) || trim($configured) === '') {
            return;
        }

        $path = rtrim($configured, DIRECTORY_SEPARATOR);
        $ok = is_dir($path) && is_writable($path);
        if (! $ok) {
            $message = 'MEDIA_PATH is set to ['.$path.'] but that directory is missing or not writable. '
                .'Create it (and ownership for the PHP user) or clear MEDIA_PATH. See docs/hostinger-media.md.';

            Log::critical($message);

            throw new \RuntimeException($message);
        }

        if (app()->runningUnitTests()) {
            return;
        }

        // Best-effort: recreate public/storage → MEDIA_PATH when it drifted after a deploy.
        $ensure = PublicStorageLink::ensure();
        if (! $ensure['ok']) {
            Log::warning('public/storage does not point at MEDIA_PATH — site images may look blank after upload. '
                .'Run: php artisan media:ensure-link', [
                    'media_path' => $path,
                    'ensure' => $ensure,
                ]);
        }
    }
}

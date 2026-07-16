<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Models\EmailNotificationSetting;
use App\Support\EmailCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EmailCenterController extends Controller
{
    public function index(Request $request)
    {
        $stats = [
            'sent_today' => EmailLog::today()->count(),
            'pending' => EmailLog::pending()->count() + $this->queuedMailJobsCount(),
            'failed' => EmailLog::failed()->count() + $this->failedMailJobsCount(),
            'delivered' => EmailLog::delivered()->count(),
        ];

        $recentLogs = EmailLog::query()
            ->latest('id')
            ->limit(25)
            ->get();

        $templates = collect(EmailCatalog::all())->map(function (array $meta, string $key) {
            $meta['key'] = $key;
            $meta['last_sent_at'] = EmailLog::query()
                ->where('template_key', $key)
                ->where('status', EmailLog::STATUS_DELIVERED)
                ->latest('sent_at')
                ->value('sent_at');
            $meta['sent_count'] = EmailLog::query()
                ->where('template_key', $key)
                ->where('status', EmailLog::STATUS_DELIVERED)
                ->count();

            return $meta;
        })->values();

        $smtp = [
            'mailer' => config('mail.default'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
            'username' => config('mail.mailers.smtp.username'),
            'encryption' => config('mail.mailers.smtp.scheme') ?: (config('mail.mailers.smtp.port') == 465 ? 'ssl' : 'tls'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'admin_email' => config('mail.admin_email'),
            'queue_connection' => config('queue.default'),
            'configured' => config('mail.default') !== 'log' && filled(config('mail.mailers.smtp.host')),
        ];

        $queue = [
            'connection' => config('queue.default'),
            'pending_jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'mail_pending_jobs' => $this->queuedMailJobsCount(),
            'mail_failed_jobs' => $this->failedMailJobsCount(),
        ];

        $failedLogs = EmailLog::failed()->latest('id')->limit(20)->get();

        $settings = collect(config('email_notifications.types', []))->map(function (array $meta, string $type) {
            return [
                'type' => $type,
                'name' => $meta['name'] ?? $type,
                'audience' => $meta['audience'] ?? 'user',
                'enabled' => EmailNotificationSetting::isEnabled($type),
                'preference' => $meta['preference'] ?? null,
            ];
        })->values();

        $brand = config('email_notifications.brand', []);

        return view('admin.emails.index', compact(
            'stats',
            'recentLogs',
            'templates',
            'smtp',
            'queue',
            'failedLogs',
            'settings',
            'brand'
        ));
    }

    public function updateSettings(Request $request)
    {
        $enabled = $request->input('enabled', []);
        $types = array_keys(config('email_notifications.types', []));

        foreach ($types as $type) {
            EmailNotificationSetting::updateOrCreate(
                ['type' => $type],
                ['enabled' => !empty($enabled[$type])]
            );
        }

        EmailNotificationSetting::flushCache();

        return back()->with('success', 'Email notification settings saved.');
    }

    public function preview(string $key)
    {
        $template = EmailCatalog::get($key);
        abort_unless($template, 404);

        if ($key === 'password_reset') {
            return response($this->renderMarkdown('emails.password-reset-preview', [
                'resetUrl' => url('/password/reset/preview-token'),
            ]));
        }

        $mailable = EmailCatalog::makeMailable($key);
        abort_unless($mailable, 404);

        return response($mailable->render());
    }

    public function sendTest(Request $request)
    {
        $data = $request->validate([
            'template' => 'required|string',
            'email' => 'required|email',
        ]);

        $key = $data['template'];
        $template = EmailCatalog::get($key);
        abort_unless($template, 404);

        try {
            if ($key === 'password_reset') {
                $html = $this->renderMarkdown('emails.password-reset-preview', [
                    'resetUrl' => url('/password/reset/preview-token'),
                ]);
                Mail::html($html, function ($message) use ($data) {
                    $message->to($data['email'])
                        ->subject('Password Reset (Test Preview)');
                });
            } else {
                $mailable = EmailCatalog::makeMailable($key);
                abort_unless($mailable, 404);
                Mail::to($data['email'])->send($mailable);
            }

            // MessageSent listener logs successful deliveries; ensure test marker if listener missed it
            $logged = EmailLog::query()
                ->where('to_email', $data['email'])
                ->where('created_at', '>=', now()->subMinute())
                ->exists();

            if (!$logged) {
                EmailLog::create([
                    'uuid' => (string) Str::uuid(),
                    'mailable' => $template['mailable'] ?? null,
                    'template_key' => $key,
                    'to_email' => $data['email'],
                    'subject' => ($template['name'] ?? $key) . ' (Test)',
                    'status' => EmailLog::STATUS_DELIVERED,
                    'attempts' => 1,
                    'meta' => ['source' => 'email_center_test', 'mailer' => config('mail.default')],
                    'sent_at' => now(),
                ]);
            }

            return back()->with('success', 'Test email sent to ' . $data['email'] . '.');
        } catch (\Throwable $e) {
            EmailLog::create([
                'uuid' => (string) Str::uuid(),
                'mailable' => $template['mailable'] ?? null,
                'template_key' => $key,
                'to_email' => $data['email'],
                'subject' => ($template['name'] ?? $key) . ' (Test)',
                'status' => EmailLog::STATUS_FAILED,
                'error' => $e->getMessage(),
                'attempts' => 1,
                'meta' => ['source' => 'email_center_test'],
            ]);

            return back()->with('error', 'Failed to send test email: ' . $e->getMessage());
        }
    }

    public function retryFailed(Request $request)
    {
        $retried = 0;

        if (Schema::hasTable('failed_jobs') && DB::table('failed_jobs')->count() > 0) {
            try {
                Artisan::call('queue:retry', ['id' => 'all']);
                $retried++;
            } catch (\Throwable $e) {
                return back()->with('error', 'Could not retry queue jobs: ' . $e->getMessage());
            }
        }

        // Mark email_logs failed rows as pending for operational visibility
        $updated = EmailLog::failed()->update([
            'status' => EmailLog::STATUS_PENDING,
            'error' => null,
        ]);

        return back()->with(
            'success',
            'Retry requested. Failed email logs re-queued for attention: ' . $updated
            . ($retried ? ' · Laravel failed_jobs retry:all dispatched.' : '')
        );
    }

    protected function queuedMailJobsCount(): int
    {
        if (!Schema::hasTable('jobs')) {
            return 0;
        }

        return (int) DB::table('jobs')
            ->where(function ($q) {
                $q->where('payload', 'like', '%Mail%')
                    ->orWhere('payload', 'like', '%Mailable%');
            })
            ->count();
    }

    protected function failedMailJobsCount(): int
    {
        if (!Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return (int) DB::table('failed_jobs')
            ->where(function ($q) {
                $q->where('payload', 'like', '%Mail%')
                    ->orWhere('payload', 'like', '%Mailable%');
            })
            ->count();
    }

    protected function renderMarkdown(string $view, array $data = []): string
    {
        return app(\Illuminate\Mail\Markdown::class)->render($view, $data);
    }
}

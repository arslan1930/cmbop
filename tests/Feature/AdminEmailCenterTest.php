<?php

namespace Tests\Feature;

use App\Listeners\StampEmailLogFailedJobUuid;
use App\Mail\AudienceCampaignMail;
use App\Mail\WelcomeEmail;
use App\Models\DepositRequest;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailLog;
use App\Models\EmailNotificationSetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\User;
use App\Models\WebsiteSuggestion;
use App\Models\Withdrawal;
use App\Support\EmailCatalog;
use App\Support\MailJobPayload;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AdminEmailCenterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>|string  $ids
     */
    private function mockQueueRetry(array|string $ids, string $output = 'Pushing failed queue jobs back onto the queue.'): void
    {
        $ids = is_array($ids) ? $ids : [$ids];
        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:retry', ['id' => $ids])
            ->andReturnUsing(function () use ($ids, $output) {
                if (str_contains($output, 'Pushing failed queue jobs')) {
                    DB::table('failed_jobs')->whereIn('uuid', $ids)->delete();
                }

                return 0;
            });
        Artisan::shouldReceive('output')->andReturn($output);
    }

    private function userWithRole(string $roleName, array $overrides = []): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function seedLiveCustomerRecords(): User
    {
        $leaked = $this->userWithRole('advertiser', [
            'name' => 'Leaked Customer',
            'email' => 'leaked@example.com',
        ]);
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Leaked Publisher',
            'email' => 'leaked-publisher@example.com',
        ]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Leaked Publisher Site',
            'site_url' => 'https://leaked-site.example',
            'domain' => 'leaked-site.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => '3',
            'description' => 'Live row that previews must not use',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $leaked->id,
            'order_number' => 'ORD-LEAKED',
            'reference_code' => 'REF-LEAKED',
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 100,
            'publisher_price' => 85,
            'content_link' => 'https://leaked-site.example/article.docx',
        ]);

        DepositRequest::create([
            'user_id' => $leaked->id,
            'reference_code' => 'DEP-LEAKED',
            'amount' => 250,
            'payment_method' => 'bank_transfer',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 80,
            'fee' => 0,
            'net_amount' => 80,
            'payment_method' => 'paypal',
            'status' => 'pending',
        ]);

        Invoice::create([
            'user_id' => $leaked->id,
            'order_id' => $order->id,
            'invoice_number' => 'INV-LEAKED-1',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'invoice_date' => now(),
            'customer_name' => $leaked->name,
            'customer_email' => $leaked->email,
            'currency' => 'EUR',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'order_number' => $order->order_number,
            'line_items' => [],
            'billing_snapshot' => [],
        ]);

        ProblemReport::create([
            'user_id' => $leaked->id,
            'name' => $leaked->name,
            'email' => $leaked->email,
            'subject' => 'Leaked problem',
            'message' => 'Should never appear in Email Center previews.',
            'status' => 'resolved',
            'admin_notes' => 'Leaked admin notes',
        ]);

        WebsiteSuggestion::create([
            'user_id' => $leaked->id,
            'website_name' => 'Leaked Tech Blog',
            'website_url' => 'https://leaked-tech.example',
            'domain' => 'leaked-tech.example',
            'status' => 'accepted',
            'admin_notes' => 'Leaked suggestion notes',
        ]);

        SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $leaked->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'name_matches' => true,
            'proof_message' => 'Leaked ownership proof',
            'contact_email' => $leaked->email,
            'status' => 'pending',
        ]);

        return $leaked;
    }

    public function test_guest_is_redirected_from_email_center(): void
    {
        $this->get(route('admin.emails.index'))->assertRedirect(route('login'));
        $this->get(route('admin.emails.preview', 'welcome'))->assertRedirect(route('login'));
    }

    public function test_advertiser_and_publisher_cannot_open_email_center(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $this->actingAs($advertiser)->get(route('admin.emails.index'))->assertForbidden();
        $this->actingAs($publisher)->get(route('admin.emails.index'))->assertForbidden();
        $this->actingAs($advertiser)->get(route('admin.emails.preview', 'welcome'))->assertForbidden();
    }

    public function test_admin_can_open_email_center(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->assertSee('Email Center', false)
            ->assertSee('synthetic preview', false);
    }

    public function test_admin_can_preview_welcome_and_unknown_key_is_404(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', 'welcome'))
            ->assertOk()
            ->assertSee('Welcome aboard, Sample', false);

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', 'not-a-real-template'))
            ->assertNotFound();
    }

    public function test_catalog_previews_stay_synthetic_when_live_rows_exist(): void
    {
        $this->seedLiveCustomerRecords();
        $admin = $this->userWithRole('admin');
        $invoicesBefore = Invoice::query()->count();

        foreach (array_keys(EmailCatalog::all()) as $key) {
            $html = $this->actingAs($admin)
                ->get(route('admin.emails.preview', $key))
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString('leaked@example.com', $html, $key);
            $this->assertStringNotContainsString('Leaked Customer', $html, $key);
            $this->assertStringNotContainsString('leaked-publisher@example.com', $html, $key);
            $this->assertStringNotContainsString('ORD-LEAKED', $html, $key);
            $this->assertStringNotContainsString('DEP-LEAKED', $html, $key);
            $this->assertStringNotContainsString('INV-LEAKED-1', $html, $key);
        }

        $this->assertSame($invoicesBefore, Invoice::query()->count());
    }

    public function test_welcome_preview_uses_placeholder_verify_url(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', 'welcome'))
            ->assertOk()
            ->assertSee('/email/verify/preview-id/preview-hash', false);
    }

    public function test_live_user_with_preview_email_is_not_treated_as_catalog_stand_in(): void
    {
        $user = $this->userWithRole('advertiser', [
            'email' => EmailCatalog::PREVIEW_EMAIL,
            'email_verified_at' => null,
        ]);

        $this->assertFalse(EmailCatalog::isPreviewUser($user));

        $html = (new WelcomeEmail($user))->render();
        $this->assertStringNotContainsString('/email/verify/preview-id/preview-hash', $html);
        $this->assertStringContainsString('/email/verify/'.$user->id.'/', $html);
    }

    public function test_force_send_failure_logs_envelope_recipient_not_sample_user(): void
    {
        $admin = $this->userWithRole('admin');
        $mail = EmailCatalog::makeMailable('welcome');
        $this->assertNotNull($mail);
        $mail->forceSend = true;
        $mail->skipUserPreference = true;
        $mail->dedupeKey = 'email_center_test:welcome:force-fail';
        $mail->to($admin->email);
        $mail->failed(new \RuntimeException('SMTP down'));

        $log = EmailLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->email, $log->to_email);
        $this->assertNotSame(EmailCatalog::PREVIEW_EMAIL, $log->to_email);
        $this->assertSame('email_center_test', $log->meta['source'] ?? null);
    }

    public function test_send_test_rejects_non_admin_inbox(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.test'), [
                'template' => 'welcome',
                'email' => 'someone-else@example.com',
            ])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, EmailLog::query()->count());
    }

    public function test_send_test_delivers_welcome_and_logs_once(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.test'), [
                'template' => 'welcome',
                'email' => $admin->email,
            ])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, EmailLog::query()->count());
        $log = EmailLog::query()->first();
        $this->assertSame($admin->email, $log->to_email);
        $this->assertSame('welcome', $log->template_key);
        $this->assertSame(EmailLog::STATUS_DELIVERED, $log->status);
        $this->assertSame('email_center_test', $log->meta['source'] ?? null);
        $this->assertStringNotContainsString('leaked@example.com', (string) $log->subject);
    }

    public function test_send_test_bypasses_global_disable_and_dedupe(): void
    {
        $admin = $this->userWithRole('admin');
        EmailNotificationSetting::updateOrCreate(
            ['type' => 'welcome'],
            ['enabled' => false]
        );
        EmailNotificationSetting::flushCache('welcome');
        $this->assertFalse(EmailNotificationSetting::isEnabled('welcome'));

        $this->actingAs($admin)
            ->post(route('admin.emails.test'), [
                'template' => 'welcome',
                'email' => $admin->email,
            ])
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->post(route('admin.emails.test'), [
                'template' => 'welcome',
                'email' => $admin->email,
            ])
            ->assertSessionHas('success');

        $this->assertSame(2, EmailLog::query()->where('template_key', 'welcome')->count());
    }

    public function test_send_test_records_mailable_when_faked(): void
    {
        Mail::fake();
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.emails.test'), [
                'template' => 'welcome',
                'email' => $admin->email,
            ])
            ->assertSessionHas('success');

        Mail::assertSent(WelcomeEmail::class, function (WelcomeEmail $mail) use ($admin) {
            return $mail->hasTo($admin->email)
                && $mail->forceSend === true
                && EmailCatalog::isPreviewUser($mail->user);
        });
    }

    public function test_send_test_every_template_succeeds_without_live_side_effects(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedLiveCustomerRecords();
        $admin = $this->userWithRole('admin');
        $invoicesBefore = Invoice::query()->count();

        foreach (array_keys(EmailCatalog::templates()) as $key) {
            $response = $this->actingAs($admin)
                ->from(route('admin.emails.index'))
                ->post(route('admin.emails.test'), [
                    'template' => $key,
                    'email' => $admin->email,
                ]);

            $this->assertTrue(
                $response->isRedirect(route('admin.emails.index')),
                $key.' status '.$response->status().': '.($response->exception?->getMessage() ?? '')
            );
            $this->assertTrue(
                $response->getSession()->has('success'),
                $key.': '.($response->getSession()->get('error') ?? $response->exception?->getMessage() ?? 'missing success flash')
            );
        }

        $this->assertSame($invoicesBefore, Invoice::query()->count());
        $this->assertSame(
            count(EmailCatalog::templates()),
            EmailLog::query()->where('to_email', $admin->email)->where('status', EmailLog::STATUS_DELIVERED)->count()
        );
    }

    public function test_send_test_password_reset_does_not_invent_a_delivered_row_on_failure_path(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.emails.test'), [
                'template' => 'password_reset',
                'email' => $admin->email,
            ])
            ->assertSessionHas('success');

        $logs = EmailLog::query()->where('to_email', $admin->email)->get();
        $this->assertCount(1, $logs);
        $this->assertSame('password_reset', $logs->first()->template_key);
        $this->assertSame(EmailLog::STATUS_DELIVERED, $logs->first()->status);
        $this->assertSame('email_center_test', $logs->first()->meta['source'] ?? null);
        $this->assertNotEmpty($logs->first()->dedupe_key);
    }

    public function test_catalog_keys_match_notification_config(): void
    {
        $configKeys = array_keys(config('email_notifications.types'));

        $this->assertEqualsCanonicalizing($configKeys, array_keys(EmailCatalog::all()));
        $this->assertSame($configKeys, array_keys(EmailCatalog::templates()));
        $this->assertArrayHasKey('spend_budget_alert', EmailCatalog::templates());
        foreach ([
            'email_verification',
            'content_evaluation_result',
            'site_discount_ended',
            'payout_profile_updated',
            'bulk_site_request_submitted',
            'bulk_sites_seeded',
            'admin_assigned_site',
            'audience_campaign',
            'bulk_request_cancelled',
            'bulk_request_items_rejected',
            'spend_budget_alert',
        ] as $key) {
            $this->assertArrayHasKey($key, EmailCatalog::templates());
            $this->assertNotSame('', EmailCatalog::templates()[$key]['description'] ?? '');
            $this->assertNotSame('Other', EmailCatalog::templates()[$key]['category'] ?? 'Other');
        }
    }

    public function test_email_center_lists_templates_from_config_including_spend_budget_alert(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->assertSee('Spend Budget Alert', false)
            ->assertSee('Email Verification', false)
            ->assertSee('Content Evaluation Result', false)
            ->assertSee('Site Discount Ended', false)
            ->assertSee('Payout Profile Updated by Support', false)
            ->assertSee('Bulk Site Request Submitted', false)
            ->assertSee('Bulk Sites Seeded', false)
            ->assertSee('Admin Assigned Site', false)
            ->assertSee('Updates & Campaigns')
            ->assertSee('Bulk Website Request Cancelled', false)
            ->assertSee('Bulk Request Sites Not Added', false);
    }

    public function test_key_from_subject_uses_unique_needles_longest_first(): void
    {
        $cases = [
            'Welcome to SEOLinkBuildings' => 'welcome',
            'New Withdrawal Request - €50.00' => 'withdrawal_request',
            'Withdrawal request received (WD-12)' => 'withdrawal_requested_confirmation',
            'Withdrawal Request Approved' => 'withdrawal_status',
            'Withdrawal Request Completed' => 'withdrawal_status',
            'New Order for Your Site: Example' => 'publisher_new_order',
            'New order #ORD-1 created' => 'order_status_changed',
            'Manual Payment Required - New Order #ORD-1' => 'admin_manual_payment',
            'Order Accepted - #ORD-1' => 'order_accepted',
            'Payment Confirmed for Order #ORD-1' => 'order_payment_confirmed',
            'Payment Successful – Invoice Attached' => 'payment_successful_invoice',
            'Deposit Approved - €100.00' => 'deposit_approved',
            'New Deposit Request - €100.00' => 'deposit_submitted',
            'Your site discount has ended — Sample Site' => 'site_discount_ended',
            'Your payout details were updated' => 'payout_profile_updated',
            'Your article was approved for publication' => 'content_evaluation_result',
            'Article evaluation update: action needed' => 'content_evaluation_result',
            'Bulk site request from Sample User' => 'bulk_site_request_submitted',
            'Your sites were added to Pending sites' => 'bulk_sites_seeded',
            'Please accept a website we added for you' => 'admin_assigned_site',
            'Your bulk website request was cancelled' => 'bulk_request_cancelled',
            'We did not add a site from bulk request #0' => 'bulk_request_items_rejected',
            'Spend budget warning' => 'spend_budget_alert',
            'Monthly spend budget reached' => 'spend_budget_alert',
            'Low wallet balance alert' => 'spend_budget_alert',
            'Verify your email (Test Preview)' => 'email_verification',
            'Password Reset (Test Preview)' => 'password_reset',
        ];

        foreach ($cases as $subject => $expected) {
            $this->assertSame($expected, EmailCatalog::keyFromSubject($subject), $subject);
        }
    }

    public function test_every_notification_type_has_a_preview(): void
    {
        $admin = $this->userWithRole('admin');

        foreach (array_keys(config('email_notifications.types')) as $key) {
            $this->actingAs($admin)
                ->get(route('admin.emails.preview', $key))
                ->assertOk();
        }
    }

    public function test_password_reset_preview_uses_public_preview_token(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', 'password_reset'))
            ->assertOk()
            ->assertSee('/password/reset/preview-token', false)
            ->assertSee(rtrim(app_public_url(), '/'), false);
    }

    public function test_email_verification_preview_is_a_placeholder(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', 'email_verification'))
            ->assertOk()
            ->assertSee('/email/verify/preview-id/preview-hash', false);
    }

    public function test_settings_reject_empty_payload_and_skip_framework_types(): void
    {
        $admin = $this->userWithRole('admin');
        EmailNotificationSetting::updateOrCreate(
            ['type' => 'password_reset'],
            ['enabled' => true]
        );

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.settings'), [])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHasErrors('enabled');

        $this->assertTrue(EmailNotificationSetting::query()->where('type', 'password_reset')->value('enabled'));

        $enabled = [];
        foreach (config('email_notifications.types') as $type => $meta) {
            if (! empty($meta['framework'])) {
                continue;
            }
            $enabled[$type] = $type === 'welcome' ? '0' : '1';
        }

        $this->actingAs($admin)
            ->post(route('admin.emails.settings'), ['enabled' => $enabled])
            ->assertSessionHas('success');

        EmailNotificationSetting::flushCache();
        $this->assertFalse(EmailNotificationSetting::isEnabled('welcome'));
        $this->assertTrue(EmailNotificationSetting::isEnabled('password_reset'));
        $this->assertTrue(EmailNotificationSetting::isEnabled('email_verification'));
    }

    public function test_retry_only_retries_mail_failed_jobs_and_leaves_logs(): void
    {
        $admin = $this->userWithRole('admin');
        $mailUuid = (string) Str::uuid();
        $otherUuid = (string) Str::uuid();

        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => $admin->email,
            'subject' => 'Welcome',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
        ]);

        DB::table('failed_jobs')->insert([
            [
                'uuid' => $mailUuid,
                'connection' => 'database',
                'queue' => 'emails',
                'payload' => json_encode([
                    'displayName' => 'App\\Mail\\WelcomeEmail',
                    'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                    'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
                ]),
                'exception' => 'SMTP failed',
                'failed_at' => now(),
            ],
            [
                'uuid' => $otherUuid,
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode([
                    'displayName' => 'App\\Jobs\\EnrichSiteJob',
                    'data' => ['commandName' => 'App\\Jobs\\EnrichSiteJob'],
                ]),
                'exception' => 'timeout',
                'failed_at' => now(),
            ],
        ]);

        $this->mockQueueRetry([$mailUuid]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'))
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $this->assertSame(EmailLog::STATUS_FAILED, EmailLog::query()->first()->status);
        $this->assertTrue(DB::table('failed_jobs')->where('uuid', $otherUuid)->exists());
    }

    public function test_kpis_do_not_double_count_queue_jobs(): void
    {
        $admin = $this->userWithRole('admin');

        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => $admin->email,
            'status' => EmailLog::STATUS_DELIVERED,
            'sent_at' => now(),
        ]);
        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => $admin->email,
            'status' => EmailLog::STATUS_DELIVERED,
            'sent_at' => now()->subDay(),
        ]);
        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => $admin->email,
            'status' => EmailLog::STATUS_PENDING,
        ]);
        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => $admin->email,
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'bounce',
        ]);

        DB::table('jobs')->insert([
            'queue' => 'emails',
            'payload' => json_encode(['displayName' => 'App\\Mail\\WelcomeEmail']),
            'attempts' => 0,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Delivered Today', $html);
        preg_match_all('/<div class="value[^"]*">\s*([0-9,]+)\s*<\/div>/', $html, $matches);
        $values = array_map(fn ($v) => (int) str_replace(',', '', $v), $matches[1] ?? []);
        $this->assertSame([3, 1, 1, 1], $values);
    }

    public function test_mailable_failed_hook_writes_email_log(): void
    {
        $mail = EmailCatalog::makeMailable('welcome');
        $this->assertNotNull($mail);
        $mail->failed(new \RuntimeException('SMTP down'));

        $this->assertDatabaseHas('email_logs', [
            'template_key' => 'welcome',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
        ]);
    }

    public function test_successful_send_updates_failed_log_with_same_dedupe_key(): void
    {
        $admin = $this->userWithRole('admin');
        $mail = EmailCatalog::makeMailable('welcome');
        $this->assertNotNull($mail);
        $mail->forceSend = true;
        $mail->skipUserPreference = true;
        $mail->dedupeKey = 'welcome-dedupe-retry';
        $mail->failed(new \RuntimeException('SMTP down'));

        $this->assertSame(1, EmailLog::query()->count());
        $this->assertSame(EmailLog::STATUS_FAILED, EmailLog::query()->value('status'));

        Mail::to($admin->email)->sendNow($mail);

        $this->assertSame(1, EmailLog::query()->count());
        $log = EmailLog::query()->first();
        $this->assertSame(EmailLog::STATUS_DELIVERED, $log->status);
        $this->assertSame($admin->email, $log->to_email);
        $this->assertNull($log->error);
        $this->assertSame(2, $log->attempts);
    }

    public function test_retry_rebuilds_framework_test_log_without_duplicate(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.emails.test'), [
                'template' => 'password_reset',
                'email' => $admin->email,
            ])
            ->assertSessionHas('success');

        $log = EmailLog::query()->first();
        $this->assertNotNull($log);
        $log->update([
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, EmailLog::query()->count());
        $fresh = $log->fresh();
        $this->assertSame(EmailLog::STATUS_DELIVERED, $fresh->status);
        $this->assertNull($fresh->error);
        $this->assertSame('email_center_test', $fresh->meta['source'] ?? null);
    }

    public function test_retry_rebuilds_legacy_framework_log_without_source(): void
    {
        $admin = $this->userWithRole('admin');
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'email_verification',
            'to_email' => $admin->email,
            'subject' => 'Verify your email (Test Preview)',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => [],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, EmailLog::query()->count());
        $this->assertSame(EmailLog::STATUS_DELIVERED, $log->fresh()->status);
    }

    public function test_retry_does_not_rebuild_production_framework_log(): void
    {
        $admin = $this->userWithRole('admin');
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'email_verification',
            'to_email' => 'customer@example.com',
            'subject' => 'Verify your email',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue'],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('error');

        $fresh = $log->fresh();
        $this->assertSame(EmailLog::STATUS_FAILED, $fresh->status);
        $this->assertSame('customer@example.com', $fresh->to_email);
        $this->assertSame(1, EmailLog::query()->count());
    }

    public function test_retry_rebuilds_email_center_test_log(): void
    {
        $admin = $this->userWithRole('admin');
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'dedupe_key' => 'email_center_test:welcome:fixed',
            'to_email' => $admin->email,
            'subject' => 'Welcome (Test)',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'email_center_test'],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, EmailLog::query()->count());
        $fresh = $log->fresh();
        $this->assertSame(EmailLog::STATUS_DELIVERED, $fresh->status);
        $this->assertSame($admin->email, $fresh->to_email);
        $this->assertNull($fresh->error);
    }

    public function test_retry_production_log_without_job_does_not_rebuild(): void
    {
        $admin = $this->userWithRole('admin');
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'to_email' => 'customer@example.com',
            'subject' => 'Welcome to SEOLinkBuildings',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue'],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('error');

        $this->assertSame(EmailLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertSame(0, EmailLog::query()->where('status', EmailLog::STATUS_DELIVERED)->count());
    }

    public function test_retry_production_log_requeues_matching_mail_job(): void
    {
        $admin = $this->userWithRole('admin');
        $mailUuid = (string) Str::uuid();
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => null,
            'template_key' => 'welcome',
            'to_email' => 'customer@example.com',
            'subject' => 'Welcome to SEOLinkBuildings',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue'],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => $mailUuid,
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode([
                'displayName' => WelcomeEmail::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
            ]),
            'exception' => 'SMTP failed',
            'failed_at' => now(),
        ]);

        $this->mockQueueRetry([$mailUuid]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $fresh = $log->fresh();
        $this->assertSame(EmailLog::STATUS_PENDING, $fresh->status);
        $this->assertSame(2, $fresh->attempts);
        $this->assertNull($fresh->error);
    }

    public function test_retry_production_log_uses_recipient_matching_job(): void
    {
        $admin = $this->userWithRole('admin');
        $customerUuid = (string) Str::uuid();
        $otherUuid = (string) Str::uuid();
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'to_email' => 'customer@example.com',
            'subject' => 'Welcome to SEOLinkBuildings',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue'],
        ]);

        DB::table('failed_jobs')->insert([
            [
                'uuid' => $otherUuid,
                'connection' => 'database',
                'queue' => 'emails',
                'payload' => json_encode([
                    'displayName' => WelcomeEmail::class,
                    'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
                    'to' => 'other@example.com',
                ]),
                'exception' => 'SMTP failed',
                'failed_at' => now(),
            ],
            [
                'uuid' => $customerUuid,
                'connection' => 'database',
                'queue' => 'emails',
                'payload' => json_encode([
                    'displayName' => WelcomeEmail::class,
                    'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
                    'to' => 'customer@example.com',
                ]),
                'exception' => 'SMTP failed',
                'failed_at' => now(),
            ],
        ]);

        $this->mockQueueRetry([$customerUuid]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');
    }

    public function test_retry_production_log_refuses_ambiguous_mail_jobs(): void
    {
        $admin = $this->userWithRole('admin');
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'to_email' => 'customer@example.com',
            'subject' => 'Welcome to SEOLinkBuildings',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue'],
        ]);

        DB::table('failed_jobs')->insert([
            [
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'emails',
                'payload' => json_encode([
                    'displayName' => WelcomeEmail::class,
                    'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
                ]),
                'exception' => 'SMTP failed',
                'failed_at' => now(),
            ],
            [
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'emails',
                'payload' => json_encode([
                    'displayName' => WelcomeEmail::class,
                    'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
                ]),
                'exception' => 'SMTP failed',
                'failed_at' => now(),
            ],
        ]);

        Artisan::shouldReceive('call')->never();

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('error');

        $this->assertSame(EmailLog::STATUS_FAILED, $log->fresh()->status);
    }

    public function test_retry_production_log_uses_stored_failed_job_uuid(): void
    {
        $admin = $this->userWithRole('admin');
        $storedUuid = (string) Str::uuid();
        $otherUuid = (string) Str::uuid();
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'to_email' => 'customer@example.com',
            'subject' => 'Welcome to SEOLinkBuildings',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue', 'failed_job_uuid' => $storedUuid],
        ]);

        DB::table('failed_jobs')->insert([
            [
                'uuid' => $otherUuid,
                'connection' => 'database',
                'queue' => 'emails',
                'payload' => json_encode([
                    'displayName' => WelcomeEmail::class,
                    'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
                    'to' => 'customer@example.com',
                ]),
                'exception' => 'SMTP failed',
                'failed_at' => now(),
            ],
            [
                'uuid' => $storedUuid,
                'connection' => 'database',
                'queue' => 'emails',
                'payload' => json_encode([
                    'displayName' => WelcomeEmail::class,
                    'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
                    'to' => 'customer@example.com',
                ]),
                'exception' => 'SMTP failed',
                'failed_at' => now(),
            ],
        ]);

        $this->mockQueueRetry([$storedUuid]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');
    }

    public function test_retry_production_log_does_not_match_prefix_dedupe_key(): void
    {
        $admin = $this->userWithRole('admin');
        $wrongUuid = (string) Str::uuid();
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'dedupe_key' => 'welcome:1',
            'to_email' => 'customer@example.com',
            'subject' => 'Welcome to SEOLinkBuildings',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue'],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => $wrongUuid,
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode([
                'displayName' => WelcomeEmail::class,
                'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
                'dedupe_key' => 'welcome:10',
                'to' => 'other@example.com',
            ]),
            'exception' => 'SMTP failed',
            'failed_at' => now(),
        ]);

        Artisan::shouldReceive('call')->never();

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('error');

        $this->assertSame(EmailLog::STATUS_FAILED, $log->fresh()->status);
    }

    public function test_retry_production_log_refuses_single_job_for_other_recipient(): void
    {
        $admin = $this->userWithRole('admin');
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'to_email' => 'customer@example.com',
            'subject' => 'Welcome to SEOLinkBuildings',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue'],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode([
                'displayName' => WelcomeEmail::class,
                'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
                'to' => 'other@example.com',
            ]),
            'exception' => 'SMTP failed',
            'failed_at' => now(),
        ]);

        Artisan::shouldReceive('call')->never();

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('error');

        $this->assertSame(EmailLog::STATUS_FAILED, $log->fresh()->status);
    }

    public function test_mail_failure_keeps_failed_job_uuid_on_later_failure(): void
    {
        $user = $this->userWithRole('advertiser');
        $mail = new WelcomeEmail($user);
        $mail->dedupeKey = 'welcome-fail-meta:'.$user->id;
        $mail->failed(new \RuntimeException('first'));

        $log = EmailLog::query()->where('dedupe_key', $mail->dedupeKey)->first();
        $this->assertNotNull($log);

        $kept = (string) Str::uuid();
        $log->update(['meta' => array_merge((array) $log->meta, ['failed_job_uuid' => $kept])]);

        $mail->failed(new \RuntimeException('second'));

        $fresh = $log->fresh();
        $this->assertSame($kept, $fresh->meta['failed_job_uuid'] ?? null);
        $this->assertSame('second', $fresh->error);
        $this->assertSame(EmailLog::STATUS_FAILED, $fresh->status);
    }

    public function test_job_failed_event_stamps_email_log_uuid(): void
    {
        $uuid = (string) Str::uuid();
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'to_email' => 'customer@example.com',
            'subject' => 'Welcome to SEOLinkBuildings',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue'],
        ]);

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('uuid')->andReturn($uuid);
        $job->shouldReceive('getRawBody')->andReturn(json_encode([
            'displayName' => WelcomeEmail::class,
            'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
            'to' => 'customer@example.com',
        ]));

        (new StampEmailLogFailedJobUuid)->handle(
            new JobFailed('database', $job, new \RuntimeException('SMTP'))
        );

        $this->assertSame($uuid, $log->fresh()->meta['failed_job_uuid'] ?? null);
    }

    public function test_retry_ignores_stored_uuid_for_other_recipient(): void
    {
        $admin = $this->userWithRole('admin');
        $storedUuid = (string) Str::uuid();
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'to_email' => 'customer@example.com',
            'subject' => 'Welcome to SEOLinkBuildings',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue', 'failed_job_uuid' => $storedUuid],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => $storedUuid,
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode([
                'displayName' => WelcomeEmail::class,
                'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
                'to' => 'other@example.com',
            ]),
            'exception' => 'SMTP failed',
            'failed_at' => now(),
        ]);

        Artisan::shouldReceive('call')->never();

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('error');

        $this->assertSame(EmailLog::STATUS_FAILED, $log->fresh()->status);
    }

    public function test_retry_does_not_mark_pending_when_queue_retry_misses(): void
    {
        $admin = $this->userWithRole('admin');
        $mailUuid = (string) Str::uuid();
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'to_email' => 'customer@example.com',
            'subject' => 'Welcome to SEOLinkBuildings',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue'],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => $mailUuid,
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode([
                'displayName' => WelcomeEmail::class,
                'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
            ]),
            'exception' => 'SMTP failed',
            'failed_at' => now(),
        ]);

        $this->mockQueueRetry([$mailUuid], "Unable to find failed job with ID [{$mailUuid}].");

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('error');

        $fresh = $log->fresh();
        $this->assertSame(EmailLog::STATUS_FAILED, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
    }

    public function test_retry_refreshes_stale_queued_at_before_requeue(): void
    {
        $admin = $this->userWithRole('admin');
        $mailUuid = (string) Str::uuid();
        $oldQueuedAt = now()->subDays(3)->toIso8601String();
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'to_email' => 'customer@example.com',
            'subject' => 'Welcome to SEOLinkBuildings',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue'],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => $mailUuid,
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode([
                'displayName' => WelcomeEmail::class,
                'data' => [
                    'commandName' => 'Illuminate\\Mail\\SendQueuedMailable',
                    'command' => 'O:36:"Illuminate\\Mail\\SendQueuedMailable":1:{s:8:"queuedAt";s:'.strlen($oldQueuedAt).':"'.$oldQueuedAt.'";}',
                ],
            ]),
            'exception' => 'SMTP failed',
            'failed_at' => now(),
        ]);

        $this->mockQueueRetry([$mailUuid]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $payload = (string) DB::table('failed_jobs')->where('uuid', $mailUuid)->value('payload');
        $this->assertStringNotContainsString($oldQueuedAt, $payload);
        $queuedAt = MailJobPayload::queuedAt($payload);
        $this->assertNotNull($queuedAt);
        $this->assertTrue($queuedAt->greaterThan(now()->subMinute()));
    }

    public function test_retry_requeues_failed_campaign_recipient(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $mailUuid = (string) Str::uuid();
        $campaign = EmailCampaign::create([
            'name' => 'Retry campaign',
            'subject' => 'Retry campaign',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'sent_count' => 0,
            'skipped_count' => 1,
            'status' => EmailCampaign::STATUS_FAILED,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        $row = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_FAILED,
            'skip_reason' => EmailCampaignRecipient::SKIP_ERROR,
        ]);
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => AudienceCampaignMail::class,
            'template_key' => 'audience_campaign',
            'dedupe_key' => 'audience_campaign:'.$campaign->id.':user:'.$advertiser->id,
            'to_email' => $advertiser->email,
            'subject' => 'Retry campaign',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => [
                'source' => 'queue',
                'campaign_id' => $campaign->id,
                'user_id' => $advertiser->id,
            ],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => $mailUuid,
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode([
                'displayName' => AudienceCampaignMail::class,
                'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
                'to' => $advertiser->email,
            ]),
            'exception' => 'SMTP failed',
            'failed_at' => now(),
        ]);

        $this->mockQueueRetry([$mailUuid]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $fresh = $row->fresh();
        $this->assertSame(EmailCampaignRecipient::STATUS_QUEUED, $fresh->status);
        $this->assertNull($fresh->skip_reason);
        $this->assertSame(EmailLog::STATUS_PENDING, $log->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->sent_count);
        $this->assertSame(0, $campaign->fresh()->skipped_count);
        $this->assertSame(EmailCampaign::STATUS_SENT, $campaign->fresh()->status);
    }

    public function test_bulk_retry_marks_linked_campaign_log_pending(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $mailUuid = (string) Str::uuid();
        $campaign = EmailCampaign::create([
            'name' => 'Bulk retry campaign',
            'subject' => 'Bulk retry campaign',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'sent_count' => 0,
            'skipped_count' => 1,
            'status' => EmailCampaign::STATUS_FAILED,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        $row = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_FAILED,
            'skip_reason' => EmailCampaignRecipient::SKIP_ERROR,
        ]);
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => AudienceCampaignMail::class,
            'template_key' => 'audience_campaign',
            'dedupe_key' => 'audience_campaign:'.$campaign->id.':user:'.$advertiser->id,
            'to_email' => $advertiser->email,
            'subject' => 'Bulk retry campaign',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => [
                'source' => 'queue',
                'failed_job_uuid' => $mailUuid,
                'campaign_id' => $campaign->id,
                'user_id' => $advertiser->id,
            ],
        ]);
        $unlinked = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'to_email' => $admin->email,
            'subject' => 'Welcome',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => $mailUuid,
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode([
                'displayName' => AudienceCampaignMail::class,
                'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
                'to' => $advertiser->email,
            ]),
            'exception' => 'SMTP failed',
            'failed_at' => now(),
        ]);

        $this->mockQueueRetry([$mailUuid]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'))
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $this->assertSame(EmailLog::STATUS_PENDING, $log->fresh()->status);
        $this->assertSame(EmailLog::STATUS_FAILED, $unlinked->fresh()->status);
        $this->assertSame(EmailCampaignRecipient::STATUS_QUEUED, $row->fresh()->status);
        $this->assertSame(EmailCampaign::STATUS_SENT, $campaign->fresh()->status);
    }

    public function test_bulk_retry_does_not_mark_logs_when_jobs_remain_failed(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $mailUuid = (string) Str::uuid();
        $campaign = EmailCampaign::create([
            'name' => 'Stale uuid',
            'subject' => 'Stale uuid',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'sent_count' => 0,
            'skipped_count' => 1,
            'status' => EmailCampaign::STATUS_FAILED,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        $row = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_FAILED,
            'skip_reason' => EmailCampaignRecipient::SKIP_ERROR,
        ]);
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => AudienceCampaignMail::class,
            'template_key' => 'audience_campaign',
            'dedupe_key' => 'audience_campaign:'.$campaign->id.':user:'.$advertiser->id,
            'to_email' => $advertiser->email,
            'subject' => 'Stale uuid',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => [
                'source' => 'queue',
                'failed_job_uuid' => $mailUuid,
                'campaign_id' => $campaign->id,
                'user_id' => $advertiser->id,
            ],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => $mailUuid,
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode([
                'displayName' => AudienceCampaignMail::class,
                'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
            ]),
            'exception' => 'SMTP failed',
            'failed_at' => now(),
        ]);

        Artisan::shouldReceive('call')->once()->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('Pushing failed queue jobs back onto the queue.');

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'))
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $this->assertTrue(DB::table('failed_jobs')->where('uuid', $mailUuid)->exists());
        $this->assertSame(EmailLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertSame(EmailCampaignRecipient::STATUS_FAILED, $row->fresh()->status);
        $this->assertSame(EmailCampaign::STATUS_FAILED, $campaign->fresh()->status);
    }

    public function test_mail_failure_does_not_wipe_known_recipient(): void
    {
        $user = $this->userWithRole('advertiser');
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'dedupe_key' => 'welcome-keep-to:'.$user->id,
            'to_email' => $user->email,
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'first',
            'attempts' => 1,
            'meta' => ['source' => 'queue'],
        ]);

        $mail = new WelcomeEmail($user);
        $mail->dedupeKey = 'welcome-keep-to:'.$user->id;
        $mail->recipientUser = null;
        $mail->failed(new \RuntimeException('second'));

        $fresh = $log->fresh();
        $this->assertSame($user->email, $fresh->to_email);
        $this->assertSame('second', $fresh->error);
    }

    public function test_email_center_index_stays_under_query_budget(): void
    {
        $admin = $this->userWithRole('admin');
        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => $admin->email,
            'status' => EmailLog::STATUS_DELIVERED,
            'sent_at' => now(),
        ]);

        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.emails.index'))->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $emailLogQueries = collect($log)->filter(
            fn (array $query) => str_contains(strtolower($query['query']), 'email_logs')
        )->count();

        $this->assertLessThan(8, $emailLogQueries, 'email_logs queried '.$emailLogQueries.' times');
        $this->assertLessThan(80, count($log), 'Email Center index ran '.count($log).' queries');
    }

    public function test_retry_confirm_copy_does_not_claim_to_reset_logs(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->assertSee('Retry failed mail jobs', false)
            ->assertDontSee('reset failed email logs', false);
    }

    public function test_settings_can_enable_all_editable_types(): void
    {
        $admin = $this->userWithRole('admin');
        $enabled = [];
        foreach (config('email_notifications.types') as $type => $meta) {
            if (! empty($meta['framework'])) {
                continue;
            }
            $enabled[$type] = '1';
        }

        $this->actingAs($admin)
            ->post(route('admin.emails.settings'), ['enabled' => $enabled])
            ->assertSessionHas('success');

        EmailNotificationSetting::flushCache();
        $this->assertTrue(EmailNotificationSetting::isEnabled('welcome'));
        $this->assertTrue(EmailNotificationSetting::isEnabled('order_status_changed'));
    }

    public function test_email_center_ui_exposes_ops_links_queue_health_and_failed_empty_state(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->assertSee(route('admin.campaigns.index'), false)
            ->assertSee(route('admin.audiences.index'), false)
            ->assertSee('Campaigns', false)
            ->assertSee('Audiences', false)
            ->assertSee('Queue health', false)
            ->assertSee('Mail queue:', false)
            ->assertSee('Auto-drain:', false)
            ->assertSee('queue:work --queue=default,emails', false)
            ->assertSee('Encryption', false)
            ->assertSee('SMTP delivered ≠ inbox', false)
            ->assertSee('No failed sends in the log', false)
            ->assertSee('Send test to me', false)
            ->assertSee('data-ec-critical="welcome,order_status_changed,publisher_new_order,deposit_approved,admin_stalled_order"', false)
            ->assertSee('data-ec-was-enabled', false)
            ->assertSee('audience=publisher', false)
            ->assertSee('audience=admin', false)
            ->assertSee('Managed by Laravel auth', false)
            ->assertSee('Open Campaigns', false)
            ->assertSee('Order Emails', false)
            ->assertSee('Payment Emails', false);
    }

    public function test_recent_logs_can_be_filtered_and_opened(): void
    {
        $admin = $this->userWithRole('admin');
        $keep = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => 'keep@example.com',
            'subject' => 'Welcome keep',
            'status' => EmailLog::STATUS_DELIVERED,
            'dedupe_key' => 'welcome-keep',
            'audience' => 'user',
            'meta' => ['source' => 'queue'],
            'sent_at' => now(),
        ]);
        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'order_accepted',
            'to_email' => 'other@example.com',
            'subject' => 'Order accepted',
            'status' => EmailLog::STATUS_PENDING,
            'sent_at' => now(),
        ]);

        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => 'keep_user@example.com',
            'subject' => 'Welcome underscore',
            'status' => EmailLog::STATUS_PENDING,
        ]);
        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => 'keepxuser@example.com',
            'subject' => 'Welcome wildcard',
            'status' => EmailLog::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.emails.index', ['status' => 'delivered', 'template_key' => 'welcome', 'to_email' => 'keep@']))
            ->assertOk()
            ->assertSee('keep@example.com', false)
            ->assertDontSee('other@example.com', false);

        $this->actingAs($admin)
            ->get(route('admin.emails.index', ['to_email' => 'keep_']))
            ->assertOk()
            ->assertSee('keep_user@example.com', false)
            ->assertDontSee('keepxuser@example.com', false);

        $this->actingAs($admin)
            ->get(route('admin.emails.log', $keep))
            ->assertOk()
            ->assertSee('welcome-keep', false)
            ->assertSee('keep@example.com', false)
            ->assertSee('Welcome keep', false);

        $this->actingAs($this->userWithRole('advertiser'))
            ->get(route('admin.emails.log', $keep))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.emails.index', ['status' => 'not-a-status', 'date_from' => 'nope']))
            ->assertOk()
            ->assertSee('keep@example.com', false)
            ->assertSee('other@example.com', false);

        $this->actingAs($admin)
            ->get(route('admin.emails.index', ['status' => 'failed', 'to_email' => 'nobody-matches@example.com']))
            ->assertOk()
            ->assertSee('No emails match these filters', false)
            ->assertDontSee('No emails logged yet', false)
            ->assertSee('Clear', false);

        $failed = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => 'failed-row@example.com',
            'subject' => 'Welcome failed',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'sent_at' => now()->subDay(),
        ]);
        $sentToday = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => 'sent-today@example.com',
            'subject' => 'Welcome sent today',
            'status' => EmailLog::STATUS_DELIVERED,
            'sent_at' => now(),
        ]);
        $sentToday->forceFill(['created_at' => now()->subDay()])->save();

        $this->actingAs($admin)
            ->get(route('admin.emails.index', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('sent-today@example.com', false)
            ->assertSee(route('admin.emails.log', $failed), false);
    }

    public function test_recent_logs_are_paginated(): void
    {
        $admin = $this->userWithRole('admin');
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'template_key' => 'welcome',
                'to_email' => 'page-user-'.$i.'@example.com',
                'subject' => 'Welcome '.$i,
                'status' => EmailLog::STATUS_DELIVERED,
                'sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        EmailLog::query()->insert($rows);

        $this->actingAs($admin)
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->assertSee('page-user-50@example.com', false)
            ->assertDontSee('page-user-0@example.com', false)
            ->assertSee('50 per page', false);

        $this->actingAs($admin)
            ->get(route('admin.emails.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('page-user-0@example.com', false);
    }

    public function test_campaign_snapshot_lists_recent_campaigns(): void
    {
        $admin = $this->userWithRole('admin');
        EmailCampaign::create([
            'name' => 'Spring outreach',
            'subject' => 'Hello advertisers',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'status' => EmailCampaign::STATUS_SENT,
            'sent_count' => 12,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->assertSee('Spring outreach', false)
            ->assertSee('12 sent', false)
            ->assertSee('Advertisers', false);
    }

    public function test_order_status_preview_accepts_audience_query(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', ['key' => 'order_status_changed', 'audience' => 'publisher']))
            ->assertOk()
            ->assertSee('please continue the placement', false);

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', ['key' => 'order_status_changed', 'audience' => 'admin']))
            ->assertOk()
            ->assertSee('admin copy', false);
    }
}

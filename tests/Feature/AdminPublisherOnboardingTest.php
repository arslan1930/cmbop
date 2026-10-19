<?php

namespace Tests\Feature;

use App\Mail\PublisherStaffOnboardingMail;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Services\Staff\PublisherOnboardingService;
use App\Support\EmailCatalog;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AdminPublisherOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);
    }

    public function test_admin_can_open_onboarding_screens(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.publishers.onboard'))
            ->assertOk()
            ->assertSee('Add publisher on their behalf', false)
            ->assertSee('Invite to Accept', false)
            ->getContent();

        $this->assertStringContainsString('jquery-3.6.0.min.js', $html);
        $this->assertStringContainsString('js/multi-select.js', $html);
        $this->assertStringContainsString('initMultiSelect', $html);
        $this->assertStringContainsString('id="categoryOptions"', $html);
        $this->assertStringContainsString('multi-select-option', $html);
        $this->assertStringContainsString('Search languages', $html);
        $this->assertMatchesRegularExpression('/id="language"[^>]*data-admin-select-search="1"/s', $html);
        $this->assertMatchesRegularExpression('/id="publisher_language"[^>]*data-admin-select-search="1"/s', $html);
        $this->assertMatchesRegularExpression('/id="country"[^>]*data-admin-select-search="1"/s', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="name"', $html);

        $niche = (string) (Category::query()->where('name', 'Marketing, PR & Advertising')->value('name')
            ?: Category::query()->orderBy('name')->value('name'));
        $this->assertNotSame('', $niche);
        $this->assertStringContainsString('data-value="'.e($niche).'"', $html);

        $this->actingAs($this->admin)
            ->get(route('admin.publishers.onboard.bulk'))
            ->assertOk()
            ->assertSee('Bulk publisher onboarding', false)
            ->assertSee('Dry-run preview', false);

        $this->actingAs($this->admin)
            ->get(route('admin.publishers.onboardings'))
            ->assertOk()
            ->assertSee('Publisher onboarding', false);
    }

    public function test_marketing_cannot_open_onboarding_screens(): void
    {
        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $marketer->roles()->attach($marketingRole->id);

        $this->actingAs($marketer)
            ->get(route('admin.publishers.onboard'))
            ->assertRedirect(route('marketing.dashboard'));
    }

    public function test_admin_creates_publisher_and_site_and_sends_one_onboarding_email(): void
    {
        Mail::fake();
        [$country, $language, $niche] = $this->market();

        $response = $this->actingAs($this->admin)->post(route('admin.publishers.onboard.store'), [
            'email' => 'new-publisher@example.com',
            'name' => 'Ada Publisher',
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'mode' => 'single',
            'site_name' => 'Onboard News',
            'site_url' => 'https://onboard-news.example',
            'example_url' => 'https://onboard-news.example/sample',
            'price' => 120,
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'site_country' => strtolower($country->code),
            'site_language' => strtolower($language->code),
            'categories' => $niche,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Quality editorial site for guest posts. ', 4),
            'written_request' => 1,
        ]);

        $response->assertRedirect(route('admin.publishers.onboardings'));
        $response->assertSessionHas('success');

        $publisher = User::query()->where('email', 'new-publisher@example.com')->first();
        $this->assertNotNull($publisher);
        $this->assertSame('Ada Publisher', $publisher->name);
        $this->assertTrue($publisher->isStaffCreated());
        $this->assertSame('invited', $publisher->staffOnboardingStatus());
        $this->assertFalse($publisher->hasVerifiedEmail());
        $this->assertTrue($publisher->hasRole('publisher'));
        $this->assertTrue($publisher->needsStaffPasswordSetup());

        $site = Site::where('domain', 'onboard-news.example')->first();
        $this->assertNotNull($site);
        $this->assertTrue($site->isPendingPublisherAcceptance());
        $this->assertSame((int) $publisher->id, (int) $site->publisher_id);
        $this->assertEquals(120.0, (float) $site->price);
        $this->assertStringContainsString($niche, (string) $site->category);

        Mail::assertQueued(PublisherStaffOnboardingMail::class, function (PublisherStaffOnboardingMail $mail) use ($publisher) {
            if (! $mail->hasTo($publisher->email)) {
                return false;
            }
            $mail->build();
            $html = $mail->render();

            return str_contains($html, '/onboard/password/')
                && str_contains($html, 'My Sites')
                && ! str_contains(strtolower($html), 'fee');
        });
        Mail::assertQueuedCount(1);
    }

    public function test_existing_verified_publisher_is_reused(): void
    {
        Mail::fake();
        [$country, $language, $niche] = $this->market();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $existing = User::factory()->create([
            'email' => 'reuse@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $existing->roles()->attach($publisherRole->id);

        $this->actingAs($this->admin)->post(route('admin.publishers.onboard.store'), $this->singlePayload(
            $country,
            $language,
            $niche,
            'reuse@example.com',
            'https://reuse-site.example'
        ))->assertRedirect(route('admin.publishers.onboardings'));

        $this->assertSame(1, User::query()->where('email', 'reuse@example.com')->count());
        $this->assertNotNull(Site::where('domain', 'reuse-site.example')->first());
        $existing->refresh();
        $this->assertTrue($existing->hasVerifiedEmail());
        $this->assertFalse($existing->isStaffCreated());
    }

    public function test_set_password_verifies_email_and_lands_on_invites(): void
    {
        Mail::fake();
        [$country, $language, $niche] = $this->market();
        $this->actingAs($this->admin)->post(
            route('admin.publishers.onboard.store'),
            $this->singlePayload($country, $language, $niche, 'set-pass@example.com', 'https://set-pass.example')
        )->assertRedirect();

        $publisher = User::query()->where('email', 'set-pass@example.com')->firstOrFail();
        $this->assertFalse($publisher->hasVerifiedEmail());

        $url = URL::temporarySignedRoute(
            'publisher.onboard.password',
            now()->addDays(7),
            ['user' => $publisher->id, 'hash' => sha1($publisher->email)]
        );

        $this->get($url)->assertOk()->assertSee('Set your publisher password', false);

        $storeUrl = URL::temporarySignedRoute(
            'publisher.onboard.password.store',
            now()->addDays(7),
            ['user' => $publisher->id, 'hash' => sha1($publisher->email)]
        );

        $this->post($storeUrl, [
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
            'terms' => 1,
        ])->assertRedirect(route('publisher.websites', ['status' => 'invites'], false));

        $publisher->refresh();
        $this->assertTrue($publisher->hasVerifiedEmail());
        $this->assertSame('activated', $publisher->staffOnboardingStatus());
        $this->assertTrue(Hash::check('NewPassw0rd!', $publisher->password));
        $this->assertAuthenticatedAs($publisher);
        $this->assertTrue(session('prompt_payout_setup'));
    }

    public function test_google_sign_in_activates_the_same_staff_account(): void
    {
        Mail::fake();
        [$country, $language, $niche] = $this->market();
        $this->actingAs($this->admin)->post(
            route('admin.publishers.onboard.store'),
            $this->singlePayload($country, $language, $niche, 'google-link@example.com', 'https://google-link.example')
        )->assertRedirect();

        $publisher = User::query()->where('email', 'google-link@example.com')->firstOrFail();
        $this->assertSame('invited', $publisher->staffOnboardingStatus());

        $publisher->google_id = 'google-abc';
        $publisher->save();
        app(PublisherOnboardingService::class)->activate($publisher);

        $publisher->refresh();
        $this->assertSame('activated', $publisher->staffOnboardingStatus());
        $this->assertTrue($publisher->hasVerifiedEmail());
        $this->assertSame('google-abc', $publisher->google_id);
        $this->assertSame(
            $publisher->id,
            User::query()->where('email', 'google-link@example.com')->value('id')
        );
        $this->assertTrue($publisher->hasPendingStaffInvites());
    }

    public function test_onboarding_saves_selected_niches(): void
    {
        Mail::fake();
        [$country, $language, $niche] = $this->market();
        $second = (string) Category::query()->where('name', '!=', $niche)->orderBy('name')->value('name');
        $this->assertNotSame('', $second);

        $payload = $this->singlePayload($country, $language, $niche, 'niches@example.com', 'https://niches-site.example');
        $payload['categories'] = $niche.'|'.$second;

        $this->actingAs($this->admin)
            ->post(route('admin.publishers.onboard.store'), $payload)
            ->assertRedirect(route('admin.publishers.onboardings'));

        $site = Site::where('domain', 'niches-site.example')->first();
        $this->assertNotNull($site);
        $saved = array_values(array_filter(preg_split('/\|/', (string) $site->category) ?: []));
        $this->assertEqualsCanonicalizing([$niche, $second], $saved);
    }

    public function test_site_url_with_stray_characters_is_rejected(): void
    {
        Mail::fake();
        [$country, $language, $niche] = $this->market();

        $invalidUrls = [
            'https://test.com`',
            'https://test.com"',
            "https://test.com'",
            'https://test.com/foo bar',
        ];

        foreach ($invalidUrls as $index => $url) {
            $email = 'bad-url-'.$index.'@example.com';
            $this->actingAs($this->admin)
                ->from(route('admin.publishers.onboard'))
                ->post(route('admin.publishers.onboard.store'), $this->singlePayload(
                    $country,
                    $language,
                    $niche,
                    $email,
                    $url
                ))
                ->assertRedirect();

            $this->assertNull(User::query()->where('email', $email)->first());
            $this->assertNull(Site::query()->where('site_url', $url)->first());
            $failures = session('bulk_row_errors');
            $this->assertIsArray($failures);
            $this->assertNotEmpty($failures);
            $messages = implode(' ', array_merge(...array_map(
                static fn ($failure) => $failure['errors'] ?? [],
                $failures
            )));
            $this->assertStringContainsString('Invalid URL', $messages);
        }

        Mail::assertNothingQueued();
    }

    public function test_site_url_whitespace_is_trimmed_and_accepted(): void
    {
        Mail::fake();
        [$country, $language, $niche] = $this->market();
        $payload = $this->singlePayload($country, $language, $niche, 'trim-url@example.com', 'https://trim-url.example');
        $payload['site_url'] = '  https://trim-url.example  ';
        $payload['example_url'] = '  https://trim-url.example/sample  ';

        $this->actingAs($this->admin)
            ->post(route('admin.publishers.onboard.store'), $payload)
            ->assertRedirect(route('admin.publishers.onboardings'));

        $site = Site::where('domain', 'trim-url.example')->first();
        $this->assertNotNull($site);
        $this->assertSame('https://trim-url.example', $site->site_url);
        $this->assertSame('https://trim-url.example/sample', $site->example_url);
        $this->assertSame('Onboard Pub', User::query()->where('email', 'trim-url@example.com')->value('name'));
    }

    public function test_bulk_dry_run_flags_invalid_site_url_characters(): void
    {
        Mail::fake();
        [$country, $language, $niche] = $this->market();
        $code = strtolower((string) $country->code);
        $lang = strtolower((string) $language->code);
        $description = str_repeat('Quality editorial site for guest posts. ', 4);
        $rows = "bad-tick@example.com,Name,https://test.com`,80,{$code},{$lang},40,35,12000,Tick Site,https://test.com/sample,3days,permanent,dofollow,,{$niche},{$description}";

        $this->actingAs($this->admin)
            ->from(route('admin.publishers.onboard.bulk'))
            ->post(route('admin.publishers.onboard.bulk.preview'), ['rows' => $rows])
            ->assertRedirect();

        $preview = session('onboard_preview');
        $this->assertIsArray($preview);
        $this->assertNotEmpty($preview['failures']);
        $this->assertFalse($preview['can_commit'] ?? true);
        $messages = implode(' ', array_merge(...array_map(
            static fn ($failure) => $failure['errors'] ?? [],
            $preview['failures']
        )));
        $this->assertStringContainsString('Invalid URL', $messages);
        $this->assertNull(User::query()->where('email', 'bad-tick@example.com')->first());
        Mail::assertNothingQueued();
    }

    public function test_bulk_dry_run_shows_row_errors_and_saves_nothing(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)->post(route('admin.publishers.onboard.bulk.preview'), [
            'rows' => 'not-an-email,Name,https://bad.example,10,de,de,40,40,1000,Site,https://bad.example/p,3days,permanent,dofollow,,News,short',
        ])->assertRedirect();

        $preview = session('onboard_preview');
        $this->assertIsArray($preview);
        $this->assertNotEmpty($preview['failures']);
        $this->assertFalse($preview['can_commit']);
        $this->assertNull(User::query()->where('email', 'not-an-email')->first());
        Mail::assertNothingQueued();
    }

    public function test_bulk_commit_groups_by_email_and_sends_one_mail_each(): void
    {
        Mail::fake();
        [$country, $language, $niche] = $this->market();
        $code = strtolower($country->code);
        $lang = strtolower($language->code);
        $description = str_repeat('Quality editorial site for guest posts. ', 4);
        $rows = implode("\n", [
            "one@example.com,One Pub,https://bulk-a.example,80,{$code},{$lang},40,35,12000,Bulk A,https://bulk-a.example/post,3days,permanent,dofollow,,{$niche},{$description}",
            "one@example.com,One Pub,https://bulk-b.example,90,{$code},{$lang},20,20,1000,Bulk B,https://bulk-b.example/post,3days,permanent,dofollow,,{$niche},{$description}",
            "two@example.com,Two Pub,https://bulk-c.example,70,{$code},{$lang},30,30,8000,Bulk C,https://bulk-c.example/post,3days,permanent,dofollow,,{$niche},{$description}",
        ]);

        $this->actingAs($this->admin)->from(route('admin.publishers.onboard.bulk'))
            ->post(route('admin.publishers.onboard.bulk.preview'), ['rows' => $rows])
            ->assertRedirect();
        $preview = session('onboard_preview');
        $this->assertTrue($preview['can_commit'] ?? false);
        $this->assertSame(2, (int) ($preview['publisher_count'] ?? 0));

        $this->actingAs($this->admin)->post(route('admin.publishers.onboard.bulk.store'), [
            'rows' => $rows,
            'confirm' => 1,
        ])->assertRedirect(route('admin.publishers.onboardings'));

        $this->assertNotNull(User::query()->where('email', 'one@example.com')->first());
        $this->assertNotNull(User::query()->where('email', 'two@example.com')->first());
        $this->assertNotNull(Site::where('domain', 'bulk-a.example')->first());
        $this->assertNotNull(Site::where('domain', 'bulk-b.example')->first());
        $this->assertNotNull(Site::where('domain', 'bulk-c.example')->first());

        Mail::assertQueued(PublisherStaffOnboardingMail::class, 2);
        Mail::assertQueued(PublisherStaffOnboardingMail::class, fn ($mail) => $mail->hasTo('one@example.com') && $mail->sites->count() === 2);
        Mail::assertQueued(PublisherStaffOnboardingMail::class, fn ($mail) => $mail->hasTo('two@example.com') && $mail->sites->count() === 1);
    }

    public function test_admin_can_resend_invite_and_see_status(): void
    {
        Mail::fake();
        [$country, $language, $niche] = $this->market();
        $this->actingAs($this->admin)->post(
            route('admin.publishers.onboard.store'),
            $this->singlePayload($country, $language, $niche, 'status@example.com', 'https://status-site.example')
        )->assertRedirect();

        $publisher = User::query()->where('email', 'status@example.com')->firstOrFail();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.publishers.onboardings'))
            ->assertOk()
            ->assertSee('Invited', false)
            ->assertSee('status@example.com', false)
            ->getContent();
        $this->assertStringContainsString('Resend invite', $html);

        Mail::fake();
        $this->actingAs($this->admin)
            ->post(route('admin.publishers.resend', $publisher))
            ->assertRedirect();
        Mail::assertQueued(PublisherStaffOnboardingMail::class, fn ($mail) => $mail->hasTo('status@example.com'));

        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $publisher))
            ->assertOk()
            ->assertSee('Staff onboarded · Invited', false)
            ->assertSee('Resend onboarding invite', false);
    }

    public function test_email_catalog_includes_onboarding_template_without_fee_copy(): void
    {
        $this->assertArrayHasKey('publisher_staff_onboarding', EmailCatalog::all());
        $this->assertArrayHasKey('publisher_staff_onboarding', EmailCatalog::templates());
        $mail = EmailCatalog::makeMailable('publisher_staff_onboarding');
        $this->assertInstanceOf(PublisherStaffOnboardingMail::class, $mail);
        $html = $mail->render();
        $this->assertStringNotContainsString('fee', strtolower($html));
        $this->assertStringContainsString('Teqno Ltd', $html);
    }

    /**
     * @return array{0: Country, 1: Language, 2: string}
     */
    private function market(): array
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $allowed = app(CountryLanguagePairs::class)->languageCodesForCountry(strtolower((string) $country->code));
        $language = $allowed !== []
            ? (Language::marketplace()->whereIn('code', $allowed)->first() ?? Language::marketplace()->firstOrFail())
            : (Language::marketplace()->where('code', 'de')->first() ?? Language::marketplace()->firstOrFail());
        $niche = (string) (Category::query()->where('name', 'Marketing, PR & Advertising')->value('name')
            ?: Category::query()->orderBy('name')->value('name'));

        return [$country, $language, $niche];
    }

    /**
     * @return array<string, mixed>
     */
    private function singlePayload(Country $country, Language $language, string $niche, string $email, string $url): array
    {
        return [
            'email' => $email,
            'name' => 'Onboard Pub',
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'mode' => 'single',
            'site_name' => 'Onboard News',
            'site_url' => $url,
            'example_url' => rtrim($url, '/').'/sample',
            'price' => 99,
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'site_country' => strtolower($country->code),
            'site_language' => strtolower($language->code),
            'categories' => $niche,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Quality editorial site for guest posts. ', 4),
            'written_request' => 1,
        ];
    }
}

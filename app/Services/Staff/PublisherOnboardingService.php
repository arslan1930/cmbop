<?php

namespace App\Services\Staff;

use App\Jobs\CaptureSiteScreenshotJob;
use App\Mail\PublisherStaffOnboardingMail;
use App\Models\BulkSiteRequest;
use App\Models\Country;
use App\Models\InAppNotification;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use App\Support\SignupCountry;
use App\Support\StaffListingPublishMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublisherOnboardingService
{
    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVATED = 'activated';

    public function __construct(
        private StaffAssignedListingService $listings,
        private InAppNotificationService $notifier,
    ) {}

    /**
     * @return array{user: User, created: bool, reused: bool}
     */
    public function findOrCreatePublisher(
        string $email,
        ?string $name,
        ?string $country,
        ?string $language,
        User $actor
    ): array {
        $email = strtolower(trim($email));
        $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing) {
            $this->assertReusablePublisher($existing);
            $this->ensurePublisherWorkspace($existing, $country);
            if ($name && trim((string) $existing->name) === '') {
                $existing->name = trim($name);
                $existing->save();
            }

            return ['user' => $existing->fresh() ?? $existing, 'created' => false, 'reused' => true];
        }

        return [
            'user' => $this->createStaffPublisher($email, $name, $country, $language, $actor),
            'created' => true,
            'reused' => false,
        ];
    }

    /**
     * @param  list<array<string, string>>  $rawRows
     * @return array{ready: list<array<string, mixed>>, failures: list<array{line: int, errors: list<string>, row: array<string, mixed>}>, groups: array<string, array<string, mixed>>}
     */
    public function previewRows(array $rawRows, ?string $fallbackCountry = null, ?string $fallbackLanguage = null): array
    {
        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $seen = [];
        $ready = [];
        $failures = [];
        foreach ($rawRows as $index => $fields) {
            $line = (int) ($fields['_line'] ?? ($index + 1));
            if ($fallbackCountry && trim((string) ($fields['country'] ?? '')) === '') {
                $fields['country'] = $fallbackCountry;
            }
            if ($fallbackLanguage && trim((string) ($fields['language'] ?? '')) === '') {
                $fields['language'] = $fallbackLanguage;
            }
            $emailErrors = $this->emailErrors((string) ($fields['email'] ?? ''));
            $checked = $this->listings->validateRow($fields, $line, $allowedCountries, $allowedLanguages, $seen);
            $checked['errors'] = array_values(array_unique(array_merge($emailErrors, $checked['errors'])));
            $checked['row']['email'] = strtolower(trim((string) ($fields['email'] ?? '')));
            $checked['row']['name'] = trim((string) ($fields['name'] ?? ''));
            if ($checked['errors'] !== []) {
                $failures[] = $checked;
            } else {
                $ready[] = $checked['row'];
            }
        }

        return [
            'ready' => $ready,
            'failures' => $failures,
            'groups' => $this->groupReadyRows($ready, $failures),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $readyRows
     * @return array{publishers: list<User>, sites: list<Site>, created_user_ids: list<int>, reused_user_ids: list<int>}
     */
    public function commitReadyRows(array $readyRows, User $actor, ?string $fallbackCountry = null, ?string $fallbackName = null): array
    {
        if ($readyRows === []) {
            throw ValidationException::withMessages([
                'rows' => ['No valid site rows to save.'],
            ]);
        }

        $grouped = collect($readyRows)->groupBy(fn (array $row) => strtolower((string) ($row['email'] ?? '')));
        $publishers = [];
        $sites = [];
        $createdIds = [];
        $reusedIds = [];

        DB::transaction(function () use ($grouped, $actor, $fallbackCountry, $fallbackName, &$publishers, &$sites, &$createdIds, &$reusedIds) {
            foreach ($grouped as $email => $rows) {
                $first = $rows->first();
                $resolved = $this->findOrCreatePublisher(
                    (string) $email,
                    trim((string) ($first['name'] ?? '')) ?: $fallbackName,
                    trim((string) ($first['country'] ?? '')) ?: $fallbackCountry,
                    trim((string) ($first['language'] ?? '')) ?: null,
                    $actor
                );
                $publisher = $resolved['user'];
                $batch = [];
                foreach ($rows as $row) {
                    $batch[] = $this->listings->persistInvite(
                        $row,
                        (int) $publisher->id,
                        StaffListingPublishMode::INVITE,
                        (int) $actor->id
                    );
                }
                BulkSiteRequest::openForStaffInvites((int) $publisher->id, (int) $actor->id, $batch);
                $publishers[] = $publisher;
                foreach ($batch as $site) {
                    $sites[] = $site;
                }
                if ($resolved['created']) {
                    $createdIds[] = (int) $publisher->id;
                } else {
                    $reusedIds[] = (int) $publisher->id;
                }
            }
        });

        $sitesByPublisher = collect($sites)->groupBy(fn (Site $site) => (int) $site->publisher_id);
        foreach ($publishers as $publisher) {
            $publisherSites = $sitesByPublisher->get((int) $publisher->id, collect())->all();
            $this->afterCommitPublisher($publisher, $publisherSites, $actor);
        }

        return [
            'publishers' => $publishers,
            'sites' => $sites,
            'created_user_ids' => $createdIds,
            'reused_user_ids' => $reusedIds,
        ];
    }

    /**
     * @param  list<Site>  $sites
     */
    public function sendOnboardingMail(User $publisher, array $sites, bool $resend = false): bool
    {
        if (! $publisher->email) {
            return false;
        }

        try {
            $mail = new PublisherStaffOnboardingMail($publisher, $sites);
            if ($resend) {
                $mail->dedupeKey = 'publisher-staff-onboarding-'.$publisher->id.'-resend-'.Str::uuid();
            }
            Mail::to($publisher->email)->send($mail);
            $publisher->markStaffInviteSent();

            return true;
        } catch (\Throwable $e) {
            Log::warning('Failed to send publisher staff onboarding email', [
                'user_id' => $publisher->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  list<Site>  $sites
     */
    public function notifyPublisher(User $publisher, array $sites): bool
    {
        $count = count($sites);
        $names = collect($sites)->pluck('domain')->filter()->take(12)->implode(', ');
        $extra = $count > 12 ? ' and '.($count - 12).' more' : '';
        $needsPassword = $publisher->needsStaffPasswordSetup();

        try {
            return $this->notifier->notify(
                $publisher->id,
                InAppNotificationService::TYPE_SITE_STATUS,
                $needsPassword
                    ? ($count === 1
                        ? 'Set your password and accept a website we added'
                        : 'Set your password and accept websites we added')
                    : ($count === 1
                        ? 'Please accept a website we added for you'
                        : 'Please accept websites we added for you'),
                $needsPassword
                    ? 'Our team created your publisher account and added '
                        .($count === 1 ? '1 website' : $count.' websites')
                        .'. Set a password (or sign in with Google), then accept the listing in My Sites → Invites. '
                        .$names.$extra
                    : ($count === 1
                        ? 'Our team added 1 website. Accept it in My Sites → Invites. '.$names
                        : 'Our team added '.$count.' websites. Accept them in My Sites → Invites. '.$names.$extra),
                [
                    'category' => InAppNotificationService::CATEGORY_ACCOUNT,
                    'icon' => 'check-circle',
                    'priority' => InAppNotification::PRIORITY_HIGH,
                    'related' => $sites[0] ?? null,
                    'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                    'action_label' => $needsPassword ? 'Set password' : 'Review & accept',
                    'action_url' => route('publisher.websites', ['status' => 'invites'], false),
                ]
            ) !== null;
        } catch (\Throwable $e) {
            Log::warning('Failed to bell-notify staff-onboarded publisher', [
                'user_id' => $publisher->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function activate(User $user): void
    {
        $user->activateStaffOnboarding();
        if (! $user->hasVerifiedEmail()) {
            $user->email_verified_at = now();
            $user->save();
        }
        $this->switchToPublisherRole($user);
    }

    /**
     * @return Builder<User>
     */
    public function onboardedPublishers(): Builder
    {
        return User::query()
            ->staffCreated()
            ->with(['roles'])
            ->withCount([
                'notArchivedSites as sites_count',
                'notArchivedSites as pending_invites_count' => fn ($q) => $q->whereNull('publisher_accepted_at'),
            ])
            ->orderByDesc('id');
    }

    private function createStaffPublisher(
        string $email,
        ?string $name,
        ?string $country,
        ?string $language,
        User $actor
    ): User {
        $advertiserRole = Role::where('name', 'advertiser')->first();
        $publisherRole = Role::where('name', 'publisher')->first();
        if (! $advertiserRole || ! $publisherRole) {
            throw new \RuntimeException('Roles not found. Please run database seeders.');
        }

        $user = new User;
        $user->name = trim((string) $name) !== '' ? trim((string) $name) : Str::before($email, '@');
        $user->email = $email;
        $user->password = Str::password(64, letters: true, numbers: true, symbols: false);
        $user->email_verified_at = null;
        $user->active_role_id = $publisherRole->id;
        $user->save();

        $attrs = User::existingAttributes([
            'staff_created' => true,
            'staff_created_by' => $actor->id,
            'staff_invite_sent_at' => null,
            'staff_onboarded_at' => null,
        ]);
        if ($attrs !== []) {
            $user->forceFill($attrs)->save();
        }

        $user->roles()->sync([$advertiserRole->id, $publisherRole->id]);
        SignupCountry::applyToUser($user, $country);
        Wallet::insertRegistrationPair($user->id, $advertiserRole->id, $publisherRole->id, 0.0);

        ActivityLogger::tryLog(
            'user.staff_created_publisher',
            ($actor->name ?? 'Staff').' created publisher '.$user->email.' on their behalf',
            $user,
            [
                'email' => $user->email,
                'country' => $country,
                'language' => $language,
            ]
        );

        return $user->fresh() ?? $user;
    }

    private function ensurePublisherWorkspace(User $user, ?string $country): void
    {
        $publisherRole = Role::where('name', 'publisher')->first();
        $advertiserRole = Role::where('name', 'advertiser')->first();
        if (! $publisherRole) {
            return;
        }

        $roleIds = $user->roles()->pluck('roles.id')->all();
        $attach = [];
        if (! in_array($publisherRole->id, $roleIds, true)) {
            $attach[] = $publisherRole->id;
        }
        if ($advertiserRole && ! in_array($advertiserRole->id, $roleIds, true)) {
            $attach[] = $advertiserRole->id;
        }
        if ($attach !== []) {
            $user->roles()->syncWithoutDetaching($attach);
        }

        if (! $user->active_role_id) {
            $user->active_role_id = $publisherRole->id;
            $user->save();
        }

        if ($country && blank($user->country)) {
            SignupCountry::applyToUser($user, $country);
        }

        $missingAdvertiser = $advertiserRole && $user->wallets()->where('role_id', $advertiserRole->id)->doesntExist();
        $missingPublisher = $user->wallets()->where('role_id', $publisherRole->id)->doesntExist();
        if ($missingAdvertiser && $missingPublisher && $advertiserRole) {
            Wallet::insertRegistrationPair($user->id, $advertiserRole->id, $publisherRole->id, 0.0);

            return;
        }

        foreach (array_filter([
            $missingAdvertiser ? $advertiserRole?->id : null,
            $missingPublisher ? $publisherRole->id : null,
        ]) as $roleId) {
            $now = now();
            $payload = [
                'user_id' => $user->id,
                'role_id' => $roleId,
                'balance' => 0,
                'reserved_balance' => 0,
                'currency' => 'EUR',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('wallets', 'bonus_balance')) {
                $payload['bonus_balance'] = 0;
                $payload['bonus_reserved'] = 0;
            }
            DB::table('wallets')->insert($payload);
        }
    }

    private function assertReusablePublisher(User $existing): void
    {
        if (method_exists($existing, 'isSuspended') && $existing->isSuspended()) {
            throw ValidationException::withMessages([
                'email' => ['That account is suspended. Unsuspend it before adding listings.'],
            ]);
        }

        $names = $existing->roles()->pluck('name')->map(fn ($n) => strtolower((string) $n));
        if ($names->contains('admin') || $names->contains('marketing')) {
            throw ValidationException::withMessages([
                'email' => ['That email belongs to a staff account. Choose a publisher or unused email.'],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $ready
     * @param  list<array{line: int, errors: list<string>, row: array<string, mixed>}>  $failures
     * @return array<string, array<string, mixed>>
     */
    private function groupReadyRows(array $ready, array $failures): array
    {
        $groups = [];
        foreach ($ready as $row) {
            $email = strtolower((string) ($row['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            if (! isset($groups[$email])) {
                $groups[$email] = [
                    'email' => $email,
                    'name' => (string) ($row['name'] ?? ''),
                    'sites' => [],
                    'errors' => [],
                    'exists' => User::query()->whereRaw('LOWER(email) = ?', [$email])->exists(),
                ];
            }
            if ($groups[$email]['name'] === '' && ($row['name'] ?? '') !== '') {
                $groups[$email]['name'] = (string) $row['name'];
            }
            $groups[$email]['sites'][] = $row['domain'] ?? $row['site_url'] ?? '';
        }
        foreach ($failures as $failure) {
            $email = strtolower((string) ($failure['row']['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            if (! isset($groups[$email])) {
                $groups[$email] = [
                    'email' => $email,
                    'name' => (string) ($failure['row']['name'] ?? ''),
                    'sites' => [],
                    'errors' => [],
                    'exists' => User::query()->whereRaw('LOWER(email) = ?', [$email])->exists(),
                ];
            }
            $groups[$email]['errors'] = array_values(array_unique(array_merge(
                $groups[$email]['errors'],
                $failure['errors']
            )));
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    private function emailErrors(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['A valid publisher email is required.'];
        }

        $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $existing) {
            return [];
        }

        try {
            $this->assertReusablePublisher($existing);
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->all();
        }

        return [];
    }

    /**
     * @param  list<Site>  $sites
     */
    private function afterCommitPublisher(User $publisher, array $sites, User $actor): void
    {
        foreach ($sites as $site) {
            if (config('site_enrichment.enabled', true)) {
                try {
                    CaptureSiteScreenshotJob::dispatch($site->id, 'staff_assign');
                } catch (\Throwable $e) {
                    Log::warning('Failed to queue screenshot for onboarded site', [
                        'site_id' => $site->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            try {
                ActivityLogger::log(
                    'site.assigned_for_acceptance',
                    ($actor->name ?? 'Staff').' added site "'.$site->site_name.'" during publisher onboarding',
                    $site,
                    [
                        'publisher_id' => $publisher->id,
                        'assigned_by_user_id' => $actor->id,
                        'domain' => $site->domain,
                        'staff_onboarding' => true,
                        'publish_mode' => StaffListingPublishMode::INVITE,
                    ],
                    $site->site_name
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to log onboarded site: '.$e->getMessage());
            }
        }

        $this->sendOnboardingMail($publisher, $sites);
        $this->notifyPublisher($publisher, $sites);
    }

    private function switchToPublisherRole(User $user): void
    {
        $publisherRole = Role::where('name', 'publisher')->first();
        if (! $publisherRole) {
            return;
        }
        if (! $user->roles()->where('roles.id', $publisherRole->id)->exists()) {
            $user->roles()->syncWithoutDetaching([$publisherRole->id]);
        }
        $user->active_role_id = $publisherRole->id;
        $user->save();
    }
}

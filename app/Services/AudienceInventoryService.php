<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudienceInventoryService
{
    public const AUDIENCE_ADVERTISERS = 'advertisers';

    public const AUDIENCE_PUBLISHERS = 'publishers';

    public const AUDIENCE_BOTH = 'both';

    public const AUDIENCE_SELECTED = 'selected';

    public const AUDIENCE_ADVERTISERS_NO_ORDERS = 'advertisers_no_orders';

    public const AUDIENCE_ADVERTISERS_NEVER_CHECKED_OUT = 'advertisers_never_checked_out';

    public const AUDIENCE_ADVERTISERS_NO_PAID_ORDERS = 'advertisers_no_paid_orders';

    public const AUDIENCE_ADVERTISERS_PAID_ORDERS = 'advertisers_paid_orders';

    public const AUDIENCE_PUBLISHERS_NO_SITES = 'publishers_no_sites';

    public const AUDIENCE_PUBLISHERS_NO_ACTIVE_SITES = 'publishers_no_active_sites';

    public const AUDIENCE_ADVERTISERS_NEVER_DEPOSITED = 'advertisers_never_deposited';

    public const AUDIENCE_ADVERTISERS_DEPOSITED_NO_ORDERS = 'advertisers_deposited_no_orders';

    public const PICKER_LIMIT = 200;

    public const EXPORT_LIMIT = 10000;

    /**
     * @return list<string>
     */
    public static function customerPaymentStatuses(): array
    {
        // `completed` is a legacy/alias paid flag (see AdvertiserOrderStatus).
        return ['paid', 'completed', 'refunded'];
    }

    /**
     * @return list<string>
     */
    public static function creditedDepositStatuses(): array
    {
        return ['approved', 'completed'];
    }

    /**
     * @return list<string>
     */
    public static function audienceKeys(): array
    {
        return [
            self::AUDIENCE_ADVERTISERS,
            self::AUDIENCE_PUBLISHERS,
            self::AUDIENCE_BOTH,
            self::AUDIENCE_SELECTED,
            self::AUDIENCE_ADVERTISERS_NO_ORDERS,
            self::AUDIENCE_ADVERTISERS_NEVER_CHECKED_OUT,
            self::AUDIENCE_ADVERTISERS_NO_PAID_ORDERS,
            self::AUDIENCE_ADVERTISERS_PAID_ORDERS,
            self::AUDIENCE_PUBLISHERS_NO_SITES,
            self::AUDIENCE_PUBLISHERS_NO_ACTIVE_SITES,
            self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED,
            self::AUDIENCE_ADVERTISERS_DEPOSITED_NO_ORDERS,
        ];
    }

    /**
     * Inventory tab slug => canonical audience key (aliases included).
     *
     * @return array<string, string>
     */
    public static function inventoryTabs(): array
    {
        return [
            'advertisers' => self::AUDIENCE_ADVERTISERS,
            'publishers' => self::AUDIENCE_PUBLISHERS,
            'both' => self::AUDIENCE_BOTH,
            'no_orders' => self::AUDIENCE_ADVERTISERS_NO_ORDERS,
            'never_checked_out' => self::AUDIENCE_ADVERTISERS_NO_ORDERS,
            'no_paid_orders' => self::AUDIENCE_ADVERTISERS_NO_PAID_ORDERS,
            'paid_orders' => self::AUDIENCE_ADVERTISERS_PAID_ORDERS,
            'no_sites' => self::AUDIENCE_PUBLISHERS_NO_SITES,
            'no_active_sites' => self::AUDIENCE_PUBLISHERS_NO_ACTIVE_SITES,
            'never_deposited' => self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED,
            'deposited_no_orders' => self::AUDIENCE_ADVERTISERS_DEPOSITED_NO_ORDERS,
        ];
    }

    /**
     * Map a tab slug, legacy alias, or already-canonical key to a campaign key.
     * Unknown values become advertisers (same as the old controller default).
     */
    public static function normalizeAudienceKey(string $raw): string
    {
        return self::canonicalAudienceKey($raw) ?? self::AUDIENCE_ADVERTISERS;
    }

    /**
     * @return string|null Canonical key, or null when $raw is not a known segment
     */
    public static function canonicalAudienceKey(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if ($raw === 'advertiser') {
            return self::AUDIENCE_ADVERTISERS;
        }
        if ($raw === 'publisher') {
            return self::AUDIENCE_PUBLISHERS;
        }

        $tabs = self::inventoryTabs();
        if (isset($tabs[$raw])) {
            return $tabs[$raw];
        }

        if ($raw === self::AUDIENCE_ADVERTISERS_NEVER_CHECKED_OUT) {
            return self::AUDIENCE_ADVERTISERS_NO_ORDERS;
        }

        if (in_array($raw, self::audienceKeys(), true)) {
            return $raw;
        }

        return null;
    }

    public static function isListableKey(string $key): bool
    {
        $canonical = self::canonicalAudienceKey($key);

        return $canonical !== null && $canonical !== self::AUDIENCE_SELECTED;
    }

    /**
     * Canonical inventory tab for a key (never_checked_out collapses to no_orders).
     */
    public static function tabForAudienceKey(string $key): string
    {
        $canonical = self::normalizeAudienceKey($key);

        foreach (self::inventoryTabs() as $tab => $audienceKey) {
            if ($audienceKey === $canonical && $tab !== 'never_checked_out') {
                return $tab;
            }
        }

        return 'advertisers';
    }

    public static function label(?string $audience): string
    {
        if ($audience === null || $audience === '') {
            return '';
        }

        $key = self::canonicalAudienceKey($audience) ?? $audience;

        return match ($key) {
            self::AUDIENCE_ADVERTISERS => 'Advertisers',
            self::AUDIENCE_PUBLISHERS => 'Publishers',
            self::AUDIENCE_BOTH => 'Advertisers + Publishers',
            self::AUDIENCE_ADVERTISERS_NO_ORDERS, self::AUDIENCE_ADVERTISERS_NEVER_CHECKED_OUT => 'Advertisers (never checked out)',
            self::AUDIENCE_ADVERTISERS_NO_PAID_ORDERS => 'Advertisers (no paid orders)',
            self::AUDIENCE_ADVERTISERS_PAID_ORDERS => 'Advertisers (paid orders)',
            self::AUDIENCE_PUBLISHERS_NO_SITES => 'Publishers (no sites)',
            self::AUDIENCE_PUBLISHERS_NO_ACTIVE_SITES => 'Publishers (no active sites)',
            self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED => 'Advertisers (never deposited)',
            self::AUDIENCE_ADVERTISERS_DEPOSITED_NO_ORDERS => 'Advertisers (deposited, no paid orders)',
            self::AUDIENCE_SELECTED => 'Selected users',
            default => ucfirst($audience),
        };
    }

    public static function statKeyForTab(string $tab): string
    {
        return match ($tab) {
            'publishers' => 'publishers',
            'both' => 'both_unique',
            'no_orders', 'never_checked_out' => 'advertisers_never_checked_out',
            'no_paid_orders' => 'advertisers_no_paid_orders',
            'paid_orders' => 'advertisers_paid_orders',
            'no_sites' => 'publishers_no_sites',
            'no_active_sites' => 'publishers_no_active_sites',
            'never_deposited' => 'advertisers_never_deposited',
            'deposited_no_orders' => 'advertisers_deposited_no_orders',
            default => 'advertisers',
        };
    }

    public static function exportLabel(string $tabOrKey): string
    {
        $key = self::canonicalAudienceKey($tabOrKey) ?? $tabOrKey;

        return match ($key) {
            self::AUDIENCE_PUBLISHERS => 'Publishers',
            self::AUDIENCE_BOTH => 'Advertisers + Publishers',
            self::AUDIENCE_ADVERTISERS_NO_ORDERS, self::AUDIENCE_ADVERTISERS_NEVER_CHECKED_OUT => 'Never checked out',
            self::AUDIENCE_ADVERTISERS_NO_PAID_ORDERS => 'No paid orders',
            self::AUDIENCE_ADVERTISERS_PAID_ORDERS => 'Paid customers',
            self::AUDIENCE_PUBLISHERS_NO_SITES => 'No sites',
            self::AUDIENCE_PUBLISHERS_NO_ACTIVE_SITES => 'No active sites',
            self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED => 'Never deposited',
            self::AUDIENCE_ADVERTISERS_DEPOSITED_NO_ORDERS => 'Deposited, no paid orders',
            default => 'Advertisers',
        };
    }

    public function advertiserCount(bool $includeUnverified = true): int
    {
        return $this->count(self::AUDIENCE_ADVERTISERS, null, $includeUnverified);
    }

    public function publisherCount(bool $includeUnverified = true): int
    {
        return $this->count(self::AUDIENCE_PUBLISHERS, null, $includeUnverified);
    }

    public function advertisersNoOrdersCount(bool $includeUnverified = true): int
    {
        return $this->count(self::AUDIENCE_ADVERTISERS_NO_ORDERS, null, $includeUnverified);
    }

    public function advertisersNoPaidOrdersCount(bool $includeUnverified = true): int
    {
        return $this->count(self::AUDIENCE_ADVERTISERS_NO_PAID_ORDERS, null, $includeUnverified);
    }

    public function publishersNoSitesCount(bool $includeUnverified = true): int
    {
        return $this->count(self::AUDIENCE_PUBLISHERS_NO_SITES, null, $includeUnverified);
    }

    public function advertisersNeverDepositedCount(bool $includeUnverified = true): int
    {
        return $this->count(self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED, null, $includeUnverified);
    }

    public function queryForRole(string $roleName): Builder
    {
        $role = Role::query()->where('name', $roleName)->first();

        $query = $this->baseUserQuery();

        if (! $role) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('roles', fn (Builder $q) => $q->where('roles.id', $role->id));
    }

    /**
     * Advertisers who have never placed an order row (including abandoned checkout).
     */
    public function queryAdvertisersNoOrders(): Builder
    {
        return $this->queryForRole('advertiser')->whereDoesntHave('orders');
    }

    /**
     * Alias of queryAdvertisersNoOrders() — never started checkout.
     */
    public function queryAdvertisersNeverCheckedOut(): Builder
    {
        return $this->queryAdvertisersNoOrders();
    }

    /**
     * Advertisers who have never been a customer (no paid, completed, or refunded order).
     */
    public function queryAdvertisersNoPaidOrders(): Builder
    {
        return $this->queryForRole('advertiser')
            ->whereDoesntHave('orders', function (Builder $q) {
                $q->whereIn('payment_status', self::customerPaymentStatuses());
            });
    }

    /**
     * Publishers who have not listed any site.
     */
    public function queryPublishersNoSites(): Builder
    {
        return $this->queryForRole('publisher')->whereDoesntHave('sites');
    }

    /**
     * Publishers with no catalog-visible listing.
     *
     * Publisher archive keeps active=1 (so restore does not force a site live).
     * Counting only active=1 therefore missed archived-only publishers.
     * Match the advertiser catalog: active + verified + not archived + not
     * leftover from a cancelled bulk.
     */
    public function queryPublishersNoActiveSites(): Builder
    {
        return $this->queryForRole('publisher')
            ->whereDoesntHave('sites', function (Builder $q) {
                $q->catalogVisible();
            });
    }

    /**
     * Advertisers who have actually bought something.
     *
     * An abandoned unpaid checkout is not a customer. A later refund still
     * means they checked out and paid, so paid + completed + refunded all count.
     */
    public function queryAdvertisersWithPaidOrders(): Builder
    {
        return $this->queryForRole('advertiser')
            ->whereHas('orders', function (Builder $q) {
                $q->whereIn('payment_status', self::customerPaymentStatuses());
            });
    }

    /**
     * Advertisers who have never funded their wallet.
     *
     * Only a credited deposit counts: one still awaiting confirmation or since
     * rejected brought no money in, and the signup bonus is not a deposit.
     */
    public function queryAdvertisersNeverDeposited(): Builder
    {
        return $this->queryForRole('advertiser')
            ->whereDoesntHave('depositRequests', function (Builder $q) {
                $q->whereIn('status', self::creditedDepositStatuses());
            });
    }

    /**
     * Advertisers who funded a wallet but never became a customer.
     *
     * An abandoned unpaid checkout still belongs here — they have credit and
     * did not finish paying. A paid, completed, or refunded order does not.
     */
    public function queryAdvertisersDepositedNoOrders(): Builder
    {
        return $this->queryForRole('advertiser')
            ->whereHas('depositRequests', function (Builder $q) {
                $q->whereIn('status', self::creditedDepositStatuses());
            })
            ->whereDoesntHave('orders', function (Builder $q) {
                $q->whereIn('payment_status', self::customerPaymentStatuses());
            });
    }

    public function queryMarketplaceUsers(): Builder
    {
        $roleIds = $this->marketplaceRoleIds();
        $query = $this->baseUserQuery();

        if ($roleIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('roles', fn (Builder $q) => $q->whereIn('roles.id', $roleIds));
    }

    /**
     * Resolve a list/export/paginate key to a user query.
     * Tab slugs and aliases are canonicalized first so collect/count cannot
     * silently return an empty set for a known inventory tab.
     */
    public function queryForAudienceKey(string $audienceKey): Builder
    {
        $audienceKey = self::canonicalAudienceKey($audienceKey) ?? $audienceKey;

        $query = match ($audienceKey) {
            self::AUDIENCE_ADVERTISERS => $this->queryForRole('advertiser'),
            self::AUDIENCE_PUBLISHERS => $this->queryForRole('publisher'),
            self::AUDIENCE_BOTH => $this->queryMarketplaceUsers(),
            self::AUDIENCE_ADVERTISERS_NO_ORDERS => $this->queryAdvertisersNoOrders(),
            self::AUDIENCE_ADVERTISERS_NO_PAID_ORDERS => $this->queryAdvertisersNoPaidOrders(),
            self::AUDIENCE_ADVERTISERS_PAID_ORDERS => $this->queryAdvertisersWithPaidOrders(),
            self::AUDIENCE_PUBLISHERS_NO_SITES => $this->queryPublishersNoSites(),
            self::AUDIENCE_PUBLISHERS_NO_ACTIVE_SITES => $this->queryPublishersNoActiveSites(),
            self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED => $this->queryAdvertisersNeverDeposited(),
            self::AUDIENCE_ADVERTISERS_DEPOSITED_NO_ORDERS => $this->queryAdvertisersDepositedNoOrders(),
            default => User::query()->whereRaw('1 = 0'),
        };

        return $this->excludeStaffAccounts($query);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(string $audienceKey, ?string $search = null, int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        $query = $this->queryForAudienceKey($audienceKey);
        $this->applySearch($query, $search);
        $this->applyInventoryFilters($query, $filters, $audienceKey);
        $this->applyListCounts($query);

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @return Collection<int, User>
     */
    public function collect(string $audience, ?array $selectedIds = null, bool $includeUnverified = false): Collection
    {
        return $this->recipientBuilder($audience, $selectedIds, $includeUnverified)->get();
    }

    /**
     * id + email only, no role eager-loads — used by campaign send so a large
     * audience cannot OOM the HTTP request before the job is dispatched.
     *
     * @param  array<int, int|string>|null  $selectedIds
     * @return Collection<int, User>
     */
    public function collectRecipientRows(string $audience, ?array $selectedIds = null, bool $includeUnverified = false): Collection
    {
        return $this->recipientBuilder($audience, $selectedIds, $includeUnverified)
            ->setEagerLoads([])
            ->reorder()
            ->orderBy('users.id')
            ->select(['users.id', 'users.email'])
            ->get();
    }

    /**
     * Recipient count without hydrating User models.
     *
     * @param  array<int, int|string>|null  $selectedIds
     */
    public function count(string $audience, ?array $selectedIds = null, bool $includeUnverified = false): int
    {
        return $this->recipientBuilder($audience, $selectedIds, $includeUnverified)->count();
    }

    /**
     * Shared query for count / collect / send. Unknown keys are empty so
     * the three cannot drift (tab slugs canonicalize first).
     *
     * @param  array<int, int|string>|null  $selectedIds
     */
    protected function recipientBuilder(string $audience, ?array $selectedIds, bool $includeUnverified): Builder
    {
        $key = self::canonicalAudienceKey($audience);
        if ($key === null) {
            return User::query()->whereRaw('1 = 0');
        }

        if ($key === self::AUDIENCE_SELECTED) {
            return $this->querySelected($selectedIds, $includeUnverified);
        }

        return $this->applyRecipientScope($this->queryForAudienceKey($key), $includeUnverified);
    }

    public function bothUniqueCount(bool $includeUnverified = true): int
    {
        return $this->count(self::AUDIENCE_BOTH, null, $includeUnverified);
    }

    /**
     * @return Collection<int, User>
     */
    public function pickerUsers(string $roleName, int $limit = self::PICKER_LIMIT): Collection
    {
        return $this->pickerQuery($roleName)
            ->limit($limit)
            ->get(['id', 'name', 'email']);
    }

    /**
     * True when the custom picker is not showing every user in that role.
     * Uses the same universe as pickerUsers() (verified first, then the rest).
     */
    public function pickerIsCapped(string $roleName, int $limit = self::PICKER_LIMIT): bool
    {
        return $this->pickerQuery($roleName)->count() > $limit;
    }

    protected function pickerQuery(string $roleName): Builder
    {
        $query = $this->queryForRole($roleName);
        $this->excludeStaffAccounts($query);

        return $query
            ->setEagerLoads([])
            ->reorder()
            ->orderByRaw('case when email_verified_at is null then 1 else 0 end')
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * @param  array<int, int|string>|null  $selectedIds
     */
    protected function querySelected(?array $selectedIds, bool $includeUnverified): Builder
    {
        $ids = array_values(array_filter(array_map('intval', $selectedIds ?: [])));
        $roleIds = $this->marketplaceRoleIds();

        if ($ids === []) {
            return User::query()->whereRaw('1 = 0');
        }

        $query = User::query()
            ->whereIn('id', $ids)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereRaw('TRIM(email) != ?', [''])
            ->orderBy('name');

        if ($roleIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereHas('roles', fn (Builder $q) => $q->whereIn('roles.id', $roleIds));
        $this->excludeStaffAccounts($query);

        return $this->applyRecipientScope($query, $includeUnverified);
    }

    protected function applyRecipientScope(Builder $query, bool $includeUnverified): Builder
    {
        if (! $includeUnverified) {
            $query->whereNotNull('email_verified_at');
        }

        return $query;
    }

    /**
     * @return Collection<int, int>
     */
    protected function marketplaceRoleIds(): Collection
    {
        return Role::query()
            ->whereIn('name', ['advertiser', 'publisher'])
            ->pluck('id');
    }

    protected function baseUserQuery(): Builder
    {
        return User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereRaw('TRIM(email) != ?', [''])
            ->with(['roles', 'activeRoleRelation'])
            ->orderBy('name');
    }

    /**
     * Inventory + campaigns never email staff, including dual-role
     * admin/marketing accounts that still have advertiser or publisher.
     * Reminder queries keep queryForRole() as-is so deposit / add-site /
     * digest commands are unchanged.
     */
    protected function excludeStaffAccounts(Builder $query): Builder
    {
        return $query->whereDoesntHave('roles', function (Builder $q) {
            $q->whereIn('roles.name', ['admin', 'marketing']);
        });
    }

    protected function applySearch(Builder $query, ?string $search): Builder
    {
        if (! filled($search)) {
            return $query;
        }

        $like = like_contains($search);

        return $query->where(function (Builder $q) use ($like) {
            $q->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyInventoryFilters(Builder $query, array $filters, string $audienceKey): Builder
    {
        $verified = $filters['verified'] ?? 'all';
        if ($verified === 'yes') {
            $query->whereNotNull('email_verified_at');
        } elseif ($verified === 'no') {
            $query->whereNull('email_verified_at');
        }

        if (filled($filters['registered_from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['registered_from']);
        }
        if (filled($filters['registered_to'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['registered_to']);
        }
        if (filled($filters['country'] ?? null)) {
            $query->whereRaw('LOWER(country) = ?', [mb_strtolower(trim((string) $filters['country']))]);
        }

        $marketing = $filters['marketing'] ?? 'all';
        if ($marketing === 'opted_out') {
            $query->whereHas('emailNotificationPreferences', function (Builder $q) {
                $q->where('preference_key', 'marketing_emails')->where('enabled', false);
            });
        } elseif ($marketing === 'opted_in') {
            $query->whereDoesntHave('emailNotificationPreferences', function (Builder $q) {
                $q->where('preference_key', 'marketing_emails')->where('enabled', false);
            });
        }

        if (! empty($filters['exclude_dual_role'])) {
            $this->excludeDualRoleUsers($query, $audienceKey);
        }

        $sort = ($filters['sort'] ?? 'name') === 'registered' ? 'created_at' : 'name';
        $dir = ($filters['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->reorder()->orderBy($sort, $dir)->orderBy('id', $dir);

        return $query;
    }

    protected function excludeDualRoleUsers(Builder $query, string $audienceKey): void
    {
        $canonical = self::canonicalAudienceKey($audienceKey) ?? $audienceKey;

        if ($canonical === self::AUDIENCE_BOTH) {
            $query->where(function (Builder $q) {
                $q->whereDoesntHave('roles', fn (Builder $r) => $r->where('roles.name', 'advertiser'))
                    ->orWhereDoesntHave('roles', fn (Builder $r) => $r->where('roles.name', 'publisher'));
            });

            return;
        }

        $other = $this->otherMarketplaceRole($canonical);
        if ($other !== null) {
            $query->whereDoesntHave('roles', fn (Builder $q) => $q->where('roles.name', $other));
        }
    }

    protected function otherMarketplaceRole(string $audienceKey): ?string
    {
        $audienceKey = self::canonicalAudienceKey($audienceKey) ?? $audienceKey;

        return match ($audienceKey) {
            self::AUDIENCE_ADVERTISERS,
            self::AUDIENCE_ADVERTISERS_NO_ORDERS,
            self::AUDIENCE_ADVERTISERS_NO_PAID_ORDERS,
            self::AUDIENCE_ADVERTISERS_PAID_ORDERS,
            self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED,
            self::AUDIENCE_ADVERTISERS_DEPOSITED_NO_ORDERS => 'publisher',
            self::AUDIENCE_PUBLISHERS,
            self::AUDIENCE_PUBLISHERS_NO_SITES,
            self::AUDIENCE_PUBLISHERS_NO_ACTIVE_SITES => 'advertiser',
            default => null,
        };
    }

    protected function applyListCounts(Builder $query): void
    {
        $query->withCount([
            'orders as paid_orders_count' => fn (Builder $q) => $q->whereIn('payment_status', self::customerPaymentStatuses()),
            'sites as sites_count',
            'depositRequests as completed_deposits_count' => fn (Builder $q) => $q->whereIn('status', self::creditedDepositStatuses()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportCsv(string $audienceKey, ?string $search = null, array $filters = []): StreamedResponse
    {
        $filename = $audienceKey.'-audience-'.now()->format('Y-m-d-His').'.csv';
        $query = $this->queryForAudienceKey($audienceKey);
        $this->applySearch($query, $search);
        $this->applyInventoryFilters($query, $filters, $audienceKey);
        $this->applyListCounts($query);
        $query->reorder('id');

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ];

        return response()->streamDownload(function () use ($query, $audienceKey) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'id',
                'name',
                'email',
                'audience_role',
                'all_roles',
                'active_role',
                'email_verified',
                'country',
                'paid_orders_count',
                'sites_count',
                'completed_deposits_count',
                'registered_at',
            ]);

            $exported = 0;
            $query->chunkById(200, function (Collection $users) use ($out, $audienceKey, &$exported) {
                foreach ($users as $user) {
                    if ($exported >= self::EXPORT_LIMIT) {
                        fputcsv($out, ['# truncated', '', '', '', '', '', '', '', '', '', '', '']);

                        return false;
                    }

                    fputcsv($out, [
                        $user->id,
                        $this->csvCell($user->name),
                        $this->csvCell($user->email),
                        $audienceKey,
                        $this->csvCell($user->roles->pluck('name')->implode('|')),
                        $this->csvCell((string) $user->activeRole()),
                        $user->hasVerifiedEmail() ? 'yes' : 'no',
                        $this->csvCell((string) ($user->country ?? '')),
                        (int) ($user->paid_orders_count ?? 0),
                        (int) ($user->sites_count ?? 0),
                        (int) ($user->completed_deposits_count ?? 0),
                        optional($user->created_at)?->toDateTimeString(),
                    ]);
                    $exported++;
                }

                return true;
            }, 'users.id', 'id');

            fclose($out);
        }, $filename, $headers);
    }

    public function csvCell(mixed $value): string
    {
        $s = (string) $value;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$s;
        }

        return $s;
    }

    public function stats(bool $includeUnverified = true): array
    {
        $pairs = [
            'advertisers' => self::AUDIENCE_ADVERTISERS,
            'publishers' => self::AUDIENCE_PUBLISHERS,
            'both_unique' => self::AUDIENCE_BOTH,
            'advertisers_no_orders' => self::AUDIENCE_ADVERTISERS_NO_ORDERS,
            'advertisers_no_paid_orders' => self::AUDIENCE_ADVERTISERS_NO_PAID_ORDERS,
            'advertisers_paid_orders' => self::AUDIENCE_ADVERTISERS_PAID_ORDERS,
            'publishers_no_sites' => self::AUDIENCE_PUBLISHERS_NO_SITES,
            'publishers_no_active_sites' => self::AUDIENCE_PUBLISHERS_NO_ACTIVE_SITES,
            'advertisers_never_deposited' => self::AUDIENCE_ADVERTISERS_NEVER_DEPOSITED,
            'advertisers_deposited_no_orders' => self::AUDIENCE_ADVERTISERS_DEPOSITED_NO_ORDERS,
        ];

        $out = [];
        foreach ($pairs as $statKey => $audienceKey) {
            $all = $this->count($audienceKey, null, true);
            $verified = $this->count($audienceKey, null, false);
            $out[$statKey] = $includeUnverified ? $all : $verified;
            $out[$statKey.'_all'] = $all;
            $out[$statKey.'_verified'] = $verified;
        }

        $out['advertisers_never_checked_out'] = $out['advertisers_no_orders'];
        $out['advertisers_never_checked_out_all'] = $out['advertisers_no_orders_all'];
        $out['advertisers_never_checked_out_verified'] = $out['advertisers_no_orders_verified'];

        return $out;
    }
}

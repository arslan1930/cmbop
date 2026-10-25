<?php

namespace App\Services\Reminders;

use App\Mail\AbandonedCartReminderMail;
use App\Models\AbandonedCartReminderSend;
use App\Models\AbandonedCartSetting;
use App\Models\AdvertiserSavedCartItem;
use App\Models\EmailNotificationPreference;
use App\Models\EmailNotificationSetting;
use App\Models\Order;
use App\Models\Site;
use App\Models\User;
use App\Services\Advertiser\AdvertiserCartPersistence;
use App\Services\CartPricingService;
use App\Services\EmailNotificationService;
use App\Support\MailAddress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AbandonedCartReminderService
{
    public function __construct(
        private AdvertiserCartPersistence $carts,
        private CartPricingService $pricing,
        private EmailNotificationService $emails,
        private ReminderFatigueGuard $fatigue,
    ) {}

    public function isEnabled(): bool
    {
        return AbandonedCartSetting::isEnabled();
    }

    public function cartsReady(): bool
    {
        return $this->carts->tableReady();
    }

    /**
     * @return array{sent: int, skipped: int, considered: int}
     */
    public function sendDue(?int $onlyUserId = null, bool $dryRun = false): array
    {
        $sent = 0;
        $skipped = 0;
        $considered = 0;

        if (! $this->isEnabled()) {
            return compact('sent', 'skipped', 'considered');
        }

        if (! EmailNotificationSetting::isEnabled('abandoned_cart_reminder')) {
            return compact('sent', 'skipped', 'considered');
        }

        if (! $this->carts->tableReady() || ! AbandonedCartReminderSend::tableAvailable()) {
            return compact('sent', 'skipped', 'considered');
        }

        $userIds = AdvertiserSavedCartItem::query()
            ->select('user_id')
            ->when($onlyUserId, fn ($q) => $q->where('user_id', $onlyUserId))
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $considered++;
            $user = User::query()->find((int) $userId);
            if (! $user) {
                $skipped++;

                continue;
            }

            $stage = $this->dueStage($user);
            if ($stage === null) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $sent++;

                continue;
            }

            if ($this->sendStage($user, $stage)) {
                $sent++;
            } else {
                $skipped++;
            }
        }

        return compact('sent', 'skipped', 'considered');
    }

    public function dueStage(User $user): ?int
    {
        if (! $this->recipientAllowed($user)) {
            return null;
        }

        $quantities = $this->carts->quantitiesForUser($user);
        if ($quantities === []) {
            return null;
        }

        $lines = $this->pricedLines($user, $quantities);
        if ($lines === []) {
            return null;
        }

        $activity = $this->carts->lastActivityAt($user);
        if (! $activity instanceof Carbon) {
            return null;
        }

        if ($this->hasPaidOrderSince($user, $activity)) {
            return null;
        }

        $fingerprint = $this->carts->fingerprint($quantities);
        if ($fingerprint === '') {
            return null;
        }

        $hours = $activity->diffInMinutes(now()) / 60;
        $stage1Hours = (int) config('reminders.abandoned_cart.stage_1_hours', 24);
        $stage2Hours = (int) config('reminders.abandoned_cart.stage_2_hours', 72);

        $due = [];
        if ($hours + 0.0001 >= $stage1Hours
            && ! AbandonedCartReminderSend::alreadySent((int) $user->id, $fingerprint, AbandonedCartReminderMail::STAGE_1)) {
            $due[] = AbandonedCartReminderMail::STAGE_1;
        }
        if ($hours + 0.0001 >= $stage2Hours
            && ! AbandonedCartReminderSend::alreadySent((int) $user->id, $fingerprint, AbandonedCartReminderMail::STAGE_2)) {
            $due[] = AbandonedCartReminderMail::STAGE_2;
        }

        return $due === [] ? null : min($due);
    }

    public function sendStage(User $user, int $stage): bool
    {
        if (! $this->isEnabled() || ! EmailNotificationSetting::isEnabled('abandoned_cart_reminder')) {
            return false;
        }

        if (! $this->recipientAllowed($user)) {
            return false;
        }

        $quantities = $this->carts->quantitiesForUser($user);
        $lines = $this->pricedLines($user, $quantities);
        if ($lines === []) {
            return false;
        }

        $activity = $this->carts->lastActivityAt($user);
        if (! $activity instanceof Carbon || $this->hasPaidOrderSince($user, $activity)) {
            return false;
        }

        $fingerprint = $this->carts->fingerprint($quantities);
        if ($fingerprint === '' || AbandonedCartReminderSend::alreadySent((int) $user->id, $fingerprint, $stage)) {
            return false;
        }

        if (! $this->fatigue->allows($user)) {
            return false;
        }

        if (! EmailNotificationPreference::allows($user, 'marketing_emails')) {
            return false;
        }

        $mailable = new AbandonedCartReminderMail($user, $stage, $lines, $fingerprint);
        $ok = $this->emails->sendReminder($user, $mailable);
        if ($ok) {
            AbandonedCartReminderSend::record((int) $user->id, $fingerprint, $stage);
            $this->fatigue->record($user);
        }

        return $ok;
    }

    public function recipientAllowed(User $user): bool
    {
        if (! MailAddress::isValid($user->email)) {
            return false;
        }

        if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
            return false;
        }

        if (! $user->email_verified_at) {
            return false;
        }

        try {
            if (! $user->hasRole('advertiser')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function hasPaidOrderSince(User $user, Carbon $since): bool
    {
        try {
            if (! Schema::hasTable((new Order)->getTable())) {
                return false;
            }

            $query = Order::query()
                ->where('user_id', $user->id)
                ->whereIn('payment_status', ['paid', 'completed', 'refunded']);

            if (Schema::hasColumn((new Order)->getTable(), 'paid_at')) {
                $query->where(function ($inner) use ($since) {
                    $inner->where('paid_at', '>=', $since)
                        ->orWhere(function ($fallback) use ($since) {
                            $fallback->whereNull('paid_at')
                                ->where('created_at', '>=', $since);
                        });
                });
            } else {
                $query->where('created_at', '>=', $since);
            }

            return $query->exists();
        } catch (\Throwable $e) {
            Log::warning('Abandoned cart paid-order check failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * @param  array<int, int>  $quantities
     * @return list<array{domain: string, quantity: int, price: float, was: ?float, discount: ?float}>
     */
    public function pricedLines(User $user, array $quantities): array
    {
        if ($quantities === []) {
            return [];
        }

        try {
            $sites = Site::query()
                ->catalogVisible()
                ->whereIn('id', array_keys($quantities))
                ->get()
                ->keyBy('id');
        } catch (\Throwable) {
            return [];
        }

        $lines = [];
        foreach ($quantities as $siteId => $quantity) {
            $site = $sites->get((int) $siteId);
            if (! $site instanceof Site || $site->isOwnedBy($user)) {
                continue;
            }

            try {
                $pricing = $this->pricing->priceForAdvertiser($site, null, (int) $quantity, 'none', false);
            } catch (\Throwable $e) {
                Log::warning('Abandoned cart price failed', [
                    'user_id' => $user->id,
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $unit = (float) ($pricing['total'] ?? $pricing['advertiser_eur_amount'] ?? 0);
            $listUnit = (float) ($pricing['list_total'] ?? $unit);
            $discount = (float) ($pricing['discount_percent'] ?? 0);
            $qty = max(1, (int) $quantity);

            $lines[] = [
                'domain' => $this->displayDomain($site),
                'quantity' => $qty,
                'price' => round($unit * $qty, 2),
                'was' => $discount > 0 && $listUnit > $unit ? round($listUnit * $qty, 2) : null,
                'discount' => $discount > 0 ? $discount : null,
            ];
        }

        return $lines;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function adminRows(int $limit = 50): Collection
    {
        if (! $this->carts->tableReady()) {
            return collect();
        }

        $grouped = AdvertiserSavedCartItem::query()
            ->selectRaw('user_id, COUNT(*) as item_count, SUM(quantity) as placement_count, MAX(updated_at) as last_activity_at')
            ->groupBy('user_id')
            ->orderByDesc('last_activity_at')
            ->limit($limit)
            ->get();

        $users = User::query()->whereIn('id', $grouped->pluck('user_id'))->get()->keyBy('id');
        $sends = AbandonedCartReminderSend::tableAvailable()
            ? AbandonedCartReminderSend::query()
                ->whereIn('user_id', $grouped->pluck('user_id'))
                ->get()
                ->groupBy('user_id')
            : collect();

        return $grouped->map(function ($row) use ($users, $sends) {
            $user = $users->get((int) $row->user_id);
            $userSends = $sends->get((int) $row->user_id, collect());

            return [
                'user' => $user,
                'item_count' => (int) $row->item_count,
                'placement_count' => (int) $row->placement_count,
                'last_activity_at' => $row->last_activity_at,
                'stage_1_count' => $userSends->where('stage', 1)->count(),
                'stage_2_count' => $userSends->where('stage', 2)->count(),
            ];
        });
    }

    /**
     * @return array{carts: int, items: int, stage_1: int, stage_2: int}
     */
    public function adminCounts(): array
    {
        $carts = 0;
        $items = 0;
        $stage1 = 0;
        $stage2 = 0;

        if ($this->carts->tableReady()) {
            try {
                $carts = AdvertiserSavedCartItem::query()->select('user_id')->distinct()->pluck('user_id')->count();
                $items = (int) AdvertiserSavedCartItem::query()->count();
            } catch (\Throwable) {
            }
        }

        if (AbandonedCartReminderSend::tableAvailable()) {
            try {
                $stage1 = (int) AbandonedCartReminderSend::query()->where('stage', 1)->count();
                $stage2 = (int) AbandonedCartReminderSend::query()->where('stage', 2)->count();
            } catch (\Throwable) {
            }
        }

        return [
            'carts' => $carts,
            'items' => $items,
            'stage_1' => $stage1,
            'stage_2' => $stage2,
        ];
    }

    private function displayDomain(Site $site): string
    {
        $domain = trim((string) ($site->domain ?? ''));
        if ($domain !== '') {
            return $domain;
        }

        $host = parse_url((string) ($site->site_url ?? ''), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'A website';
    }
}

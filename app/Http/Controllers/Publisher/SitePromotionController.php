<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Services\SitePromotionService;
use App\Services\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class SitePromotionController extends Controller
{
    public function __construct(private readonly SitePromotionService $promotions) {}

    /**
     * Feature, timed sale, and bulk may be set on any live Active-tab row
     * (active or verified). Verification is a trust badge, not a promo lock —
     * the Feature dialog already says featuring still works while Get Verified
     * is open. Cancelled-bulk leftovers stay blocked.
     */
    private function ownedPromotableSite(int $id): Site|JsonResponse
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if ($site->isArchived()) {
            return response()->json([
                'success' => false,
                'message' => 'Archived sites cannot be promoted. Restore the site first.',
            ], 422);
        }

        if ($site->isFromCancelledBulk()) {
            return response()->json([
                'success' => false,
                'message' => 'This listing is not in the catalog and cannot be promoted.',
            ], 422);
        }

        if (! $site->active && ! $site->verified) {
            return response()->json([
                'success' => false,
                'message' => 'Only verified or active sites can use promotions.',
            ], 422);
        }

        return $site;
    }

    public function feature(Request $request, int $id)
    {
        $site = $this->ownedPromotableSite($id);
        if ($site instanceof JsonResponse) {
            return $site;
        }

        $result = $this->promotions->featureWithWallet($site, auth()->user());

        if ($result['success'] ?? false) {
            ActivityLogger::log(
                'site.featured',
                auth()->user()->name.' featured "'.$site->site_name.'"',
                $site,
                ['days' => $this->promotions->featureDays(), 'price' => $this->promotions->featurePrice()],
                $site->site_name
            );
        }

        if (! ($result['success'] ?? false) && ($result['needs_top_up'] ?? false)) {
            $result['stripe_checkout_url'] = route('publisher.sites.feature.checkout', $site->id);
        }

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * Create a Stripe Checkout session to pay for featuring a site by card.
     */
    public function featureCheckout(int $id)
    {
        $site = $this->ownedPromotableSite($id);
        if ($site instanceof JsonResponse) {
            return $site;
        }

        $user = auth()->user();
        $price = $this->promotions->featurePrice();
        $days = $this->promotions->featureDays();

        if (! config('services.stripe.secret')) {
            return response()->json([
                'success' => false,
                'message' => 'Card payments are not configured. Please use wallet balance or contact support.',
            ], 503);
        }

        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            $session = Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => [
                            'name' => 'Feature website — '.$site->site_name,
                            'description' => 'Featured catalog placement for '.$days.' days',
                        ],
                        'unit_amount' => StripePaymentService::toCents($price),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => route('publisher.sites.feature.success', $site->id).'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('publisher.websites').'?feature_canceled=1',
                'customer_email' => $user->email,
                'metadata' => [
                    'type' => 'site_feature',
                    'site_id' => (string) $site->id,
                    'user_id' => (string) $user->id,
                    'price' => (string) $price,
                    'days' => (string) $days,
                ],
            ]);

            return response()->json([
                'success' => true,
                'checkout_url' => $session->url,
                'session_id' => $session->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Feature Stripe checkout failed', ['error' => $e->getMessage(), 'site_id' => $site->id]);

            return response()->json([
                'success' => false,
                'message' => 'Could not start card checkout. Please try again or use wallet balance.',
            ], 500);
        }
    }

    public function featureSuccess(Request $request, int $id)
    {
        $site = Site::findOrFail($id);
        $sessionId = (string) $request->query('session_id', '');

        if ($sessionId === '' || ! config('services.stripe.secret')) {
            return redirect()->route('publisher.websites')
                ->with('error', 'Invalid feature payment session.');
        }

        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            $session = Session::retrieve($sessionId);

            if ($session->payment_status !== 'paid') {
                return redirect()->route('publisher.websites')
                    ->with('error', 'Payment was not completed.');
            }

            if ((string) ($session->metadata->user_id ?? '') !== (string) auth()->id()
                || (string) ($session->metadata->site_id ?? '') !== (string) $site->id
                || ($session->metadata->type ?? '') !== 'site_feature') {
                return redirect()->route('publisher.websites')
                    ->with('error', 'Payment session does not match this website.');
            }

            $this->promotions->assertStripeChargeMatchesFeaturePrice($session);

            if ((int) $site->publisher_id !== (int) auth()->id()) {
                $credit = $this->promotions->creditPayerWhenFeatureCannotApply(
                    $site,
                    auth()->user(),
                    $sessionId
                );

                return redirect()->route('publisher.websites')
                    ->with($credit['success'] ? 'success' : 'error', $credit['message'] ?? 'Could not apply featured placement.');
            }

            // Apply after payment is confirmed — even if the site was archived meantime —
            // so the publisher is not charged without receiving the feature.
            $result = $this->promotions->featureFromStripePayment($site, auth()->user(), $sessionId);

            if ($result['credited'] ?? false) {
                return redirect()->route('publisher.websites')
                    ->with('success', $result['message'] ?? 'Payment credited to your publisher wallet.');
            }

            if ($result['success'] ?? false) {
                $message = $result['message'] ?? 'Website featured successfully.';
                if ($site->isArchived()) {
                    $message .= ' Restore the site from Archive to show the feature in the catalog.';
                }

                return redirect()->route('publisher.websites')
                    ->with('success', $message);
            }

            return redirect()->route('publisher.websites')
                ->with('error', $result['message'] ?? 'Could not apply feature after payment.');
        } catch (\Throwable $e) {
            Log::error('Feature Stripe success handling failed', ['error' => $e->getMessage()]);

            return redirect()->route('publisher.websites')
                ->with('error', 'Could not verify payment. Contact support if you were charged.');
        }
    }

    public function walletSummary()
    {
        $roleId = Wallet::publisherRoleId();
        $withdrawable = 0.0;
        if ($roleId) {
            $wallet = Wallet::where('user_id', auth()->id())->where('role_id', $roleId)->first();
            $withdrawable = $wallet ? $wallet->withdrawableBalance() : 0.0;
        }

        return response()->json([
            'success' => true,
            // Featuring spends cash only — keep `balance` as withdrawable so the
            // publisher UI does not offer "Pay from wallet" on bonus-inflated totals.
            'balance' => $withdrawable,
            'withdrawable' => $withdrawable,
            'feature_price' => $this->promotions->featurePrice(),
            'feature_days' => $this->promotions->featureDays(),
            'top_up_url' => route('publisher.balance'),
            'balance_url' => route('publisher.balance'),
            'stripe_available' => (bool) config('services.stripe.secret'),
            'hint' => 'Pay from publisher earnings (welcome bonus cannot be used), or pay by card with Stripe. Use Balance to transfer funds between wallets.',
        ]);
    }

    public function joinBulk(Request $request, int $id)
    {
        $site = $this->ownedPromotableSite($id);
        if ($site instanceof JsonResponse) {
            return $site;
        }

        $data = $request->validate([
            'percent' => 'required|numeric|min:'.config('site_promotions.bulk.min_percent', 10)
                .'|max:'.config('site_promotions.bulk.max_percent', 80),
        ]);

        $alreadyJoined = $site->joinsBulkDiscount();
        $previousPercent = $alreadyJoined ? (float) $site->bulk_discount_percent : null;
        $site = $this->promotions->joinBulkDiscount($site, (float) $data['percent']);
        $newPercent = (float) $site->bulk_discount_percent;
        $pct = rtrim(rtrim(number_format($newPercent, 2), '0'), '.');
        $lead = $alreadyJoined
            ? 'Updated bulk discount to '.$pct.'% on 3–5 articles.'
            : 'Joined bulk discount programme ('.$pct.'% on 3–5 articles).';

        if (! $alreadyJoined || abs(($previousPercent ?? 0) - $newPercent) >= 0.001) {
            ActivityLogger::tryLog(
                $alreadyJoined ? 'site.bulk_discount_updated' : 'site.bulk_discount_joined',
                auth()->user()->name.' '.$lead,
                $site,
                [
                    'percent' => $newPercent,
                    'from' => $previousPercent,
                ],
                $site->site_name
            );
        }

        return response()->json([
            'success' => true,
            'message' => $lead.' Exclusive better-of with any timed sale — not stacked; advertisers see the post-fee-floor rate.',
            'site' => $site,
        ]);
    }

    public function leaveBulk(int $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);
        $wasJoined = $site->joinsBulkDiscount();
        $previousPercent = $wasJoined ? (float) $site->bulk_discount_percent : null;
        $site = $this->promotions->leaveBulkDiscount($site);

        if ($wasJoined) {
            ActivityLogger::tryLog(
                'site.bulk_discount_left',
                auth()->user()->name.' left the bulk discount programme on "'.$site->site_name.'"',
                $site,
                ['from' => $previousPercent],
                $site->site_name
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Left the bulk discount program.',
            'site' => $site,
        ]);
    }

    public function setDiscount(Request $request, int $id)
    {
        $site = $this->ownedPromotableSite($id);
        if ($site instanceof JsonResponse) {
            return $site;
        }

        $data = $request->validate([
            'percent' => 'required|numeric|min:'.config('site_promotions.custom_discount.min_percent', 1)
                .'|max:'.config('site_promotions.custom_discount.max_percent', 70),
            'days' => 'required|integer|min:1|max:'.config('site_promotions.custom_discount.max_days', 90),
        ]);

        $site = $this->promotions->setCustomDiscount($site, (float) $data['percent'], (int) $data['days']);

        ActivityLogger::log(
            'site.discount_set',
            auth()->user()->name.' set a '.$data['percent'].'% discount on "'.$site->site_name.'" for '.$data['days'].' days',
            $site,
            $data,
            $site->site_name
        );

        return response()->json([
            'success' => true,
            'message' => 'Discount live for '.$data['days'].' day(s). You’ll get an email when it ends.',
            'site' => $site,
        ]);
    }

    public function clearDiscount(int $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);
        $hadDiscount = $site->custom_discount_percent !== null
            || $site->custom_discount_starts_at !== null
            || $site->custom_discount_ends_at !== null;
        $previousPercent = $site->custom_discount_percent !== null
            ? (float) $site->custom_discount_percent
            : null;
        $site = $this->promotions->clearCustomDiscount($site);

        if ($hadDiscount) {
            ActivityLogger::tryLog(
                'site.discount_cleared',
                auth()->user()->name.' cleared the custom discount on "'.$site->site_name.'"',
                $site,
                ['from' => $previousPercent],
                $site->site_name
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Custom discount removed.',
            'site' => $site,
        ]);
    }
}

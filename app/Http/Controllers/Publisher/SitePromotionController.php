<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Services\SitePromotionService;
use App\Services\StripePaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class SitePromotionController extends Controller
{
    public function __construct(private readonly SitePromotionService $promotions)
    {
    }

    public function feature(Request $request, int $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);
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
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);
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
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);
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

            $result = $this->promotions->featureFromStripePayment($site, auth()->user(), $sessionId);

            if ($result['success'] ?? false) {
                ActivityLogger::log(
                    'site.featured_stripe',
                    auth()->user()->name.' featured "'.$site->site_name.'" via Stripe',
                    $site,
                    ['session_id' => $sessionId, 'days' => $this->promotions->featureDays()],
                    $site->site_name
                );

                return redirect()->route('publisher.websites')
                    ->with('success', $result['message'] ?? 'Website featured successfully.');
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
        $balance = 0.0;
        if ($roleId) {
            $wallet = Wallet::where('user_id', auth()->id())->where('role_id', $roleId)->first();
            $balance = (float) ($wallet?->balance ?? 0);
        }

        return response()->json([
            'success' => true,
            'balance' => $balance,
            'feature_price' => $this->promotions->featurePrice(),
            'feature_days' => $this->promotions->featureDays(),
            'top_up_url' => route('advertiser.add-funds'),
            'balance_url' => route('publisher.balance'),
            'stripe_available' => (bool) config('services.stripe.secret'),
            'hint' => 'Pay from publisher earnings, or pay by card with Stripe. You can also top up via Add Funds and transfer to your publisher wallet.',
        ]);
    }

    public function joinBulk(Request $request, int $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);
        $data = $request->validate([
            'percent' => 'required|numeric|min:'.config('site_promotions.bulk.min_percent', 10)
                .'|max:'.config('site_promotions.bulk.max_percent', 15),
        ]);

        $site = $this->promotions->joinBulkDiscount($site, (float) $data['percent']);

        return response()->json([
            'success' => true,
            'message' => 'Joined bulk discount program ('.rtrim(rtrim(number_format((float) $site->bulk_discount_percent, 2), '0'), '.').'% on 3–5 articles).',
            'site' => $site,
        ]);
    }

    public function leaveBulk(int $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);
        $site = $this->promotions->leaveBulkDiscount($site);

        return response()->json([
            'success' => true,
            'message' => 'Left the bulk discount program.',
            'site' => $site,
        ]);
    }

    public function setDiscount(Request $request, int $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);
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
        $site = $this->promotions->clearCustomDiscount($site);

        return response()->json([
            'success' => true,
            'message' => 'Custom discount removed.',
            'site' => $site,
        ]);
    }
}

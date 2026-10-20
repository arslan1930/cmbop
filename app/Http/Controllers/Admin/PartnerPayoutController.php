<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerApiKey;
use App\Models\PartnerClawback;
use App\Models\PartnerConnection;
use App\Models\PartnerPayoutModeAcceptance;
use App\Services\ActivityLogger;
use App\Services\Partners\PartnerApiKeyService;
use App\Services\Partners\PartnerChannel;
use App\Services\Partners\PartnerLedgerService;
use App\Services\Partners\PartnerPayoutModeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;
use RuntimeException;

class PartnerPayoutController extends Controller
{
    public function __construct(
        private PartnerLedgerService $ledger,
        private PartnerPayoutModeService $modes,
        private PartnerApiKeyService $keys,
    ) {}

    public function index(): View
    {
        $partners = Partner::query()->with('user')->orderByDesc('id')->get();
        $rows = [];
        foreach ($partners as $partner) {
            $rows[] = [
                'partner' => $partner,
                'mode' => PartnerChannel::payoutMode($partner),
                'pending' => $this->ledger->pendingMinor($partner),
                'available' => $this->ledger->availableMinor($partner),
                'exposure' => $this->ledger->instantExposureMinor($partner),
                'cap' => (int) ($partner->instant_exposure_cap_minor ?: 50000),
                'keep_live' => PartnerPayoutModeAcceptance::query()
                    ->where('partner_id', $partner->id)
                    ->where('terms_type', PartnerPayoutModeAcceptance::KEEP_LIVE)
                    ->whereNull('revoked_at')
                    ->exists(),
            ];
        }
        $clawbacks = PartnerClawback::query()->with('partner.user')->orderByDesc('id')->limit(30)->get();

        return view('admin.partners.payouts', compact('rows', 'clawbacks'));
    }

    public function switchMode(Request $request, Partner $partner): RedirectResponse
    {
        $data = $request->validate([
            'mode' => 'required|in:held,instant',
            'reason' => 'required|string|min:5|max:500',
        ]);
        try {
            if ($data['mode'] === PartnerChannel::MODE_INSTANT) {
                $this->modes->switchToInstant($partner, $request->user(), $data['reason']);
            } else {
                $this->modes->switchToHeld($partner, $request->user(), $data['reason']);
            }
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payout mode updated.');
    }

    public function issueKey(Request $request, Partner $partner): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'mode' => 'required|in:live,test',
        ]);
        try {
            $issued = $this->keys->issue($partner, $data['name'], PartnerApiKeyService::SCOPES, $data['mode'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        ActivityLogger::tryLog('partner.api_key.issued', 'Issued partner API key', $partner, [
            'prefix' => $issued['key']->prefix,
        ], null, $request->user());

        return back()->with('success', 'API key created. Copy it now — it will not be shown again.')
            ->with('plain_api_key', $issued['plain']);
    }

    public function revokeKey(Request $request, Partner $partner, PartnerApiKey $key): RedirectResponse
    {
        if ((int) $key->partner_id !== (int) $partner->id) {
            abort(404);
        }
        $this->keys->revoke($key);

        return back()->with('success', 'API key revoked.');
    }

    public function savePullConnection(Request $request, Partner $partner): RedirectResponse
    {
        $data = $request->validate([
            'api_base_url' => 'required|url|max:2000',
            'auth_header_name' => 'nullable|string|max:120',
            'auth_key' => 'nullable|string|max:500',
            'items_path' => 'nullable|string|max:191',
            'page_param' => 'nullable|string|max:40',
            'max_pages' => 'nullable|integer|min:1|max:50',
        ]);
        $connection = PartnerConnection::query()->firstOrNew([
            'partner_id' => $partner->id,
            'type' => PartnerConnection::TYPE_API_PULL,
        ]);
        $connection->api_base_url = $data['api_base_url'];
        $connection->auth_header_name = $data['auth_header_name'] ?? 'Authorization';
        if (($data['auth_key'] ?? '') !== '') {
            $connection->auth_key_encrypted = Crypt::encryptString($data['auth_key']);
        }
        $connection->items_path = $data['items_path'] ?? 'data';
        $connection->pagination = [
            'page_param' => $data['page_param'] ?? 'page',
            'start_page' => 1,
            'max_pages' => (int) ($data['max_pages'] ?? 10),
        ];
        $connection->status = PartnerConnection::STATUS_ACTIVE;
        $connection->save();

        return back()->with('success', 'Pull adapter saved. Cron partners:sync-api-pull will fetch it.');
    }
}

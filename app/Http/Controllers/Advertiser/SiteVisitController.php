<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Services\Catalog\RevealPaceGuard;
use App\Services\Catalog\SiteUrlVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Sends an advertiser to a publisher's site without printing its address.
 *
 * Outside hide mode the catalog already shows full identity, so this is a plain
 * attributed redirect — no disclosure row, no pace check.
 *
 * Inside hide mode "Open site" is the path when the row is still masked. That
 * visit records a disclosure and counts toward pace like an eye reveal; a
 * frozen account is sent back to the catalog instead of unlocking another host.
 */
class SiteVisitController extends Controller
{
    public function __invoke(
        int $site,
        SiteUrlVisibility $visibility,
        RevealPaceGuard $pace,
    ): RedirectResponse {
        $model = Site::query()->catalogVisible()->find($site);

        if (! $model || blank($model->site_url)) {
            return redirect()
                ->route('advertiser.catalog')
                ->with('error', 'That website is no longer listed.');
        }

        $user = auth()->user();

        // Pace + visit disclosure only matter while copy-strike hide mode masks
        // the row. Outside that, identity is already open — do not invent a
        // "reveal first" gate or burn pace on a normal open.
        if ($user && $visibility->inHideMode($user)) {
            try {
                if (! $visibility->canSee($user, $model)) {
                    if ($pace->assess($user)['state'] === RevealPaceGuard::FROZEN) {
                        return redirect()
                            ->route('advertiser.catalog')
                            ->with('error', RevealPaceGuard::freezeUserMessage());
                    }

                    $visibility->reveal($user, $model, SiteUrlReveal::SOURCE_VISIT);
                }
            } catch (\Throwable $e) {
                // Never strand someone on a blank page over bookkeeping.
                Log::warning('Could not record site visit', [
                    'site_id' => $site,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $url = $model->site_url;

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://'.ltrim($url, '/');
        }

        return redirect()->away($url);
    }
}

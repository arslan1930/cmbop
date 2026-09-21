<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Services\Catalog\RevealPaceGuard;
use App\Services\Catalog\SiteUrlVisibility;
use App\Support\UserFacingError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        Request $request,
        int $site,
        SiteUrlVisibility $visibility,
        RevealPaceGuard $pace,
    ): RedirectResponse {
        try {
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
            //
            // Open site / ?sample=1 / ?path= must use the same SLOW + FROZEN
            // rules as the eye. Skipping SLOW let a script walk /go/{id} and
            // unlock hosts while the eye was being told to wait.
            if ($user && $visibility->inHideMode($user)) {
                try {
                    if (! $visibility->canSee($user, $model)) {
                        if (! $visibility->hasEverSeen($user, $model)) {
                            $verdict = $pace->assess($user);
                            if ($verdict['state'] === RevealPaceGuard::FROZEN) {
                                return redirect()
                                    ->route('advertiser.catalog')
                                    ->with('error', RevealPaceGuard::freezeUserMessage());
                            }
                            if ($verdict['state'] === RevealPaceGuard::SLOW) {
                                return redirect()
                                    ->route('advertiser.catalog')
                                    ->with('error', RevealPaceGuard::slowUserMessage((int) ($verdict['retry_after'] ?? 3)));
                            }
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
            // Description / sample links must not put the publisher URL in href —
            // "Copy link address" would bypass copy-track. Click still lands
            // on the article via ?sample=1 or a same-host ?path=.
            $path = $request->query('path');
            if (is_string($path) && $this->isSafeRelativePath($path)) {
                $origin = $this->listingOrigin($model->site_url);
                if ($origin !== '') {
                    $url = $origin.$path;
                }
            } elseif ($request->boolean('sample')) {
                $sample = safe_external_url($model->example_url, '');
                if (str_starts_with($sample, 'http://') || str_starts_with($sample, 'https://')) {
                    $url = $sample;
                }
            }

            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                $url = 'https://'.ltrim($url, '/');
            }

            return redirect()->away($url);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('advertiser.catalog')
                ->with('error', UserFacingError::message($e, 'That website is no longer listed.'));
        }
    }

    /**
     * Path-only query for description links. Must not accept a host.
     */
    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 500) {
            return false;
        }

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }

        if (str_contains($path, '\\') || str_contains($path, "\0") || str_contains($path, '://')) {
            return false;
        }

        return true;
    }

    private function listingOrigin(string $siteUrl): string
    {
        $safe = safe_external_url($siteUrl, '');
        if (! str_starts_with($safe, 'http://') && ! str_starts_with($safe, 'https://')) {
            return '';
        }

        $parts = parse_url($safe);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $origin = $parts['scheme'].'://'.$parts['host'];
        if (! empty($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}

<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Catalog\RevealPaceGuard;
use App\Services\Catalog\SiteUrlVisibility;
use App\Support\UserFacingError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Hands an advertiser one publisher domain.
 *
 * There is no quota. Someone researching a campaign may work through hundreds of
 * listings and should never be told to come back tomorrow. What is checked is
 * the pace: a rate no person sustains earns a pause, not a refusal.
 */
class SiteUrlRevealController extends Controller
{
    public function __invoke(
        int $site,
        SiteUrlVisibility $visibility,
        RevealPaceGuard $pace,
    ): JsonResponse {
        try {
            $user = auth()->user();
            $model = Site::query()->catalogVisible()->find($site);

            if (! $model) {
                return response()->json([
                    'success' => false,
                    'message' => 'That website is no longer listed.',
                ], 404);
            }

            // Eye reveal exists only in copy-strike hide mode. Normals already
            // see full addresses — this endpoint must not invent a mask toggle.
            if (! $visibility->inHideMode($user)) {
                return response()->json([
                    'success' => false,
                    'code' => 'hide_mode_only',
                    'message' => 'Show/hide is only available while catalog hide mode is active.',
                ], 403);
            }

            // Ensure sticky storage exists before any early return — otherwise a
            // missing table makes canSee false forever and hide asks them to
            // "open" an address they just painted in the browser.
            $visibility->ensureSchema();

            // Already visible, or theirs to begin with: no new disclosure, so the
            // pace check does not apply.
            if ($visibility->canSee($user, $model)) {
                return response()->json($this->identityPayload($visibility, $model, true));
            }

            // They opened it before and hid it with the eye. Showing it again is
            // the same disclosure — do not make them wait on pace for a toggle.
            if ($visibility->hasEverSeen($user, $model)) {
                $visibility->reveal($user, $model);

                return response()->json($this->identityPayload($visibility, $model, true));
            }

            $verdict = $pace->assess($user);

            if ($verdict['state'] === RevealPaceGuard::FROZEN) {
                return response()->json([
                    'success' => false,
                    'code' => 'paused',
                    'message' => RevealPaceGuard::freezeUserMessage(),
                ], 429)->header('Retry-After', (string) ($verdict['retry_after'] ?? 300));
            }

            if ($verdict['state'] === RevealPaceGuard::SLOW) {
                // Not a refusal: retry_after is the real time until this account
                // has room again, so waiting it out genuinely works. Short waits
                // the page absorbs silently; longer ones it says out loud rather
                // than leaving someone watching a spinner.
                $wait = (int) ($verdict['retry_after'] ?? 3);

                return response()->json([
                    'success' => false,
                    'code' => 'slow_down',
                    'retry_after' => $wait,
                    'message' => $wait <= 10
                        ? 'One moment…'
                        : 'You are opening addresses faster than we serve them. '
                            .'This one will be available in about '.$wait.' seconds — nothing is blocked, and everything you have already opened stays available.',
                ], 429)->header('Retry-After', (string) $wait);
            }

            $visibility->reveal($user, $model);

            return response()->json($this->identityPayload($visibility, $model, true));
        } catch (\Throwable $e) {
            Log::error('Site URL reveal failed', [
                'site_id' => $site,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message(
                    $e,
                    'Could not save that website address — try again so it stays visible after refresh'
                ),
            ], 500);
        }
    }

    /**
     * One eye unlocks name + rooted URL together (hide-mode dual mask).
     *
     * @return array{success: bool, url: string, rooted_url: string, name: string, sticky: bool}
     */
    private function identityPayload(SiteUrlVisibility $visibility, Site $model, bool $sticky): array
    {
        $host = $visibility->host($model->site_url);
        $rooted = $visibility->rootedUrl($model->site_url);
        if ($rooted === '') {
            $rooted = 'https://'.$host;
        }

        return [
            'success' => true,
            'url' => $host,
            'rooted_url' => $rooted,
            'name' => (string) $model->site_name,
            'sticky' => $sticky,
        ];
    }
}

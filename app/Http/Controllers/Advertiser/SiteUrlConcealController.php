<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Catalog\SiteUrlVisibility;
use App\Support\UserFacingError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Hides a publisher domain the advertiser has already opened.
 *
 * The disclosure row stays — this only flips their display preference — so a
 * refresh keeps the address masked until they click the eye again.
 */
class SiteUrlConcealController extends Controller
{
    public function __invoke(int $site, SiteUrlVisibility $visibility): JsonResponse
    {
        try {
            $user = auth()->user();
            $model = Site::query()->catalogVisible()->find($site);

            if (! $model) {
                return response()->json([
                    'success' => false,
                    'message' => 'That website is no longer listed.',
                ], 404);
            }

            // Eye conceal exists only in copy-strike hide mode.
            if (! $visibility->inHideMode($user)) {
                return response()->json([
                    'success' => false,
                    'code' => 'hide_mode_only',
                    'message' => 'Show/hide is only available while catalog hide mode is active.',
                ], 403);
            }

            // Staff / the listing's publisher always see the real host; there is
            // nothing useful to "hide" for them in the catalog UI.
            if ($user->isAdmin() || $user->isMarketing() || (int) $model->publisher_id === (int) $user->id) {
                return response()->json($this->maskedPayload($visibility, $user, $model));
            }

            $visibility->ensureSchema();

            if (! $visibility->hasEverSeen($user, $model)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Reveal this address with the eye first — then you can hide it again.',
                ], 422);
            }

            $visibility->conceal($user, $model);

            return response()->json($this->maskedPayload($visibility, $user, $model));
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Could not hide that website address'),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Site URL conceal failed', [
                'site_id' => $site,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Could not hide that website address'),
            ], 500);
        }
    }

    /**
     * @return array{success: bool, masked: string, masked_rooted: string, masked_name: string}
     */
    private function maskedPayload(SiteUrlVisibility $visibility, $user, Site $model): array
    {
        $maskedHost = $visibility->mask($model->site_url);
        $scheme = 'https';
        $raw = trim((string) $model->site_url);
        if (preg_match('#^(https?):#i', $raw, $m) === 1) {
            $scheme = strtolower($m[1]);
        }

        $inHide = $visibility->inHideMode($user);

        return [
            'success' => true,
            'masked' => $maskedHost,
            'masked_rooted' => $scheme.'://'.$maskedHost,
            // Outside hide mode the listing name stays visible; only URL remasks.
            'masked_name' => $inHide
                ? $visibility->maskName($model->site_name)
                : (string) $model->site_name,
        ];
    }
}

<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class SiteClaimController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'website_url' => 'required|url|max:255',
            'website_name' => 'required|string|max:190',
            'proof_message' => 'required|string|min:20|max:3000',
            'contact_email' => 'nullable|email|max:190',
        ]);

        $domain = $this->extractDomain($data['website_url']);
        if (! $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Please enter a valid website URL.',
            ], 422);
        }

        $site = Site::where('domain', $domain)->first();
        if (! $site) {
            return response()->json([
                'success' => false,
                'message' => 'We could not find that website in our catalog. If you own it, add it with “Add New Website” instead.',
            ], 422);
        }

        if ((int) $site->publisher_id === (int) auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You already own this listing.',
            ], 422);
        }

        $pending = SiteClaim::query()
            ->where('site_id', $site->id)
            ->where('claimer_id', auth()->id())
            ->where('status', 'pending')
            ->exists();

        if ($pending) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending claim for this website. We’ll email you after review.',
            ], 422);
        }

        $nameMatches = $this->namesMatch($data['website_name'], (string) $site->site_name);

        $claim = SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => auth()->id(),
            'website_name' => $data['website_name'],
            'website_url' => $data['website_url'],
            'domain' => $domain,
            'name_matches' => $nameMatches,
            'proof_message' => $data['proof_message'],
            'contact_email' => $data['contact_email'] ?? auth()->user()->email,
            'status' => 'pending',
        ]);

        ActivityLogger::log(
            'site.claim_submitted',
            auth()->user()->name.' claimed ownership of '.$site->site_name,
            $site,
            [
                'claim_id' => $claim->id,
                'name_matches' => $nameMatches,
                'provided_name' => $data['website_name'],
            ],
            $site->site_name
        );

        return response()->json([
            'success' => true,
            'message' => $nameMatches
                ? 'Claim submitted. The website name matches our listing — our team will verify ownership and get back to you.'
                : 'Claim submitted. The website name you entered does not exactly match our listing, so we will verify carefully before transferring ownership.',
            'name_matches' => $nameMatches,
        ]);
    }

    private function extractDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower($host));
    }

    private function namesMatch(string $provided, string $listed): bool
    {
        $normalize = static function (string $value): string {
            $value = mb_strtolower(trim($value));
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;

            return $value;
        };

        return $normalize($provided) === $normalize($listed);
    }
}

<?php

namespace App\Services\Mcp;

use App\Models\AdvertiserApiToken;
use App\Models\McpOAuthAccessToken;
use App\Models\User;
use Illuminate\Http\Request;

class McpCustomerAuth
{
    /**
     * @return array{user: User, via: string}|null
     */
    public function authenticate(Request $request): ?array
    {
        $plain = $this->bearer($request);
        if ($plain === '') {
            return null;
        }

        $personal = AdvertiserApiToken::findByPlain($plain);
        if ($personal !== null && $personal->user !== null) {
            $personal->markUsed();

            return ['user' => $personal->user, 'via' => 'personal'];
        }

        $oauth = McpOAuthAccessToken::findUsableAccess($plain);
        if ($oauth !== null && $oauth->user !== null) {
            $oauth->markUsed();

            return ['user' => $oauth->user, 'via' => 'oauth'];
        }

        return null;
    }

    public function advertiserReady(?User $user): ?string
    {
        if ($user === null) {
            return 'Invalid or missing access token.';
        }

        if ($user->isSuspended()) {
            return 'This account is suspended.';
        }

        if ($user->email_verified_at === null) {
            return 'Verify your email before using SEOLinkBuildings MCP.';
        }

        if (! $user->hasRole('advertiser')) {
            return 'MCP is available for advertiser accounts only.';
        }

        return null;
    }

    public function bearer(Request $request): string
    {
        $header = trim((string) $request->header('Authorization', ''));
        if (preg_match('/^Bearer\s+(\S+)/i', $header, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}

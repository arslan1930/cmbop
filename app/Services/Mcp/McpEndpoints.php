<?php

namespace App\Services\Mcp;

class McpEndpoints
{
    public function customerPath(): string
    {
        return '/'.ltrim((string) config('mcp.customer_path', '/api/mcp/customer'), '/');
    }

    public function ssePath(): string
    {
        return '/'.ltrim((string) config('mcp.customer_sse_path', '/api/mcp/customer/sse'), '/');
    }

    public function customerUrl(): string
    {
        return url($this->customerPath());
    }

    public function sseUrl(): string
    {
        return url($this->ssePath());
    }

    public function issuer(): string
    {
        $configured = config('mcp.authorization_server');

        return is_string($configured) && $configured !== ''
            ? rtrim($configured, '/')
            : rtrim(url('/'), '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function protectedResourceMetadata(?string $path = null): array
    {
        $resource = $path !== null && $path !== ''
            ? url('/'.ltrim($path, '/'))
            : $this->customerUrl();

        return [
            'resource' => $resource,
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => ['mcp:use'],
            'bearer_methods_supported' => ['header'],
            'resource_documentation' => url('/advertiser/api'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function authorizationServerMetadata(): array
    {
        return [
            'issuer' => $this->issuer(),
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/oauth/token'),
            'registration_endpoint' => url('/oauth/register'),
            'revocation_endpoint' => url('/oauth/revoke'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => ['mcp:use'],
        ];
    }
}

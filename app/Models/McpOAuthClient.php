<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class McpOAuthClient extends Model
{
    protected $table = 'mcp_oauth_clients';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'redirect_uris',
        'token_endpoint_auth_method',
        'grant_types',
        'logo_uri',
        'client_uri',
    ];

    protected $casts = [
        'redirect_uris' => 'array',
        'grant_types' => 'array',
    ];

    /**
     * @param  list<string>  $redirectUris
     */
    public static function register(string $name, array $redirectUris, ?string $logoUri = null, ?string $clientUri = null): self
    {
        return static::query()->create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'redirect_uris' => array_values($redirectUris),
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'logo_uri' => $logoUri,
            'client_uri' => $clientUri,
        ]);
    }

    public function allowsRedirect(string $uri): bool
    {
        $uri = trim($uri);

        return in_array($uri, $this->redirect_uris ?? [], true);
    }
}

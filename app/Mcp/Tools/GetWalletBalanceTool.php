<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_wallet_balance')]
#[Title('Get wallet balance')]
#[Description('The signed-in advertiser\'s EUR wallet (spendable, reserved, bonus), plus native publisher_wallets when the account also publishes.')]
#[IsReadOnly]
class GetWalletBalanceTool extends Tool
{
    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Sign in as an advertiser to read the wallet.');
        }

        $roleId = Wallet::advertiserRoleId();
        $wallet = $roleId
            ? Wallet::query()->where('user_id', $user->id)->where('role_id', $roleId)->first()
            : null;

        $snapshot = $wallet?->roleSnapshot() ?? Wallet::emptyRoleSnapshot();
        $publisherWallets = [];
        try {
            foreach (Wallet::forPublisherAll((int) $user->id) as $publisherWallet) {
                $publisherSnapshot = $publisherWallet->roleSnapshot();
                $publisherWallets[] = [
                    'currency' => strtoupper((string) ($publisherWallet->currency ?: 'EUR')) ?: 'EUR',
                    'spendable' => $publisherSnapshot['spendable'],
                    'reserved' => $publisherSnapshot['reserved'],
                    'bonus' => $publisherSnapshot['bonus'],
                    'withdrawable' => $publisherSnapshot['withdrawable'],
                ];
            }
        } catch (\Throwable) {
            $publisherWallets = [];
        }

        return Response::json([
            'currency' => 'EUR',
            'spendable' => $snapshot['spendable'],
            'reserved' => $snapshot['reserved'],
            'bonus' => $snapshot['bonus'],
            'withdrawable' => $snapshot['withdrawable'],
            'publisher_wallets' => $publisherWallets,
        ]);
    }
}

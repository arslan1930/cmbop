<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Models\Wallet;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_wallet_balance')]
#[Title('Get wallet balance')]
#[Description('The signed-in advertiser\'s EUR wallet only (spendable, reserved, bonus). Never returns publisher payout wallets.')]
#[IsReadOnly]
class GetWalletBalanceTool extends Tool
{
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

        return Response::json([
            'currency' => 'EUR',
            'spendable' => $snapshot['spendable'],
            'reserved' => $snapshot['reserved'],
            'bonus' => $snapshot['bonus'],
            'withdrawable' => $snapshot['withdrawable'],
        ]);
    }
}

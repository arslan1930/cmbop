<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvertiserWalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $roleId = Wallet::advertiserRoleId();
        $wallet = $user && $roleId
            ? Wallet::query()->where('user_id', $user->id)->where('role_id', $roleId)->first()
            : null;
        $snapshot = $wallet?->roleSnapshot() ?? Wallet::emptyRoleSnapshot();

        return response()->json([
            'status' => 'ok',
            'currency' => 'EUR',
            'spendable' => $snapshot['spendable'],
            'reserved' => $snapshot['reserved'],
            'bonus' => $snapshot['bonus'],
            'withdrawable' => $snapshot['withdrawable'],
        ]);
    }
}

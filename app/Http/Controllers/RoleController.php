<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Role;

class RoleController extends Controller
{
    /**
     * Switch the active role for the logged-in user
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function switchRole(Request $request)
    {
        $request->validate([
            'active_role_id' => 'required|exists:roles,id',
        ]);

        $user = auth()->user();

        // Ensure user actually has this role
        if (!$user->roles->pluck('id')->contains($request->active_role_id)) {
            return back()->with('error', 'You cannot switch to this role.');
        }

        // Update active_role_id
        $user->active_role_id = $request->active_role_id;
        $user->save();

        // Optional: load the active wallet
        $activeWallet = $user->activeWallet();

        // Success message
        return redirect($user->getDashboardRoute())
    ->with('success', 'Role switched to ' . $user->activeRole());
    }
}
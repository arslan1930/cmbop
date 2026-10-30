<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbandonedCartSetting;
use App\Services\ActivityLogger;
use App\Services\Reminders\AbandonedCartReminderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AbandonedCartController extends Controller
{
    public function index(AbandonedCartReminderService $reminders): View
    {
        $tableReady = $reminders->cartsReady();
        $settingsReady = true;
        try {
            AbandonedCartSetting::ensureTable();
            $settingsReady = Schema::hasTable((new AbandonedCartSetting)->getTable());
        } catch (\Throwable) {
            $settingsReady = false;
        }

        $enabled = false;
        try {
            $enabled = $reminders->isEnabled();
        } catch (\Throwable) {
            $enabled = false;
        }

        $counts = ['carts' => 0, 'items' => 0, 'stage_1' => 0, 'stage_2' => 0];
        $rows = collect();
        try {
            $counts = $reminders->adminCounts();
            $rows = $reminders->adminRows();
        } catch (\Throwable $e) {
            Log::warning('Abandoned cart admin list failed', ['error' => $e->getMessage()]);
        }

        return view('admin.abandoned-carts.index', [
            'enabled' => $enabled,
            'tableReady' => $tableReady,
            'settingsReady' => $settingsReady,
            'counts' => $counts,
            'rows' => $rows,
            'stage1Hours' => (int) config('reminders.abandoned_cart.stage_1_hours', 24),
            'stage2Hours' => (int) config('reminders.abandoned_cart.stage_2_hours', 72),
        ]);
    }

    public function toggle(Request $request, AbandonedCartReminderService $reminders): RedirectResponse
    {
        try {
            AbandonedCartSetting::ensureTable();
            if (! Schema::hasTable((new AbandonedCartSetting)->getTable())) {
                return back()->with('error', 'Abandoned cart settings are not available yet. Run the SQL/migration first.');
            }
        } catch (\Throwable) {
            return back()->with('error', 'Abandoned cart settings are not available yet. Run the SQL/migration first.');
        }

        $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);
        $enabled = $request->boolean('enabled');
        $already = $reminders->isEnabled() === $enabled;

        try {
            AbandonedCartSetting::setEnabled($enabled, $request->user()?->id);
        } catch (\Throwable $e) {
            Log::warning('Failed to update abandoned cart setting: '.$e->getMessage());

            return back()->with('error', 'Could not update abandoned cart reminders. Please try again.');
        }

        if ($reminders->isEnabled() !== $enabled) {
            return back()->with('error', 'Could not update abandoned cart reminders. Please try again.');
        }

        if (! $already) {
            ActivityLogger::tryLog(
                'abandoned_cart.toggled',
                ($request->user()?->name ?? 'Admin').' '.($enabled ? 'enabled' : 'disabled').' abandoned cart reminders',
                null,
                ['enabled' => $enabled]
            );
        }

        return back()->with('success', $enabled
            ? 'Abandoned cart reminders are on. Stage 1 and 2 emails can send on the hourly schedule.'
            : 'Abandoned cart reminders are off. Nothing will be sent until you turn them on.');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteAnnouncement;
use App\Services\ActivityLogger;
use App\Services\PromotionListQuery;
use App\Support\PromotionUrl;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $announcements = new LengthAwarePaginator([], 0, 20);
        $statusCounts = ['all' => 0, 'live' => 0, 'scheduled' => 0, 'expired' => 0, 'paused' => 0, 'trashed' => 0];

        try {
            if (Schema::hasTable('site_announcements')) {
                $statusCounts = PromotionListQuery::statusCounts(SiteAnnouncement::query());
                $query = SiteAnnouncement::query();
                PromotionListQuery::apply($query, $request, 'title', 'message');
                $announcements = $query->latest('id')->paginate(20)->withQueryString();
            }
        } catch (\Throwable $e) {
            Log::warning('Admin announcements index failed', ['error' => $e->getMessage()]);
        }

        return view('admin.promotions.announcements.index', compact('announcements', 'statusCounts'));
    }

    public function create(Request $request)
    {
        $presetKey = scalar_text($request->query('preset'));
        $presets = config('promotions.featured_notices', []);
        $preset = $presetKey !== '' ? ($presets[$presetKey] ?? null) : null;

        $defaults = [
            'type' => 'limited_offer',
            'style' => 'promo',
            'audience' => 'all',
            'title' => null,
            'message' => null,
            'cta_label' => null,
            'cta_url' => null,
            'is_active' => true,
            'is_dismissible' => true,
            'priority' => 100,
            'starts_at' => now(),
            'ends_at' => null,
        ];

        if ($preset) {
            $defaults['type'] = $presetKey;
            $defaults['style'] = $preset['default_style'] ?? 'info';
            $defaults['title'] = $preset['default_title'] ?? null;
            $defaults['message'] = $preset['default_message'] ?? null;
            $defaults['cta_label'] = $preset['default_cta_label'] ?? null;
            $defaults['cta_url'] = $preset['default_cta_url'] ?? null;
            $defaults['priority'] = $preset['default_priority'] ?? 100;
            $days = (int) ($preset['default_ends_in_days'] ?? 0);
            $defaults['ends_at'] = $days > 0 ? now()->addDays($days) : null;
        }

        return view('admin.promotions.announcements.form', [
            'announcement' => new SiteAnnouncement($defaults),
            'mode' => 'create',
            'presetKey' => $preset ? $presetKey : null,
            'presetMeta' => $preset,
        ]);
    }

    public function store(Request $request)
    {
        if (! Schema::hasTable('site_announcements')) {
            return redirect()
                ->route(staff_route_prefix().'promotions.index')
                ->with('error', 'Announcements are unavailable until the database migration has been run.');
        }

        $data = $this->validated($request);
        $data['created_by'] = auth()->id();
        if ($this->announcementsHaveColumn('version')) {
            $data['version'] = 1;
        } else {
            unset($data['version']);
        }

        $announcement = SiteAnnouncement::create($data);
        $this->log('announcement.created', $announcement, 'created announcement');

        return redirect()
            ->route(staff_route_prefix().'promotions.announcements.index')
            ->with('success', 'Announcement created.');
    }

    public function edit(SiteAnnouncement $announcement)
    {
        return view('admin.promotions.announcements.form', [
            'announcement' => $announcement,
            'mode' => 'edit',
            'presetKey' => null,
            'presetMeta' => null,
        ]);
    }

    public function update(Request $request, SiteAnnouncement $announcement)
    {
        $data = $this->validated($request);
        $contentChanged = $announcement->title !== $data['title']
            || $announcement->message !== $data['message']
            || $announcement->type !== $data['type']
            || $announcement->style !== $data['style']
            || $announcement->audience !== $data['audience']
            || (bool) $announcement->is_dismissible !== (bool) $data['is_dismissible']
            || (string) $announcement->cta_label !== (string) ($data['cta_label'] ?? null)
            || (string) $announcement->cta_url !== (string) ($data['cta_url'] ?? null);

        if (($contentChanged || $request->boolean('reset_dismissals'))
            && $this->announcementsHaveColumn('version')) {
            $data['version'] = ((int) $announcement->version ?: 1) + 1;
        }

        $announcement->update($data);
        $this->log('announcement.updated', $announcement, 'updated announcement');

        return redirect()
            ->route(staff_route_prefix().'promotions.announcements.index')
            ->with('success', 'Announcement updated.');
    }

    public function destroy(SiteAnnouncement $announcement)
    {
        $id = (int) $announcement->id;
        $title = $announcement->title;
        $announcement->delete();
        $this->log('announcement.deleted', $announcement, 'deleted announcement');

        session()->put('promotions_undo', [
            'type' => 'announcement',
            'id' => $id,
            'until' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()
            ->route(staff_route_prefix().'promotions.announcements.index')
            ->with('success', 'Announcement “'.$title.'” deleted.');
    }

    public function restore(int $id)
    {
        try {
            if (! Schema::hasTable('site_announcements')) {
                return back()->with('error', 'Announcement could not be restored.');
            }

            $model = SiteAnnouncement::withTrashed()->findOrFail($id);
        } catch (\Throwable) {
            return back()->with('error', 'Announcement could not be restored.');
        }

        if (! $model->restore()) {
            return back()->with('error', 'Announcement could not be restored.');
        }

        $this->log('announcement.restored', $model, 'restored announcement');
        session()->forget('promotions_undo');

        return back()->with('success', 'Announcement restored.');
    }

    public function toggle(SiteAnnouncement $announcement)
    {
        $announcement->update(['is_active' => ! $announcement->is_active]);
        $this->log('announcement.toggled', $announcement, $announcement->is_active ? 'activated announcement' : 'paused announcement');

        return back()->with('success', $announcement->is_active ? 'Announcement activated.' : 'Announcement paused.');
    }

    public function duplicate(SiteAnnouncement $announcement)
    {
        $copy = $announcement->replicate([
            'impressions', 'clicks', 'deleted_at',
        ]);
        $copy->title = $announcement->title.' (copy)';
        $copy->is_active = false;
        if ($this->announcementsHaveColumn('version')) {
            $copy->version = 1;
        }
        if ($this->announcementsHaveColumn('clicks')) {
            $copy->clicks = 0;
        }
        $copy->created_by = auth()->id();
        $copy->save();
        $this->log('announcement.duplicated', $copy, 'duplicated announcement', ['source_id' => $announcement->id]);

        return redirect()
            ->route(staff_route_prefix().'promotions.announcements.edit', $copy)
            ->with('success', 'Announcement duplicated. Review and activate when ready.');
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:2000'],
            'type' => ['required', Rule::in(array_keys(config('promotions.announcement_types', [])))],
            'style' => ['required', Rule::in(array_keys(config('promotions.announcement_styles', [])))],
            'audience' => ['required', Rule::in(array_keys(config('promotions.audiences', [])))],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'string', 'max:500', PromotionUrl::rule()],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', Rule::when($request->filled('starts_at'), ['after_or_equal:starts_at'])],
            'is_active' => ['sometimes', 'boolean'],
            'is_dismissible' => ['sometimes', 'boolean'],
            'reset_dismissals' => ['sometimes', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['is_dismissible'] = $request->boolean('is_dismissible');
        $data['priority'] = (int) ($data['priority'] ?? 100);
        $data['cta_url'] = PromotionUrl::normalizeForStorage($data['cta_url'] ?? null);
        unset($data['reset_dismissals']);

        return $data;
    }

    private function announcementsHaveColumn(string $column): bool
    {
        try {
            return Schema::hasColumn('site_announcements', $column);
        } catch (\Throwable) {
            return false;
        }
    }

    private function log(string $action, SiteAnnouncement $announcement, string $verb, array $extra = []): void
    {
        try {
            ActivityLogger::log(
                $action,
                (auth()->user()?->name ?? 'Staff').' '.$verb.' "'.$announcement->title.'"',
                $announcement,
                array_merge([
                    'audience' => $announcement->audience,
                    'type' => $announcement->type,
                ], $extra),
                $announcement->title
            );
        } catch (\Throwable $e) {
            Log::warning('Promotion activity log failed', ['error' => $e->getMessage()]);
        }
    }
}

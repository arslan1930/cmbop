<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteAnnouncement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function index()
    {
        $announcements = SiteAnnouncement::query()
            ->latest('id')
            ->paginate(20);

        return view('admin.promotions.announcements.index', compact('announcements'));
    }

    public function create(Request $request)
    {
        $presetKey = $request->query('preset');
        $presets = config('promotions.featured_notices', []);
        $preset = $presets[$presetKey] ?? null;

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
            $ctaUrl = $preset['default_cta_url'] ?? null;
            $defaults['cta_url'] = $ctaUrl ? url($ctaUrl) : null;
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
        $data = $this->validated($request);
        $data['created_by'] = auth()->id();

        SiteAnnouncement::create($data);

        return redirect()
            ->route('admin.promotions.announcements.index')
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
        $announcement->update($this->validated($request));

        return redirect()
            ->route('admin.promotions.announcements.index')
            ->with('success', 'Announcement updated.');
    }

    public function destroy(SiteAnnouncement $announcement)
    {
        $announcement->delete();

        return redirect()
            ->route('admin.promotions.announcements.index')
            ->with('success', 'Announcement deleted.');
    }

    public function toggle(SiteAnnouncement $announcement)
    {
        $announcement->update(['is_active' => !$announcement->is_active]);

        return back()->with('success', $announcement->is_active ? 'Announcement activated.' : 'Announcement paused.');
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
            'cta_url' => ['nullable', 'url', 'max:500'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'is_dismissible' => ['sometimes', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['is_dismissible'] = $request->boolean('is_dismissible');
        $data['priority'] = (int) ($data['priority'] ?? 100);

        return $data;
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdBanner;
use App\Services\ActivityLogger;
use App\Services\PromotionListQuery;
use App\Services\PromotionService;
use App\Support\PromotionUrl;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdBannerController extends Controller
{
    public function index(Request $request)
    {
        $banners = new LengthAwarePaginator([], 0, 20);
        $statusCounts = ['all' => 0, 'live' => 0, 'scheduled' => 0, 'expired' => 0, 'paused' => 0, 'trashed' => 0];

        try {
            if (Schema::hasTable('ad_banners')) {
                $statusCounts = PromotionListQuery::statusCounts(AdBanner::query());
                $query = AdBanner::query();
                PromotionListQuery::apply($query, $request, 'name', 'title');
                $banners = $query->latest('id')->paginate(20)->withQueryString();
            }
        } catch (\Throwable $e) {
            Log::warning('Admin banners index failed', ['error' => $e->getMessage()]);
        }

        return view('admin.promotions.banners.index', compact('banners', 'statusCounts'));
    }

    public function create()
    {
        return view('admin.promotions.banners.form', [
            'banner' => new AdBanner([
                'size_key' => 'medium_rectangle',
                'width' => 300,
                'height' => 250,
                'placement' => 'content_top',
                'audience' => 'all',
                'is_active' => true,
                'open_in_new_tab' => true,
                'priority' => 100,
            ]),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request)
    {
        if (! Schema::hasTable('ad_banners')) {
            return redirect()
                ->route(staff_route_prefix().'promotions.index')
                ->with('error', 'Banners are unavailable until the database migration has been run.');
        }

        $data = $this->validated($request);
        $data['created_by'] = auth()->id();
        $data = $this->applySizeDimensions($data);
        $this->assertImageMatchesPreset($request, $data);
        $data['image_path'] = $this->storeImage($request);

        $banner = AdBanner::create($data);
        $this->log('banner.created', $banner, 'created banner');

        $warning = $this->unwiredWarning($data['placement'] ?? '');

        return redirect()
            ->route(staff_route_prefix().'promotions.banners.index')
            ->with('success', 'Banner created.')
            ->with('warning', $warning);
    }

    public function edit(AdBanner $banner)
    {
        return view('admin.promotions.banners.form', [
            'banner' => $banner,
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, AdBanner $banner)
    {
        $data = $this->validated($request, $banner);
        $data = $this->applySizeDimensions($data);
        $this->assertImageMatchesPreset($request, $data);

        if ($request->hasFile('image')) {
            if ($banner->image_path) {
                Storage::disk('public')->delete($banner->image_path);
            }
            $data['image_path'] = $this->storeImage($request);
        }

        $banner->update($data);
        $this->log('banner.updated', $banner, 'updated banner');

        $warning = $this->unwiredWarning($data['placement'] ?? '');

        return redirect()
            ->route(staff_route_prefix().'promotions.banners.index')
            ->with('success', 'Banner updated.')
            ->with('warning', $warning);
    }

    public function destroy(AdBanner $banner)
    {
        $id = (int) $banner->id;
        $name = $banner->name;
        $banner->delete();
        $this->log('banner.deleted', $banner, 'deleted banner');

        session()->put('promotions_undo', [
            'type' => 'banner',
            'id' => $id,
            'until' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()
            ->route(staff_route_prefix().'promotions.banners.index')
            ->with('success', 'Banner “'.$name.'” deleted.');
    }

    public function restore(int $id)
    {
        $model = AdBanner::withTrashed()->findOrFail($id);
        $model->restore();
        $this->log('banner.restored', $model, 'restored banner');
        session()->forget('promotions_undo');

        return back()->with('success', 'Banner restored.');
    }

    public function toggle(AdBanner $banner)
    {
        $banner->update(['is_active' => ! $banner->is_active]);
        $this->log('banner.toggled', $banner, $banner->is_active ? 'activated banner' : 'paused banner');

        return back()->with('success', $banner->is_active ? 'Banner activated.' : 'Banner paused.');
    }

    public function duplicate(AdBanner $banner)
    {
        $copy = $banner->replicate(['impressions', 'clicks', 'deleted_at']);
        $copy->name = $banner->name.' (copy)';
        $copy->is_active = false;
        $copy->impressions = 0;
        $copy->clicks = 0;
        $copy->created_by = auth()->id();

        if ($banner->image_path && Storage::disk('public')->exists($banner->image_path)) {
            $ext = pathinfo($banner->image_path, PATHINFO_EXTENSION);
            $newPath = 'banners/'.Str::uuid().($ext ? '.'.$ext : '');
            Storage::disk('public')->copy($banner->image_path, $newPath);
            $copy->image_path = $newPath;
        }

        $copy->save();
        $this->log('banner.duplicated', $copy, 'duplicated banner', ['source_id' => $banner->id]);

        return redirect()
            ->route(staff_route_prefix().'promotions.banners.edit', $copy)
            ->with('success', 'Banner duplicated. Review and activate when ready.');
    }

    protected function validated(Request $request, ?AdBanner $banner = null): array
    {
        $requiresImage = ! $banner || (! $banner->image_path && ! $banner->image_url);
        $mimes = implode(',', config('promotions.banner_mimes', ['jpeg', 'png', 'jpg', 'gif', 'webp']));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:160'],
            'alt_text' => ['nullable', 'string', 'max:160'],
            'size_key' => ['required', Rule::in(array_keys(config('promotions.banner_sizes', [])))],
            'width' => ['nullable', 'integer', 'min:20', 'max:2000'],
            'height' => ['nullable', 'integer', 'min:20', 'max:2000'],
            'image' => [$requiresImage && ! $request->filled('image_url') ? 'required' : 'nullable', 'file', 'mimes:'.$mimes, 'max:5120'],
            'image_url' => ['nullable', 'string', 'max:500', PromotionUrl::rule()],
            'link_url' => ['nullable', 'string', 'max:500', PromotionUrl::rule()],
            'placement' => ['required', Rule::in(array_keys(config('promotions.banner_placements', [])))],
            'audience' => ['required', Rule::in(array_keys(config('promotions.audiences', [])))],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', Rule::when($request->filled('starts_at'), ['after_or_equal:starts_at'])],
            'is_active' => ['sometimes', 'boolean'],
            'open_in_new_tab' => ['sometimes', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['open_in_new_tab'] = $request->boolean('open_in_new_tab');
        $data['priority'] = (int) ($data['priority'] ?? 100);
        $data['image_url'] = PromotionUrl::normalizeForStorage($data['image_url'] ?? null);
        $data['link_url'] = PromotionUrl::normalizeForStorage($data['link_url'] ?? null);
        unset($data['image']);

        return $data;
    }

    protected function applySizeDimensions(array $data): array
    {
        $sizes = config('promotions.banner_sizes', []);
        $meta = $sizes[$data['size_key']] ?? null;

        if ($meta && $data['size_key'] !== 'custom') {
            $data['width'] = (int) $meta['width'];
            $data['height'] = (int) $meta['height'];
        } else {
            $data['width'] = (int) ($data['width'] ?? 300);
            $data['height'] = (int) ($data['height'] ?? 250);
        }

        return $data;
    }

    protected function assertImageMatchesPreset(Request $request, array $data): void
    {
        if (! $request->hasFile('image') || ($data['size_key'] ?? '') === 'custom') {
            return;
        }

        $path = $request->file('image')->getRealPath();
        if (! $path) {
            return;
        }

        $info = @getimagesize($path);
        if (! is_array($info) || empty($info[0]) || empty($info[1])) {
            return;
        }

        $expectedW = (int) ($data['width'] ?? 0);
        $expectedH = (int) ($data['height'] ?? 0);
        if ($expectedW < 1 || $expectedH < 1) {
            return;
        }

        $tolerance = (float) config('promotions.banner_dimension_tolerance', 0.10);
        $wOk = abs($info[0] - $expectedW) <= ($expectedW * $tolerance);
        $hOk = abs($info[1] - $expectedH) <= ($expectedH * $tolerance);
        if ($wOk && $hOk) {
            return;
        }

        throw ValidationException::withMessages([
            'image' => 'This file is '.$info[0].'×'.$info[1].'; the '
                .($data['size_key'] ?? 'selected').' slot is '.$expectedW.'×'.$expectedH
                .'. Use Custom size or a matching asset.',
        ]);
    }

    protected function storeImage(Request $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        return $request->file('image')->store('banners', 'public');
    }

    protected function unwiredWarning(string $placement): ?string
    {
        if ($placement === '' || app(PromotionService::class)->placementIsWired($placement)) {
            return null;
        }

        return 'This placement is not mounted on any layout. The banner will not appear.';
    }

    private function log(string $action, AdBanner $banner, string $verb, array $extra = []): void
    {
        try {
            ActivityLogger::log(
                $action,
                (auth()->user()?->name ?? 'Staff').' '.$verb.' "'.$banner->name.'"',
                $banner,
                array_merge([
                    'audience' => $banner->audience,
                    'placement' => $banner->placement,
                ], $extra),
                $banner->name
            );
        } catch (\Throwable $e) {
            Log::warning('Promotion activity log failed', ['error' => $e->getMessage()]);
        }
    }
}

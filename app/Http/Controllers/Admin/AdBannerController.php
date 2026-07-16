<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdBanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdBannerController extends Controller
{
    public function index()
    {
        $banners = AdBanner::query()
            ->latest('id')
            ->paginate(20);

        return view('admin.promotions.banners.index', compact('banners'));
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
        $data = $this->validated($request);
        $data['created_by'] = auth()->id();
        $data = $this->applySizeDimensions($data);
        $data['image_path'] = $this->storeImage($request);

        AdBanner::create($data);

        return redirect()
            ->route('admin.promotions.banners.index')
            ->with('success', 'Banner created.');
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

        if ($request->hasFile('image')) {
            if ($banner->image_path) {
                Storage::disk('public')->delete($banner->image_path);
            }
            $data['image_path'] = $this->storeImage($request);
        }

        $banner->update($data);

        return redirect()
            ->route('admin.promotions.banners.index')
            ->with('success', 'Banner updated.');
    }

    public function destroy(AdBanner $banner)
    {
        if ($banner->image_path) {
            Storage::disk('public')->delete($banner->image_path);
        }
        $banner->delete();

        return redirect()
            ->route('admin.promotions.banners.index')
            ->with('success', 'Banner deleted.');
    }

    public function toggle(AdBanner $banner)
    {
        $banner->update(['is_active' => !$banner->is_active]);

        return back()->with('success', $banner->is_active ? 'Banner activated.' : 'Banner paused.');
    }

    protected function validated(Request $request, ?AdBanner $banner = null): array
    {
        $requiresImage = !$banner || (!$banner->image_path && !$banner->image_url);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:160'],
            'alt_text' => ['nullable', 'string', 'max:160'],
            'size_key' => ['required', Rule::in(array_keys(config('promotions.banner_sizes', [])))],
            'width' => ['nullable', 'integer', 'min:20', 'max:2000'],
            'height' => ['nullable', 'integer', 'min:20', 'max:2000'],
            'image' => [$requiresImage && !$request->filled('image_url') ? 'required' : 'nullable', 'file', 'mimes:jpeg,png,jpg,gif,webp,svg', 'max:5120'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'link_url' => ['nullable', 'url', 'max:500'],
            'placement' => ['required', Rule::in(array_keys(config('promotions.banner_placements', [])))],
            'audience' => ['required', Rule::in(array_keys(config('promotions.audiences', [])))],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'open_in_new_tab' => ['sometimes', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['open_in_new_tab'] = $request->boolean('open_in_new_tab');
        $data['priority'] = (int) ($data['priority'] ?? 100);
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

    protected function storeImage(Request $request): ?string
    {
        if (!$request->hasFile('image')) {
            return null;
        }

        return $request->file('image')->store('banners', 'public');
    }
}

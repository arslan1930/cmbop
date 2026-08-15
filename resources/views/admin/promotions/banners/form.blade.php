@extends(staff_layout())

@section('content')
<link rel="stylesheet" href="{{ asset('assets/css/promotions.css') }}">
<div class="container-fluid" style="max-width: 1100px;">
    <div class="mb-4">
        <a href="{{ staff_route('promotions.banners.index') }}" class="text-decoration-none small text-muted">
            <i class="fa fa-arrow-left me-1"></i> Back to banners
        </a>
        <h1 class="h3 mb-1 mt-2">{{ $mode === 'create' ? 'New Ad Banner' : 'Edit Ad Banner' }}</h1>
        <p class="text-muted mb-0">Choose a size that fits your website slot, then upload the creative.</p>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ scalar_text($error) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="bannerForm"
                          action="{{ $mode === 'create' ? staff_route('promotions.banners.store') : staff_route('promotions.banners.update', $banner) }}">
                        @csrf
                        @if($mode === 'edit')
                            @method('PUT')
                        @endif

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Internal name</label>
                                <input type="text" name="name" class="form-control" value="{{ old_text('name', $banner->name) }}" required maxlength="120" placeholder="BF25 marketplace rectangle">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Display title (optional)</label>
                                <input type="text" name="title" id="banner_title" class="form-control" value="{{ old_text('title', $banner->title) }}" maxlength="160">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Size preset</label>
                                <select name="size_key" id="size_key" class="form-select" required>
                                    @foreach(config('promotions.banner_sizes') as $key => $size)
                                        <option value="{{ $key }}"
                                            data-width="{{ $size['width'] }}"
                                            data-height="{{ $size['height'] }}"
                                            @selected(old('size_key', $banner->size_key) === $key)>
                                            {{ $size['label'] }}
                                            @if($key !== 'custom') — {{ $size['width'] }}×{{ $size['height'] }} @endif
                                            ({{ $size['hint'] }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3" id="customWidthWrap">
                                <label class="form-label">Width (px)</label>
                                <input type="number" name="width" id="banner_width" class="form-control" value="{{ old_text('width', $banner->width) }}" min="20" max="2000">
                            </div>
                            <div class="col-md-3" id="customHeightWrap">
                                <label class="form-label">Height (px)</label>
                                <input type="number" name="height" id="banner_height" class="form-control" value="{{ old_text('height', $banner->height) }}" min="20" max="2000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Placement</label>
                                <select name="placement" id="banner_placement" class="form-select" required>
                                    @foreach(config('promotions.banner_placements') as $key => $label)
                                        @php $wired = config('promotions.wired_placements.'.$key, []); @endphp
                                        <option value="{{ $key }}" data-wired="{{ is_array($wired) && $wired !== [] ? '1' : '0' }}"
                                            @selected(old('placement', $banner->placement) === $key)>
                                            {{ $label }}@if(!is_array($wired) || $wired === []) — not shown on the site yet @endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Audience</label>
                                <select name="audience" class="form-select" required>
                                    @foreach(config('promotions.audiences') as $key => $label)
                                        <option value="{{ $key }}" @selected(old('audience', $banner->audience) === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Image upload</label>
                                <input type="file" name="image" id="banner_image" class="form-control" accept="image/*">
                                <div class="form-text">JPEG/PNG/WebP/GIF, max 5MB. Dimensions must match the size preset within 10% (or choose Custom). External URLs are not dimension-checked.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Or image URL</label>
                                <input type="text" name="image_url" id="banner_image_url" class="form-control" inputmode="url" value="{{ old_text('image_url', $banner->image_url) }}" placeholder="https://cdn.example.com/banner.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Click-through URL</label>
                                <input type="text" name="link_url" class="form-control" inputmode="url" value="{{ old_text('link_url', $banner->link_url) }}" placeholder="https://… or /advertiser/catalog">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Alt text</label>
                                <input type="text" name="alt_text" class="form-control" value="{{ old_text('alt_text', $banner->alt_text) }}" maxlength="160">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Priority (lower = higher)</label>
                                <input type="number" name="priority" class="form-control" value="{{ old_text('priority', $banner->priority ?? 100) }}" min="1" max="9999">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Starts at</label>
                                <input type="datetime-local" name="starts_at" class="form-control"
                                       value="{{ old_text('starts_at', optional($banner->safeStartsAt())->format('Y-m-d\TH:i')) }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Ends at</label>
                                <input type="datetime-local" name="ends_at" class="form-control"
                                       value="{{ old_text('ends_at', optional($banner->safeEndsAt())->format('Y-m-d\TH:i')) }}">
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                                        @checked(old_checked('is_active', (bool) $banner->is_active))>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="open_in_new_tab" value="1" id="open_in_new_tab"
                                        @checked(old_checked('open_in_new_tab', (bool) $banner->open_in_new_tab))>
                                    <label class="form-check-label" for="open_in_new_tab">Open link in new tab</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">
                                {{ $mode === 'create' ? 'Create banner' : 'Save changes' }}
                            </button>
                            <a href="{{ staff_route('promotions.banners.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        @if($mode === 'edit')
                            <form method="POST" action="{{ staff_route('promotions.banners.duplicate', $banner) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary">Duplicate</button>
                            </form>
                        @endif
                        <a href="{{ staff_route('promotions.preview', [
                            'audience' => $banner->audience === 'all' ? 'public' : $banner->audience,
                            'placement' => $banner->placement,
                        ]) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">Preview slot</a>
                        <a href="{{ url(config('promotions.placement_preview_urls.'.scalar_text($banner->placement), '/')) }}"
                           class="btn btn-outline-secondary" target="_blank" rel="noopener">View on site</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm sticky-below-topbar">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-eye me-2 text-primary"></i>Live preview</strong>
                    <div class="small text-muted">
                        Slot size: <span id="previewSizeLabel">—</span>
                        · Placement: <span id="previewPlacementLabel">—</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="border rounded-3 bg-light p-3 d-flex justify-content-center align-items-center" style="min-height: 220px;">
                        <div class="ad-banner text-center" id="bannerPreviewWrap" style="--ad-w: 300px; --ad-h: 250px;">
                            <img id="bannerPreviewImg"
                                 src="{{ $banner->imageSrc() ?: 'https://placehold.co/300x250/0b6266/ffffff?text=Banner+Preview' }}"
                                 alt="Banner preview"
                                 class="ad-banner__img"
                                 style="background:#ddd;">
                            <div class="ad-banner__caption" id="bannerPreviewCaption">{{ scalar_text($banner->title) }}</div>
                        </div>
                    </div>
                    <div class="small text-muted mt-2" id="bannerPreviewHint">Upload an image or paste a URL to refresh the preview.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const select = document.getElementById('size_key');
    const width = document.getElementById('banner_width');
    const height = document.getElementById('banner_height');
    const placement = document.getElementById('banner_placement');
    const title = document.getElementById('banner_title');
    const imageInput = document.getElementById('banner_image');
    const imageUrl = document.getElementById('banner_image_url');
    const img = document.getElementById('bannerPreviewImg');
    const wrap = document.getElementById('bannerPreviewWrap');
    const caption = document.getElementById('bannerPreviewCaption');
    const sizeLabel = document.getElementById('previewSizeLabel');
    const placementLabel = document.getElementById('previewPlacementLabel');
    const currentSrc = @json($banner->imageSrc());
    let objectUrl = null;

    function syncSize() {
        const opt = select.options[select.selectedIndex];
        const isCustom = select.value === 'custom';
        const w = parseInt(opt.dataset.width || '0', 10);
        const h = parseInt(opt.dataset.height || '0', 10);
        if (!isCustom && w && h) {
            width.value = w;
            height.value = h;
            width.readOnly = true;
            height.readOnly = true;
        } else {
            width.readOnly = false;
            height.readOnly = false;
        }
        refreshPreview();
    }

    function refreshPreview() {
        const w = parseInt(width.value || '300', 10);
        const h = parseInt(height.value || '250', 10);
        wrap.style.setProperty('--ad-w', w + 'px');
        wrap.style.setProperty('--ad-h', h + 'px');
        img.width = w;
        img.height = h;
        sizeLabel.textContent = w + '×' + h + ' px';
        placementLabel.textContent = placement.options[placement.selectedIndex]?.text || '—';
        caption.textContent = title.value.trim();
        caption.style.display = title.value.trim() ? '' : 'none';

        if (objectUrl) {
            img.src = objectUrl;
            return;
        }
        if (imageUrl.value.trim()) {
            img.src = imageUrl.value.trim();
            return;
        }
        if (currentSrc) {
            img.src = currentSrc;
            return;
        }
        img.src = 'https://placehold.co/' + w + 'x' + h + '/0b6266/ffffff?text=' + encodeURIComponent(w + 'x' + h);
    }

    select.addEventListener('change', syncSize);
    [width, height, placement, title, imageUrl].forEach(function (el) {
        el.addEventListener('input', refreshPreview);
        el.addEventListener('change', refreshPreview);
    });
    imageInput.addEventListener('change', function () {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        const file = imageInput.files && imageInput.files[0];
        if (file) {
            objectUrl = URL.createObjectURL(file);
        }
        refreshPreview();
    });

    syncSize();
})();
</script>
@endsection

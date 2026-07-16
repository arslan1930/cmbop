@extends('admin.layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/promotions.css') }}">
<div class="container-fluid" style="max-width: 1100px;">
    <div class="mb-4">
        <a href="{{ route('admin.promotions.announcements.index') }}" class="text-decoration-none small text-muted">
            <i class="fa fa-arrow-left me-1"></i> Back to announcements
        </a>
        <h1 class="h3 mb-1 mt-2">{{ $mode === 'create' ? 'New Announcement' : 'Edit Announcement' }}</h1>
        <p class="text-muted mb-0">Publish discounts, Black Friday offers, or platform change notices.</p>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" id="announcementForm"
                          action="{{ $mode === 'create' ? route('admin.promotions.announcements.store') : route('admin.promotions.announcements.update', $announcement) }}">
                        @csrf
                        @if($mode === 'edit')
                            @method('PUT')
                        @endif

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Title</label>
                                <input type="text" name="title" id="ann_title" class="form-control" value="{{ old('title', $announcement->title) }}" required maxlength="160" placeholder="Black Friday — 25% off guest posts">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Message</label>
                                <textarea name="message" id="ann_message" class="form-control" rows="4" required maxlength="2000" placeholder="Short update shown across the site.">{{ old('message', $announcement->message) }}</textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Type</label>
                                <select name="type" id="ann_type" class="form-select" required>
                                    @foreach(config('promotions.announcement_types') as $key => $meta)
                                        <option value="{{ $key }}" data-icon="{{ $meta['icon'] }}" @selected(old('type', $announcement->type) === $key)>{{ $meta['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Style</label>
                                <select name="style" id="ann_style" class="form-select" required>
                                    @foreach(config('promotions.announcement_styles') as $key => $label)
                                        <option value="{{ $key }}" @selected(old('style', $announcement->style) === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Audience</label>
                                <select name="audience" class="form-select" required>
                                    @foreach(config('promotions.audiences') as $key => $label)
                                        <option value="{{ $key }}" @selected(old('audience', $announcement->audience) === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CTA label (optional)</label>
                                <input type="text" name="cta_label" id="ann_cta_label" class="form-control" value="{{ old('cta_label', $announcement->cta_label) }}" maxlength="80" placeholder="Shop the offer">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CTA URL (optional)</label>
                                <input type="url" name="cta_url" id="ann_cta_url" class="form-control" value="{{ old('cta_url', $announcement->cta_url) }}" maxlength="500" placeholder="https://">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Priority (lower = higher)</label>
                                <input type="number" name="priority" class="form-control" value="{{ old('priority', $announcement->priority ?? 100) }}" min="1" max="9999">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Starts at</label>
                                <input type="datetime-local" name="starts_at" class="form-control"
                                       value="{{ old('starts_at', optional($announcement->starts_at)->format('Y-m-d\TH:i')) }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Ends at</label>
                                <input type="datetime-local" name="ends_at" class="form-control"
                                       value="{{ old('ends_at', optional($announcement->ends_at)->format('Y-m-d\TH:i')) }}">
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                                        @checked(old('is_active', $announcement->is_active))>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_dismissible" value="1" id="is_dismissible"
                                        @checked(old('is_dismissible', $announcement->is_dismissible))>
                                    <label class="form-check-label" for="is_dismissible">Users can dismiss</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">
                                {{ $mode === 'create' ? 'Create announcement' : 'Save changes' }}
                            </button>
                            <a href="{{ route('admin.promotions.announcements.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm sticky-top" style="top: 1rem;">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-eye me-2 text-primary"></i>Live preview</strong>
                    <div class="small text-muted">How this announcement will look on the website.</div>
                </div>
                <div class="card-body">
                    <div class="site-announcements mb-0">
                        <div id="annPreview" class="site-announcement site-announcement--info" role="status">
                            <div class="site-announcement__inner">
                                <div class="site-announcement__icon" aria-hidden="true">
                                    <i id="annPreviewIcon" class="fa fa-bullhorn"></i>
                                </div>
                                <div class="site-announcement__body">
                                    <strong class="site-announcement__title" id="annPreviewTitle">Announcement title</strong>
                                    <span class="site-announcement__message" id="annPreviewMessage">Your message appears here.</span>
                                    <a class="site-announcement__cta" id="annPreviewCta" href="#" style="display:none;">CTA</a>
                                </div>
                                <button type="button" class="site-announcement__dismiss" aria-label="Dismiss" id="annPreviewDismiss">
                                    <i class="fa fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const preview = document.getElementById('annPreview');
    const titleEl = document.getElementById('annPreviewTitle');
    const msgEl = document.getElementById('annPreviewMessage');
    const ctaEl = document.getElementById('annPreviewCta');
    const iconEl = document.getElementById('annPreviewIcon');
    const dismissEl = document.getElementById('annPreviewDismiss');
    const title = document.getElementById('ann_title');
    const message = document.getElementById('ann_message');
    const style = document.getElementById('ann_style');
    const type = document.getElementById('ann_type');
    const ctaLabel = document.getElementById('ann_cta_label');
    const ctaUrl = document.getElementById('ann_cta_url');
    const dismissible = document.getElementById('is_dismissible');

    function refresh() {
        titleEl.textContent = title.value.trim() || 'Announcement title';
        msgEl.textContent = message.value.trim() || 'Your message appears here.';
        preview.className = 'site-announcement site-announcement--' + (style.value || 'info');
        const opt = type.options[type.selectedIndex];
        iconEl.className = 'fa ' + (opt?.dataset?.icon || 'fa-bullhorn');
        const label = ctaLabel.value.trim();
        if (label) {
            ctaEl.style.display = 'inline-block';
            ctaEl.textContent = label;
            ctaEl.href = ctaUrl.value.trim() || '#';
        } else {
            ctaEl.style.display = 'none';
        }
        dismissEl.style.display = dismissible.checked ? '' : 'none';
    }

    ['input', 'change'].forEach(function (evt) {
        [title, message, style, type, ctaLabel, ctaUrl, dismissible].forEach(function (el) {
            el.addEventListener(evt, refresh);
        });
    });
    refresh();
})();
</script>
@endsection

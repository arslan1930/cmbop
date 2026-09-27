<div class="col-md-6" id="site_tag">
    <label class="form-label fw-semibold" for="site_tag_input">Tag</label>
    <select id="site_tag_input" name="site_tag" class="form-select">
        @foreach(\App\Support\SiteTag::staffFormOptions() as $value => $label)
            <option value="{{ $value === '' ? 'none' : $value }}" @selected((old('site_tag', $site->tagValue() ?? 'none') ?: 'none') === ($value === '' ? 'none' : $value))>{{ $label }}</option>
        @endforeach
    </select>
    <div class="form-text">Does not block going live. No tags leaves the listing untagged.</div>
</div>

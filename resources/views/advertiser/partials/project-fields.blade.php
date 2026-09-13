@php
    $nameValue = $nameValue ?? '';
    $urlValue = $urlValue ?? '';
    $fieldId = $fieldId ?? 'project';
@endphp

<div class="mb-3">
    <label class="form-label" for="project-name-{{ $fieldId }}">Project Name</label>
    <input type="text"
           id="project-name-{{ $fieldId }}"
           name="project_name"
           value="{{ $nameValue }}"
           class="form-control"
           maxlength="255"
           pattern="[A-Za-z0-9 \-]+"
           title="Letters, numbers, spaces, and hyphens only"
           required>
    <div class="form-text">Letters, numbers, spaces, and hyphens only.</div>
</div>
<div class="mb-0">
    <label class="form-label" for="project-url-{{ $fieldId }}">Project URL</label>
    <input type="url"
           id="project-url-{{ $fieldId }}"
           name="project_url"
           value="{{ $urlValue }}"
           class="form-control"
           maxlength="255"
           placeholder="https://client.example"
           required>
    <div class="form-text">www and the bare host count as the same site. One project per client website.</div>
</div>

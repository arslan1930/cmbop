@php
    $avatarUser = $user ?? auth()->user();
    $avatarSize = (int) ($size ?? 36);
    $avatarInitial = strtoupper(substr((string) ($avatarUser?->name ?: '?'), 0, 1));
    $avatarUrl = is_string($avatarUser?->avatar ?? null) ? trim((string) $avatarUser->avatar) : '';
    if ($avatarUrl !== '' && ! filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
        $avatarUrl = '';
    }
    $avatarId = 'ua'.($avatarUser?->id ?? '0').'s'.$avatarSize;
    $avatarAlt = trim((string) ($avatarUser?->name ?: 'Account'));
@endphp
@if($avatarUrl !== '')
    <img src="{{ $avatarUrl }}"
         alt="{{ $avatarAlt }}"
         class="rounded-circle"
         width="{{ $avatarSize }}"
         height="{{ $avatarSize }}"
         style="width: {{ $avatarSize }}px; height: {{ $avatarSize }}px; object-fit: cover;"
         loading="lazy"
         referrerpolicy="no-referrer"
         onerror="this.style.display='none'; var el=this.nextElementSibling; if(el){ el.classList.remove('d-none'); el.classList.add('d-flex'); }">
    <div class="rounded-circle text-white d-none justify-content-center align-items-center"
         style="width: {{ $avatarSize }}px; height: {{ $avatarSize }}px; font-weight: 600; background: #5bc4c7;"
         aria-hidden="true">
        {{ $avatarInitial }}
    </div>
@else
    <div class="rounded-circle text-white d-flex justify-content-center align-items-center"
         style="width: {{ $avatarSize }}px; height: {{ $avatarSize }}px; font-weight: 600; background: #5bc4c7;"
         aria-hidden="true">
        {{ $avatarInitial }}
    </div>
@endif

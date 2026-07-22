{{--
  Shared admin page header.
  @var string $title
  @var string|null $subtitle
  @var string|null $actionUrl
  @var string|null $actionLabel
  @var string|null $actionIcon
--}}
@php
    $subtitle = $subtitle ?? null;
    $actionUrl = $actionUrl ?? null;
    $actionLabel = $actionLabel ?? null;
    $actionIcon = $actionIcon ?? 'fa-plus';
@endphp
<div class="admin-page-header d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">{{ $title }}</h1>
        @if($subtitle)
            <p class="text-muted mb-0">{{ $subtitle }}</p>
        @endif
    </div>
    @if($actionUrl && $actionLabel)
        <div>
            <a href="{{ $actionUrl }}" class="btn btn-sm btn-primary">
                <i class="fa {{ $actionIcon }} me-1"></i>{{ $actionLabel }}
            </a>
        </div>
    @endif
</div>

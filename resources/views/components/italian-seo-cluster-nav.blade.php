@props([
    'links' => [],
    'current' => null,
    'title' => 'Pagine italiane',
])

@if(!empty($links))
<nav class="mb-4" aria-label="{{ $title }}">
    <p class="small text-muted mb-2">{{ $title }}</p>
    <ul class="list-unstyled d-flex flex-wrap gap-2 mb-0">
        @foreach($links as $link)
            <li>
                @if(($link['slug'] ?? '') === $current)
                    <span class="badge rounded-pill" style="background:#1a585e;">{{ $link['label'] ?? '' }}</span>
                @else
                    <a href="{{ $link['url'] ?? '#' }}" class="badge rounded-pill text-decoration-none" style="background:rgba(26,88,94,0.12); color:#1a585e;">{{ $link['label'] ?? '' }}</a>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
@endif

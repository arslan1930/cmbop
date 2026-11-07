@php
    $placements = $placements ?? [];
    $missingLabel = $missingLabel ?? 'Not submitted yet';
    $urlHeading = $urlHeading ?? 'Live article URL';
@endphp
@foreach($placements as $placement)
@php
    $siteLabel = (string) ($placement['site_label'] ?? $placement['site_name'] ?? 'Website');
    $liveUrl = $placement['live_url'] ?? null;
@endphp
<p style="margin:16px 0 4px;color:#1a585e;font-size:14px;font-weight:600;">Website</p>
<p style="margin:0 0 12px;color:#334155;font-size:15px;line-height:1.5;">{{ $siteLabel }}</p>
<p style="margin:0 0 4px;color:#1a585e;font-size:14px;font-weight:600;">{{ $urlHeading }}</p>
@if(filled($liveUrl))
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;word-break:break-all;overflow-wrap:anywhere;">
<a class="mail-url" href="{{ $liveUrl }}" style="color:#1a585e;word-break:break-all;overflow-wrap:anywhere;">{{ $liveUrl }}</a>
</p>
@else
<p style="margin:0 0 16px;color:#64748b;font-size:15px;line-height:1.5;">{{ $missingLabel }}</p>
@endif
@endforeach

@php
    $rows = $rows ?? [];
@endphp
@if(!empty($rows))
<table class="mail-summary" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;margin:16px 0 8px;width:100%;">
@foreach($rows as $row)
@php
    $label = (string) ($row['label'] ?? '');
    $value = (string) ($row['value'] ?? '—');
    $url = $row['url'] ?? null;
@endphp
<tr>
<td class="mail-summary-label" valign="top" style="width:38%;padding:10px 14px 10px 0;color:#1a585e;font-size:14px;font-weight:600;line-height:1.4;vertical-align:top;border-bottom:1px solid #e4e4e7;">{{ $label }}</td>
<td class="mail-summary-value" valign="top" style="padding:10px 0;color:#334155;font-size:14px;line-height:1.5;vertical-align:top;border-bottom:1px solid #e4e4e7;word-break:break-word;overflow-wrap:anywhere;">
@if(filled($url))
<a class="mail-url" href="{{ $url }}" style="color:#1a585e;word-break:break-all;overflow-wrap:anywhere;">{{ $value }}</a>
@else
{{ $value }}
@endif
</td>
</tr>
@endforeach
</table>
@endif

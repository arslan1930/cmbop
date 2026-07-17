{{--
  Reusable NotificationCard — shared by dropdown (JS twin) and /notifications/all
  Props:
    $notification  InAppNotification model OR array from toApiArray()
    $as            'button'|'a'|'div' (default a if url else div)
    $showTools     bool — archive/delete tools (dropdown only)
--}}
@php
    $item = is_array($notification)
        ? $notification
        : $notification->toApiArray();

    $isUnread = !empty($item['is_unread']);
    $url = $item['action_url'] ?? null;
    $as = $as ?? ($url ? 'a' : 'div');
    $showTools = $showTools ?? false;
    $icon = $item['icon'] ?? 'bell';
    $timeLabel = $item['relative_time']
        ?? (isset($notification) && !is_array($notification) && $notification->created_at
            ? $notification->created_at->diffForHumans()
            : '');
    if (!$timeLabel && !empty($item['created_at'])) {
        try {
            $timeLabel = \Illuminate\Support\Carbon::parse($item['created_at'])->diffForHumans();
        } catch (\Throwable $e) {
            $timeLabel = '';
        }
    }

    $tag = in_array($as, ['a', 'button', 'div'], true) ? $as : 'div';
    $classes = 'nc-item' . ($isUnread ? ' is-unread' : '');
@endphp

@if($tag === 'a')
<a href="{{ $url ?: '#' }}"
   class="{{ $classes }}"
   @if(!empty($item['id'])) data-nc-id="{{ $item['id'] }}" @endif
   @if($url) data-nc-url="{{ $url }}" @endif
   @isset($onclick) onclick="{{ $onclick }}" @endisset>
@elseif($tag === 'button')
<button type="button"
        class="{{ $classes }}"
        @if(!empty($item['id'])) data-nc-id="{{ $item['id'] }}" @endif
        @if($url) data-nc-url="{{ $url }}" @endif>
@else
<div class="{{ $classes }}"
     @if(!empty($item['id'])) data-nc-id="{{ $item['id'] }}" @endif>
@endif

    <div class="nc-icon" aria-hidden="true">
        @include('partials.notification-icon', ['name' => $icon])
    </div>

    <div class="nc-item-main">
        <p class="nc-item-title">{{ $item['title'] ?? '' }}</p>
        @if(!empty($item['message']))
            <p class="nc-item-msg">{{ $item['message'] }}</p>
        @endif
        <div class="nc-item-meta">
            @if($timeLabel)
                <span class="nc-item-time">{{ $timeLabel }}</span>
            @endif
            @if($url)
                <span class="nc-item-action">{{ $item['action_label'] ?? 'View details' }} →</span>
            @endif
        </div>
    </div>

    <div class="nc-item-aside">
        <span class="nc-dot pulse-badge {{ $isUnread ? 'is-pulsing' : '' }}" aria-hidden="true"></span>
        @if($showTools)
            <div class="nc-item-tools">
                @if($isUnread)
                    <span class="nc-tool" data-nc-tool="read" data-id="{{ $item['id'] }}">Read</span>
                @endif
                <span class="nc-tool" data-nc-tool="archive" data-id="{{ $item['id'] }}">Archive</span>
                <span class="nc-tool" data-nc-tool="delete" data-id="{{ $item['id'] }}">Delete</span>
            </div>
        @endif
    </div>

@if($tag === 'a')
</a>
@elseif($tag === 'button')
</button>
@else
</div>
@endif

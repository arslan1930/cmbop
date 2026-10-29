{{--
  Reusable NotificationCard — shared by dropdown (JS twin) and /notifications/all
  Props:
    $notification  array from InAppNotificationPresenter (preferred) OR model/toApiArray()
    $showTools     bool — kebab menu tools
--}}
@php
    $item = is_array($notification)
        ? $notification
        : (method_exists($notification, 'toApiArray') ? $notification->toApiArray() : []);

    $isUnread = !empty($item['is_unread']);
    $actionNeeded = !empty($item['action_needed']);
    $isGroup = !empty($item['is_group']);
    $url = $item['action_url'] ?? null;
    $liveUrl = $item['live_url'] ?? null;
    $showTools = $showTools ?? false;
    $icon = $item['icon'] ?? 'bell';
    $favicon = $item['favicon_url'] ?? null;
    $timeLabel = $item['display_time']
        ?? $item['clock_time']
        ?? $item['relative_time']
        ?? '';
    if (!$timeLabel && !empty($item['created_at'])) {
        try {
            $dt = \Illuminate\Support\Carbon::parse($item['created_at']);
            $timeLabel = $dt->isToday()
                ? $dt->diffForHumans(null, true).' ago'
                : $dt->format('g:i A');
        } catch (\Throwable $e) {
            $timeLabel = '';
        }
    }

    $classes = 'nc-item'
        . ($isUnread ? ' is-unread' : '')
        . ($actionNeeded ? ' is-action-needed' : '')
        . ($isGroup ? ' is-group' : '');
    $primaryId = $item['id'] ?? ($item['event_ids'][0] ?? null);
    $eventIdsAttr = !empty($item['event_ids']) ? implode(',', $item['event_ids']) : (string) ($primaryId ?? '');
@endphp

<div class="{{ $classes }}"
     @if($primaryId) data-nc-id="{{ $primaryId }}" @endif
     @if($eventIdsAttr !== '') data-nc-ids="{{ $eventIdsAttr }}" @endif
     @if($url) data-nc-url="{{ $url }}" @endif>

    <div class="nc-icon" aria-hidden="true">
        @if($favicon)
            <img src="{{ $favicon }}" alt="" class="nc-favicon" width="18" height="18" loading="lazy" decoding="async" data-nc-favicon>
            <span class="nc-icon-fallback" hidden>@include('partials.notification-icon', ['name' => $icon])</span>
        @else
            @include('partials.notification-icon', ['name' => $icon])
        @endif
    </div>

    <div class="nc-item-main">
        <div class="nc-item-title-row">
            <p class="nc-item-title">{{ $item['title'] ?? '' }}</p>
            @if($actionNeeded)
                <span class="nc-action-tag">Action needed</span>
            @endif
        </div>
        @if(!empty($item['message']))
            <p class="nc-item-msg">{{ $item['message'] }}</p>
        @endif
        @if(!empty($item['auto_approve_label']))
            <p class="nc-auto-approve">{{ $item['auto_approve_label'] }}</p>
        @endif
        @if(!empty($item['lifecycle_stepper']))
            @include('partials.order-lifecycle-stepper', ['stepper' => $item['lifecycle_stepper']])
        @elseif(!empty($item['timeline']) && is_array($item['timeline']))
            @include('partials.order-lifecycle-stepper', ['stepper' => ['steps' => $item['timeline'], 'current' => 0]])
        @endif
        @if($isGroup && !empty($item['events']) && count($item['events']) > 1)
            <details class="nc-group-details">
                <summary>{{ count($item['events']) }} updates</summary>
                <ul class="nc-group-events">
                    @foreach($item['events'] as $event)
                        <li>
                            <strong>{{ $event['title'] ?? '' }}</strong>
                            @if(!empty($event['display_time']) || !empty($event['clock_time']))
                                <span class="nc-item-time">{{ $event['display_time'] ?? $event['clock_time'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
        <div class="nc-item-meta">
            @if($timeLabel)
                <span class="nc-item-time">{{ $timeLabel }}</span>
            @endif
            @if($isUnread)
                <span class="nc-item-state">Unread</span>
            @elseif(!empty($item['is_archived']))
                <span class="nc-item-state is-archived">Archived</span>
            @endif
            @if($url)
                <a class="nc-item-action" href="{{ $url }}" data-nc-primary-action
                   @isset($onclick) onclick="{{ $onclick }}" @endisset>{{ $item['action_label'] ?? 'View details' }} →</a>
            @endif
            @if($liveUrl)
                <a class="nc-open-link" href="{{ $liveUrl }}" target="_blank" rel="noopener noreferrer">Open link</a>
            @endif
        </div>
    </div>

    <div class="nc-item-aside">
        <span class="nc-dot pulse-badge {{ $isUnread ? 'is-pulsing' : '' }}" aria-hidden="true"></span>
        @if($showTools && $primaryId)
            <div class="nc-kebab" data-nc-kebab>
                <button type="button"
                        class="nc-kebab-btn"
                        data-nc-kebab-toggle
                        aria-haspopup="menu"
                        aria-expanded="false"
                        aria-label="Notification actions">
                    <span aria-hidden="true">⋯</span>
                </button>
                <div class="nc-kebab-menu" role="menu" hidden>
                    @if($isUnread)
                        <button type="button" class="nc-kebab-item" role="menuitem" data-nc-tool="read" data-id="{{ $primaryId }}">Mark as read</button>
                    @elseif(empty($item['is_archived']))
                        <button type="button" class="nc-kebab-item" role="menuitem" data-nc-tool="unread" data-id="{{ $primaryId }}">Mark as unread</button>
                    @endif
                    <button type="button" class="nc-kebab-item" role="menuitem" data-nc-tool="archive" data-id="{{ $primaryId }}">Archive</button>
                    <button type="button" class="nc-kebab-item nc-kebab-item--danger" role="menuitem" data-nc-tool="delete" data-id="{{ $primaryId }}">Delete</button>
                </div>
            </div>
        @endif
    </div>
</div>

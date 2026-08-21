@php
    $activeTab = $campaignTab ?? 'compose';
    $draftCount = $draftCount ?? 0;
    $sendingCount = $sendingCount ?? 0;
    $sentCount = $sentCount ?? 0;
@endphp
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item" role="presentation">
        <a class="nav-link{{ $activeTab === 'compose' ? ' active' : '' }}" href="{{ route('admin.campaigns.index') }}">Compose</a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link{{ $activeTab === 'drafts' ? ' active' : '' }}" href="{{ route('admin.campaigns.drafts') }}">
            Drafts
            @if($draftCount > 0)
                <span class="badge bg-primary-subtle text-primary ms-1">{{ $draftCount }}</span>
            @endif
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link{{ $activeTab === 'sending' ? ' active' : '' }}" href="{{ route('admin.campaigns.index', ['tab' => 'sending']) }}">
            Sending
            @if($sendingCount > 0)
                <span class="badge bg-primary-subtle text-primary ms-1">{{ $sendingCount }}</span>
            @endif
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link{{ $activeTab === 'sent' ? ' active' : '' }}" href="{{ route('admin.campaigns.index', ['tab' => 'sent']) }}">
            Sent
            @if($sentCount > 0)
                <span class="badge bg-primary-subtle text-primary ms-1">{{ $sentCount }}</span>
            @endif
        </a>
    </li>
</ul>

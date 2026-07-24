{{--
  Role switcher: lists every role the user has except the active one.
  Required because advertisers normally have Advertiser + Publisher; granting
  Marketing makes three roles, and the old firstWhere() switcher never offered Marketing.
--}}
@php
    $switchUser = auth()->user();
    if ($switchUser && ! $switchUser->relationLoaded('roles')) {
        $switchUser->load('roles');
    }
    $otherRoles = $switchUser
        ? $switchUser->roles
            ->filter(fn ($role) => (int) $role->id !== (int) $switchUser->active_role_id)
            ->sortBy(fn ($role) => match ($role->name) {
                'admin' => 1,
                'marketing' => 2,
                'advertiser' => 3,
                'publisher' => 4,
                default => 9,
            })
            ->values()
        : collect();

    $roleLabel = static function (string $name): string {
        return match ($name) {
            'marketing' => 'Marketing',
            'admin' => 'Admin',
            'advertiser' => 'Advertiser',
            'publisher' => 'Publisher',
            default => ucfirst($name),
        };
    };

    $variant = $variant ?? 'outline-primary';
    $size = $size ?? 'sm';
@endphp

@if($otherRoles->isNotEmpty())
    @if($otherRoles->count() === 1)
        @php $only = $otherRoles->first(); @endphp
        <form method="POST" action="{{ route('switch.role') }}" class="role-switch-form d-inline">
            @csrf
            <input type="hidden" name="active_role_id" value="{{ $only->id }}">
            <button type="submit"
                    class="btn btn-{{ $size }} btn-{{ $variant }} role-switch-btn"
                    data-role-name="{{ $roleLabel($only->name) }}">
                Switch to {{ $roleLabel($only->name) }}
            </button>
        </form>
    @else
        <div class="dropdown d-inline-block role-switch-dropdown">
            <button class="btn btn-{{ $size }} btn-{{ $variant }} dropdown-toggle"
                    type="button"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                    aria-label="Switch role">
                Switch role
            </button>
            <ul class="dropdown-menu">
                @foreach($otherRoles as $role)
                    <li>
                        <form method="POST" action="{{ route('switch.role') }}" class="role-switch-form">
                            @csrf
                            <input type="hidden" name="active_role_id" value="{{ $role->id }}">
                            <button type="submit"
                                    class="dropdown-item role-switch-btn"
                                    data-role-name="{{ $roleLabel($role->name) }}">
                                {{ $roleLabel($role->name) }}
                                @if($role->name === 'marketing')
                                    <small class="text-muted d-block">Admin panel · site review</small>
                                @elseif($role->name === 'admin')
                                    <small class="text-muted d-block">Full admin panel</small>
                                @endif
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endif

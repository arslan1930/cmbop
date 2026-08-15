@php
    $undo = session('promotions_undo');
    $undoValid = is_array($undo)
        && isset($undo['type'], $undo['id'], $undo['until'])
        && (int) $undo['until'] >= now()->timestamp;
@endphp
@if($undoValid)
    <div class="alert alert-secondary d-flex flex-wrap justify-content-between align-items-center gap-2" role="status">
        <span>Deleted just now. You can undo for a few minutes.</span>
        <form method="POST"
              action="{{ $undo['type'] === 'banner'
                  ? staff_route('promotions.banners.restore', $undo['id'])
                  : staff_route('promotions.announcements.restore', $undo['id']) }}">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-primary">Undo</button>
        </form>
    </div>
@endif

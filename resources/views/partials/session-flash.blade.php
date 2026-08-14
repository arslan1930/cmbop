{{--
    Shared flash messages. Rendered once per layout above @yield('content'),
    so individual views must not repeat their own session('success'/'error')
    blocks — that produced ~44 inconsistent copies, most without role="alert".

    role="status" for success (polite) and role="alert" for errors (assertive)
    so screen readers announce outcomes without hijacking on every success.
--}}
@php
    $flashSuccess = session_text('success');
    $flashError = session_text('error');
    $flashWarning = session_text('warning');
    $flashInfo = session_text('info');
@endphp

@if($flashSuccess || $flashError || $flashWarning || $flashInfo || $errors->any())
    <div class="slb-flash-stack" data-slb-flash>
        @if($flashSuccess)
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-start gap-2"
                 role="status" aria-live="polite">
                <i class="fa fa-circle-check mt-1" aria-hidden="true"></i>
                <div class="flex-grow-1">{{ $flashSuccess }}</div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss message"></button>
            </div>
        @endif

        @if($flashError)
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-start gap-2"
                 role="alert" aria-live="assertive">
                <i class="fa fa-circle-exclamation mt-1" aria-hidden="true"></i>
                <div class="flex-grow-1">{{ $flashError }}</div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss message"></button>
            </div>
        @endif

        @if($flashWarning)
            <div class="alert alert-warning alert-dismissible fade show d-flex align-items-start gap-2"
                 role="alert" aria-live="assertive">
                <i class="fa fa-triangle-exclamation mt-1" aria-hidden="true"></i>
                <div class="flex-grow-1">{{ $flashWarning }}</div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss message"></button>
            </div>
        @endif

        @if($flashInfo)
            <div class="alert alert-info alert-dismissible fade show d-flex align-items-start gap-2"
                 role="status" aria-live="polite">
                <i class="fa fa-circle-info mt-1" aria-hidden="true"></i>
                <div class="flex-grow-1">{{ $flashInfo }}</div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss message"></button>
            </div>
        @endif

        @if($errors->any() && ! $flashError)
            <div class="alert alert-danger alert-dismissible fade show" role="alert" aria-live="assertive">
                <div class="d-flex align-items-start gap-2">
                    <i class="fa fa-circle-exclamation mt-1" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        <strong>Please fix the following:</strong>
                        <ul class="mb-0 mt-1 ps-3">
                            @foreach($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss message"></button>
                </div>
            </div>
        @endif
    </div>
@endif

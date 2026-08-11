@extends('layouts.app')

@section('title', 'Confirm deposit approval - SEOLinkBuildings')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card shadow">
                <div class="card-body p-4 p-md-5">
                    @if($canApprove)
                        <div class="text-center mb-4">
                            <i class="fa-solid fa-wallet fa-3x text-primary mb-3" aria-hidden="true"></i>
                            <h1 class="h3 mb-2">Confirm deposit approval</h1>
                            <p class="text-muted mb-0">
                                Review the details below. Confirming will credit the advertiser wallet immediately.
                            </p>
                        </div>

                        <dl class="row mb-4">
                            <dt class="col-sm-4 text-muted">Amount</dt>
                            <dd class="col-sm-8 fw-semibold">€{{ number_format((float) $deposit->amount, 2) }}</dd>

                            <dt class="col-sm-4 text-muted">Method</dt>
                            <dd class="col-sm-8">{{ ucfirst((string) $deposit->payment_method) }}</dd>

                            <dt class="col-sm-4 text-muted">Reference</dt>
                            <dd class="col-sm-8"><code>REF{{ $deposit->reference_code }}</code></dd>

                            <dt class="col-sm-4 text-muted">Advertiser</dt>
                            <dd class="col-sm-8">
                                {{ $deposit->user->name ?? 'Unknown' }}
                                @if($deposit->user?->email)
                                    <br><span class="text-muted small">{{ $deposit->user->email }}</span>
                                @endif
                            </dd>

                            <dt class="col-sm-4 text-muted">Requested</dt>
                            <dd class="col-sm-8">{{ optional($deposit->created_at)->format('M d, Y H:i') }}</dd>

                            @if($deposit->user_marked_paid_at)
                                <dt class="col-sm-4 text-muted">Reported paid</dt>
                                <dd class="col-sm-8">{{ $deposit->user_marked_paid_at->format('M d, Y H:i') }}</dd>
                            @endif

                            @if($deposit->user_payment_note)
                                <dt class="col-sm-4 text-muted">Their note</dt>
                                <dd class="col-sm-8">{{ $deposit->user_payment_note }}</dd>
                            @endif
                        </dl>

                        <form method="POST" action="{{ $confirmAction }}">
                            @csrf
                            <div class="mb-3">
                                <label for="admin_notes" class="form-label">Admin notes (optional)</label>
                                <textarea name="admin_notes" id="admin_notes" rows="2" class="form-control" maxlength="1000" placeholder="e.g. Wire matched on statement">{{ old('admin_notes') }}</textarea>
                                @error('admin_notes')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <button type="submit" class="btn btn-success w-100 mb-2">
                                <i class="fa fa-check me-1" aria-hidden="true"></i>
                                Confirm and credit €{{ number_format((float) $deposit->amount, 2) }}
                            </button>
                        </form>

                        <a href="{{ route('admin.deposits') }}" class="btn btn-link w-100 text-muted">
                            Cancel — back to deposits
                        </a>
                    @else
                        <div class="text-center">
                            <i class="fa-solid fa-circle-info fa-3x text-secondary mb-3" aria-hidden="true"></i>
                            <h1 class="h3 mb-2">Deposit already processed</h1>
                            <p class="text-muted mb-4">
                                This deposit is <strong>{{ $deposit->status }}</strong> and cannot be approved again from this link.
                            </p>
                            <a href="{{ route('admin.deposits') }}" class="btn btn-primary">
                                Open deposits
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

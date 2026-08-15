@extends('layouts.app')

@section('title', 'Confirm deposit approval - SEOLinkBuildings')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card shadow">
                <div class="card-body p-4 p-md-5">
                    @if(!empty($isCard) && $deposit->isPending())
                        <div class="text-center mb-4">
                            <i class="fa-solid fa-credit-card fa-3x text-secondary mb-3" aria-hidden="true"></i>
                            <h1 class="h3 mb-2">Stripe deposit — do not credit here</h1>
                            <p class="text-muted mb-0">
                                This deposit settles through Stripe when the payment succeeds.
                                Approving it here would credit the wallet twice.
                            </p>
                        </div>

                        <dl class="row mb-4">
                            <dt class="col-sm-4 text-muted">Amount</dt>
                            <dd class="col-sm-8 fw-semibold">€{{ number_format((float) $incomingAmount, 2) }}</dd>

                            <dt class="col-sm-4 text-muted">Reference</dt>
                            <dd class="col-sm-8"><code>REF{{ $deposit->reference_code }}</code></dd>

                            <dt class="col-sm-4 text-muted">Advertiser</dt>
                            <dd class="col-sm-8">
                                {{ $deposit->user?->name ?? 'Unknown' }}
                                @if($deposit->user?->email)
                                    <br><span class="text-muted small">{{ $deposit->user->email }}</span>
                                @endif
                            </dd>
                        </dl>

                        <div class="border rounded p-3 mb-4 bg-light">
                            <h2 class="h6 text-uppercase text-muted mb-3">Wallet snapshot</h2>
                            <dl class="row mb-0">
                                <dt class="col-sm-5 text-muted">Current balance</dt>
                                <dd class="col-sm-7 fw-semibold">€{{ number_format((float) $currentBalance, 2) }}</dd>
                            </dl>
                        </div>

                        <a href="{{ route('admin.deposits') }}" class="btn btn-primary w-100">
                            Open deposits
                        </a>
                    @elseif($canApprove)
                        <div class="text-center mb-4">
                            <i class="fa-solid fa-wallet fa-3x text-primary mb-3" aria-hidden="true"></i>
                            <h1 class="h3 mb-2">Confirm deposit approval</h1>
                            <p class="text-muted mb-0">
                                Review the wallet context below. Confirming will credit the advertiser wallet immediately.
                            </p>
                        </div>

                        <dl class="row mb-3">
                            <dt class="col-sm-4 text-muted">Credit now</dt>
                            <dd class="col-sm-8 fw-semibold fs-5">€{{ number_format((float) $incomingAmount, 2) }}</dd>

                            <dt class="col-sm-4 text-muted">Method</dt>
                            <dd class="col-sm-8">{{ ucfirst((string) $deposit->payment_method) }}</dd>

                            <dt class="col-sm-4 text-muted">Reference</dt>
                            <dd class="col-sm-8"><code>REF{{ $deposit->reference_code }}</code></dd>

                            <dt class="col-sm-4 text-muted">Advertiser</dt>
                            <dd class="col-sm-8">
                                {{ $deposit->user?->name ?? 'Unknown' }}
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

                        @if($possibleDuplicate && $duplicateMatches->isNotEmpty())
                            <div class="alert alert-warning text-start mb-4" role="alert">
                                <p class="mb-2">
                                    <strong>Possible duplicate:</strong>
                                    this advertiser already received the same amount recently.
                                    Confirm this is a separate transfer before crediting again.
                                </p>
                                <ul class="mb-0 small ps-3">
                                    @foreach($duplicateMatches as $match)
                                        <li>
                                            €{{ number_format((float) $match->amount, 2) }}
                                            on {{ optional($match->approved_at ?? $match->created_at)->format('M d, Y') }}
                                            (<code>REF{{ $match->reference_code }}</code>)
                                            @if($match->payment_method)
                                                · {{ ucfirst((string) $match->payment_method) }}
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="border rounded p-3 mb-4 bg-light">
                            <h2 class="h6 text-uppercase text-muted mb-3">Wallet snapshot</h2>
                            <dl class="row mb-0">
                                <dt class="col-sm-5 text-muted">Current balance</dt>
                                <dd class="col-sm-7 fw-semibold">€{{ number_format((float) $currentBalance, 2) }}</dd>

                                @if($bonusBalance > 0)
                                    <dt class="col-sm-5 text-muted">Of which bonus</dt>
                                    <dd class="col-sm-7">€{{ number_format((float) $bonusBalance, 2) }}</dd>
                                @endif

                                <dt class="col-sm-5 text-muted">After this approval</dt>
                                <dd class="col-sm-7 fw-semibold text-success">
                                    €{{ number_format((float) $currentBalance, 2) }}
                                    →
                                    €{{ number_format((float) $projectedBalance, 2) }}
                                </dd>
                            </dl>
                            <p class="small text-muted mb-0 mt-2">
                                Nothing is credited until you confirm below.
                            </p>
                        </div>

                        <div class="mb-4">
                            <h2 class="h6 text-uppercase text-muted mb-2">Recent completed deposits</h2>
                            @if($priorDeposits->isEmpty())
                                <p class="text-muted small mb-0">No completed deposits yet for this advertiser.</p>
                            @else
                                <ul class="list-unstyled mb-0 small">
                                    @foreach($priorDeposits as $prior)
                                        <li class="d-flex justify-content-between gap-2 py-1 border-bottom border-light">
                                            <span>
                                                <strong>€{{ number_format((float) $prior->amount, 2) }}</strong>
                                                · {{ ucfirst((string) $prior->payment_method) }}
                                                · <code class="small">REF{{ $prior->reference_code }}</code>
                                            </span>
                                            <span class="text-muted text-nowrap">
                                                {{ optional($prior->approved_at ?? $prior->created_at)->format('M d, Y') }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

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
                                Confirm and credit €{{ number_format((float) $incomingAmount, 2) }}
                            </button>
                        </form>

                        <a href="{{ route('admin.deposits') }}" class="btn btn-link w-100 text-muted">
                            Cancel — back to deposits
                        </a>
                    @elseif($deposit->isPending())
                        <div class="text-center mb-4">
                            <i class="fa-solid fa-circle-info fa-3x text-secondary mb-3" aria-hidden="true"></i>
                            <h1 class="h3 mb-2">Cannot credit from this link</h1>
                            <p class="text-muted mb-0">
                                This deposit is still <strong>pending</strong> but cannot be approved here.
                                Open Deposits to review it.
                            </p>
                        </div>

                        <div class="border rounded p-3 mb-4 bg-light text-start">
                            <h2 class="h6 text-uppercase text-muted mb-3">Wallet snapshot</h2>
                            <dl class="row mb-0">
                                <dt class="col-sm-5 text-muted">Current balance</dt>
                                <dd class="col-sm-7 fw-semibold">€{{ number_format((float) $currentBalance, 2) }}</dd>
                            </dl>
                        </div>

                        @if($priorDeposits->isNotEmpty())
                            <div class="mb-4 text-start">
                                <h2 class="h6 text-uppercase text-muted mb-2">Recent completed deposits</h2>
                                <ul class="list-unstyled mb-0 small">
                                    @foreach($priorDeposits as $prior)
                                        <li class="d-flex justify-content-between gap-2 py-1 border-bottom border-light">
                                            <span>
                                                <strong>€{{ number_format((float) $prior->amount, 2) }}</strong>
                                                · {{ ucfirst((string) $prior->payment_method) }}
                                            </span>
                                            <span class="text-muted text-nowrap">
                                                {{ optional($prior->approved_at ?? $prior->created_at)->format('M d, Y') }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <a href="{{ route('admin.deposits') }}" class="btn btn-primary w-100">
                            Open deposits
                        </a>
                    @else
                        <div class="text-center mb-4">
                            <i class="fa-solid fa-circle-info fa-3x text-secondary mb-3" aria-hidden="true"></i>
                            <h1 class="h3 mb-2">Deposit already processed</h1>
                            <p class="text-muted mb-0">
                                This deposit is <strong>{{ $deposit->status }}</strong> and cannot be approved again from this link.
                            </p>
                        </div>

                        <div class="border rounded p-3 mb-4 bg-light text-start">
                            <h2 class="h6 text-uppercase text-muted mb-3">Wallet snapshot</h2>
                            <dl class="row mb-0">
                                <dt class="col-sm-5 text-muted">Current balance</dt>
                                <dd class="col-sm-7 fw-semibold">€{{ number_format((float) $currentBalance, 2) }}</dd>
                            </dl>
                        </div>

                        @if($priorDeposits->isNotEmpty())
                            <div class="mb-4 text-start">
                                <h2 class="h6 text-uppercase text-muted mb-2">Recent completed deposits</h2>
                                <ul class="list-unstyled mb-0 small">
                                    @foreach($priorDeposits as $prior)
                                        <li class="d-flex justify-content-between gap-2 py-1 border-bottom border-light">
                                            <span>
                                                <strong>€{{ number_format((float) $prior->amount, 2) }}</strong>
                                                · {{ ucfirst((string) $prior->payment_method) }}
                                            </span>
                                            <span class="text-muted text-nowrap">
                                                {{ optional($prior->approved_at ?? $prior->created_at)->format('M d, Y') }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <a href="{{ route('admin.deposits') }}" class="btn btn-primary w-100">
                            Open deposits
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

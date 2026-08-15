@extends('layouts.app')

@section('title', 'Confirm payout marked paid - SEOLinkBuildings')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card shadow">
                <div class="card-body p-4 p-md-5">
                    @if($canMarkPaid)
                        <div class="text-center mb-4">
                            <i class="fa-solid fa-money-check-dollar fa-3x text-primary mb-3" aria-hidden="true"></i>
                            <h1 class="h3 mb-2">Confirm marked paid</h1>
                            <p class="text-muted mb-0">
                                Only confirm after you have sent the net amount outside the app. Funds were already deducted when the publisher requested withdrawal.
                            </p>
                        </div>

                        <dl class="row mb-3">
                            <dt class="col-sm-4 text-muted">Pay now (net)</dt>
                            <dd class="col-sm-8 fw-semibold fs-5">€{{ number_format((float) $withdrawal->net_amount, 2) }}</dd>

                            <dt class="col-sm-4 text-muted">Gross / fee</dt>
                            <dd class="col-sm-8">
                                €{{ number_format((float) $withdrawal->amount, 2) }}
                                @if((float) $withdrawal->fee > 0)
                                    <span class="text-muted small">(fee €{{ number_format((float) $withdrawal->fee, 2) }})</span>
                                @endif
                            </dd>

                            <dt class="col-sm-4 text-muted">Method</dt>
                            <dd class="col-sm-8">{{ strtoupper((string) $withdrawal->payment_method) }}</dd>

                            <dt class="col-sm-4 text-muted">Reference</dt>
                            <dd class="col-sm-8"><code>WD-{{ $withdrawal->id }}</code></dd>

                            <dt class="col-sm-4 text-muted">Publisher</dt>
                            <dd class="col-sm-8">
                                {{ $withdrawal->user->name ?? 'Unknown' }}
                                @if($withdrawal->user?->email)
                                    <br><span class="text-muted small">{{ $withdrawal->user->email }}</span>
                                @endif
                            </dd>

                            <dt class="col-sm-4 text-muted">Requested</dt>
                            <dd class="col-sm-8">{{ optional($withdrawal->created_at)->format('M d, Y H:i') }}</dd>

                            <dt class="col-sm-4 text-muted">Status</dt>
                            <dd class="col-sm-8">{{ $withdrawal->status }}</dd>
                        </dl>

                        @if($possibleDuplicate && $duplicateMatches->isNotEmpty())
                            <div class="alert alert-warning text-start mb-4" role="alert">
                                <p class="mb-2">
                                    <strong>Possible duplicate payout:</strong>
                                    this publisher already had the same net amount marked paid recently.
                                    Confirm this is a separate request before marking paid again.
                                </p>
                                <ul class="mb-0 small ps-3">
                                    @foreach($duplicateMatches as $match)
                                        <li>
                                            €{{ number_format((float) $match->net_amount, 2) }}
                                            on {{ optional($match->processed_at ?? $match->created_at)->format('M d, Y') }}
                                            (<code>WD-{{ $match->id }}</code>)
                                            · {{ strtoupper((string) $match->payment_method) }}
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
                            </dl>
                            <p class="small text-muted mb-0 mt-2">
                                Gross €{{ number_format((float) $withdrawal->amount, 2) }} was already deducted when this withdrawal was requested.
                            </p>
                        </div>

                        <div class="mb-4">
                            <h2 class="h6 text-uppercase text-muted mb-2">Recent paid withdrawals</h2>
                            @if($priorPaid->isEmpty())
                                <p class="text-muted small mb-0">No completed payouts yet for this publisher.</p>
                            @else
                                <ul class="list-unstyled mb-0 small">
                                    @foreach($priorPaid as $prior)
                                        <li class="d-flex justify-content-between gap-2 py-1 border-bottom border-light">
                                            <span>
                                                <strong>€{{ number_format((float) $prior->net_amount, 2) }}</strong>
                                                · {{ strtoupper((string) $prior->payment_method) }}
                                                · <code class="small">WD-{{ $prior->id }}</code>
                                            </span>
                                            <span class="text-muted text-nowrap">
                                                {{ optional($prior->processed_at ?? $prior->created_at)->format('M d, Y') }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        <div class="border rounded p-3 mb-4 bg-light">
                            <h2 class="h6 text-uppercase text-muted mb-2">Destination</h2>
                            <pre class="mb-0 small" style="white-space: pre-wrap;">{{ $withdrawal->destination_copy_text }}</pre>
                        </div>

                        <form method="POST" action="{{ $confirmAction }}">
                            @csrf
                            <div class="mb-3">
                                <label for="notes" class="form-label">Admin notes (optional)</label>
                                <textarea name="notes" id="notes" rows="2" class="form-control" maxlength="2000" placeholder="e.g. Wise transfer sent">{{ old('notes') }}</textarea>
                                @error('notes')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <button type="submit" class="btn btn-success w-100 mb-2">
                                <i class="fa fa-check me-1" aria-hidden="true"></i>
                                Confirm marked paid — €{{ number_format((float) $withdrawal->net_amount, 2) }}
                            </button>
                        </form>

                        <a href="{{ route('admin.withdrawals', [], false) }}" class="btn btn-link w-100 text-muted">
                            Cancel — back to payout queue
                        </a>
                    @else
                        <div class="text-center mb-4">
                            <i class="fa-solid fa-circle-info fa-3x text-secondary mb-3" aria-hidden="true"></i>
                            <h1 class="h3 mb-2">Withdrawal already settled</h1>
                            <p class="text-muted mb-0">
                                This withdrawal is <strong>{{ $withdrawal->status }}</strong> and cannot be marked paid again from this link.
                            </p>
                        </div>

                        <div class="border rounded p-3 mb-4 bg-light text-start">
                            <h2 class="h6 text-uppercase text-muted mb-3">Wallet snapshot</h2>
                            <dl class="row mb-0">
                                <dt class="col-sm-5 text-muted">Current balance</dt>
                                <dd class="col-sm-7 fw-semibold">€{{ number_format((float) $currentBalance, 2) }}</dd>
                            </dl>
                        </div>

                        @if(!empty($missingStatement))
                            <div class="alert alert-warning text-start mb-4" role="alert">
                                The payout statement is missing. Create it so the publisher can download the PAY document. This does not send money again.
                            </div>
                            <form method="POST" action="{{ $confirmAction }}">
                                @csrf
                                <button type="submit" class="btn btn-primary w-100 mb-2">
                                    Create payout statement
                                </button>
                            </form>
                        @endif

                        <a href="{{ route('admin.withdrawals', [], false) }}" class="btn {{ !empty($missingStatement) ? 'btn-link text-muted' : 'btn-primary' }} w-100">Open payout queue</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

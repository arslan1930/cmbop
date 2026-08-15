@extends('admin.layouts.app')

@section('content')
@php
    $availabilityLabel = match ($availability) {
        'available' => 'Approved',
        'evaluating' => 'Evaluating',
        'in_progress' => 'In progress',
        'published' => 'Completed/LIVE',
        'needs_fix' => 'Needs corrections',
        'expired' => 'Expired',
        'archived' => 'Archived',
        default => str_replace('_', ' ', (string) $submission->moderation_status),
    };
    $availabilityTone = match ($availability) {
        'available', 'published' => 'success',
        'evaluating' => 'info',
        'in_progress' => 'primary',
        'needs_fix' => 'danger',
        'expired' => 'warning',
        'archived' => 'dark',
        default => 'secondary',
    };
    $indexUrl = route('admin.content-library.index', $filterQuery ?? []);
    $advertiserUrl = $submission->user
        ? route('admin.users.index', ['user' => $submission->user->id]).'#user-'.$submission->user->id
        : null;
    $orderUrl = $submission->order_id
        ? route('admin.orders.show', $submission->order_id)
        : null;
    $siteName = $placement?->site_name
        ?: $placement?->site?->site_name
        ?: $placement?->site_url
        ?: $placement?->site?->site_url;
@endphp
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <a href="{{ $indexUrl }}" class="small text-muted text-decoration-none">← Content Library</a>
            <h1 class="h3 mb-1 mt-1">{{ $submission->title ?: $submission->original_filename }}</h1>
            <p class="text-muted mb-0 small">
                #{{ $submission->id }}
                @if($advertiserUrl)
                    · <a href="{{ $advertiserUrl }}">{{ $submission->user?->email }}</a>
                @else
                    · {{ $submission->user?->email }}
                @endif
                · {{ strtoupper(trim(implode('/', array_filter([$submission->country, $submission->language])))) ?: '—' }}
                · <span class="badge text-bg-{{ $availabilityTone }}">{{ $availabilityLabel }}</span>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if($fileOnDisk)
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.content-library.download', $submission) }}">
                    Download .docx
                </a>
            @elseif($submission->hasStoredFile())
                <span class="badge text-bg-warning text-dark align-self-center">Original file missing on disk</span>
            @endif
        </div>
    </div>

    @if($notice)
        <div class="alert alert-warning py-2 px-3 small">{{ $notice }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Details</strong></div>
                <div class="card-body small">
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted">Advertiser</dt>
                        <dd class="col-7">
                            @if($advertiserUrl)
                                <a href="{{ $advertiserUrl }}">{{ $submission->user?->name ?: 'User #'.$submission->user_id }}</a>
                            @else
                                {{ $submission->user?->name ?: '—' }}
                            @endif
                            <br><span class="text-muted">{{ $submission->user?->email }}</span>
                        </dd>
                        <dt class="col-5 text-muted">Scores</dt>
                        <dd class="col-7">U {{ $submission->uniqueness_score ?? '—' }}% · Q {{ $submission->quality_score ?? '—' }}%</dd>
                        <dt class="col-5 text-muted">Words</dt>
                        <dd class="col-7">{{ $submission->word_count ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Uploaded</dt>
                        <dd class="col-7">{{ optional($submission->created_at)->toDayDateTimeString() ?: '—' }}</dd>
                        <dt class="col-5 text-muted">Evaluated</dt>
                        <dd class="col-7">{{ optional($submission->evaluated_at)->toDayDateTimeString() ?: '—' }}</dd>
                        <dt class="col-5 text-muted">Order</dt>
                        <dd class="col-7">
                            @if($orderUrl)
                                <a href="{{ $orderUrl }}">{{ $submission->order?->order_number ?: '#'.$submission->order_id }}</a>
                                <div class="text-muted">{{ $submission->order?->status }} · {{ $submission->order?->payment_status }}</div>
                            @else
                                —
                            @endif
                        </dd>
                        <dt class="col-5 text-muted">Placement</dt>
                        <dd class="col-7">
                            {{ $siteName ?: '—' }}
                            @if($placement?->publisher_status)
                                <div class="text-muted">{{ str_replace('_', ' ', (string) $placement->publisher_status) }}</div>
                            @endif
                        </dd>
                        <dt class="col-5 text-muted">Live URL</dt>
                        <dd class="col-7">
                            @if($liveUrl)
                                @php $safeLive = safe_external_url($liveUrl); @endphp
                                @if($safeLive !== '#')
                                    <a href="{{ $safeLive }}" target="_blank" rel="noopener noreferrer">{{ $liveUrl }}</a>
                                @else
                                    <span class="text-muted">{{ $liveUrl }}</span>
                                @endif
                            @else
                                —
                            @endif
                        </dd>
                        <dt class="col-5 text-muted">Image rights</dt>
                        <dd class="col-7">
                            {{ $submission->image_rights ?: '—' }}
                            @if(filled($submission->image_rights_source))
                                <div class="text-muted">{{ $submission->image_rights_source }}</div>
                            @endif
                            @if($submission->hasImages() && ! $submission->imageRightsCoverContent())
                                <div class="text-danger">Images are not covered by a rights claim.</div>
                            @endif
                        </dd>
                        <dt class="col-5 text-muted">Checkout link</dt>
                        <dd class="col-7">
                            @if($submission->hasLink())
                                {{ $submission->anchor_text }}
                                <div>
                                    @php $safeTarget = safe_external_url($submission->target_url); @endphp
                                    @if($safeTarget !== '#')
                                        <a href="{{ $safeTarget }}" target="_blank" rel="noopener noreferrer">{{ $submission->target_url }}</a>
                                    @else
                                        <span class="text-muted">{{ $submission->target_url }}</span>
                                    @endif
                                </div>
                            @else
                                —
                            @endif
                            @if(! $submission->hasCheckoutReadyLinks())
                                <div class="text-danger">{{ \App\Models\ContentSubmission::CHECKOUT_LINK_MESSAGE }}</div>
                            @endif
                        </dd>
                        <dt class="col-5 text-muted">File</dt>
                        <dd class="col-7">
                            {{ $submission->original_filename ?: '—' }}
                            <div class="text-muted">
                                {{ $submission->disk ?: 'local' }}
                                @if($submission->size_bytes)
                                    · {{ number_format(((int) $submission->size_bytes) / 1024, 1) }} KB
                                @endif
                            </div>
                            @if($submission->hasStoredFile() && ! $fileOnDisk)
                                <div class="text-danger">Original file missing on disk</div>
                            @endif
                        </dd>
                        <dt class="col-5 text-muted">Expires</dt>
                        <dd class="col-7">{{ optional($submission->expires_at)->toDayDateTimeString() ?: '—' }}</dd>
                        <dt class="col-5 text-muted">Archived</dt>
                        <dd class="col-7">{{ optional($submission->archived_at)->toDayDateTimeString() ?: '—' }}</dd>
                    </dl>
                </div>
            </div>

            @if(($reasons['blocking'] ?? []) !== [] || ($reasons['advisory'] ?? []) !== [] || $matchedTerms !== [] || $blockedUrls !== [])
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white"><strong>Evaluation reasons</strong></div>
                    <div class="card-body small">
                        @if(($reasons['blocking'] ?? []) !== [])
                            <div class="fw-semibold text-danger mb-1">Blocking</div>
                            <ul class="mb-2">
                                @foreach($reasons['blocking'] as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if(($reasons['advisory'] ?? []) !== [])
                            <div class="fw-semibold text-warning mb-1">Advisory</div>
                            <ul class="mb-2">
                                @foreach($reasons['advisory'] as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if($matchedTerms !== [])
                            <div class="fw-semibold mb-1">Matched terms</div>
                            <p class="mb-2">{{ implode(', ', $matchedTerms) }}</p>
                        @endif
                        @if($blockedUrls !== [])
                            <div class="fw-semibold mb-1">Blocked URLs</div>
                            <ul class="mb-0">
                                @foreach($blockedUrls as $url)
                                    <li>{{ $url }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Staff actions</strong></div>
                <div class="card-body small">
                    @if($canRetry)
                        <form method="POST" action="{{ route('admin.content-library.retry', $submission) }}" class="mb-3"
                              data-slb-confirm="Re-run uniqueness and policy evaluation on this article?"
                              data-slb-confirm-title="Re-evaluate article?"
                              data-slb-confirm-text="Re-evaluate">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-primary">Re-evaluate</button>
                        </form>
                    @endif

                    @if($canOverrideApprove || $canOverrideReject)
                        <form method="POST" action="{{ route('admin.content-library.override', $submission) }}" class="mb-3">
                            @csrf
                            <label class="form-label" for="adminLibraryOverrideNotes">Override notes</label>
                            <textarea name="notes" id="adminLibraryOverrideNotes" class="form-control form-control-sm mb-2" rows="3" required minlength="5" maxlength="2000" placeholder="Why this decision?">{{ old_text('notes') }}</textarea>
                            <div class="d-flex flex-wrap gap-2">
                                @if($canOverrideApprove)
                                    <button type="submit" name="decision" value="approved" class="btn btn-sm btn-success"
                                            data-slb-confirm="Force-approve this article? Checkout still needs a file, market, image rights, and a valid link pair."
                                            data-slb-confirm-title="Approve article?"
                                            data-slb-confirm-text="Approve">
                                        Override approve
                                    </button>
                                @endif
                                @if($canOverrideReject)
                                    <button type="submit" name="decision" value="rejected" class="btn btn-sm btn-outline-danger"
                                            data-slb-confirm="Reject this article? The advertiser will need to edit and resubmit."
                                            data-slb-confirm-title="Reject article?"
                                            data-slb-confirm-text="Reject"
                                            data-slb-confirm-danger="1">
                                        Override reject
                                    </button>
                                @endif
                            </div>
                        </form>
                    @endif

                    @if($canArchive)
                        <form method="POST" action="{{ route('admin.content-library.archive', $submission) }}"
                              data-slb-confirm="Archive this article? The advertiser can still restore it."
                              data-slb-confirm-title="Archive article?"
                              data-slb-confirm-text="Archive">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Archive</button>
                        </form>
                    @endif

                    @if($canRestore)
                        <form method="POST" action="{{ route('admin.content-library.restore', $submission) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-primary">Restore from archive</button>
                        </form>
                    @endif

                    @if(! $canRetry && ! $canOverrideApprove && ! $canOverrideReject && ! $canArchive && ! $canRestore)
                        <p class="text-muted mb-0">No staff actions available for this article.</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>Preview</strong></div>
                <div class="card-body">
                    @if($previewHtml)
                        <div class="border rounded-3 p-3 bg-white" style="max-height:70vh;overflow:auto;">
                            {!! $previewHtml !!}
                        </div>
                    @else
                        <p class="text-muted mb-0">No preview HTML stored for this article.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

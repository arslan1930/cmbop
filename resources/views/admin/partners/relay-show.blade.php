@extends(staff_layout())

@section('title', 'Partner order '.$orderItem->id)

@section('content')
<div class="container-fluid py-3">
    <a href="{{ route('admin.partners.relay') }}" class="small">← Relay queue</a>
    <h4 class="fw-bold">Order item #{{ $orderItem->id }}</h4>
    <p class="text-muted small">Buyer {{ $orderItem->order?->user?->email }} · Partner {{ $orderItem->site?->publisher?->email }} · SLB price €{{ number_format((float) ($orderItem->slb_price_eur_internal ?? $orderItem->advertiser_eur_amount ?? $orderItem->price), 2) }} · Partner payout €{{ number_format((float) ($orderItem->partner_payout_eur ?? $orderItem->publisher_amount), 2) }}</p>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h5>Thread (originals)</h5>
            @foreach ($messages as $message)
                <div class="border-bottom py-2">
                    <div class="small text-muted">{{ $message->author_type }} · {{ $message->relay_status }} · {{ optional($message->created_at)->toDayDateTimeString() }}</div>
                    <div><strong>Original:</strong> {{ $message->originalBody() }}</div>
                    <div><strong>Masked:</strong> {{ $message->body_masked }}</div>
                    @foreach ($message->attachments as $file)
                        <div><a href="{{ route('admin.partners.relay.attachment', ['item' => $orderItem->id, 'attachment' => $file->id]) }}">{{ $file->filename_original }}</a> (partner sees {{ $file->filename_masked }})</div>
                    @endforeach
                    @if ($message->relay_status === 'held_for_review')
                        <form method="POST" action="{{ route('admin.partners.messages.release', $message) }}" class="d-inline">@csrf<button class="btn btn-sm btn-primary mt-1">Release masked</button></form>
                        <form method="POST" action="{{ route('admin.partners.messages.hide', $message) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-danger mt-1">Hide</button></form>
                    @endif
                </div>
            @endforeach
            <form method="POST" action="{{ route('admin.partners.relay.note', $orderItem->id) }}" class="mt-3">
                @csrf
                <label class="form-label">Admin note to both parties (will be masked)</label>
                <textarea class="form-control" name="body" required></textarea>
                <button class="btn btn-sm btn-outline-primary mt-2" type="submit">Post note</button>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h5>Corrections</h5>
            @foreach ($corrections as $row)
                <div class="d-flex justify-content-between align-items-start border-bottom py-2">
                    <div>#{{ $row->number }} · {{ $row->status }} · {{ $row->instructions }}</div>
                    @if ($row->status === 'pending_relay')
                        <form method="POST" action="{{ route('admin.partners.relay.correction', $row) }}">@csrf<button class="btn btn-sm btn-primary">Relay</button></form>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h5>Disputes</h5>
            @foreach ($disputes as $dispute)
                <div class="border-bottom py-2">
                    <div>{{ $dispute->status }} · {{ $dispute->reason }} · {{ $dispute->message }}</div>
                    @if ($dispute->status === 'open')
                        <form method="POST" action="{{ route('admin.partners.disputes.resolve', $dispute) }}" class="row g-2 mt-1">
                            @csrf
                            <div class="col-md-3">
                                <select name="outcome" class="form-select form-select-sm">
                                    <option value="partner">Partner</option>
                                    <option value="buyer">Buyer</option>
                                </select>
                            </div>
                            <div class="col-md-6"><input class="form-control form-control-sm" name="note" placeholder="Decision note"></div>
                            <div class="col-md-3"><button class="btn btn-sm btn-primary" type="submit">Resolve</button></div>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection

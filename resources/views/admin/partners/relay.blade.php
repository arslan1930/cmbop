@extends(staff_layout())

@section('title', 'Partner relay')

@section('content')
<div class="container-fluid py-3">
    <h4 class="fw-bold mb-1">Partner order relay</h4>
    <p class="text-muted small">Buyer change requests and masked messages wait here. Originals stay on this screen only.</p>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h5>Pending corrections</h5>
            <table class="table table-sm">
                <thead><tr><th>Order item</th><th>#</th><th>Instructions</th><th></th></tr></thead>
                <tbody>
                @forelse ($pending as $row)
                    <tr>
                        <td>#{{ $row->order_item_id }} {{ $row->orderItem?->site?->domain }}</td>
                        <td>{{ $row->number }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($row->instructions, 140) }}</td>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.partners.relay.show', $row->order_item_id) }}">Open</a>
                            <form method="POST" action="{{ route('admin.partners.relay.correction', $row) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-primary" type="submit">Relay</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">None.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h5>Held messages</h5>
            <table class="table table-sm">
                <thead><tr><th>Item</th><th>Author</th><th>Original</th><th>Masked</th><th></th></tr></thead>
                <tbody>
                @forelse ($held as $msg)
                    <tr>
                        <td>#{{ $msg->order_item_id }}</td>
                        <td>{{ $msg->author_type }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($msg->originalBody(), 80) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($msg->body_masked, 80) }}</td>
                        <td class="text-nowrap">
                            <form method="POST" action="{{ route('admin.partners.messages.release', $msg) }}" class="d-inline">@csrf<button class="btn btn-sm btn-primary">Release</button></form>
                            <form method="POST" action="{{ route('admin.partners.messages.hide', $msg) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-danger">Hide</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">None.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h5>Open disputes</h5>
            <table class="table table-sm">
                <thead><tr><th>Item</th><th>Reason</th><th></th></tr></thead>
                <tbody>
                @forelse ($disputes as $dispute)
                    <tr>
                        <td>#{{ $dispute->order_item_id }}</td>
                        <td>{{ $dispute->reason }} — {{ \Illuminate\Support\Str::limit($dispute->message, 80) }}</td>
                        <td><a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.partners.relay.show', $dispute->order_item_id) }}">Decide</a></td>
                    </tr>
                @empty
                    <tr><td colspan="3">None.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

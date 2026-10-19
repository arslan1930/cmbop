@extends(staff_layout())

@section('title', 'Import #'.$job->id)

@section('content')
<div class="container-fluid py-3">
    <a href="{{ route('admin.partners.imports') }}" class="small">← Imports</a>
    <h4 class="fw-bold">Import #{{ $job->id }}</h4>
    <p class="text-muted small">{{ $job->partner?->displayName() }} · {{ $job->source }} · {{ $job->status }}</p>
    <pre class="small bg-light p-2">{{ json_encode($job->counts, JSON_PRETTY_PRINT) }}</pre>
    @if($job->status === 'held_for_confirmation')
        <form method="POST" action="{{ route('admin.partners.imports.confirm-drop', $job) }}" class="mb-3">
            @csrf
            <p class="text-warning">Feed dropped or disappeared. Nothing was paused. Confirm to pause the missing sites (open orders stay).</p>
            <button class="btn btn-warning" type="submit">Confirm pause missing sites</button>
        </form>
    @endif
    @if($job->error)<div class="alert alert-danger">{{ $job->error }}</div>@endif
    <div class="table-responsive card border-0 shadow-sm">
        <table class="table table-sm mb-0">
            <thead><tr><th>Row</th><th>Domain</th><th>Result</th><th>Reason</th></tr></thead>
            <tbody>
                @foreach($job->rows as $row)
                    <tr>
                        <td>{{ $row->row_number }}</td>
                        <td>{{ $row->domain }}</td>
                        <td>{{ $row->result }}</td>
                        <td>{{ $row->reason_code }} {{ $row->reason }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

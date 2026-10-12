@extends('advertiser.layouts.app')

@section('title', 'Catalog API')

@section('content')
<div class="container-fluid py-4">
    <h2 class="h4 mb-2">Catalog API</h2>
    <p class="text-muted small mb-4">
        Search the same live catalog (prices and hide-mode) from your own tools.
        Orders still go through
        <a href="{{ route('advertiser.catalog') }}">Advertiser → Catalog</a>.
        Account name and password stay on
        <a href="{{ route('profile') }}">Profile</a>
        (shared for every role — that page is not under /advertiser).
    </p>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(!empty($plainToken))
        <div class="alert alert-warning" id="advertiserApiPlainTokenAlert">
            <strong>Copy this key now.</strong> It will not be shown again.
            <div class="input-group mt-2">
                <input type="text" class="form-control font-monospace" id="advertiserApiPlainToken" readonly value="{{ $plainToken }}">
                <button type="button" class="btn btn-outline-secondary" id="advertiserApiCopyBtn">Copy</button>
            </div>
            <p class="small text-danger mb-0 mt-2 d-none" id="advertiserApiCopyError" role="status">Copy failed. Select the key and copy it manually.</p>
        </div>
        <div class="alert alert-success d-none" id="advertiserApiCopiedAlert" role="status">
            Copied. The key is hidden and will not be shown again.
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h3 class="h6">Create a key</h3>
            <form method="POST" action="{{ route('advertiser.api.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small" for="advertiserApiTokenName">Name</label>
                    <input type="text" name="name" id="advertiserApiTokenName" class="form-control form-control-sm" required maxlength="80" placeholder="Claude, agency tool…">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">Create key</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h3 class="h6">Your keys</h3>
            @if($tokens->isEmpty())
                <p class="small text-muted mb-0">No keys yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Prefix</th>
                                <th>Last used</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tokens as $token)
                                <tr>
                                    <td>{{ $token->name }}</td>
                                    <td class="font-monospace">{{ $token->token_prefix }}…</td>
                                    <td class="small text-muted">{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('advertiser.api.destroy', $token) }}" onsubmit="return confirm('Revoke this key?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Revoke</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h3 class="h6">Search example</h3>
            <p class="small text-muted mb-2">
                <code>GET {{ $catalogUrl }}</code>
                with header <code>Authorization: Bearer YOUR_KEY</code>
            </p>
            <pre class="small bg-light border rounded p-3 mb-0">curl -sS -H "Authorization: Bearer YOUR_KEY" \
  "{{ $catalogUrl }}?country=no&amp;price_max=150&amp;website=https://my-rentacar.no"</pre>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var btn = document.getElementById('advertiserApiCopyBtn');
    var input = document.getElementById('advertiserApiPlainToken');
    var box = document.getElementById('advertiserApiPlainTokenAlert');
    var done = document.getElementById('advertiserApiCopiedAlert');
    var err = document.getElementById('advertiserApiCopyError');
    if (!btn || !input || !box) {
        return;
    }

    function hideKey() {
        input.value = '';
        box.classList.add('d-none');
        if (done) {
            done.classList.remove('d-none');
        }
    }

    function copiedLook() {
        btn.textContent = 'Copied';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        btn.disabled = true;
    }

    function writeClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            input.focus();
            input.select();
            try {
                if (document.execCommand('copy')) {
                    resolve();
                } else {
                    reject(new Error('copy'));
                }
            } catch (e) {
                reject(e);
            }
        });
    }

    btn.addEventListener('click', function () {
        var value = input.value;
        if (!value) {
            return;
        }
        writeClipboard(value).then(function () {
            if (err) {
                err.classList.add('d-none');
            }
            copiedLook();
            window.setTimeout(hideKey, 700);
        }).catch(function () {
            if (err) {
                err.classList.remove('d-none');
            }
        });
    });
})();
</script>
@endpush

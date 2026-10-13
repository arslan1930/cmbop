@extends('advertiser.layouts.app')

@section('title', 'API & MCP')

@section('content')
<div class="container-fluid py-4">
    <h1 class="h4 mb-2">API &amp; MCP</h1>
    <p class="text-muted small mb-4">
        Connect Claude, Cursor, or Claude Code to the live SEOLinkBuildings catalog.
        Assistants can search publishers, save favorites or cart items, and read your orders and wallet.
        Checkout still happens on
        <a href="{{ route('advertiser.catalog') }}">Advertiser → Catalog</a>.
        Account name and password stay on
        <a href="{{ route('profile') }}">Profile</a>.
    </p>

    @if(!empty($plainToken))
        <div class="alert alert-warning" id="advertiserApiPlainTokenAlert">
            <strong>Copy this key now.</strong> It will not be shown again.
            <div class="input-group mt-2">
                <input type="text" class="form-control font-monospace" id="advertiserApiPlainToken" readonly value="{{ $plainToken }}">
                <button type="button" class="btn btn-outline-secondary slb-copy-btn" data-copy-target="advertiserApiPlainToken">Copy</button>
            </div>
            <p class="small text-danger mb-0 mt-2 d-none" id="advertiserApiCopyError" role="status">Copy failed. Select the key and copy it manually.</p>
        </div>
        <div class="alert alert-success d-none" id="advertiserApiCopiedAlert" role="status">
            Copied. The key is hidden and will not be shown again.
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h3 class="h6">Your MCP URL</h3>
            <p class="small text-muted mb-2">
                Paste this into Claude → Customize → Connectors → Add custom connector.
                Claude signs in with OAuth. Cursor / Claude Code can use the same URL with a personal Bearer token.
            </p>
            <div class="input-group">
                <input type="text" class="form-control font-monospace" id="advertiserMcpUrl" readonly value="{{ $mcpUrl }}">
                <button type="button" class="btn btn-outline-secondary slb-copy-btn" data-copy-target="advertiserMcpUrl">Copy</button>
            </div>
            <p class="small text-muted mt-2 mb-0">
                SSE clients (<code>--type sse</code>):
                <code>{{ $sseUrl }}</code>
            </p>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h3 class="h6">Connect to Claude</h3>
            <ol class="small mb-0 ps-3">
                <li>Open Claude (claude.ai or the desktop app) → <strong>Customize → Connectors</strong>.</li>
                <li>Choose <strong>Add custom connector</strong>.</li>
                <li>Name it <strong>SEOLinkBuildings</strong> (letters only — no spaces, dots, or accents) and paste the MCP URL above.</li>
                <li>Click <strong>Connect</strong>. Sign in to SEOLinkBuildings and click <strong>Allow</strong>.</li>
                <li>After a server update, disconnect the connector, connect again, and start a <strong>new chat</strong>. Claude’s chat tool list does not refresh from Settings alone.</li>
                <li>Ask something like: “Find me 10 publishers, DR over 40, price under €750, Ahrefs traffic over 1000, topic marketing.”</li>
            </ol>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h3 class="h6">Setup snippets</h3>
            <p class="small text-muted mb-2">Claude Code (Streamable HTTP)</p>
            <div class="input-group mb-3">
                <input type="text" class="form-control font-monospace small" id="advertiserClaudeHttp" readonly value="{{ $claudeHttpSnippet }}">
                <button type="button" class="btn btn-outline-secondary slb-copy-btn" data-copy-target="advertiserClaudeHttp">Copy</button>
            </div>
            <p class="small text-muted mb-2">Claude Code (SSE)</p>
            <div class="input-group mb-3">
                <input type="text" class="form-control font-monospace small" id="advertiserClaudeSse" readonly value="{{ $claudeSseSnippet }}">
                <button type="button" class="btn btn-outline-secondary slb-copy-btn" data-copy-target="advertiserClaudeSse">Copy</button>
            </div>
            <p class="small text-muted mb-2">Cursor CLI</p>
            <div class="input-group mb-3">
                <input type="text" class="form-control font-monospace small" id="advertiserCursorCli" readonly value="{{ $cursorSnippet }}">
                <button type="button" class="btn btn-outline-secondary slb-copy-btn" data-copy-target="advertiserCursorCli">Copy</button>
            </div>
            <p class="small text-muted mb-2"><code>.cursor/mcp.json</code> with a personal token</p>
            <pre class="small bg-light border rounded p-3 mb-0" id="advertiserCursorJson">{{ $cursorJson }}</pre>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2 slb-copy-btn" data-copy-target="advertiserCursorJson">Copy JSON</button>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h3 class="h6">Create a personal token</h3>
            <p class="small text-muted">
                For Cursor, Claude Code, and scripts. Send
                <code>Authorization: Bearer YOUR_KEY</code>.
                Claude.ai Connect does not need a key — it uses OAuth.
            </p>
            <form method="POST" action="{{ route('advertiser.api.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small" for="advertiserApiTokenName">Name</label>
                    <input type="text" name="name" id="advertiserApiTokenName" class="form-control form-control-sm" required maxlength="80" placeholder="Claude, Cursor, agency tool…">
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
            <h3 class="h6">Catalog REST example</h3>
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
    var tokenBox = document.getElementById('advertiserApiPlainTokenAlert');
    var tokenInput = document.getElementById('advertiserApiPlainToken');
    var tokenDone = document.getElementById('advertiserApiCopiedAlert');
    var tokenErr = document.getElementById('advertiserApiCopyError');

    function writeClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var probe = document.createElement('textarea');
            probe.value = text;
            document.body.appendChild(probe);
            probe.select();
            try {
                if (document.execCommand('copy')) {
                    resolve();
                } else {
                    reject(new Error('copy'));
                }
            } catch (e) {
                reject(e);
            } finally {
                probe.remove();
            }
        });
    }

    document.querySelectorAll('.slb-copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-copy-target') || '');
            if (!target) {
                return;
            }
            var value = ('value' in target) ? target.value : target.textContent;
            if (!value) {
                return;
            }
            writeClipboard(value).then(function () {
                if (tokenErr) {
                    tokenErr.classList.add('d-none');
                }
                var previous = btn.textContent;
                btn.textContent = 'Copied';
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-success');
                if (target.id === 'advertiserApiPlainToken' && tokenBox) {
                    window.setTimeout(function () {
                        tokenInput.value = '';
                        tokenBox.classList.add('d-none');
                        if (tokenDone) {
                            tokenDone.classList.remove('d-none');
                        }
                    }, 700);
                    btn.disabled = true;
                } else {
                    window.setTimeout(function () {
                        btn.textContent = previous;
                        btn.classList.add('btn-outline-secondary');
                        btn.classList.remove('btn-success');
                    }, 1400);
                }
            }).catch(function () {
                if (tokenErr && target.id === 'advertiserApiPlainToken') {
                    tokenErr.classList.remove('d-none');
                }
            });
        });
    });
})();
</script>
@endpush

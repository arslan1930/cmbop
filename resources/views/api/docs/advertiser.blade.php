@extends('layouts.app')

@section('title', 'Advertiser Ordering API docs')
@section('description', 'REST API for agencies: search the live catalog, read wallet balance, place prepaid wallet orders, cancel when allowed, and receive signed webhooks.')
@section('canonical', url('/api/docs/advertiser'))

@section('content')
@include('components.marketing-page-hero', [
    'title' => 'Advertiser Ordering API',
    'subtitle' => 'Same live catalog and EUR prices as Advertiser → Catalog. Orders are paid from the prepaid wallet only — no card charges through the API.',
])

<div class="container py-5" style="max-width: 860px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => 'Advertiser API docs', 'url' => url('/api/docs/advertiser')],
        ],
    ])

    <p class="text-muted">Base URL: <code>{{ $baseUrl }}/api/v1</code>. Create keys under <a href="{{ url('/advertiser/api') }}">Advertiser → API &amp; MCP</a>. Keys are shown once.</p>

    <h2 class="h5 mt-4" style="color:#1a585e;">Auth</h2>
    <p>Send a Bearer token. Query-string keys are rejected.</p>
    <pre class="small bg-light border rounded p-3">Authorization: Bearer slb_live_…</pre>
    <p>Live keys start with <code>slb_live_</code>. Test keys start with <code>slb_test_</code>. Older <code>slb_</code> catalog keys still search the catalog (scope <code>catalog:read</code>).</p>
    <p>Scopes: <code>catalog:read</code>, <code>sites:read</code>, <code>wallet:read</code>, <code>orders:read</code>, <code>orders:write</code>, <code>webhooks:write</code>.</p>

    <h2 class="h5 mt-4" style="color:#1a585e;">Endpoints</h2>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr><th>Method</th><th>Path</th><th>Scope</th></tr>
            </thead>
            <tbody>
                <tr><td>GET</td><td><code>/catalog</code></td><td>catalog:read</td></tr>
                <tr><td>GET</td><td><code>/sites/{id}</code></td><td>sites:read</td></tr>
                <tr><td>GET</td><td><code>/wallet</code></td><td>wallet:read</td></tr>
                <tr><td>POST</td><td><code>/orders</code></td><td>orders:write + Idempotency-Key</td></tr>
                <tr><td>GET</td><td><code>/orders</code></td><td>orders:read</td></tr>
                <tr><td>GET</td><td><code>/orders/{id}</code></td><td>orders:read</td></tr>
                <tr><td>POST</td><td><code>/orders/{id}/cancel</code></td><td>orders:write + Idempotency-Key</td></tr>
                <tr><td>GET/POST</td><td><code>/webhooks</code></td><td>webhooks:write</td></tr>
                <tr><td>GET/PATCH/DELETE</td><td><code>/webhooks/{id}</code></td><td>webhooks:write</td></tr>
                <tr><td>POST</td><td><code>/webhooks/{id}/test</code></td><td>webhooks:write</td></tr>
            </tbody>
        </table>
    </div>
    <p>Price is always calculated server-side from the current advertiser EUR price (plus the listing writing fee when the publisher writes, or a flat €35 SEOLinkBuildings writing fee otherwise). The SLB writing fee is never added to a publisher or partner payout. Partner rates, markup, and publisher identity are never returned.</p>

    <h2 class="h5 mt-4" style="color:#1a585e;">Examples</h2>
    <p class="small text-muted mb-1">Search the catalog</p>
    <pre class="small bg-light border rounded p-3">curl -sS -H "Authorization: Bearer slb_live_…" \
  "{{ $baseUrl }}/api/v1/catalog?country=no&amp;price_max=150"</pre>
    <p class="small text-muted mb-1">Site details</p>
    <pre class="small bg-light border rounded p-3">curl -sS -H "Authorization: Bearer slb_live_…" \
  "{{ $baseUrl }}/api/v1/sites/123"</pre>
    <p class="small text-muted mb-1">Wallet</p>
    <pre class="small bg-light border rounded p-3">curl -sS -H "Authorization: Bearer slb_live_…" \
  "{{ $baseUrl }}/api/v1/wallet"</pre>
    <p class="small text-muted mb-1">Place an order (prepaid wallet)</p>
    <pre class="small bg-light border rounded p-3">curl -sS -X POST -H "Authorization: Bearer slb_live_…" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"site_id":123,"anchor":"garden tools","target_url":"https://example.com/tools","content":"&lt;p&gt;Article HTML&lt;/p&gt;","content_format":"html"}' \
  "{{ $baseUrl }}/api/v1/orders"</pre>
    <p class="small text-muted mb-1">Have the article written (publisher if they offer it, otherwise SEOLinkBuildings at a flat €35). Optional <code>link_guarantee</code> is accepted only when the site’s publication period is at least 12 months or permanent. A guarantee refund debits the publisher the placement only — never the SLB writing fee.</p>
    <pre class="small bg-light border rounded p-3">curl -sS -X POST -H "Authorization: Bearer slb_live_…" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"site_id":123,"anchor":"garden tools","target_url":"https://example.com/tools","writing":true}' \
  "{{ $baseUrl }}/api/v1/orders"</pre>
    <p class="small text-muted mb-1">Cancel (only while the website would still allow it — upcoming scheduled, or unaccepted pending)</p>
    <pre class="small bg-light border rounded p-3">curl -sS -X POST -H "Authorization: Bearer slb_live_…" \
  -H "Idempotency-Key: $(uuidgen)" \
  "{{ $baseUrl }}/api/v1/orders/456/cancel"</pre>

    <h2 class="h5 mt-4" style="color:#1a585e;">Errors</h2>
    <ul>
        <li><code>401</code> <code>unauthorized</code> — missing or invalid key</li>
        <li><code>403</code> <code>forbidden</code> — wrong role, unverified, suspended, or missing scope</li>
        <li><code>402</code> <code>insufficient_funds</code> — wallet spendable is below the server-calculated price</li>
        <li><code>404</code> <code>not_found</code> — unknown site or another advertiser’s order</li>
        <li><code>409</code> <code>idempotency_conflict</code> — same Idempotency-Key, different body</li>
        <li><code>422</code> validation / <code>cannot_cancel</code> / <code>guarantee_not_offered</code></li>
        <li><code>429</code> rate limited (no Redis; per-key minute cap)</li>
        <li><code>503</code> <code>sandbox_unavailable</code> — test key while <code>is_sandbox</code> columns are missing (fail closed)</li>
    </ul>

    <h2 class="h5 mt-4" style="color:#1a585e;">Webhooks</h2>
    <p>Create an endpoint (secret <code>whsec_…</code> is shown once). Events: <code>order.created</code>, <code>order.accepted</code>, <code>order.rejected</code>, <code>order.published</code>, <code>order.cancelled</code>, plus <code>webhook.test</code>.</p>
    <p>Delivery headers match the partner API:</p>
    <ul>
        <li><code>SLB-Webhook-Id</code></li>
        <li><code>SLB-Webhook-Timestamp</code> — unix seconds</li>
        <li><code>SLB-Signature</code> — <code>v1=</code> HMAC-SHA256 of <code>{timestamp}.{raw body}</code></li>
    </ul>
    <pre class="small bg-light border rounded p-3">php -r '
$ts = $_SERVER["HTTP_SLB_WEBHOOK_TIMESTAMP"] ?? "";
$sig = $_SERVER["HTTP_SLB_SIGNATURE"] ?? "";
$body = file_get_contents("php://input");
$expected = "v1=".hash_hmac("sha256", $ts.".".$body, "whsec_YOUR_SECRET");
hash_equals($expected, $sig) || http_response_code(400);
'</pre>
    <p>Retries run from <code>schedule:run</code> (<code>advertisers:deliver-webhooks</code> every minute). There is no Redis.</p>

    <h2 class="h5 mt-4" style="color:#1a585e;">Sandbox</h2>
    <p>A test key only creates and sees sandbox orders: <code>is_sandbox=1</code>, no wallet debit, no publisher email, no invoice. Live keys never see those rows. If the sandbox columns are missing, test keys return <code>503 sandbox_unavailable</code>.</p>
</div>
@endsection

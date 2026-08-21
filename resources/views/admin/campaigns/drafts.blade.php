@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Drafts</h1>
            <p class="text-muted mb-0">Saved campaigns stay here until you send or delete them. Sending clones a new campaign and keeps the draft.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.campaigns.index') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i> New campaign
            </a>
            <a href="{{ route('admin.audiences.index') }}" class="btn btn-sm btn-outline-primary">
                Audience Inventory
            </a>
        </div>
    </div>

    @include('admin.campaigns.partials.tabs')

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Subject</th>
                            <th>Audience</th>
                            <th>Updated</th>
                            <th>Author</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($drafts as $draft)
                            <tr>
                                <td class="fw-semibold small">{{ $draft->name ?: '—' }}</td>
                                <td class="small">{{ \Illuminate\Support\Str::limit($draft->subject, 48) }}</td>
                                <td class="small">{{ $draft->audienceLabel() }}</td>
                                <td class="small text-muted">{{ optional($draft->updated_at)->format('M j, g:ia') ?: '—' }}</td>
                                <td class="small">{{ optional($draft->creator)->name ?: '—' }}</td>
                                <td class="text-end text-nowrap">
                                    <form method="POST" action="{{ route('admin.campaigns.preview') }}" class="d-inline campaign-draft-preview-form" target="_blank">
                                        @csrf
                                        <input type="hidden" name="subject" value="{{ $draft->subject }}">
                                        <input type="hidden" name="body_html" value="{{ $draft->body_html }}">
                                        <input type="hidden" name="cta_label" value="{{ $draft->cta_label }}">
                                        <input type="hidden" name="cta_url" value="{{ $draft->cta_url }}">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Preview</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.campaigns.send') }}" class="d-inline campaign-draft-send-form">
                                        @csrf
                                        <input type="hidden" name="draft_id" value="{{ $draft->id }}">
                                        <input type="hidden" name="name" value="{{ $draft->name }}">
                                        <input type="hidden" name="subject" value="{{ $draft->subject }}">
                                        <input type="hidden" name="body_html" value="{{ $draft->body_html }}">
                                        <input type="hidden" name="audience" value="{{ $draft->audience }}">
                                        <input type="hidden" name="cta_label" value="{{ $draft->cta_label }}">
                                        <input type="hidden" name="cta_url" value="{{ $draft->cta_url }}">
                                        <input type="hidden" name="respect_preferences" value="{{ $draft->respect_preferences ? '1' : '0' }}">
                                        <input type="hidden" name="include_unverified" value="{{ $draft->include_unverified ? '1' : '0' }}">
                                        @foreach($draft->selected_user_ids ?? [] as $userId)
                                            <input type="hidden" name="user_ids[]" value="{{ $userId }}">
                                        @endforeach
                                        <button type="submit" class="btn btn-sm btn-primary">Send</button>
                                    </form>
                                    <a href="{{ route('admin.campaigns.drafts.edit', $draft) }}" class="btn btn-sm btn-outline-primary">Open</a>
                                    <form method="POST" action="{{ route('admin.campaigns.drafts.destroy', $draft) }}" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                data-slb-confirm="Delete this draft? This cannot be undone."
                                                data-slb-confirm-title="Delete draft?"
                                                data-slb-confirm-text="Delete"
                                                data-slb-confirm-danger="1">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No drafts yet. Save from Compose to add one.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($drafts->hasPages())
            <div class="card-footer bg-white">{{ $drafts->links() }}</div>
        @endif
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white border-0">
            <strong><i class="fa fa-eye me-2 text-primary"></i>Preview</strong>
        </div>
        <div class="card-body">
            <iframe id="draftPreviewFrame" title="Draft campaign preview" sandbox referrerpolicy="no-referrer" style="width:100%; min-height:360px; border:1px solid #e2e8f0; border-radius:12px; background:#fff;"></iframe>
            <div class="small text-muted mt-2" id="draftPreviewStatus">Use Preview on a row to render the saved message.</div>
        </div>
    </div>
</div>
<script>
(function () {
    const frame = document.getElementById('draftPreviewFrame');
    const status = document.getElementById('draftPreviewStatus');
    if (!frame || !status) {
        return;
    }

    const countUrl = @json(route('admin.campaigns.recipient-count'));

    document.querySelectorAll('.campaign-draft-send-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.slbAllowSubmit === '1') {
                delete form.dataset.slbAllowSubmit;
                return;
            }

            e.preventDefault();
            const params = new URLSearchParams();
            params.set('audience', form.querySelector('[name=audience]').value);
            params.set('include_unverified', form.querySelector('[name=include_unverified]').value || '0');
            params.set('_token', form.querySelector('[name=_token]').value);
            form.querySelectorAll('[name="user_ids[]"]').forEach(function (input) {
                params.append('user_ids[]', input.value);
            });

            fetch(countUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-CSRF-TOKEN': form.querySelector('[name=_token]').value,
                },
                body: params.toString(),
            }).then(function (res) {
                if (!res.ok) {
                    throw new Error('count-failed');
                }
                return res.json();
            }).then(function (data) {
                const count = Number(data.count || 0);
                const label = data.label || 'the selected audience';
                if (count < 1) {
                    return slbAlert({ icon: 'error', title: 'No recipients found for that audience.', toast: false }).then(function () {
                        return false;
                    });
                }

                let text = 'Send to ' + count.toLocaleString() + ' recipient' + (count === 1 ? '' : 's') + ' (' + label + ')?';
                const excluded = Number(data.unverified_excluded || 0);
                if (excluded > 0) {
                    text += ' ' + excluded.toLocaleString() + ' unverified excluded.';
                }

                return slbConfirm({
                    title: 'Send campaign?',
                    text: text,
                    confirmText: 'Send now',
                    icon: 'question',
                });
            }).then(function (ok) {
                if (!ok) {
                    return;
                }
                form.dataset.slbAllowSubmit = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    HTMLFormElement.prototype.submit.call(form);
                }
            }).catch(function () {
                return slbAlert({ icon: 'error', title: 'Could not count recipients — refresh and retry.', toast: false });
            });
        });
    });

    document.querySelectorAll('.campaign-draft-preview-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            status.textContent = 'Rendering preview…';
            status.classList.remove('text-danger');
            status.classList.add('text-muted');

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json, text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(form),
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('preview failed');
                }
                return response.text();
            }).then(function (html) {
                frame.srcdoc = html;
                status.textContent = 'Saved draft preview.';
            }).catch(function () {
                status.textContent = 'Could not render this draft.';
                status.classList.add('text-danger');
                status.classList.remove('text-muted');
            });
        });
    });
})();
</script>
@endsection

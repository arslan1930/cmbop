{{-- Floating Report a problem + Suggestion box --}}
@php
    $isAuthed = auth()->check();
    $userName = auth()->user()->name ?? '';
    $userEmail = auth()->user()->email ?? '';
@endphp

<style>
.help-fab {
    position: fixed;
    right: 22px;
    bottom: 22px;
    z-index: 1080;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 10px;
}
.help-fab__btn {
    border: 0;
    border-radius: 999px;
    padding: 12px 16px;
    background: #0b6266;
    color: #fff;
    font-weight: 600;
    font-size: 14px;
    box-shadow: 0 10px 24px rgba(11, 98, 102, 0.28);
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.help-fab__btn:hover { background: #094e51; color: #fff; }
.help-fab__panel {
    width: min(380px, calc(100vw - 32px));
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
    overflow: hidden;
    display: none;
}
.help-fab__panel.is-open { display: block; }
.help-fab__tabs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
}
.help-fab__tab {
    border: 0;
    background: transparent;
    padding: 12px 10px;
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
}
.help-fab__tab.is-active {
    color: #0b6266;
    background: #fff;
    box-shadow: inset 0 -2px 0 #0b6266;
}
.help-fab__body { padding: 14px; }
.help-fab__pane { display: none; }
.help-fab__pane.is-active { display: block; }
.help-fab__hint { font-size: 12px; color: #64748b; margin-bottom: 10px; }
@media (max-width: 576px) {
    .help-fab { right: 14px; bottom: 14px; }
    .help-fab__btn span { display: none; }
}
</style>

<div class="help-fab" id="helpFeedbackWidget">
    <div class="help-fab__panel" id="helpFeedbackPanel" aria-hidden="true">
        <div class="help-fab__tabs">
            <button type="button" class="help-fab__tab is-active" data-pane="problem">Report a problem</button>
            <button type="button" class="help-fab__tab" data-pane="suggestion">Suggestion box</button>
        </div>
        <div class="help-fab__body">
            <div class="help-fab__pane is-active" id="helpPaneProblem">
                <p class="help-fab__hint">Tell us what went wrong — bugs, broken pages, or confusing flows.</p>
                <form id="helpProblemForm" class="vstack gap-2">
                    @unless($isAuthed)
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="Your name" required>
                        <input type="email" name="email" class="form-control form-control-sm" placeholder="Email" required>
                    @endunless
                    <input type="text" name="subject" class="form-control form-control-sm" placeholder="Subject" required maxlength="160">
                    <textarea name="message" class="form-control form-control-sm" rows="4" placeholder="What happened?" required minlength="10"></textarea>
                    <button type="submit" class="btn btn-sm btn-primary">Send report</button>
                </form>
            </div>
            <div class="help-fab__pane" id="helpPaneSuggestion">
                <p class="help-fab__hint">Share ideas to improve the marketplace, pricing, or publisher tools.</p>
                <form id="helpSuggestionForm" class="vstack gap-2">
                    @unless($isAuthed)
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="Your name" required>
                        <input type="email" name="email" class="form-control form-control-sm" placeholder="Email" required>
                    @endunless
                    <select name="category" class="form-select form-select-sm">
                        <option value="general">General</option>
                        <option value="feature">New feature</option>
                        <option value="ux">Usability / UX</option>
                        <option value="pricing">Pricing</option>
                        <option value="other">Other</option>
                    </select>
                    <textarea name="message" class="form-control form-control-sm" rows="4" placeholder="Your suggestion…" required minlength="10"></textarea>
                    <button type="submit" class="btn btn-sm btn-primary">Send suggestion</button>
                </form>
            </div>
        </div>
    </div>

    <button type="button" class="help-fab__btn" id="helpFeedbackToggle" aria-expanded="false" aria-controls="helpFeedbackPanel">
        <i class="fa-regular fa-life-ring" aria-hidden="true"></i>
        <span>Help & feedback</span>
    </button>
</div>

<script>
(function () {
    const panel = document.getElementById('helpFeedbackPanel');
    const toggle = document.getElementById('helpFeedbackToggle');
    if (!panel || !toggle) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        || '{{ csrf_token() }}';

    toggle.addEventListener('click', function () {
        const open = panel.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
    });

    panel.querySelectorAll('.help-fab__tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            panel.querySelectorAll('.help-fab__tab').forEach(t => t.classList.remove('is-active'));
            panel.querySelectorAll('.help-fab__pane').forEach(p => p.classList.remove('is-active'));
            tab.classList.add('is-active');
            const pane = document.getElementById(tab.dataset.pane === 'problem' ? 'helpPaneProblem' : 'helpPaneSuggestion');
            if (pane) pane.classList.add('is-active');
        });
    });

    async function submitForm(form, url) {
        const fd = new FormData(form);
        const payload = Object.fromEntries(fd.entries());
        payload.page_url = window.location.href;
        const btn = form.querySelector('[type="submit"]');
        if (btn) btn.disabled = true;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify(payload),
            });
            const data = await res.json().catch(() => ({}));
            if (window.Swal) {
                Swal.fire({
                    icon: data.success ? 'success' : 'error',
                    title: data.success ? 'Sent' : 'Could not send',
                    text: data.message || (data.success ? 'Thanks!' : 'Please try again.'),
                });
            } else {
                alert(data.message || (data.success ? 'Thanks!' : 'Please try again.'));
            }
            if (data.success) {
                form.reset();
                panel.classList.remove('is-open');
            }
        } catch (e) {
            if (window.Swal) Swal.fire({ icon: 'error', title: 'Network error', text: 'Please try again.' });
            else alert('Network error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    document.getElementById('helpProblemForm')?.addEventListener('submit', function (e) {
        e.preventDefault();
        submitForm(this, '{{ route('feedback.problem') }}');
    });
    document.getElementById('helpSuggestionForm')?.addEventListener('submit', function (e) {
        e.preventDefault();
        submitForm(this, '{{ route('feedback.suggestion') }}');
    });
})();
</script>

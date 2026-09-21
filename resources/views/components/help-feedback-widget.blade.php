{{-- Floating Report a problem + Suggestion box --}}
@php
    $isAuthed = auth()->check();
    $userName = auth()->user()?->name ?? '';
    $userEmail = auth()->user()?->email ?? '';
@endphp

<style>
.help-fab {
    position: fixed;
    right: 16px;
    bottom: 22px;
    z-index: var(--shell-z-fab, 1080);
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 10px;
    /* Stay inside the viewport — off-edge peek inflated scrollWidth on some browsers */
    transform: none;
    opacity: 0.72;
    transition: opacity .22s ease;
}
.help-fab:hover,
.help-fab:focus-within,
.help-fab.is-open {
    transform: none;
    opacity: 1;
}
.help-fab__chrome {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.help-fab__btn {
    border: 0;
    border-radius: 999px;
    padding: 12px 16px;
    background: var(--brand-primary, #1a585e);
    color: #fff;
    font-weight: 600;
    font-size: 14px;
    box-shadow: 0 10px 24px rgba(26, 88, 94, 0.28);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background-color .15s ease, transform .15s ease, box-shadow .15s ease;
}
.help-fab__btn:hover { background: var(--brand-primary-deep, #123f42); color: #fff; transform: none; box-shadow: 0 10px 24px rgba(26, 88, 94, 0.22); }
.help-fab__btn:focus-visible { outline: none; box-shadow: 0 0 0 3px var(--bs-focus-ring-color, rgba(58,174,178,.4)), 0 10px 24px rgba(11,98,102,.28); }
.help-fab__hide {
    border: 1px solid var(--brand-primary-border, #d9ecec);
    border-radius: 999px;
    width: 32px;
    height: 32px;
    padding: 0;
    background: #fff;
    color: var(--brand-ink-muted, #697078);
    font-size: 12px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.help-fab__hide:hover,
.help-fab__hide:focus-visible {
    color: var(--brand-primary, #1a585e);
    border-color: var(--brand-primary-soft, #3faeb2);
    outline: none;
}
.help-fab__show {
    border: 1px solid var(--brand-primary-border, #d9ecec);
    border-radius: 999px;
    width: 40px;
    height: 40px;
    padding: 0;
    background: #fff;
    color: var(--brand-primary, #1a585e);
    font-size: 16px;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
    display: none;
    align-items: center;
    justify-content: center;
}
.help-fab__show:hover,
.help-fab__show:focus-visible {
    background: var(--brand-primary-tint, #f4fbfb);
    outline: none;
}
.help-fab.is-hidden .help-fab__panel,
.help-fab.is-hidden .help-fab__chrome {
    display: none !important;
}
.help-fab.is-hidden .help-fab__show {
    display: inline-flex;
}
.help-fab.is-hidden {
    opacity: 0.9;
}
.help-fab__panel {
    width: min(380px, calc(100vw - 32px));
    background: #fff;
    border: 1px solid var(--brand-primary-border, #e5e7eb);
    border-radius: 16px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
    overflow: hidden;
    display: none;
    transform: translateY(8px);
    opacity: 0;
    transition: opacity .15s ease, transform .15s ease;
}
.help-fab__panel.is-open { display: block; transform: translateY(0); opacity: 1; }
.help-fab__tabs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    background: var(--brand-neutral-bg, #f8fafc);
    border-bottom: 1px solid #e5e7eb;
}
.help-fab__tab {
    border: 0;
    background: transparent;
    padding: 12px 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--brand-ink-muted, #697078);
    transition: color .15s ease, background-color .15s ease;
}
.help-fab__tab.is-active {
    color: var(--brand-primary, #1a585e);
    background: #fff;
    box-shadow: inset 0 -2px 0 var(--brand-primary, #1a585e);
}
.help-fab__tab:focus-visible { outline: 2px solid var(--brand-primary-soft, #3faeb2); outline-offset: -2px; }
.help-fab__body { padding: 14px; }
.help-fab__pane { display: none; }
.help-fab__pane.is-active { display: block; }
.help-fab__hint { font-size: 12px; color: var(--brand-ink-muted, #697078); margin-bottom: 10px; }
@media (max-width: 576px) {
    .help-fab {
        bottom: 14px;
        right: 12px;
        opacity: 0.92;
    }
    .help-fab__btn span { display: none; }
}
@media (prefers-reduced-motion: reduce) {
    .help-fab, .help-fab__btn, .help-fab__panel, .help-fab__tab { transition: none; }
    .help-fab__btn:hover { transform: none; }
}
</style>

<div class="help-fab" id="helpFeedbackWidget" title="Help & feedback">
    <div class="help-fab__panel" id="helpFeedbackPanel" role="dialog" aria-label="Help and feedback" aria-hidden="true">
        <div class="help-fab__tabs" role="tablist">
            <button type="button" class="help-fab__tab is-active" data-pane="problem" role="tab" aria-selected="true" aria-controls="helpPaneProblem">Report a problem</button>
            <button type="button" class="help-fab__tab" data-pane="suggestion" role="tab" aria-selected="false" aria-controls="helpPaneSuggestion">Suggestion box</button>
        </div>
        <div class="help-fab__body">
            <div class="help-fab__pane is-active" id="helpPaneProblem" role="tabpanel">
                <p class="help-fab__hint">Tell us what went wrong — bugs, broken pages, or confusing flows.</p>
                <form id="helpProblemForm" class="vstack gap-2">
                    @unless($isAuthed)
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="Your name" aria-label="Your name" required>
                        <input type="email" name="email" class="form-control form-control-sm" placeholder="Email" aria-label="Email" required>
                    @endunless
                    <input type="text" name="subject" class="form-control form-control-sm" placeholder="Subject" aria-label="Subject" required maxlength="160">
                    <textarea name="message" class="form-control form-control-sm" rows="4" placeholder="What happened?" aria-label="Describe the problem" required minlength="10"></textarea>
                    <button type="submit" class="btn btn-sm btn-primary">Send report</button>
                </form>
            </div>
            <div class="help-fab__pane" id="helpPaneSuggestion" role="tabpanel">
                <p class="help-fab__hint">Share ideas to improve the marketplace, pricing, or publisher tools.</p>
                <form id="helpSuggestionForm" class="vstack gap-2">
                    @unless($isAuthed)
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="Your name" aria-label="Your name" required>
                        <input type="email" name="email" class="form-control form-control-sm" placeholder="Email" aria-label="Email" required>
                    @endunless
                    <select name="category" class="form-select form-select-sm" aria-label="Suggestion category">
                        <option value="general">General</option>
                        <option value="feature">New feature</option>
                        <option value="ux">Usability / UX</option>
                        <option value="pricing">Pricing</option>
                        <option value="other">Other</option>
                    </select>
                    <textarea name="message" class="form-control form-control-sm" rows="4" placeholder="Your suggestion…" aria-label="Your suggestion" required minlength="10"></textarea>
                    <button type="submit" class="btn btn-sm btn-primary">Send suggestion</button>
                </form>
            </div>
        </div>
    </div>

    <div class="help-fab__chrome">
        <button type="button"
                class="help-fab__hide"
                id="helpFeedbackHide"
                aria-label="Hide help and feedback"
                title="Hide">
            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
        </button>
        <button type="button" class="help-fab__btn" id="helpFeedbackToggle" aria-expanded="false" aria-controls="helpFeedbackPanel" aria-label="Open help and feedback">
            <i class="fa-regular fa-life-ring" aria-hidden="true"></i>
            <span>Help &amp; feedback</span>
        </button>
    </div>
    <button type="button"
            class="help-fab__show"
            id="helpFeedbackShow"
            aria-label="Show help and feedback"
            title="Help &amp; feedback">
        <i class="fa-regular fa-life-ring" aria-hidden="true"></i>
    </button>
</div>

<script>
(function () {
    const root = document.getElementById('helpFeedbackWidget');
    const panel = document.getElementById('helpFeedbackPanel');
    const toggle = document.getElementById('helpFeedbackToggle');
    const hideBtn = document.getElementById('helpFeedbackHide');
    const showBtn = document.getElementById('helpFeedbackShow');
    if (!root || !panel || !toggle) return;

    const HIDDEN_KEY = 'helpFeedback.hidden';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        || '{{ csrf_token() }}';

    function readHidden() {
        try { return localStorage.getItem(HIDDEN_KEY) === '1'; } catch (_) { return false; }
    }
    function writeHidden(hidden) {
        try { localStorage.setItem(HIDDEN_KEY, hidden ? '1' : '0'); } catch (_) { /* private mode */ }
    }

    function setHidden(hidden) {
        root.classList.toggle('is-hidden', hidden);
        writeHidden(hidden);
        if (hidden) {
            setOpen(false);
            showBtn?.focus();
        } else {
            toggle.focus();
        }
    }

    function setOpen(open) {
        if (open && root.classList.contains('is-hidden')) {
            setHidden(false);
        }
        panel.classList.toggle('is-open', open);
        root.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) {
            const first = panel.querySelector('.help-fab__pane.is-active input, .help-fab__pane.is-active textarea');
            if (first) setTimeout(() => first.focus(), 60);
        }
    }

    if (readHidden()) {
        root.classList.add('is-hidden');
    }

    hideBtn?.addEventListener('click', function (e) {
        e.stopPropagation();
        setHidden(true);
    });
    showBtn?.addEventListener('click', function () {
        setHidden(false);
        setOpen(true);
    });

    toggle.addEventListener('click', function () {
        setOpen(!panel.classList.contains('is-open'));
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('is-open')) {
            setOpen(false);
            toggle.focus();
        }
    });
    document.addEventListener('click', function (e) {
        if (panel.classList.contains('is-open')
            && !panel.contains(e.target)
            && !toggle.contains(e.target)) {
            setOpen(false);
        }
    });

    panel.querySelectorAll('.help-fab__tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            panel.querySelectorAll('.help-fab__tab').forEach(t => {
                t.classList.remove('is-active');
                t.setAttribute('aria-selected', 'false');
            });
            panel.querySelectorAll('.help-fab__pane').forEach(p => p.classList.remove('is-active'));
            tab.classList.add('is-active');
            tab.setAttribute('aria-selected', 'true');
            const pane = document.getElementById(tab.dataset.pane === 'problem' ? 'helpPaneProblem' : 'helpPaneSuggestion');
            if (pane) {
                pane.classList.add('is-active');
                const first = pane.querySelector('input, textarea');
                if (first) first.focus();
            }
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
                slbAlert({ icon: data.success ? 'success' : 'error', title: data.message || (data.success ? 'Thanks!' : 'Please try again.') });
            }
            if (data.success) {
                form.reset();
                setOpen(false);
            }
        } catch (e) {
            if (window.Swal) Swal.fire({ icon: 'error', title: 'Network error', text: 'Please try again.' });
            else slbAlert({ icon: 'error', title: 'Network error', text: 'Please try again.' });
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

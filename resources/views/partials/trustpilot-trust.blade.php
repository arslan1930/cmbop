{{--
    Trustpilot trust signal.

    Links the public profile so visitors can read what customers actually said.
    No rating or review count is printed: we hold no verified copy of either, and
    inventing them would be both misleading and a Trustpilot guideline breach.
    The star count is the Trustpilot mark, not a claimed score.
--}}
@php
    $trustpilotUrl = config('services.trustpilot.review_url');
    $compact = $compact ?? false;
@endphp

@if($trustpilotUrl)
    <a class="trustpilot-trust {{ $compact ? 'trustpilot-trust--compact' : '' }}"
       href="{{ $trustpilotUrl }}"
       target="_blank"
       rel="noopener noreferrer nofollow"
       aria-label="{{ __('messages.trustpilot_aria') }}">
        <span class="trustpilot-trust__star" aria-hidden="true">
            <svg viewBox="0 0 24 24" role="presentation" focusable="false">
                <path fill="currentColor" d="M12 1.6l2.9 7h7.5l-6 4.8 2.2 7.4L12 16.4 5.4 20.8l2.2-7.4-6-4.8h7.5z"/>
            </svg>
        </span>
        <span class="trustpilot-trust__text">
            <strong>Trustpilot</strong>
            <span class="trustpilot-trust__cta">{{ __('messages.trustpilot_read_reviews') }}</span>
        </span>
    </a>
@endif

@once
    <style>
        .trustpilot-trust {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border: 1px solid var(--border-subtle, #e2e8f0);
            border-radius: 999px;
            background: var(--surface-1, #fff);
            font-size: 12px;
            line-height: 1.3;
            color: var(--brand-ink, #1e293b);
            text-decoration: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .trustpilot-trust:hover,
        .trustpilot-trust:focus-visible {
            border-color: #00b67a;
            box-shadow: 0 0 0 1px #00b67a;
            color: var(--brand-ink, #1e293b);
            text-decoration: none;
        }

        .trustpilot-trust__star {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            color: #00b67a;
        }

        .trustpilot-trust__star svg {
            width: 100%;
            height: 100%;
            display: block;
        }

        .trustpilot-trust__text {
            display: inline-flex;
            flex-direction: column;
            min-width: 0;
        }

        .trustpilot-trust__cta {
            color: var(--brand-ink-muted, #697078);
            font-size: 11px;
        }

        .trustpilot-trust--compact {
            padding: 4px 9px;
            font-size: 11px;
        }

        .trustpilot-trust--compact .trustpilot-trust__star {
            width: 16px;
            height: 16px;
        }
    </style>
@endonce

{{-- First-party visitor support chat. Order chat is separate. --}}
@php
    $company = 'SEOLinkBuildings';
    $welcome = 'Hi! 👋 How can we help you today?';
    if (class_exists(\App\Support\VisitorSupportChat::class)) {
        if (method_exists(\App\Support\VisitorSupportChat::class, 'companyName')) {
            $company = \App\Support\VisitorSupportChat::companyName();
        }
        if (method_exists(\App\Support\VisitorSupportChat::class, 'welcomeMessage')) {
            $welcome = \App\Support\VisitorSupportChat::welcomeMessage();
        }
    }
@endphp
<link href="{{ asset('assets/css/visitor-support-chat.css') }}?v={{ @filemtime(public_path('assets/css/visitor-support-chat.css')) ?: '1' }}" rel="stylesheet">
<div
    class="slb-live-chat"
    id="slbLiveChat"
    data-endpoint="{{ \Illuminate\Support\Facades\Route::has('support.chat') ? route('support.chat') : url('/support/chat') }}"
    data-storage-key="slb-support-chat-v1"
    data-welcome="{{ $welcome }}"
>
    <div
        class="slb-live-chat__panel"
        id="slbLiveChatPanel"
        role="dialog"
        aria-labelledby="slbLiveChatTitle"
        aria-modal="true"
        aria-hidden="true"
    >
        <div class="slb-live-chat__header">
            <div class="slb-live-chat__brand">
                <p class="slb-live-chat__name" id="slbLiveChatTitle">{{ $company }}</p>
                <p class="slb-live-chat__status">
                    <span class="slb-live-chat__dot" aria-hidden="true"></span>
                    Available
                </p>
            </div>
            <button type="button" class="slb-live-chat__close" id="slbLiveChatClose" aria-label="Close chat">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="currentColor">
                    <path d="M18.3 5.71a1 1 0 0 0-1.41 0L12 10.59 7.11 5.7A1 1 0 0 0 5.7 7.11L10.59 12 5.7 16.89a1 1 0 1 0 1.41 1.41L12 13.41l4.89 4.89a1 1 0 0 0 1.41-1.41L13.41 12l4.89-4.89a1 1 0 0 0 0-1.4z"/>
                </svg>
            </button>
        </div>
        <div
            class="slb-live-chat__messages"
            id="slbLiveChatMessages"
            role="log"
            aria-live="polite"
            aria-relevant="additions"
        ></div>
        <div
            class="slb-live-chat__typing"
            id="slbLiveChatTyping"
            aria-hidden="true"
            aria-label="Support is typing"
        >
            <span></span><span></span><span></span>
        </div>
        <p class="slb-live-chat__error" id="slbLiveChatError" role="alert"></p>
        <form class="slb-live-chat__composer" id="slbLiveChatForm">
            <textarea
                class="slb-live-chat__input"
                id="slbLiveChatInput"
                name="message"
                rows="1"
                maxlength="4000"
                placeholder="Type a message"
                autocomplete="off"
                aria-label="Message"
            ></textarea>
            <button type="submit" class="slb-live-chat__send" id="slbLiveChatSend" aria-label="Send message">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="currentColor">
                    <path d="M3.4 20.4 21 12 3.4 3.6 3 10.3 15.2 12 3 13.7z"/>
                </svg>
            </button>
        </form>
    </div>
    <button
        type="button"
        class="slb-live-chat__launcher"
        id="slbLiveChatLauncher"
        aria-label="Open live chat"
        aria-expanded="false"
        aria-controls="slbLiveChatPanel"
    >
        <span class="slb-live-chat__launcher-lottie" id="slbLiveChatLottie" data-lottie="{{ asset('assets/vendor/lottie/chatbot.json') }}" aria-hidden="true"></span>
    </button>
</div>
<script src="{{ asset('assets/vendor/lottie-web/lottie_light.min.js') }}?v={{ @filemtime(public_path('assets/vendor/lottie-web/lottie_light.min.js')) ?: '1' }}" defer></script>
<script src="{{ asset('js/visitor-support-chat.js') }}?v={{ @filemtime(public_path('js/visitor-support-chat.js')) ?: '1' }}" defer></script>

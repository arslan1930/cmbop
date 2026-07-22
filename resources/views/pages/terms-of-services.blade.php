@extends('layouts.app')

@section('title', __('messages.terms_of_service').' — SEOLinkBuildings')
@section('description', __('messages.meta_default_description'))
@section('canonical', localized_url('terms-of-services'))

@section('content')

@include('components.marketing-page-hero', [
    'title' => __('messages.terms_hero_title'),
    'subtitle' => __('messages.terms_hero_subtitle').' '.__('messages.effective_date').': '.__('messages.effective_date_value').'.',
])


<!-- ==================== TERMS CONTENT ==================== -->
<div class="container py-5" style="max-width:900px;">

    <!-- ===== 1. ACCEPTANCE OF TERMS ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#5bc4c7,#38b2ac);">1</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">{{ __('messages.section1_title') }}</h2>
        </div>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            {{ __('messages.section1_text') }}
        </p>
    </div>


    <!-- ===== 2. ELIGIBILITY ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#667eea,#764ba2);">2</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">{{ __('messages.section2_title') }}</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            {{ __('messages.section2_text1') }} <strong>{{ __('messages.age_requirement') }}</strong> {{ __('messages.section2_text2') }}
        </p>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            {{ __('messages.section2_text3') }}
        </p>
    </div>


    <!-- ===== 3. ACCOUNT REGISTRATION ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#f093fb,#f5576c);">3</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">{{ __('messages.section3_title') }}</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            {{ __('messages.section3_text1') }}
        </p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>{{ __('messages.section3_list1_1') }}</li>
            <li>{{ __('messages.section3_list1_2') }}</li>
            <li>{{ __('messages.section3_list1_3') }}</li>
        </ul>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            {{ __('messages.section3_text2') }}
        </p>
    </div>


    <!-- ===== 4. USE OF SERVICES ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#4CAF50,#66BB6A);">4</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">{{ __('messages.section4_title') }}</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            {{ __('messages.section4_text1') }} <strong>{{ __('messages.not_to') }}</strong>:
        </p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>{{ __('messages.section4_list1_1') }}</li>
            <li>{{ __('messages.section4_list1_2') }}</li>
            <li>{{ __('messages.section4_list1_3') }}</li>
            <li>{{ __('messages.section4_list1_4') }}</li>
        </ul>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            {{ __('messages.section4_text2') }}
        </p>
    </div>


    <!-- ===== 5. INTELLECTUAL PROPERTY ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#FFB74D,#FF9800);">5</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">{{ __('messages.section5_title') }}</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            {{ __('messages.section5_text1') }} <strong>seolinkbuildings.com</strong> {{ __('messages.section5_text2') }}
        </p>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            {{ __('messages.section5_text3') }}
        </p>
    </div>


    <!-- ===== 6. PAYMENT AND BILLING ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#1e40af,#3b82f6);">6</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">{{ __('messages.section6_title') }}</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            {{ __('messages.section6_text1') }}
        </p>
        <ul class="mb-0" style="color:#555; line-height:2;">
            <li>{{ __('messages.section6_list1_1') }}</li>
            <li>{{ __('messages.section6_list1_2') }}</li>
            <li>{{ __('messages.section6_list1_3') }}</li>
            <li>{{ __('messages.section6_list1_4') }}</li>
        </ul>
    </div>


    <!-- ===== 7. THIRD-PARTY LINKS ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#06b6d4,#0891b2);">7</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">{{ __('messages.section7_title') }}</h2>
        </div>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            {{ __('messages.section7_text') }}
        </p>
    </div>


    <!-- ===== 8. DISCLAIMERS ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4" style="background:#fffbeb; border-left:4px solid #f59e0b;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#f59e0b,#fbbf24);">8</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">{{ __('messages.section8_title') }}</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            {{ __('messages.section8_text1') }} <strong>"{{ __('messages.as_is') }}"</strong> {{ __('messages.section8_text2') }}
        </p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>{{ __('messages.section8_list1_1') }}</li>
            <li>{{ __('messages.section8_list1_2') }}</li>
            <li>{{ __('messages.section8_list1_3') }}</li>
        </ul>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            {{ __('messages.section8_text3') }}
        </p>
    </div>


    <!-- ===== 9. LIMITATION OF LIABILITY ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4" style="background:#fef2f2; border-left:4px solid #FF4757;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#FF4757,#ee5a6f);">9</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">{{ __('messages.section9_title') }}</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            {{ __('messages.section9_text1') }} <strong>{{ __('messages.not_be_liable') }}</strong> {{ __('messages.section9_text2') }}
        </p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>{{ __('messages.section9_list1_1') }}</li>
            <li>{{ __('messages.section9_list1_2') }}</li>
            <li>{{ __('messages.section9_list1_3') }}</li>
        </ul>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            {{ __('messages.section9_text3') }}
        </p>
    </div>


    <!-- ===== 10. INDEMNIFICATION ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#8E44AD,#9b59b6);">10</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">{{ __('messages.section10_title') }}</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            {{ __('messages.section10_text1') }}
        </p>
        <ul class="mb-0" style="color:#555; line-height:2;">
            <li>{{ __('messages.section10_list1_1') }}</li>
            <li>{{ __('messages.section10_list1_2') }}</li>
            <li>{{ __('messages.section10_list1_3') }}</li>
        </ul>
    </div>


    <!-- ===== 11. CHANGES TO TERMS ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#FF6F61,#ee5a6f);">11</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">{{ __('messages.section11_title') }}</h2>
        </div>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            {{ __('messages.section11_text') }}
        </p>
    </div>


    <!-- ===== 12. CONTACT INFO ===== -->
    <div class="mb-5 p-4 p-md-5 rounded-4" style="background:linear-gradient(135deg, #5bc4c7, #38b2ac); box-shadow:0 15px 35px rgba(78,205,203,0.25);">
        <h2 class="fw-bold mb-3" style="color:white; font-size:1.5rem;">{{ __('messages.contact_title') }}</h2>
        <p style="color:rgba(255,255,255,0.9); line-height:1.7; margin-bottom:1.25rem;">
            {{ __('messages.contact_text') }}
        </p>

        <div class="d-flex flex-column gap-3">
            <div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background:rgba(255,255,255,0.15); backdrop-filter:blur(10px);">
                <div style="width:38px; height:38px; background:white; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#38b2ac" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </div>
                <div>
                    <p class="mb-0" style="color:rgba(255,255,255,0.8); font-size:0.85rem;">{{ __('messages.email_label') }}</p>
                    <a href="mailto:support@seolinkbuildings.com" style="color:white; font-weight:600; text-decoration:none;">support@seolinkbuildings.com</a>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background:rgba(255,255,255,0.15); backdrop-filter:blur(10px);">
                <div style="width:38px; height:38px; background:white; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#38b2ac" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                </div>
                <div>
                    <p class="mb-0" style="color:rgba(255,255,255,0.8); font-size:0.85rem;">{{ __('messages.website_label') }}</p>
                    <a href="https://seolinkbuildings.com" target="_blank" style="color:white; font-weight:600; text-decoration:none;">https://seolinkbuildings.com</a>
                </div>
            </div>
        </div>
    </div>

</div>

<style>
    /* Numbered circle badges */
    .terms-num {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 800;
        font-size: 1.1rem;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
</style>

@endsection
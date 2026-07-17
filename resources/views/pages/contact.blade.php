@extends('layouts.app')

@section('title', __('messages.meta_contact_title'))
@section('description', __('messages.meta_contact_description'))
@section('canonical', localized_url('contact'))

@section('content')

<!-- ==================== CONTACT HERO ==================== -->
<section style="position:relative; width:100%; padding:48px 0 60px; overflow:hidden; background:linear-gradient(180deg, #f0f5ff 0%, #f5faff 100%);">

    <!-- Background Shapes -->
    <div style="position:absolute; top:10%; left:-100px; width:250px; height:250px; border-radius:50%; background:#FF4757; opacity:0.08; z-index:1;"></div>
    <div style="position:absolute; bottom:-80px; right:-60px; width:220px; height:220px; border-radius:50%; background:#FFD93D; opacity:0.15; z-index:1;"></div>
    <div style="position:absolute; top:30%; right:10%; width:60px; height:60px; border-radius:50%; border:10px solid #4ECDCB; opacity:0.4; z-index:1;"></div>
    <div style="position:absolute; top:15%; right:25%; width:10px; height:10px; border-radius:50%; background:#4ECDCB; opacity:0.6; z-index:1;"></div>
    <div style="position:absolute; bottom:20%; left:15%; width:12px; height:12px; border-radius:50%; background:#FF4757; opacity:0.4; z-index:1;"></div>

    <div class="container" style="position:relative; z-index:5; max-width:900px;">
        <div class="text-center">
            <h1 style="font-size:3rem; font-weight:800; color:#1a1a2e; letter-spacing:-1px; margin-bottom:1rem;">
                {{ __('messages.contact_hero_title') }}
            </h1>
            <p style="font-size:1.1rem; color:#666; max-width:600px; margin:0 auto;">
                {{ __('messages.contact_hero_subtitle') }}
            </p>
        </div>
    </div>
</section>


<!-- ==================== CONTACT CONTENT ==================== -->
<div class="container py-5" style="max-width:900px;">

    <!-- ===== CEO SECTION ===== -->
    <div class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-start gap-4 mb-4 flex-wrap">
            <div style="width:90px; height:90px; border-radius:50%; overflow:hidden; flex-shrink:0; border:3px solid #4ECDCB; box-shadow:0 4px 12px rgba(78,205,203,0.25);">
                <img src="{{ asset('assets/img/arslan.jpg') }}" 
                     alt="M Arslan - Founder & CEO" 
                     style="width:100%; height:100%; object-fit:cover;"
                     onerror="this.src='{{ asset('assets/img/support-avatar.jpg') }}'">
            </div>
            <div>
                <p class="fw-bold mb-1" style="font-size:1.25rem; color:#1a1a2e;">{{ __('messages.ceo_name') }}</p>
                <p class="mb-0" style="color:#4ECDCB; font-weight:600;">{{ __('messages.ceo_title') }}</p>
            </div>
        </div>
        <div class="p-4 rounded-3" style="background:#f7f9fc; border-left:4px solid #4ECDCB;">
            <p class="mb-0 fst-italic" style="color:#555; line-height:1.7;">
                {{ __('messages.ceo_quote') }}
            </p>
        </div>
    </div>


    <!-- ===== CONTACT INFO GRID ===== -->
    <div class="mb-5">
        <h2 class="fw-bold mb-4" style="color:#1a1a2e; font-size:1.6rem;">{{ __('messages.contact_info_title') }}</h2>
        <div class="row g-3">

            <!-- Email -->
            <div class="col-md-6">
                <div class="p-4 rounded-3 h-100 d-flex align-items-start gap-3" style="background:white; border:1px solid #eef0f3; transition:all 0.3s;">
                    <div style="width:44px; height:44px; background:linear-gradient(135deg,#4ECDCB,#38b2ac); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                    <div>
                        <p class="fw-bold mb-1" style="color:#1a1a2e;">{{ __('messages.contact_email_label') }}</p>
                        <a href="mailto:support@seolinkbuildings.com" style="color:#4ECDCB; text-decoration:none; font-weight:500;">
                            support@seolinkbuildings.com
                        </a>
                    </div>
                </div>
            </div>

            <!-- LinkedIn -->
            <div class="col-md-6">
                <div class="p-4 rounded-3 h-100 d-flex align-items-start gap-3" style="background:white; border:1px solid #eef0f3; transition:all 0.3s;">
                    <div style="width:44px; height:44px; background:linear-gradient(135deg,#0077B5,#0a66c2); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M20.5 2h-17A1.5 1.5 0 002 3.5v17A1.5 1.5 0 003.5 22h17a1.5 1.5 0 001.5-1.5v-17A1.5 1.5 0 0020.5 2zM8 19H5v-9h3zM6.5 8.25A1.75 1.75 0 118.3 6.5a1.78 1.78 0 01-1.8 1.75zM19 19h-3v-4.74c0-1.42-.6-1.93-1.38-1.93A1.74 1.74 0 0013 14.19a.66.66 0 000 .14V19h-3v-9h2.9v1.3a3.11 3.11 0 012.7-1.4c1.55 0 3.36.86 3.36 3.66z"/></svg>
                    </div>
                    <div>
                        <p class="fw-bold mb-1" style="color:#1a1a2e;">{{ __('messages.contact_linkedin_label') }}</p>
                        <a href="https://linkedin.com/company/seolinkbuildings" target="_blank" style="color:#0a66c2; text-decoration:none; font-weight:500;">
                            linkedin.com/company/seolinkbuildings
                        </a>
                    </div>
                </div>
            </div>

            <!-- Telegram -->
            <div class="col-md-6">
                <div class="p-4 rounded-3 h-100 d-flex align-items-start gap-3" style="background:white; border:1px solid #eef0f3; transition:all 0.3s;">
                    <div style="width:44px; height:44px; background:linear-gradient(135deg,#229ED9,#27a7e5); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/></svg>
                    </div>
                    <div>
                        <p class="fw-bold mb-1" style="color:#1a1a2e;">{{ __('messages.contact_telegram_label') }}</p>
                        <p class="mb-0" style="color:#229ED9; font-weight:500;">@arslan_seolinkbuildings</p>
                    </div>
                </div>
            </div>

            <!-- Business Hours -->
            <div class="col-md-6">
                <div class="p-4 rounded-3 h-100 d-flex align-items-start gap-3" style="background:white; border:1px solid #eef0f3; transition:all 0.3s;">
                    <div style="width:44px; height:44px; background:linear-gradient(135deg,#f59e0b,#fbbf24); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <p class="fw-bold mb-1" style="color:#1a1a2e;">{{ __('messages.contact_hours_label') }}</p>
                        <p class="mb-0" style="color:#666; font-size:0.92rem;">{{ __('messages.contact_hours_value') }}</p>
                        <p class="mb-0" style="color:#999; font-size:0.82rem;">{{ __('messages.contact_response_time') }}</p>
                    </div>
                </div>
            </div>

        </div>
    </div>


    <!-- ===== ABOUT SECTION ===== -->
    <div class="mb-5 p-4 p-md-5 rounded-4" style="background:linear-gradient(135deg, #f0f5ff, #f5faff); border:1px solid #e0e8f5;">
        <h2 class="fw-bold mb-3" style="color:#1a1a2e; font-size:1.5rem;">{{ __('messages.about_title') }}</h2>
        <p class="mb-0" style="color:#555; line-height:1.8;">
            {{ __('messages.about_text') }}
        </p>
    </div>


    <!-- ===== ENTERPRISE SECTION ===== -->
    <div class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#1a1a2e,#3b3b5c); border-radius:10px; display:flex; align-items:center; justify-content:center;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">{{ __('messages.enterprise_title') }}</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            {{ __('messages.enterprise_description') }}
        </p>
        <ul class="list-unstyled mb-0">
            <li class="d-flex align-items-start gap-2 mb-2">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4ECDCB" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:3px;"><polyline points="20 6 9 17 4 12"/></svg>
                <span style="color:#555;">{{ __('messages.enterprise_feature_1') }}</span>
            </li>
            <li class="d-flex align-items-start gap-2 mb-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4ECDCB" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:3px;"><polyline points="20 6 9 17 4 12"/></svg>
                <span style="color:#555;">{{ __('messages.enterprise_feature_2') }}</span>
            </li>
        </ul>
    </div>


    <!-- ===== CTA ===== -->
    <div class="text-center py-5 px-4 rounded-4" style="background:linear-gradient(135deg, #4ECDCB, #38b2ac); box-shadow:0 15px 35px rgba(78,205,203,0.25);">
        <h2 class="fw-bold mb-3" style="color:white; font-size:1.75rem;">{{ __('messages.cta_ready_title') }}</h2>
        <p class="mb-4" style="color:rgba(255,255,255,0.9); max-width:500px; margin:0 auto;">
            {{ __('messages.cta_ready_subtitle') }}
        </p>
        <a href="mailto:support@seolinkbuildings.com" 
           class="d-inline-flex align-items-center gap-2"
           style="background:white; color:#38b2ac; padding:14px 36px; border-radius:50px; font-weight:700; text-decoration:none; box-shadow:0 8px 20px rgba(0,0,0,0.1); transition:all 0.3s;"
           onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 12px 28px rgba(0,0,0,0.15)';"
           onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.1)';">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            {{ __('messages.cta_email_button') }}
        </a>
    </div>

</div>

<style>
    /* Card hover effect */
    .container .row .col-md-6 > div:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.06);
        border-color: #4ECDCB !important;
    }
</style>

@endsection
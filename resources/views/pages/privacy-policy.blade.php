@extends('layouts.app')

@section('title', 'Privacy Policy — SEOLinkBuildings')
@section('description', 'Read how SEOLinkBuildings collects, uses, and protects your personal data on our content marketplace platform.')

@section('content')

@php
  // Get locale from URL segment
  $segments = request()->segments();
  $availableLocales = ['de', 'fr', 'nl'];
  $currentLocale = 'en';
  
  if (!empty($segments) && in_array($segments[0], $availableLocales)) {
    $currentLocale = $segments[0];
    app()->setLocale($currentLocale);
  }
@endphp

<!-- ==================== PRIVACY HERO ==================== -->
<section style="position:relative; width:100%; padding:140px 0 60px; overflow:hidden; background:linear-gradient(180deg, #f0f5ff 0%, #f5faff 100%);">

    <!-- Background Shapes -->
    <div style="position:absolute; top:10%; left:-100px; width:250px; height:250px; border-radius:50%; background:#FF4757; opacity:0.08; z-index:1;"></div>
    <div style="position:absolute; bottom:-80px; right:-60px; width:220px; height:220px; border-radius:50%; background:#FFD93D; opacity:0.15; z-index:1;"></div>
    <div style="position:absolute; top:30%; right:10%; width:60px; height:60px; border-radius:50%; border:10px solid #4ECDCB; opacity:0.4; z-index:1;"></div>
    <div style="position:absolute; top:15%; right:25%; width:10px; height:10px; border-radius:50%; background:#4ECDCB; opacity:0.6; z-index:1;"></div>
    <div style="position:absolute; bottom:20%; left:15%; width:12px; height:12px; border-radius:50%; background:#FF4757; opacity:0.4; z-index:1;"></div>

    <div class="container" style="position:relative; z-index:5; max-width:900px;">
        <div class="text-center">
            <!-- Shield Icon -->
            <div style="display:inline-flex; align-items:center; justify-content:center; width:70px; height:70px; background:linear-gradient(135deg, #4ECDCB, #38b2ac); border-radius:18px; margin-bottom:1.25rem; box-shadow:0 12px 30px rgba(78,205,203,0.35);">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <h1 style="font-size:3rem; font-weight:800; color:#1a1a2e; letter-spacing:-1px; margin-bottom:1rem;">
                {{ __('messages.privacy_hero_title') }}
            </h1>
            <p style="font-size:1.1rem; color:#666; max-width:680px; margin:0 auto; line-height:1.7;">
                {{ __('messages.privacy_hero_subtitle') }}
            </p>
            <p style="color:#999; margin-top:1.25rem; font-size:0.9rem;">
                <strong style="color:#1a1a2e;">{{ __('messages.last_updated') }}:</strong> {{ __('messages.last_updated_value') }}
            </p>
        </div>
    </div>
</section>


<!-- ==================== PRIVACY CONTENT ==================== -->
<div class="container py-5" style="max-width:900px;">

    <!-- ===== TABLE OF CONTENTS ===== -->
    <div class="mb-5 p-4 rounded-4" style="background:linear-gradient(135deg, #f0f5ff, #f5faff); border:1px solid #e0e8f5;">
        <h3 class="fw-bold mb-3" style="color:#1a1a2e; font-size:1.1rem;">{{ __('messages.quick_navigation') }}</h3>
        <div class="row g-2">
            <div class="col-md-6">
                <a href="#info-collect" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ {{ __('messages.nav_info_collect') }}</a>
                <a href="#info-use" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ {{ __('messages.nav_info_use') }}</a>
                <a href="#info-share" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ {{ __('messages.nav_info_share') }}</a>
                <a href="#data-security" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ {{ __('messages.nav_data_security') }}</a>
            </div>
            <div class="col-md-6">
                <a href="#data-retention" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ {{ __('messages.nav_data_retention') }}</a>
                <a href="#your-rights" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ {{ __('messages.nav_your_rights') }}</a>
                <a href="#children-privacy" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ {{ __('messages.nav_children_privacy') }}</a>
                <a href="#contact-info" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ {{ __('messages.nav_contact_info') }}</a>
            </div>
        </div>
    </div>


    <!-- ===== 1. INFORMATION WE COLLECT ===== -->
    <div id="info-collect" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#4ECDCB,#38b2ac); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">{{ __('messages.section1_title') }}</h2>
        </div>

        <h3 class="fw-bold mt-4 mb-3" style="color:#1a1a2e; font-size:1.15rem;">{{ __('messages.section1_sub1') }}</h3>
        <p style="color:#555; line-height:1.7;">{{ __('messages.section1_text1') }}</p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>{{ __('messages.section1_list1_1') }}</li>
            <li>{{ __('messages.section1_list1_2') }}</li>
            <li>{{ __('messages.section1_list1_3') }}</li>
            <li>{{ __('messages.section1_list1_4') }}</li>
        </ul>
        <p style="color:#555; line-height:1.7;">{{ __('messages.section1_text2') }}</p>

        <h3 class="fw-bold mt-4 mb-3" style="color:#1a1a2e; font-size:1.15rem;">{{ __('messages.section1_sub2') }}</h3>
        <p style="color:#555; line-height:1.7;">{{ __('messages.section1_text3') }}</p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>{{ __('messages.section1_list2_1') }}</li>
            <li>{{ __('messages.section1_list2_2') }}</li>
            <li>{{ __('messages.section1_list2_3') }}</li>
            <li>{{ __('messages.section1_list2_4') }}</li>
            <li>{{ __('messages.section1_list2_5') }}</li>
        </ul>

        <h3 class="fw-bold mt-4 mb-3" style="color:#1a1a2e; font-size:1.15rem;">{{ __('messages.section1_sub3') }}</h3>
        <p style="color:#555; line-height:1.7;">{{ __('messages.section1_text4') }}</p>
        <p class="mb-0" style="color:#555; line-height:1.7;">{{ __('messages.section1_text5') }}</p>
    </div>


    <!-- ===== 2. HOW WE USE INFORMATION ===== -->
    <div id="info-use" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#667eea,#764ba2); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">{{ __('messages.section2_title') }}</h2>
        </div>

        <h3 class="fw-bold mt-3 mb-3" style="color:#1a1a2e; font-size:1.15rem;">{{ __('messages.section2_sub1') }}</h3>
        <p style="color:#555; line-height:1.7;">{{ __('messages.section2_text1') }}</p>
        <ul class="mb-4" style="color:#555; line-height:2;">
            <li>{{ __('messages.section2_list1_1') }}</li>
            <li>{{ __('messages.section2_list1_2') }}</li>
            <li>{{ __('messages.section2_list1_3') }}</li>
            <li>{{ __('messages.section2_list1_4') }}</li>
        </ul>

        <h3 class="fw-bold mt-4 mb-3" style="color:#1a1a2e; font-size:1.15rem;">{{ __('messages.section2_sub2') }}</h3>
        <p style="color:#555; line-height:1.7;">{{ __('messages.section2_text2') }}</p>

        <!-- Info Callout -->
        <div class="mt-4 p-4 rounded-3" style="background:#eff6ff; border-left:4px solid #3b82f6;">
            <div class="d-flex align-items-start gap-2 mb-2">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <h4 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.05rem;">{{ __('messages.section2_callout_title') }}</h4>
            </div>
            <p class="mb-0" style="color:#555; line-height:1.7;">{{ __('messages.section2_callout_text') }}</p>
        </div>
    </div>


    <!-- ===== 3. SHARING YOUR INFORMATION ===== -->
    <div id="info-share" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#f093fb,#f5576c); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">{{ __('messages.section3_title') }}</h2>
        </div>

        <h3 class="fw-bold mt-3 mb-3" style="color:#1a1a2e; font-size:1.15rem;">{{ __('messages.section3_sub1') }}</h3>
        <p style="color:#555; line-height:1.7;">{{ __('messages.section3_text1') }}</p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>{{ __('messages.section3_list1_1') }}</li>
            <li>{{ __('messages.section3_list1_2') }}</li>
            <li>{{ __('messages.section3_list1_3') }}</li>
            <li>{{ __('messages.section3_list1_4') }}</li>
        </ul>
        <p style="color:#555; line-height:1.7;">{{ __('messages.section3_text2') }}</p>

        <!-- Warning Callout -->
        <div class="mt-4 p-4 rounded-3" style="background:#fffbeb; border-left:4px solid #f59e0b;">
            <div class="d-flex align-items-start gap-2 mb-2">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:2px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <h4 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.05rem;">{{ __('messages.section3_callout_title') }}</h4>
            </div>
            <p class="mb-0" style="color:#555; line-height:1.7;">{{ __('messages.section3_callout_text') }}</p>
        </div>

        <h3 class="fw-bold mt-4 mb-3" style="color:#1a1a2e; font-size:1.15rem;">{{ __('messages.section3_sub2') }}</h3>
        <p class="mb-0" style="color:#555; line-height:1.7;">{{ __('messages.section3_text3') }}</p>
    </div>


    <!-- ===== 4. DATA SECURITY ===== -->
    <div id="data-security" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#4CAF50,#66BB6A); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">{{ __('messages.section4_title') }}</h2>
        </div>

        <h3 class="fw-bold mt-3 mb-3" style="color:#1a1a2e; font-size:1.15rem;">{{ __('messages.section4_sub1') }}</h3>
        <p style="color:#555; line-height:1.7;">{{ __('messages.section4_text1') }}</p>

        <h3 class="fw-bold mt-4 mb-3" style="color:#1a1a2e; font-size:1.15rem;">{{ __('messages.section4_sub2') }}</h3>
        <p class="mb-0" style="color:#555; line-height:1.7;">{{ __('messages.section4_text2') }}</p>
    </div>


    <!-- ===== 5. DATA RETENTION ===== -->
    <div id="data-retention" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#FFB74D,#FF9800); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">{{ __('messages.section5_title') }}</h2>
        </div>

        <p style="color:#555; line-height:1.7;">{{ __('messages.section5_text1') }}</p>
        <ul class="mb-0" style="color:#555; line-height:2;">
            <li>{{ __('messages.section5_list1_1') }}</li>
            <li>{{ __('messages.section5_list1_2') }}</li>
            <li>{{ __('messages.section5_list1_3') }}</li>
        </ul>
    </div>


    <!-- ===== 6. YOUR RIGHTS UNDER GDPR ===== -->
    <div id="your-rights" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#1e40af,#3b82f6); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">{{ __('messages.section6_title') }}</h2>
        </div>

        <p style="color:#555; line-height:1.7;">{{ __('messages.section6_text1') }}</p>
        <ul class="mb-4" style="color:#555; line-height:2;">
            <li>{{ __('messages.section6_list1_1') }}</li>
            <li>{{ __('messages.section6_list1_2') }}</li>
            <li>{{ __('messages.section6_list1_3') }}</li>
            <li>{{ __('messages.section6_list1_4') }}</li>
            <li>{{ __('messages.section6_list1_5') }}</li>
            <li>{{ __('messages.section6_list1_6') }}</li>
            <li>{{ __('messages.section6_list1_7') }}</li>
        </ul>

        <!-- Success Callout -->
        <div class="p-4 rounded-3" style="background:#f0fdf4; border-left:4px solid #4CAF50;">
            <div class="d-flex align-items-start gap-2 mb-2">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4CAF50" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:2px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <h4 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.05rem;">{{ __('messages.section6_callout_title') }}</h4>
            </div>
            <p style="color:#555; line-height:1.7;">{{ __('messages.section6_callout_text1') }}</p>
            <p style="color:#1a1a2e; font-weight:700; font-size:1.05rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4ECDCB" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:6px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <a href="mailto:support@seolinkbuildings.com" style="color:#4ECDCB; text-decoration:none;">support@seolinkbuildings.com</a>
            </p>
            <p class="mb-0" style="color:#555; line-height:1.7;">{{ __('messages.section6_callout_text2') }}</p>
        </div>
    </div>


    <!-- ===== 7. CHILDREN'S PRIVACY ===== -->
    <div id="children-privacy" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#FF6F61,#ee5a6f); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">{{ __('messages.section7_title') }}</h2>
        </div>
        <p class="mb-0" style="color:#555; line-height:1.7;">{{ __('messages.section7_text1') }}</p>
    </div>


    <!-- ===== 8. EXTERNAL LINKS ===== -->
    <div class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#06b6d4,#0891b2); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">{{ __('messages.section8_title') }}</h2>
        </div>
        <p class="mb-0" style="color:#555; line-height:1.7;">{{ __('messages.section8_text1') }}</p>
    </div>


    <!-- ===== 9. CHANGES TO POLICY ===== -->
    <div class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#8E44AD,#9b59b6); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">{{ __('messages.section9_title') }}</h2>
        </div>
        <p class="mb-0" style="color:#555; line-height:1.7;">{{ __('messages.section9_text1') }}</p>
    </div>


    <!-- ===== 10. CONTACT INFO ===== -->
    <div id="contact-info" class="mb-5 p-4 p-md-5 rounded-4" style="background:linear-gradient(135deg, #4ECDCB, #38b2ac); box-shadow:0 15px 35px rgba(78,205,203,0.25);">
        <h2 class="fw-bold mb-4" style="color:white; font-size:1.5rem;">{{ __('messages.section10_title') }}</h2>
        <p style="color:rgba(255,255,255,0.9); line-height:1.7; margin-bottom:1.25rem;">
            {{ __('messages.section10_text1') }}
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
    /* Smooth scroll for anchor links */
    html {
        scroll-behavior: smooth;
    }

    /* Quick navigation hover */
    a[href^="#"]:hover {
        color: #38b2ac !important;
        padding-left: 4px;
    }
    a[href^="#"] {
        transition: all 0.2s ease;
    }
</style>

@endsection
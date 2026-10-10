@extends(staff_layout())

@section('title', __('messages.staff_handbook_title'))

@section('content')
<div class="container-fluid py-3" style="max-width:900px;">

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">{{ __('messages.staff_handbook_title') }}</h4>
            <p class="text-muted mb-0 small">{{ __('messages.staff_handbook_intro') }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ staff_route('sites.create') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i> Add site for publisher
            </a>
            <a href="{{ staff_route('sites.index') }}" class="btn btn-sm btn-outline-secondary">Sites</a>
        </div>
    </div>

    <div class="alert alert-info border-0 shadow-sm">
        <div class="d-flex flex-wrap gap-3">
            <a href="{{ localized_url('terms-of-services') }}" target="_blank" rel="noopener noreferrer">
                {{ __('messages.staff_handbook_terms_link') }}
            </a>
            <a href="{{ localized_url('privacy-policy') }}" target="_blank" rel="noopener noreferrer">
                {{ __('messages.staff_handbook_privacy_link') }}
            </a>
        </div>
    </div>

    @foreach([
        ['title' => 'staff_handbook_section1_title', 'lists' => ['staff_handbook_section1_list1', 'staff_handbook_section1_list2', 'staff_handbook_section1_list3']],
        ['title' => 'staff_handbook_section2_title', 'lists' => ['staff_handbook_section2_list1', 'staff_handbook_section2_list2', 'staff_handbook_section2_list3', 'staff_handbook_section2_list4', 'staff_handbook_section2_list5', 'staff_handbook_section2_list6']],
        ['title' => 'staff_handbook_section3_title', 'lists' => ['staff_handbook_section3_list1', 'staff_handbook_section3_list2', 'staff_handbook_section3_list3', 'staff_handbook_section3_list4']],
        ['title' => 'staff_handbook_section4_title', 'lists' => ['staff_handbook_section4_list1', 'staff_handbook_section4_list2', 'staff_handbook_section4_list3']],
        ['title' => 'staff_handbook_section5_title', 'lists' => ['staff_handbook_section5_list1', 'staff_handbook_section5_list2', 'staff_handbook_section5_list3']],
        ['title' => 'staff_handbook_section6_title', 'lists' => ['staff_handbook_section6_list1', 'staff_handbook_section6_list2', 'staff_handbook_section6_list3', 'staff_handbook_section6_list4', 'staff_handbook_section6_list5']],
    ] as $section)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h5 class="fw-bold mb-3">{{ __('messages.'.$section['title']) }}</h5>
                <ul class="mb-0" style="line-height:1.8;">
                    @foreach($section['lists'] as $key)
                        <li>{{ __('messages.'.$key) }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endforeach

    @if(auth()->user()?->isAdmin())
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h5 class="fw-bold mb-3">{{ __('messages.staff_handbook_section7_title') }}</h5>
                <ul class="mb-0" style="line-height:1.8;">
                    <li>{{ __('messages.staff_handbook_section7_list1') }}</li>
                    <li>{{ __('messages.staff_handbook_section7_list2') }}</li>
                    <li>{{ __('messages.staff_handbook_section7_list3') }}</li>
                    <li>{{ __('messages.staff_handbook_section7_list4') }}</li>
                    <li>{{ __('messages.staff_handbook_section7_list5') }}</li>
                </ul>
            </div>
        </div>
    @endif

</div>
@endsection

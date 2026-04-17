@extends('advertiser.layouts.app')

@section('content')

<div class="container-fluid">

    <!-- HEADER -->
    <div class="row mb-3">
        <div class="col-md-12">

            <!-- Breadcrumb-style context -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item">
                        <a href="{{ route('advertiser.dashboard') }}">Dashboard</a>
                    </li>
                    <li class="breadcrumb-item active" aria-current="page">
                        All Publishers
                    </li>
                </ol>
            </nav>

            <!-- Title -->
            <h2 class="mb-1 fw-semibold">All Publishers</h2>

            <!-- Subtitle -->
            <p class="text-muted mb-0">
                Browse verified publishers and explore available placement opportunities.
            </p>

        </div>
    </div>

    <!-- CONTENT AREA -->
    <div class="row">
        <div class="col-md-12">

            <!-- You will load publishers here later -->
            <div class="card border-0 shadow-sm">
                <div class="card-body text-muted text-center py-5">
                    Publisher list will appear here.
                </div>
            </div>

        </div>
    </div>

</div>

@endsection
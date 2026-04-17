@extends('advertiser.layouts.app')

@section('content')

@php
    $projects = $projects ?? collect();
@endphp

<div class="d-flex flex-column align-items-start gap-2 mb-3">

    <div>
        <h3 class="mb-1">Perfect For Agencies & Marketing Teams</h3>
        <p class="text-muted mb-2">
            Create a project for each of your clients to ensure you never duplicate placements.
        </p>
    </div>

    <hr class="w-100">

    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#projectModal">
        <i class="fa fa-plus"></i> Create Project
    </button>

</div>

{{-- ================= ALERTS ================= --}}
@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show">
        {{ $errors->first() }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif


<!-- ================= PROJECT LIST ================= -->
<div class="row g-3 mb-4">

    @forelse($projects as $project)

        <div class="col-md-4 col-sm-6">

            <div class="card h-100 border border-secondary-subtle shadow-sm rounded-3">

                <div class="card-body">

                    <!-- HEADER ROW -->
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">

                        <!-- PROJECT NAME -->
                        <a href="{{ $project->project_url }}"
                           target="_blank"
                           class="text-decoration-none text-dark">

                            <h6 class="mb-0">
                                {{ $project->project_name }}
                                <i class="fa-solid fa-arrow-up-right-from-square ms-1 small"></i>
                            </h6>

                        </a>

                        <!-- ACTION BUTTONS -->
                        <div class="d-flex gap-1">

                            <!-- EDIT -->
                            <button class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editProjectModal{{ $project->id }}">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>

                            <!-- DELETE -->
                            <form method="POST"
                                action="{{ route('advertiser.projects.destroy', $project->id) }}"
                                onsubmit="return confirm('Delete this project?')">
                                @csrf
                                @method('DELETE')

                                <button class="btn btn-sm btn-outline-danger">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>

                        </div>

                    </div>

                    <hr class="my-2">

                    {{-- STATUS ROW (MOBILE FRIENDLY) --}}
                    <div class="d-flex align-items-center flex-wrap gap-2">

                        <!-- Heading -->
                        <span class="fw-semibold">
                            <i class="fa-solid fa-pen-to-square me-1"></i>
                            Guest Posting
                        </span>

                        <!-- Badges -->
                        <div class="d-flex flex-wrap gap-2 ms-auto">

                            <span class="badge bg-primary-subtle text-primary px-2 py-1"
                                  data-bs-toggle="tooltip"
                                  title="Not started">
                                {{ rand(1, 10) }}
                            </span>

                            <span class="badge bg-info-subtle text-info px-2 py-1"
                                  data-bs-toggle="tooltip"
                                  title="In progress">
                                {{ rand(5, 20) }}
                            </span>

                            <span class="badge bg-warning-subtle text-warning px-2 py-1"
                                  data-bs-toggle="tooltip"
                                  title="Waiting approval">
                                {{ rand(1, 8) }}
                            </span>

                            <span class="badge bg-secondary-subtle text-secondary px-2 py-1"
                                  data-bs-toggle="tooltip"
                                  title="Needs improvements">
                                {{ rand(1, 6) }}
                            </span>

                            <span class="badge bg-success-subtle text-success px-2 py-1"
                                  data-bs-toggle="tooltip"
                                  title="Completed">
                                {{ rand(10, 50) }}
                            </span>

                            <span class="badge bg-danger-subtle text-danger px-2 py-1"
                                  data-bs-toggle="tooltip"
                                  title="Rejected">
                                {{ rand(0, 5) }}
                            </span>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- ================= EDIT MODAL ================= -->
        <div class="modal fade" id="editProjectModal{{ $project->id }}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">

                    <form method="POST"
                        action="{{ route('advertiser.projects.update', $project->id) }}">
                        @csrf
                        @method('PUT')

                        <div class="modal-header">
                            <h5 class="modal-title">Edit Project</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">

                            <div class="mb-3">
                                <label class="form-label">Project Name</label>
                                <input type="text"
                                       name="project_name"
                                       value="{{ $project->project_name }}"
                                       class="form-control"
                                       required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Project URL</label>
                                <input type="url"
                                       name="project_url"
                                       value="{{ $project->project_url }}"
                                       class="form-control"
                                       required>
                            </div>            

                        </div>

                        <div class="modal-footer">
                            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-primary">Update</button>
                        </div>

                    </form>

                </div>
            </div>
        </div>

    @empty
        <div class="col-12">
            <div class="alert alert-light border">
                No projects found. Create your first project.
            </div>
        </div>
    @endforelse

</div>

<!-- ================= CREATE PROJECT MODAL ================= -->
<div class="modal fade" id="projectModal" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content shadow-lg border-0">

            <form method="POST" action="{{ route('advertiser.projects.store') }}">
                @csrf

                <div class="modal-header">
                    <h5 class="modal-title">Create New Project</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label">Project Name</label>
                        <input type="text" name="project_name" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Project URL</label>
                        <input type="url" name="project_url" class="form-control" required>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Close
                    </button>

                    <button type="submit" class="btn btn-primary">
                        Create
                    </button>
                </div>

            </form>

        </div>

    </div>

</div>

@endsection
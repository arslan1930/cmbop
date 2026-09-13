@extends('advertiser.layouts.app')

@section('title', 'Projects')

@push('page-styles')
    <link rel="stylesheet" href="{{ asset('assets/css/advertiser-projects.css') }}?v={{ @filemtime(public_path('assets/css/advertiser-projects.css')) ?: '1' }}">
@endpush

@section('content')

@php
    $projects = $projects ?? collect();
    $attentionPlacements = (int) ($attentionPlacements ?? 0);
    $attentionProjects = (int) ($attentionProjects ?? 0);
    $attentionProjectId = $attentionProjectId ?? null;
    $stageKeys = \App\Models\Project::STAGE_KEYS;
    $attentionOrdersUrl = $attentionProjects === 1 && $attentionProjectId
        ? route('advertiser.orders', ['project' => $attentionProjectId, 'project_stage' => 'needs_you'])
        : route('advertiser.orders', ['status' => 'needs_action']);
@endphp

<div class="container-fluid">

<div class="project-page-head mb-4">
    <div class="project-page-head__copy">
        <h2 class="mb-1 fw-semibold">Projects</h2>
        <p class="text-muted mb-0">
            One project per client site. Counts are placements whose destination host matches this project.
        </p>
    </div>
    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#projectModal">
        <i class="fa fa-plus" aria-hidden="true"></i> Create Project
    </button>
</div>

@if($attentionPlacements > 0)
    <div class="ui-callout ui-callout--attention ui-callout--banner project-attention mb-4" role="status" data-projects-attention>
        <div class="ui-callout__main">
            <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span>
            <div class="ui-callout__body">
                <strong>Needs your attention</strong>
                <span class="ui-callout__detail">{{ $attentionPlacements }} {{ $attentionPlacements === 1 ? 'placement needs you' : 'placements need you' }} across {{ $attentionProjects }} {{ $attentionProjects === 1 ? 'project' : 'projects' }}. Live URLs ready for review and open revisions only.</span>
            </div>
        </div>
        <div class="ui-callout__actions">
            <a href="{{ $attentionOrdersUrl }}" class="btn btn-sm btn-primary">Show placements needing you</a>
        </div>
    </div>
@endif

<div class="project-list mb-4">

    @forelse($projects as $project)
        @php
            $stageCounts = $project->stage_counts ?? \App\Models\Project::emptyStageCounts();
            $host = \App\Models\Project::hostFromUrl($project->project_url);
        @endphp

        <div class="project-card-col">

            <div class="card project-card shadow-sm rounded-3">

                <div class="card-body">

                    <div class="project-card__top">
                        <div class="project-card__identity">
                            <a href="{{ $project->project_url }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="project-card__name text-decoration-none">
                                <h3>
                                    {{ $project->project_name }}
                                    <i class="fa-solid fa-arrow-up-right-from-square ms-1 small" aria-hidden="true"></i>
                                </h3>
                            </a>
                            @if($host !== '')
                                <div class="project-card__host">{{ $host }}</div>
                            @endif
                            <div class="project-card__links">
                                <a href="{{ route('advertiser.orders', ['project' => $project->id]) }}">View orders</a>
                            </div>
                        </div>

                        <div class="project-card__actions">
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editProjectModal{{ $project->id }}"
                                    aria-label="Edit {{ $project->project_name }}">
                                <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                            </button>

                            <form method="POST"
                                action="{{ route('advertiser.projects.destroy', $project->id) }}"
                                data-slb-confirm="This project will be removed. This cannot be undone."
                                data-slb-confirm-title="Delete this project?"
                                data-slb-confirm-text="Delete project"
                                data-slb-confirm-danger="1">
                                @csrf
                                @method('DELETE')

                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete project">
                                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="project-stages">
                        @foreach($stageKeys as $stageKey)
                            @php
                                $count = (int) ($stageCounts[$stageKey] ?? 0);
                                $label = \App\Models\Project::stageLabel($stageKey);
                                $hint = \App\Models\Project::stageHint($stageKey);
                                $stageTag = $count > 0 ? 'a' : 'span';
                                $stageHref = $count > 0
                                    ? route('advertiser.orders', ['project' => $project->id, 'project_stage' => $stageKey])
                                    : null;
                            @endphp
                            <{{ $stageTag }}
                                @if($stageHref) href="{{ $stageHref }}" @endif
                                class="project-stage project-stage--{{ $stageKey }}{{ $count === 0 ? ' is-zero' : '' }}"
                                title="{{ $hint !== '' ? $label.': '.$hint : $label }}">
                                <span class="project-stage__label">{{ $label }}</span>
                                <span class="project-stage__count">{{ $count }}</span>
                            </{{ $stageTag }}>
                        @endforeach
                    </div>

                </div>

            </div>

        </div>

        <div class="modal fade" id="editProjectModal{{ $project->id }}" tabindex="-1" aria-labelledby="editProjectTitle{{ $project->id }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">

                    <form method="POST"
                        action="{{ route('advertiser.projects.update', $project->id) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="editing_project_id" value="{{ $project->id }}">

                        <div class="modal-header">
                            <h5 class="modal-title" id="editProjectTitle{{ $project->id }}">Edit Project</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <div class="modal-body">
                            @include('advertiser.partials.project-fields', [
                                'fieldId' => 'edit-'.$project->id,
                                'nameValue' => old('editing_project_id') == $project->id ? old('project_name', $project->project_name) : $project->project_name,
                                'urlValue' => old('editing_project_id') == $project->id ? old('project_url', $project->project_url) : $project->project_url,
                            ])
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Update</button>
                        </div>

                    </form>

                </div>
            </div>
        </div>

    @empty
        <div class="ui-callout ui-callout--info project-empty">
            <span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-folder-open"></i></span>
            <div class="ui-callout__body">
                <strong>No projects yet</strong>
                <span class="d-block text-muted">Create a project for each client site so placement counts stay grouped.</span>
            </div>
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#projectModal">
                Create project
            </button>
        </div>
    @endforelse

</div>

<div class="modal fade" id="projectModal" tabindex="-1" aria-labelledby="createProjectTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">

            <form method="POST" action="{{ route('advertiser.projects.store') }}">
                @csrf

                <div class="modal-header">
                    <h5 class="modal-title" id="createProjectTitle">Create New Project</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    @include('advertiser.partials.project-fields', [
                        'fieldId' => 'create',
                        'nameValue' => old('editing_project_id') ? '' : old('project_name'),
                        'urlValue' => old('editing_project_id') ? '' : old('project_url'),
                    ])
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>

            </form>

        </div>
    </div>
</div>

</div>

@endsection

@if($errors->any())
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var editId = @json(old('editing_project_id'));
    var modalId = editId ? ('editProjectModal' + editId) : 'projectModal';
    var el = document.getElementById(modalId);
    if (el && window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(el).show();
    }
});
</script>
@endpush
@endif

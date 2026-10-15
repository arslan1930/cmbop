@php
    $savedSearchQuery = \App\Services\Catalog\CatalogUrlQuery::fromRequest(request());
@endphp
<div class="modal fade" id="saveSearchModal" tabindex="-1" aria-labelledby="saveSearchModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('advertiser.saved-searches.store') }}" class="modal-content">
            @csrf
            @foreach($savedSearchQuery as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <div class="modal-header">
                <h2 class="modal-title h5" id="saveSearchModalLabel">Save this search</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="savedSearchName">Name</label>
                <input type="text" name="name" id="savedSearchName" class="form-control" required maxlength="120" placeholder="e.g. DE tech DR 40+">
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" name="notify" value="1" id="savedSearchNotify">
                    <label class="form-check-label" for="savedSearchNotify">Email me daily when new active sites match</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save search</button>
            </div>
        </form>
    </div>
</div>

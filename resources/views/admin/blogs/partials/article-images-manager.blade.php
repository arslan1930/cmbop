{{-- Centered gallery: featured + every inline image from all locale editors --}}
<div class="mt-4 pt-3 border-top" id="articleImagesManager">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div>
            <h2 class="h5 mb-0">Article images</h2>
            <p class="text-muted small mb-0">Preview, change, or delete the featured image and every image in the article body.</p>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="refreshArticleImages">
            <i class="fa fa-refresh me-1"></i> Refresh
        </button>
    </div>

    <div id="articleImagesEmpty" class="text-center text-muted border rounded py-4 bg-light">
        <i class="fa fa-image fa-2x mb-2 d-block"></i>
        No images yet. Add a featured image or insert images in the content editor.
    </div>

    <div id="articleImagesGrid" class="row g-3 justify-content-center"></div>
</div>

<input type="file" id="articleImageReplaceInput" class="d-none" accept="image/*">


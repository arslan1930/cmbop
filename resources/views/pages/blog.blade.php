@extends('layouts.app')

@section('title', __('messages.meta_blog_title'))
@section('description', __('messages.meta_blog_description'))
@section('canonical', localized_url('blog'))

@section('content')

@include('components.marketing-page-hero', [
    'kicker' => __('messages.blog_kicker'),
    'title' => __('messages.blog_heading'),
    'subtitle' => __('messages.blog_intro'),
])

<!-- ==================== BLOG CONTENT ==================== -->
<div class="container py-5" style="max-width:1200px;">

    <!-- Search and Filter Bar -->
    <div class="row mb-5">
        <div class="col-12">
            <div class="p-4 rounded-4" style="background:white; border:1px solid #eef0f3; box-shadow:0 4px 12px rgba(0,0,0,0.02);">
                <div class="row g-3 align-items-center">
                    <!-- Search Box -->
                    <div class="col-md-6">
                        <div style="position: relative;">
                            <i class="fa fa-search" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #999;"></i>
                            <input type="text" 
                                   id="liveSearch" 
                                   class="form-control" 
                                   placeholder="Search articles by title, content, or author..." 
                                   style="padding-left: 45px; height: 50px; border-radius: 12px; border:1px solid #eef0f3;"
                                   autocomplete="off">
                        </div>
                    </div>
                    
                    <!-- Tags Filter -->
                    <div class="col-md-6">
                        <div class="d-flex flex-wrap gap-2" id="tagCloud">
                            <span class="tag-filter active" data-tag="" style="background:#4ECDCB; color:white; padding:6px 16px; border-radius:25px; font-size:0.85rem; cursor:pointer; transition:all 0.2s;">All Posts</span>
                            @php
                                $allTags = [];
                                if(isset($blog) && $blog->count() > 0) {
                                    foreach($blog as $post) {
                                        if($post->tags) {
                                            $tags = is_array($post->tags) ? $post->tags : json_decode($post->tags, true);
                                            if(is_array($tags)) {
                                                foreach($tags as $tag) {
                                                    if(!in_array($tag, $allTags)) {
                                                        $allTags[] = $tag;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    sort($allTags);
                                }
                            @endphp
                            @foreach(array_slice($allTags, 0, 6) as $tag)
                                <span class="tag-filter" data-tag="{{ $tag }}" style="background:#f0f2f5; color:#555; padding:6px 16px; border-radius:25px; font-size:0.85rem; cursor:pointer; transition:all 0.2s;">{{ $tag }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Blog Posts -->
        <div class="col-lg-8">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">Recent Articles</h2>
                <div id="resultCount" class="text-muted" style="font-size:0.9rem;">
                    @if(isset($blog) && $blog->count() > 0)
                        Showing {{ $blog->firstItem() ?? 0 }} - {{ $blog->lastItem() ?? 0 }} of {{ $blog->total() ?? 0 }} posts
                    @endif
                </div>
            </div>

            <div id="blogContainer">
                @if(isset($blog) && $blog->count() > 0)
                    <div id="blogGrid">
                        @foreach($blog as $post)
                            <div class="blog-item mb-4" data-title="{{ strtolower($post->title) }}" data-content="{{ strtolower(strip_tags($post->content)) }}" data-author="{{ strtolower($post->author) }}" data-tags="{{ $post->tags ? implode(',', is_array($post->tags) ? $post->tags : json_decode($post->tags, true) ?? []) : '' }}">
                                <div class="card border-0 shadow-sm rounded-4 overflow-hidden" style="transition: all 0.3s ease;">
                                    <div class="row g-0">
                                        @if($post->featured_image)
                                            <div class="col-md-4">
                                                <div style="height: 100%; overflow: hidden;">
                                                    <img src="{{ Storage::url($post->featured_image) }}" 
                                                         class="img-fluid w-100 h-100" 
                                                         alt="{{ $post->title }}"
                                                         style="object-fit: cover; transition: transform 0.3s;">
                                                </div>
                                            </div>
                                            <div class="col-md-8">
                                                <div class="card-body p-4">
                                                    <div class="d-flex align-items-center gap-3 mb-3">
                                                        <small class="text-muted">
                                                            <i class="fa fa-user me-1"></i> {{ $post->author }}
                                                        </small>
                                                        <small class="text-muted">
                                                            <i class="fa fa-calendar me-1"></i> {{ $post->published_at ? $post->published_at->format('M d, Y') : 'Draft' }}
                                                        </small>
                                                    </div>
                                                    <h3 class="card-title h4 mb-3">
                                                        <a href="{{ localized_url('blog/'.$post->slug) }}" class="text-decoration-none" style="color:#1a1a2e; font-weight:700;">
                                                            {{ $post->title }}
                                                        </a>
                                                    </h3>
                                                    <p class="card-text text-muted" style="line-height:1.7;">
                                                        {{ Str::limit(strip_tags($post->content), 120) }}
                                                    </p>
                                                    <a href="{{ localized_url('blog/'.$post->slug) }}" class="btn btn-link text-decoration-none p-0" style="color:#4ECDCB; font-weight:600;">
                                                        Read More <i class="fa fa-arrow-right ms-1"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        @else
                                            <div class="col-12">
                                                <div class="card-body p-4">
                                                    <div class="d-flex align-items-center gap-3 mb-3">
                                                        <small class="text-muted">
                                                            <i class="fa fa-user me-1"></i> {{ $post->author }}
                                                        </small>
                                                        <small class="text-muted">
                                                            <i class="fa fa-calendar me-1"></i> {{ $post->published_at ? $post->published_at->format('M d, Y') : 'Draft' }}
                                                        </small>
                                                    </div>
                                                    <h3 class="card-title h4 mb-3">
                                                        <a href="{{ localized_url('blog/'.$post->slug) }}" class="text-decoration-none" style="color:#1a1a2e; font-weight:700;">
                                                            {{ $post->title }}
                                                        </a>
                                                    </h3>
                                                    <p class="card-text text-muted" style="line-height:1.7;">
                                                        {{ Str::limit(strip_tags($post->content), 150) }}
                                                    </p>
                                                    <a href="{{ localized_url('blog/'.$post->slug) }}" class="btn btn-link text-decoration-none p-0" style="color:#4ECDCB; font-weight:600;">
                                                        Read More <i class="fa fa-arrow-right ms-1"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    
                    <!-- Pagination -->
                    <div class="d-flex justify-content-center mt-5">
                        {{ $blog->links() }}
                    </div>
                @else
                    <div class="text-center py-5 px-4 rounded-4" style="background:#f8f9fa;">
                        <i class="fa fa-newspaper-o fa-4x mb-3" style="color:#cbd5e1;"></i>
                        <h4 style="color:#1a1a2e;">No Blog Posts Yet</h4>
                        <p class="text-muted">Check back soon for interesting articles and insights.</p>
                    </div>
                @endif
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">

            <!-- Recent Posts -->
            @php
                $recentPosts = isset($blog) ? $blog->take(5) : collect();
            @endphp
            @if($recentPosts->count() > 0)
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-body p-4">
                        <h3 class="h5 fw-bold mb-3" style="color:#1a1a2e;">
                            <i class="fa fa-clock-o me-2" style="color:#4ECDCB;"></i> Recent Posts
                        </h3>
                        @foreach($recentPosts as $recent)
                            <a href="{{ localized_url('blog/'.$recent->slug) }}" class="text-decoration-none d-block mb-3">
                                <div class="d-flex gap-3 align-items-start">
                                    @if($recent->featured_image)
                                        <img src="{{ Storage::url($recent->featured_image) }}" 
                                             alt="{{ $recent->title }}" 
                                             style="width: 60px; height: 60px; object-fit: cover; border-radius: 12px;">
                                    @else
                                        <div style="width: 60px; height: 60px; background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px;" class="d-flex align-items-center justify-content-center">
                                            <i class="fa fa-file-text-o text-white"></i>
                                        </div>
                                    @endif
                                    <div>
                                        <h6 class="mb-1 text-dark" style="font-weight: 600;">{{ Str::limit($recent->title, 40) }}</h6>
                                        <small class="text-muted">{{ $recent->published_at ? $recent->published_at->diffForHumans() : 'Draft' }}</small>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

<style>
    /* Blog Card Hover Effect */
    .blog-item .card {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    
    .blog-item .card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.1) !important;
    }
    
    .blog-item .card:hover .img-fluid {
        transform: scale(1.05);
    }
    
    /* Tag Filter Styles */
    .tag-filter:hover {
        background: #4ECDCB !important;
        color: white !important;
    }
    
    /* Pagination Styling */
    .pagination {
        gap: 8px;
    }
    
    .pagination .page-link {
        border-radius: 10px;
        border: 1px solid #eef0f3;
        color: #555;
        padding: 8px 14px;
    }
    
    .pagination .page-item.active .page-link {
        background: #4ECDCB;
        border-color: #4ECDCB;
        color: white;
    }
    
    .pagination .page-link:hover {
        background: #f0f5ff;
        color: #4ECDCB;
    }
</style>

<script>
// Live Search and Tag Filter
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('liveSearch');
    const tagFilters = document.querySelectorAll('.tag-filter');
    const blogItems = document.querySelectorAll('.blog-item');
    const resultCountDiv = document.getElementById('resultCount');
    let currentTag = '';
    let currentSearch = '';
    
    function filterPosts() {
        let visibleCount = 0;
        
        blogItems.forEach(item => {
            const title = item.getAttribute('data-title') || '';
            const content = item.getAttribute('data-content') || '';
            const author = item.getAttribute('data-author') || '';
            const tags = item.getAttribute('data-tags') || '';
            
            const matchesSearch = currentSearch === '' || 
                title.includes(currentSearch) || 
                content.includes(currentSearch) || 
                author.includes(currentSearch);
            
            const matchesTag = currentTag === '' || tags.toLowerCase().includes(currentTag.toLowerCase());
            
            if (matchesSearch && matchesTag) {
                item.style.display = 'block';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });
        
        // Update result count
        if (resultCountDiv) {
            resultCountDiv.innerHTML = `Showing ${visibleCount} of ${blogItems.length} posts`;
        }
        
        // Show/hide no results message
        let noResultsDiv = document.getElementById('noResultsMessage');
        if (visibleCount === 0) {
            if (!noResultsDiv) {
                const container = document.getElementById('blogGrid');
                if (container) {
                    noResultsDiv = document.createElement('div');
                    noResultsDiv.id = 'noResultsMessage';
                    noResultsDiv.className = 'text-center py-5';
                    noResultsDiv.innerHTML = `
                        <i class="fa fa-search fa-3x mb-3" style="color:#cbd5e1;"></i>
                        <h4 style="color:#1a1a2e;">No results found</h4>
                        <p class="text-muted">Try adjusting your search or filter criteria.</p>
                    `;
                    container.parentNode.appendChild(noResultsDiv);
                    container.style.display = 'none';
                }
            } else {
                noResultsDiv.style.display = 'block';
                if (document.getElementById('blogGrid')) {
                    document.getElementById('blogGrid').style.display = 'none';
                }
            }
        } else {
            if (noResultsDiv) {
                noResultsDiv.style.display = 'none';
            }
            if (document.getElementById('blogGrid')) {
                document.getElementById('blogGrid').style.display = 'block';
            }
        }
    }
    
    // Live search with debounce
    let searchTimeout;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentSearch = this.value.toLowerCase().trim();
                filterPosts();
            }, 300);
        });
    }
    
    // Tag filter
    tagFilters.forEach(tag => {
        tag.addEventListener('click', function() {
            tagFilters.forEach(t => {
                t.style.background = '#f0f2f5';
                t.style.color = '#555';
            });
            this.style.background = '#4ECDCB';
            this.style.color = 'white';
            
            currentTag = this.getAttribute('data-tag');
            filterPosts();
        });
    });
});

// Newsletter form submission
document.getElementById('newsletterForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const email = this.querySelector('input[type="email"]').value;
    Swal.fire({
        icon: 'success',
        title: 'Subscribed!',
        text: `Thank you for subscribing with ${email}`,
        showConfirmButton: true,
        timer: 3000
    });
    this.reset();
});
</script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
@endsection
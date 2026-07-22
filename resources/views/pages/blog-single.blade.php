@extends('layouts.app')

@section('title', ($blog->title ?? 'Blog').' — SEOLinkBuildings')
@section('description', $blog->excerpt ?: \Illuminate\Support\Str::limit(strip_tags($blog->content ?? ''), 160))
@section('og_type', 'article')
@section('og_image', !empty($blog->featured_image) ? asset('storage/'.$blog->featured_image) : asset('assets/brand/web/og-share-1200x630.png'))

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BlogPosting',
    'headline' => $blog->title,
    'description' => $blog->excerpt ?: \Illuminate\Support\Str::limit(strip_tags($blog->content ?? ''), 160),
    'datePublished' => optional($blog->published_at)?->toIso8601String(),
    'dateModified' => optional($blog->updated_at)?->toIso8601String(),
    'author' => [
        '@type' => 'Person',
        'name' => $blog->author ?: 'SEOLinkBuildings',
    ],
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'SEOLinkBuildings',
        'logo' => [
            '@type' => 'ImageObject',
            'url' => asset('assets/img/logo1.png'),
        ],
    ],
    'mainEntityOfPage' => url()->current(),
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')

<!-- ==================== BLOG POST HERO ==================== -->
<section style="position:relative; width:100%; padding:48px 0 60px; overflow:hidden; background:linear-gradient(180deg, #f0f5ff 0%, #f5faff 100%);">

    <!-- Background Shapes -->
    <div style="position:absolute; top:10%; left:-100px; width:250px; height:250px; border-radius:50%; background:#5bc4c7; opacity:0.08; z-index:1;"></div>
    <div style="position:absolute; bottom:-80px; right:-60px; width:220px; height:220px; border-radius:50%; background:#FFD93D; opacity:0.15; z-index:1;"></div>
    <div style="position:absolute; top:30%; right:10%; width:60px; height:60px; border-radius:50%; border:10px solid #5bc4c7; opacity:0.4; z-index:1;"></div>
    <div style="position:absolute; top:15%; right:25%; width:10px; height:10px; border-radius:50%; background:#5bc4c7; opacity:0.6; z-index:1;"></div>
    <div style="position:absolute; bottom:20%; left:15%; width:12px; height:12px; border-radius:50%; background:#FF4757; opacity:0.4; z-index:1;"></div>
    <div style="position:absolute; top:40%; left:20%; width:8px; height:8px; border-radius:50%; background:#FFD93D; opacity:0.5; z-index:1;"></div>

    <div class="container" style="position:relative; z-index:5; max-width:1000px;">
        <!-- Blog Home Button -->
        <div class="mb-4">
            <a href="{{ localized_url('blog') }}" class="btn btn-outline-secondary rounded-pill px-4" style="background: white; border-color: #e0e0e0; color: #555; font-size: 0.9rem;">
                <i class="fa fa-arrow-left me-2"></i> {{ __('messages.blog_back') }}
            </a>
        </div>
        
        <div class="text-center">
            <div class="mb-3">
                <span style="background:rgba(78,205,203,0.15); color:#38b2ac; padding:6px 16px; border-radius:50px; font-size:0.85rem; font-weight:600; letter-spacing:0.5px;">
                    <i class="fa fa-file-text-o me-2"></i> Blog Post
                </span>
            </div>
            <h1 style="font-size:2.8rem; font-weight:800; color:#1a1a2e; letter-spacing:-1px; margin-bottom:1.5rem; line-height:1.2;">
                {{ $blog->title }}
            </h1>
            
            <!-- Post Meta Info -->
            <div class="d-flex justify-content-center align-items-center gap-4 flex-wrap" style="color: #666;">
                <span>
                    <i class="fa fa-user me-2" style="color: #5bc4c7;"></i> 
                    <strong>{{ $blog->author }}</strong>
                </span>
                <span>
                    <i class="fa fa-calendar me-2" style="color: #5bc4c7;"></i> 
                    {{ $blog->published_at ? $blog->published_at->format('F d, Y') : 'Draft' }}
                </span>
                <span>
                    <i class="fa fa-clock-o me-2" style="color: #5bc4c7;"></i> 
                    {{ ceil(str_word_count(strip_tags($blog->content)) / 200) }} min read
                </span>
            </div>
            
            @if($blog->tags)
                <div class="mt-3">
                    @foreach(is_array($blog->tags) ? $blog->tags : json_decode($blog->tags, true) ?? [] as $tag)
                        <span class="badge bg-light text-dark me-1" style="font-weight: 500; padding: 6px 14px; border-radius: 20px;">
                            <i class="fa fa-tag me-1" style="font-size: 10px;"></i> {{ $tag }}
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>

<!-- ==================== BLOG CONTENT ==================== -->
<div class="container py-5">
    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8 mx-auto">
            <article>
                @if($blog->featured_image)
                    <div class="mb-5">
                        <img src="{{ Storage::url($blog->featured_image) }}" 
                             alt="{{ $blog->title }}" 
                             class="img-fluid rounded-4 shadow-sm w-100">
                    </div>
                @endif
                
                <div class="blog-content">
                    {!! $blog->content !!}
                </div>
                
                <!-- Share Section -->
                <div class="mt-5 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <a href="{{ localized_url('blog') }}" class="btn btn-outline-secondary rounded-pill px-4">
                            <i class="fa fa-arrow-left me-2"></i> {{ __('messages.blog_back') }}
                        </a>
                        <div class="d-flex gap-2">
                            <span class="text-muted me-2">{{ __('messages.blog_share') }}</span>
                            <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode(url()->current()) }}" target="_blank" class="btn btn-sm btn-outline-primary rounded-circle" style="width: 35px; height: 35px; padding: 0; line-height: 33px;">
                                <i class="fa fa-facebook-f"></i>
                            </a>
                            <a href="https://twitter.com/intent/tweet?url={{ urlencode(url()->current()) }}&text={{ urlencode($blog->title) }}" target="_blank" class="btn btn-sm btn-outline-info rounded-circle" style="width: 35px; height: 35px; padding: 0; line-height: 33px;">
                                <i class="fa fa-twitter"></i>
                            </a>
                            <a href="https://www.linkedin.com/shareArticle?mini=true&url={{ urlencode(url()->current()) }}&title={{ urlencode($blog->title) }}" target="_blank" class="btn btn-sm btn-outline-secondary rounded-circle" style="width: 35px; height: 35px; padding: 0; line-height: 33px;">
                                <i class="fa fa-linkedin-in"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </article>
        </div>
    </div>
</div>

<!-- ==================== RECOMMENDED POSTS SECTION ==================== -->
@php
    $recommendedPosts = isset($related)
        ? $related
        : \App\Models\Blog::published()
            ->where('id', '!=', $blog->id)
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();
@endphp

@if($recommendedPosts->count() > 0)
    <section style="background: #f8f9fa; padding: 60px 0;">
        <div class="container">
            <div class="text-center mb-5">
                <h2 style="font-weight: 700; color: #1a1a2e;">{{ __('messages.blog_related_title') }}</h2>
                <p style="color: #666;">{{ __('messages.blog_related_subtitle') }}</p>
            </div>
            <div class="row">
                @foreach($recommendedPosts as $recommended)
                    <div class="col-md-4 mb-4">
                        <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden" style="transition: all 0.3s ease;">
                            @if($recommended->featured_image)
                                <img src="{{ Storage::url($recommended->featured_image) }}" 
                                     alt="{{ $recommended->title }}" 
                                     style="height: 200px; object-fit: cover;">
                            @else
                                <div style="height: 200px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);" class="d-flex align-items-center justify-content-center">
                                    <i class="fa fa-file-text-o fa-3x text-white opacity-50"></i>
                                </div>
                            @endif
                            <div class="card-body p-4">
                                <h5 class="card-title" style="font-weight: 700;">
                                    <a href="{{ localized_url('blog/'.$recommended->slug) }}" class="text-decoration-none text-dark">
                                        {{ Str::limit($recommended->title, 60) }}
                                    </a>
                                </h5>
                                <p class="card-text text-muted" style="font-size: 0.9rem;">
                                    {{ Str::limit(strip_tags($recommended->content), 100) }}
                                </p>
                                <a href="{{ localized_url('blog/'.$recommended->slug) }}" class="btn btn-link text-decoration-none p-0" style="color: #5bc4c7; font-weight: 600;">
                                    Read More <i class="fa fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endif

<style>
    .blog-content {
        font-size: 1.05rem;
        line-height: 1.8;
        color: #2c3e50;
    }
    
    .blog-content img {
        max-width: 100%;
        height: auto;
        border-radius: 12px;
        margin: 30px 0;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .blog-content h2 {
        font-size: 1.8rem;
        font-weight: 700;
        margin-top: 40px;
        margin-bottom: 20px;
        color: #1a1a2e;
    }
    
    .blog-content h3 {
        font-size: 1.5rem;
        font-weight: 600;
        margin-top: 35px;
        margin-bottom: 15px;
        color: #1a1a2e;
    }
    
    .blog-content h4 {
        font-size: 1.3rem;
        font-weight: 600;
        margin-top: 30px;
        margin-bottom: 12px;
        color: #1a1a2e;
    }
    
    .blog-content p {
        margin-bottom: 25px;
    }
    
    .blog-content blockquote {
        border-left: 4px solid #5bc4c7;
        padding: 15px 0 15px 25px;
        margin: 30px 0;
        font-style: italic;
        color: #555;
        background: #f8f9fa;
        border-radius: 0 12px 12px 0;
    }
    
    .blog-content ul, .blog-content ol {
        margin-bottom: 25px;
        padding-left: 25px;
    }
    
    .blog-content li {
        margin-bottom: 8px;
    }
    
    .blog-content code {
        background: #f4f4f4;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 0.9em;
    }
    
    .blog-content pre {
        background: #2d2d2d;
        color: #f8f8f2;
        padding: 20px;
        border-radius: 12px;
        overflow-x: auto;
        margin: 25px 0;
    }
    
    .blog-content table {
        width: 100%;
        border-collapse: collapse;
        margin: 25px 0;
    }
    
    .blog-content th, .blog-content td {
        border: 1px solid #dee2e6;
        padding: 10px;
        text-align: left;
    }
    
    .blog-content th {
        background: #f8f9fa;
        font-weight: 600;
    }
    
    /* Card hover effect */
    .col-md-4 .card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.1) !important;
    }
    
    @media (max-width: 768px) {
        .blog-content {
            font-size: 1rem;
        }
        
        .blog-content h2 {
            font-size: 1.5rem;
        }
        
        .blog-content h3 {
            font-size: 1.3rem;
        }
        
        section .container .text-center h2 {
            font-size: 1.5rem;
        }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
@endsection
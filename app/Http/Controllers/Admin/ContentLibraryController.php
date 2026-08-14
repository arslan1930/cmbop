<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentSubmission;
use App\Services\ContentUpload\ArticlePreviewHtml;
use Illuminate\Http\Request;

class ContentLibraryController extends Controller
{
    public function index(Request $request)
    {
        $status = strtolower(trim(scalar_text($request->query('status', 'all'))));
        $language = strtolower(trim(scalar_text($request->query('language', ''))));
        $country = strtolower(trim(scalar_text($request->query('country', ''))));
        $search = trim(scalar_text($request->query('q', '')));
        $userId = (int) scalar_text($request->query('user_id', 0));

        if (! in_array($status, ['all', 'approved', 'rejected', 'pending', 'processing', 'error', 'needs_improvement', 'archived', 'expired'], true)) {
            $status = 'all';
        }

        $query = ContentSubmission::query()
            ->forLibraryList()
            ->with(['user:id,name,email'])
            ->latest('id');

        if ($status === 'archived') {
            $query->whereNotNull('archived_at');
        } elseif ($status === 'expired') {
            $query->whereNull('archived_at')
                ->whereNull('order_id')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now());
        } else {
            $query->whereNull('archived_at');
            if ($status !== 'all') {
                $query->where('moderation_status', $status);
            }
        }

        if ($language !== '' && $language !== 'all') {
            $query->where('language', $language);
        }
        if ($country !== '' && $country !== 'all') {
            $query->where('country', $country);
        }
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('original_filename', 'like', $like)
                    ->orWhereHas('user', function ($u) use ($like) {
                        $u->where('email', 'like', $like)->orWhere('name', 'like', $like);
                    });
            });
        }

        $submissions = $query->paginate(30)->withQueryString();

        $statusCounts = ContentSubmission::query()
            ->whereNull('archived_at')
            ->selectRaw('moderation_status, COUNT(*) as total')
            ->groupBy('moderation_status')
            ->pluck('total', 'moderation_status');

        return view('admin.content-library.index', [
            'submissions' => $submissions,
            'status' => $status,
            'language' => $language ?: 'all',
            'country' => $country ?: 'all',
            'search' => $search,
            'userId' => $userId ?: null,
            'statusCounts' => $statusCounts,
            'archivedCount' => ContentSubmission::query()->whereNotNull('archived_at')->count(),
            'expiredCount' => ContentSubmission::query()
                ->whereNull('archived_at')
                ->whereNull('order_id')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->count(),
        ]);
    }

    public function show(ContentSubmission $submission)
    {
        $submission->load(['user:id,name,email', 'orderItem.site', 'orderItems.site']);

        return view('admin.content-library.show', [
            'submission' => $submission,
            'previewHtml' => ArticlePreviewHtml::normalize((string) ($submission->preview_html ?? '')),
            'reasons' => $submission->evaluationReasonGroups(),
        ]);
    }

    public function preview(ContentSubmission $submission)
    {
        $html = ArticlePreviewHtml::normalize((string) ($submission->preview_html ?? ''));

        return response()->json([
            'success' => true,
            'id' => (int) $submission->id,
            'title' => $submission->title ?: $submission->original_filename,
            'preview_html' => $html,
            'moderation_status' => $submission->moderation_status,
            'language' => $submission->language,
            'country' => $submission->country,
            'user' => [
                'id' => $submission->user_id,
                'email' => $submission->user?->email,
            ],
        ]);
    }
}

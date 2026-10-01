<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\ContentUpload\ContentUploadService;
use App\Support\AdminModeration;
use App\Support\PhpIniSize;
use App\Support\UserFacingError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContentModerationController extends Controller
{
    public function index(Request $request, ContentModerationService $moderation, ContentUploadService $uploads): View
    {
        try {
            $cfg = $moderation->effectiveConfig();
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load moderation settings. Please refresh and try again.')
            );
            $cfg = config('content_moderation', []);
        }

        try {
            $uploadCfg = $uploads->effectiveConfig();
        } catch (\Throwable $e) {
            report($e);
            $uploadCfg = config('content_upload', []);
        }

        try {
            $stats = $moderation->adminStats();
        } catch (\Throwable) {
            $stats = [
                'total' => 0,
                'needs' => 0,
                'approved' => 0,
                'rejected' => 0,
                'errors' => 0,
                'skipped' => 0,
                'overridden' => 0,
                'today' => 0,
            ];
        }

        AdminModeration::rememberReturnQuery($request);
        $filters = AdminModeration::indexQuery($request);
        $status = (string) ($filters['status'] ?? 'all');
        if ($status === '') {
            $status = 'all';
        }
        $search = (string) ($filters['q'] ?? '');
        $category = (string) ($filters['category'] ?? 'all');
        if ($category === '') {
            $category = 'all';
        }
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');

        $page = (int) scalar_text($request->query('page', 1));
        if ($page < 1) {
            $page = 1;
        }
        $logs = $this->emptyModerationLogPaginator($page);

        if ($this->schemaTableAvailable('content_moderation_logs')) {
            try {
                $with = ['user:id,name,email'];
                if ($this->schemaTableAvailable('content_submissions')) {
                    $with[] = 'submission:id,title,original_filename,moderation_log_id,user_id';
                }

                $query = ContentModerationLog::query()
                    ->with($with)
                    ->latest('id');

                if ($status === 'needs') {
                    $query->needsDecision();
                } elseif ($status === 'approved') {
                    $query->where('status', ContentModerationLog::STATUS_APPROVED)
                        ->notSkipped()
                        ->where('admin_override', false);
                } elseif ($status === 'rejected') {
                    $query->where('status', ContentModerationLog::STATUS_REJECTED);
                } elseif ($status === 'error') {
                    $query->where('status', ContentModerationLog::STATUS_ERROR);
                } elseif ($status === 'skipped') {
                    $query->skipped();
                } elseif ($status === 'overridden') {
                    $query->where('admin_override', true);
                }

                if ($search !== '') {
                    $like = like_contains($search);
                    $query->where(function ($q) use ($like, $search) {
                        $q->where('document_url', 'like', $like)
                            ->orWhere('detected_category', 'like', $like)
                            ->orWhere('error_message', 'like', $like)
                            ->orWhereHas('user', function ($u) use ($like) {
                                $u->where('email', 'like', $like)->orWhere('name', 'like', $like);
                            });
                        if ($this->schemaTableAvailable('content_submissions')) {
                            $q->orWhereHas('submission', function ($s) use ($like) {
                                $s->where('title', 'like', $like)
                                    ->orWhere('original_filename', 'like', $like);
                            });
                        }
                        if (ctype_digit($search)) {
                            $q->orWhere('id', (int) $search)
                                ->orWhere('content_submission_id', (int) $search);
                        }
                    });
                }

                if ($category !== '' && $category !== 'all') {
                    $query->where('detected_category', $category);
                }

                if ($from !== '') {
                    $query->whereDate('created_at', '>=', $from);
                }
                if ($to !== '') {
                    $query->whereDate('created_at', '<=', $to);
                }

                $logs = $query->paginate(25, ['*'], 'page', $page)->withQueryString();
            } catch (\Throwable) {
                $logs = $this->emptyModerationLogPaginator($page);
            }
        } else {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $from = '';
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $to = '';
            }
        }

        try {
            $phpUploadMaxKb = PhpIniSize::uploadMaxKilobytes();
            $articleUploadMaxKb = $uploads->effectiveMaxKilobytes($uploadCfg);
            $phpBlocksArticleUploads = $phpUploadMaxKb < $articleUploadMaxKb;

            $extraKeywords = ContentModerationSetting::getValue('extra_keywords', []) ?: [];
            $exceptions = ContentModerationSetting::getValue('exceptions', []) ?: [];
            $disabledCategories = ContentModerationSetting::getValue('disabled_categories', []) ?: [];
            $enabledCategories = ContentModerationSetting::getValue('enabled_categories', []) ?: [];
            $activeCategories = $moderation->activeCategories();
            $builtinExceptions = $this->builtinExceptionPhrases();
        } catch (\Throwable $e) {
            report($e);
            if (! session()->has('error')) {
                session()->flash(
                    'error',
                    UserFacingError::message($e, 'We could not load moderation settings. Please refresh and try again.')
                );
            }
            $phpUploadMaxKb = 2048;
            $articleUploadMaxKb = 10240;
            $phpBlocksArticleUploads = false;
            $extraKeywords = [];
            $exceptions = [];
            $disabledCategories = [];
            $enabledCategories = [];
            $activeCategories = $cfg['categories'] ?? [];
            $builtinExceptions = [];
        }

        $listUrl = AdminModeration::listUrl($filters);

        return view('admin.moderation.index', compact(
            'cfg',
            'activeCategories',
            'uploadCfg',
            'stats',
            'logs',
            'extraKeywords',
            'exceptions',
            'disabledCategories',
            'enabledCategories',
            'phpUploadMaxKb',
            'articleUploadMaxKb',
            'phpBlocksArticleUploads',
            'status',
            'search',
            'category',
            'from',
            'to',
            'builtinExceptions',
            'listUrl',
        ));
    }

    public function show(Request $request, ContentModerationLog $log, ContentModerationService $moderation): View
    {
        $relations = ['user:id,name,email', 'overrider:id,name,email'];
        if ($this->schemaTableAvailable('content_submissions')) {
            $relations[] = 'submission.user:id,name,email';
        }

        try {
            $log->load($relations);
        } catch (\Throwable) {
        }

        try {
            $submission = $moderation->submissionForLog($log);
        } catch (\Throwable) {
            $submission = null;
        }

        try {
            $report = $moderation->publicReport($log);
        } catch (\Throwable) {
            $report = [
                'word_count' => $log->word_count,
                'quality_score' => null,
                'checks' => [],
                'passed' => (bool) $log->passed,
                'status' => $log->status,
                'matched_terms' => [],
                'blocked_urls' => [],
                'fix_hints' => [],
            ];
        }

        return view('admin.moderation.show', [
            'log' => $log,
            'submission' => $submission,
            'report' => $report,
            'listUrl' => $this->moderationListUrl($request),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        if (! $this->schemaTableAvailable('content_moderation_settings')) {
            return back()->with('error', 'Moderation settings are unavailable because the destination table is missing.');
        }

        $allCats = array_keys(config('content_moderation.categories', []));
        $data = $request->validateWithBag('policy', [
            'enabled' => ['sometimes', 'boolean'],
            'confidence_threshold' => ['required', 'integer', 'min:1', 'max:99'],
            'min_word_count' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'block_on_quality_failure' => ['sometimes', 'boolean'],
            'extra_keywords' => ['nullable', 'string'],
            'exceptions' => ['nullable', 'string'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', Rule::in($allCats)],
        ]);

        try {
            return $this->persistPolicySettings($request, $data, $allCats);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', UserFacingError::message(
                $e,
                'Could not save moderation settings on this database.'
            ));
        } catch (\Throwable) {
            return back()->withInput()->with('error', 'Could not save moderation settings on this database.');
        }
    }

    public function updateUploadSettings(Request $request): RedirectResponse
    {
        if (! $this->schemaTableAvailable('content_moderation_settings')) {
            return back()->with('error', 'Upload settings are unavailable because the destination table is missing.');
        }

        $data = $request->validateWithBag('upload', [
            'scheduling_enabled' => ['sometimes', 'boolean'],
            'uploads_enabled' => ['sometimes', 'boolean'],
            'require_same_language' => ['sometimes', 'boolean'],
            'retention_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'min_uniqueness' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        try {
            return $this->persistUploadSettings($request, $data);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', UserFacingError::message(
                $e,
                'Could not save upload settings on this database.'
            ));
        } catch (\Throwable) {
            return back()->withInput()->with('error', 'Could not save upload settings on this database.');
        }
    }

    public function testScan(Request $request, ContentModerationService $moderation): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['nullable', 'string', 'max:2000'],
            'text' => ['nullable', 'string', 'max:200000'],
        ]);
        $url = trim((string) ($data['url'] ?? ''));
        $text = trim((string) ($data['text'] ?? ''));
        if ($url === '' && $text === '') {
            throw ValidationException::withMessages([
                'text' => 'Paste article text or a public URL.',
            ]);
        }

        try {
            $report = $moderation->previewScan($url !== '' ? $url : null, $text !== '' ? $text : null);
        } catch (\Throwable $e) {
            report($e);

            return $this->redirectToModerationList()
                ->withInput($data)
                ->with('error', UserFacingError::message($e, 'Could not run the test scan.'));
        }

        return $this->redirectToModerationList()
            ->with('moderation_test', $report)
            ->withInput($data);
    }

    public function rescan(Request $request, ContentModerationLog $log, ContentModerationService $moderation): RedirectResponse
    {
        $admin = $request->user();
        if (! $admin instanceof User) {
            abort(403);
        }

        try {
            $result = $moderation->rescanStaffLog($log, $admin);
        } catch (\Throwable) {
            return back()->with('error', 'Could not re-scan this log on this database.');
        }

        if (! empty($result['ok']) && $result['log'] instanceof ContentModerationLog) {
            return redirect()
                ->route('admin.moderation.show', $result['log'])
                ->with('success', $result['message']);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allCats
     */
    private function persistPolicySettings(Request $request, array $data, array $allCats): RedirectResponse
    {
        $wasEnabled = (bool) ((ContentModerationSetting::getValue('config_override', []) ?: [])['enabled']
            ?? config('content_moderation.enabled', true));
        $previousDisabled = ContentModerationSetting::getValue('disabled_categories', []) ?: [];
        $before = $this->moderationSettingsSnapshot();

        $override = ContentModerationSetting::getValue('config_override', []) ?: [];
        $override['enabled'] = $request->boolean('enabled');
        $override['confidence_threshold'] = (int) $data['confidence_threshold'];
        $override['quality'] = $override['quality'] ?? config('content_moderation.quality', []);
        $override['quality']['min_word_count'] = (int) ($data['min_word_count'] ?? 500);
        $override['quality']['block_on_quality_failure'] = $request->boolean('block_on_quality_failure');

        ContentModerationSetting::setValue('config_override', $override);

        $keywords = $this->linesToArray($data['extra_keywords'] ?? '');
        $exceptions = $this->linesToArray($data['exceptions'] ?? '');
        ContentModerationSetting::setValue('extra_keywords', $keywords);
        ContentModerationSetting::setValue('exceptions', $exceptions);

        $selected = $data['categories'] ?? [];
        $disabled = array_values(array_diff($allCats, $selected));
        $enabled = array_values(array_intersect($allCats, $selected));
        ContentModerationSetting::setValue('disabled_categories', $disabled);
        ContentModerationSetting::setValue('enabled_categories', $enabled);
        ContentModerationSetting::clearCache();

        $after = $this->moderationSettingsSnapshot();
        if ($before !== $after) {
            ActivityLogger::tryLog(
                'moderation.settings_updated',
                ($request->user()?->name ?? 'Admin').' updated content moderation settings',
                null,
                [
                    'scope' => 'policy',
                    'enabled' => $after['enabled'],
                    'was_enabled' => $wasEnabled,
                    'confidence_threshold' => $after['confidence_threshold'],
                    'disabled_categories' => $after['disabled_categories'],
                    'previous_disabled_categories' => is_array($previousDisabled) ? array_values($previousDisabled) : [],
                    'extra_keyword_count' => count($keywords),
                ]
            );
        }

        return $this->redirectToModerationList()->with('success', 'Content policy settings saved.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistUploadSettings(Request $request, array $data): RedirectResponse
    {
        $before = $this->moderationSettingsSnapshot();
        $uploadOverride = ContentModerationSetting::getValue('upload_config', []) ?: [];
        $uploadOverride['allowed_extensions'] = ['docx'];
        $uploadOverride['preferred_extension'] = 'docx';
        $uploadOverride['enabled'] = $request->boolean('uploads_enabled');
        $uploadOverride['max_kilobytes'] = ContentUploadService::MAX_KILOBYTES;
        $uploadOverride['retention_months'] = (int) ($data['retention_months'] ?? 6);
        $uploadOverride['scheduling'] = $uploadOverride['scheduling'] ?? config('content_upload.scheduling', []);
        $uploadOverride['scheduling']['enabled'] = $request->boolean('scheduling_enabled');
        $uploadOverride['placement'] = $uploadOverride['placement'] ?? config('content_upload.placement', []);
        $uploadOverride['placement']['require_same_language'] = $request->boolean('require_same_language');
        $uploadOverride['evaluation'] = $uploadOverride['evaluation'] ?? config('content_upload.evaluation', []);
        $uploadOverride['evaluation']['min_uniqueness'] = (int) ($data['min_uniqueness'] ?? 50);
        ContentModerationSetting::setValue('upload_config', $uploadOverride);
        ContentModerationSetting::clearCache();

        $after = $this->moderationSettingsSnapshot();
        if ($before !== $after) {
            ActivityLogger::tryLog(
                'moderation.settings_updated',
                ($request->user()?->name ?? 'Admin').' updated article upload settings',
                null,
                [
                    'scope' => 'upload',
                    'uploads_enabled' => $after['uploads_enabled'],
                    'retention_months' => $after['retention_months'],
                ]
            );
        }

        return $this->redirectToModerationList()->with('success', 'Article upload settings saved.');
    }

    private function moderationListUrl(?Request $request = null): string
    {
        $request = $request ?: request();

        return AdminModeration::listUrl(AdminModeration::sessionReturnQuery($request));
    }

    private function redirectToModerationList(): RedirectResponse
    {
        return redirect()->to($this->moderationListUrl());
    }

    public function override(Request $request, ContentModerationLog $log, ContentModerationService $moderation): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        try {
            $result = $moderation->applyAdminOverride($log, $request->user(), trim($data['notes']));
        } catch (\Throwable) {
            return back()->with('error', 'Could not override this scan on this database.');
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function revert(Request $request, ContentModerationLog $log, ContentModerationService $moderation): RedirectResponse
    {
        try {
            $result = $moderation->revertAdminOverride($log, $request->user());
        } catch (\Throwable) {
            return back()->with('error', 'Could not revert this override on this database.');
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function moderationSettingsSnapshot(): array
    {
        $override = ContentModerationSetting::getValue('config_override', []) ?: [];
        $quality = is_array($override['quality'] ?? null)
            ? $override['quality']
            : (array) config('content_moderation.quality', []);
        $upload = ContentModerationSetting::getValue('upload_config', []) ?: [];
        $allCats = array_keys(config('content_moderation.categories', []));
        $disabled = ContentModerationSetting::getValue('disabled_categories', []) ?: [];
        $enabled = ContentModerationSetting::getValue('enabled_categories', []) ?: [];
        if ($enabled === [] && $disabled === []) {
            $enabled = $allCats;
        }

        return $this->normalizeModerationSettings([
            'enabled' => (bool) ($override['enabled'] ?? config('content_moderation.enabled', true)),
            'confidence_threshold' => (int) ($override['confidence_threshold'] ?? config('content_moderation.confidence_threshold', 70)),
            'min_word_count' => (int) ($quality['min_word_count'] ?? 500),
            'block_on_quality_failure' => (bool) ($quality['block_on_quality_failure'] ?? false),
            'extra_keywords' => ContentModerationSetting::getValue('extra_keywords', []) ?: [],
            'exceptions' => ContentModerationSetting::getValue('exceptions', []) ?: [],
            'disabled_categories' => $disabled,
            'enabled_categories' => $enabled,
            'uploads_enabled' => (bool) ($upload['enabled'] ?? config('content_upload.enabled', true)),
            'retention_months' => (int) ($upload['retention_months'] ?? config('content_upload.retention_months', 6)),
            'scheduling_enabled' => (bool) data_get($upload, 'scheduling.enabled', config('content_upload.scheduling.enabled', true)),
            'require_same_language' => (bool) data_get($upload, 'placement.require_same_language', config('content_upload.placement.require_same_language', false)),
            'min_uniqueness' => (int) data_get($upload, 'evaluation.min_uniqueness', config('content_upload.evaluation.min_uniqueness', 50)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function normalizeModerationSettings(array $settings): array
    {
        foreach (['extra_keywords', 'exceptions', 'disabled_categories', 'enabled_categories'] as $key) {
            $values = array_values(array_filter(array_map(
                static fn ($value) => trim((string) $value),
                is_array($settings[$key] ?? null) ? $settings[$key] : []
            ), static fn (string $value) => $value !== ''));
            sort($values);
            $settings[$key] = $values;
        }

        $settings['enabled'] = (bool) $settings['enabled'];
        $settings['confidence_threshold'] = (int) $settings['confidence_threshold'];
        $settings['min_word_count'] = (int) $settings['min_word_count'];
        $settings['block_on_quality_failure'] = (bool) $settings['block_on_quality_failure'];
        $settings['uploads_enabled'] = (bool) $settings['uploads_enabled'];
        $settings['retention_months'] = (int) $settings['retention_months'];
        $settings['scheduling_enabled'] = (bool) $settings['scheduling_enabled'];
        $settings['require_same_language'] = (bool) $settings['require_same_language'];
        $settings['min_uniqueness'] = (int) $settings['min_uniqueness'];

        ksort($settings);

        return $settings;
    }

    /**
     * @return array<int, string>
     */
    protected function linesToArray(string $text): array
    {
        $parts = preg_split('/[\r\n,]+/', $text) ?: [];
        $parts = array_map(fn ($p) => trim($p), $parts);

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * @return list<string>
     */
    protected function builtinExceptionPhrases(): array
    {
        $out = [];
        foreach (config('content_moderation.exceptions', []) as $key => $value) {
            if (is_int($key) && is_string($value) && trim($value) !== '') {
                $out[] = $value;
            } elseif (is_string($key) && is_array($value)) {
                foreach ($value as $phrase) {
                    if (is_string($phrase) && trim($phrase) !== '') {
                        $out[] = $phrase;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function emptyModerationLogPaginator(int $page): LengthAwarePaginator
    {
        return (new LengthAwarePaginator([], 0, 25, $page))->withQueryString();
    }

    private function schemaTableAvailable(string $table): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        try {
            DB::table($table)->limit(1)->exists();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}

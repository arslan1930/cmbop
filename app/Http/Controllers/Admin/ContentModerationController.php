<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\ContentUpload\AdminLibraryStaffActions;
use App\Services\ContentUpload\ContentUploadService;
use App\Support\PhpIniSize;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ContentModerationController extends Controller
{
    public function index(ContentModerationService $moderation, ContentUploadService $uploads)
    {
        $cfg = $moderation->effectiveConfig();
        $uploadCfg = $uploads->effectiveConfig();
        $stats = $moderation->adminStats();
        $logs = ContentModerationLog::query()
            ->with('user')
            ->latest('id')
            ->paginate(25);

        $phpUploadMaxKb = PhpIniSize::uploadMaxKilobytes();
        $articleUploadMaxKb = $uploads->effectiveMaxKilobytes($uploadCfg);
        $phpBlocksArticleUploads = $phpUploadMaxKb < $articleUploadMaxKb;

        $extraKeywords = ContentModerationSetting::getValue('extra_keywords', []) ?: [];
        $exceptions = ContentModerationSetting::getValue('exceptions', []) ?: [];
        $disabledCategories = ContentModerationSetting::getValue('disabled_categories', []) ?: [];
        $enabledCategories = ContentModerationSetting::getValue('enabled_categories', []) ?: [];
        // What the scanner will actually apply, not what the config file says.
        $activeCategories = $moderation->activeCategories();

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
        ));
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'confidence_threshold' => ['required', 'integer', 'min:1', 'max:99'],
            'min_word_count' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'block_on_quality_failure' => ['sometimes', 'boolean'],
            'extra_keywords' => ['nullable', 'string'],
            'exceptions' => ['nullable', 'string'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string'],
            'allowed_extensions' => ['nullable', 'string'],
            'max_kilobytes' => ['nullable', 'integer', 'min:10240', 'max:10240'],
            'scheduling_enabled' => ['sometimes', 'boolean'],
            'uploads_enabled' => ['sometimes', 'boolean'],
            'require_same_language' => ['sometimes', 'boolean'],
            'retention_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'min_uniqueness' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

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

        $allCats = array_keys(config('content_moderation.categories', []));
        $selected = $data['categories'] ?? [];
        $disabled = array_values(array_diff($allCats, $selected));
        $enabled = array_values(array_intersect($allCats, $selected));
        ContentModerationSetting::setValue('disabled_categories', $disabled);
        ContentModerationSetting::setValue('enabled_categories', $enabled);

        $uploadOverride = ContentModerationSetting::getValue('upload_config', []) ?: [];
        // Platform policy: Microsoft Word (.docx) only.
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

        return back()->with('success', 'Moderation and content upload settings saved.');
    }

    public function override(Request $request, ContentModerationLog $log, AdminLibraryStaffActions $staffActions)
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $notes = trim((string) ($data['notes'] ?? ''));
        if ($notes === '') {
            $notes = 'Approved via moderation scan override.';
        }

        $submission = $staffActions->submissionForLog($log);
        if ($submission) {
            try {
                $staffActions->override($submission, 'approved', $request->user(), $notes);
            } catch (ValidationException $e) {
                return back()->with(
                    'error',
                    collect($e->errors())->flatten()->first()
                        ?: 'The linked article could not be approved. The scan log was left unchanged.'
                );
            }
        }

        $log->update([
            'passed' => true,
            'status' => ContentModerationLog::STATUS_APPROVED,
            'admin_override' => true,
            'overridden_by' => auth()->id(),
            'overridden_at' => now(),
            'admin_notes' => $notes,
        ]);

        if (! $submission) {
            return back()->with('success', 'Scan log overridden. No Content Library article is linked to this scan.');
        }

        return redirect()
            ->route('admin.content-library.show', $submission)
            ->with('success', 'Article #'.$submission->id.' approved — open in Content Library.');
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
}

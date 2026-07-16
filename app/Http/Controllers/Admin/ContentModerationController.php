<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\ContentUpload\ContentUploadService;
use Illuminate\Http\Request;

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

        $extraKeywords = ContentModerationSetting::getValue('extra_keywords', []) ?: [];
        $exceptions = ContentModerationSetting::getValue('exceptions', []) ?: [];
        $disabledCategories = ContentModerationSetting::getValue('disabled_categories', []) ?: [];
        $enabledCategories = ContentModerationSetting::getValue('enabled_categories', []) ?: [];

        return view('admin.moderation.index', compact(
            'cfg',
            'uploadCfg',
            'stats',
            'logs',
            'extraKeywords',
            'exceptions',
            'disabledCategories',
            'enabledCategories'
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
            'max_kilobytes' => ['nullable', 'integer', 'min:100', 'max:51200'],
            'scheduling_enabled' => ['sometimes', 'boolean'],
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
        $uploadOverride['max_kilobytes'] = (int) ($data['max_kilobytes'] ?? 5120);
        $uploadOverride['retention_months'] = (int) ($data['retention_months'] ?? 6);
        $uploadOverride['scheduling'] = $uploadOverride['scheduling'] ?? config('content_upload.scheduling', []);
        $uploadOverride['scheduling']['enabled'] = $request->boolean('scheduling_enabled');
        $uploadOverride['evaluation'] = $uploadOverride['evaluation'] ?? config('content_upload.evaluation', []);
        $uploadOverride['evaluation']['min_uniqueness'] = (int) ($data['min_uniqueness'] ?? 50);
        ContentModerationSetting::setValue('upload_config', $uploadOverride);

        ContentModerationSetting::clearCache();

        return back()->with('success', 'Moderation and content upload settings saved.');
    }

    public function override(Request $request, ContentModerationLog $log)
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $log->update([
            'passed' => true,
            'status' => ContentModerationLog::STATUS_APPROVED,
            'admin_override' => true,
            'overridden_by' => auth()->id(),
            'overridden_at' => now(),
            'admin_notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Submission overridden as approved. Advertiser may resubmit the same link.');
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

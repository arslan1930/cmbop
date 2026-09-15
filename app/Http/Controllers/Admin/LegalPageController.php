<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalPageOverride;
use App\Services\ActivityLogger;
use App\Services\BlogHtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LegalPageController extends Controller
{
    public function index(): View
    {
        LegalPageOverride::ensureTable();
        $locales = LegalPageOverride::locales();
        $rows = [];

        foreach (LegalPageOverride::PAGES as $slug => $meta) {
            $overrides = [];
            if (LegalPageOverride::tableAvailable()) {
                $overrides = LegalPageOverride::query()
                    ->where('slug', $slug)
                    ->get()
                    ->keyBy('locale');
            }
            $published = [];
            $drafts = [];
            foreach ($locales as $locale) {
                $row = $overrides[$locale] ?? null;
                if ($row?->isPublished()) {
                    $published[] = $locale;
                } elseif ($row) {
                    $drafts[] = $locale;
                }
            }
            $rows[] = [
                'slug' => $slug,
                'label' => $meta['label'],
                'published' => $published,
                'drafts' => $drafts,
                'public_url' => localized_url($slug),
            ];
        }

        return view('admin.legal.index', compact('rows', 'locales'));
    }

    public function edit(Request $request, string $slug): View|RedirectResponse
    {
        if (! LegalPageOverride::isKnownSlug($slug)) {
            abort(404);
        }
        LegalPageOverride::ensureTable();

        $locale = strtolower(search_text($request->input('locale')) ?: 'en');
        if (! in_array($locale, LegalPageOverride::locales(), true)) {
            $locale = 'en';
        }

        $override = null;
        $publishedLocales = [];
        if (LegalPageOverride::tableAvailable()) {
            $override = LegalPageOverride::query()->where('slug', $slug)->where('locale', $locale)->first();
            $publishedLocales = LegalPageOverride::query()
                ->where('slug', $slug)
                ->whereNotNull('published_at')
                ->pluck('locale')
                ->all();
        }

        return view('admin.legal.edit', [
            'slug' => $slug,
            'meta' => LegalPageOverride::PAGES[$slug],
            'locale' => $locale,
            'locales' => LegalPageOverride::locales(),
            'override' => $override,
            'publishedLocales' => $publishedLocales,
            'publicUrl' => localized_url($slug, $locale),
        ]);
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        if (! LegalPageOverride::isKnownSlug($slug)) {
            abort(404);
        }
        LegalPageOverride::ensureTable();
        if (! LegalPageOverride::tableAvailable()) {
            return back()->with('error', 'Legal pages cannot be saved on this database.');
        }

        $data = $request->validate([
            'locale' => 'required|in:'.implode(',', LegalPageOverride::locales()),
            'title' => 'nullable|string|max:180',
            'body_html' => 'required|string|max:200000',
            'publish' => 'nullable|boolean',
        ]);

        $html = app(BlogHtmlSanitizer::class)->sanitize($data['body_html']);
        if (BlogHtmlSanitizer::isBlank($html)) {
            throw ValidationException::withMessages([
                'body_html' => 'Add page content before saving.',
            ]);
        }

        $publish = $request->boolean('publish');
        LegalPageOverride::query()->updateOrCreate(
            ['slug' => $slug, 'locale' => $data['locale']],
            [
                'title' => search_text($data['title'] ?? '') ?: null,
                'body_html' => $html,
                'published_at' => $publish ? now() : null,
                'updated_by' => $request->user()?->id,
            ]
        );

        ActivityLogger::tryLog(
            $publish ? 'legal.page_published' : 'legal.page_saved',
            ($request->user()?->name ?? 'Admin').' '.($publish ? 'published' : 'saved').' '.$slug.' ('.$data['locale'].')',
            null,
            [
                'slug' => $slug,
                'locale' => $data['locale'],
                'published' => $publish,
            ]
        );

        return redirect()
            ->route('admin.legal.edit', ['slug' => $slug, 'locale' => $data['locale']])
            ->with('success', $publish
                ? 'Custom '.$data['locale'].' page is live.'
                : 'Draft saved. Built-in translations still show until you publish.');
    }

    public function revert(Request $request, string $slug): RedirectResponse
    {
        if (! LegalPageOverride::isKnownSlug($slug)) {
            abort(404);
        }
        $locale = strtolower(search_text($request->input('locale')) ?: 'en');
        if (! in_array($locale, LegalPageOverride::locales(), true)) {
            return back()->with('error', 'Unknown locale.');
        }

        LegalPageOverride::ensureTable();
        $row = LegalPageOverride::tableAvailable()
            ? LegalPageOverride::query()->where('slug', $slug)->where('locale', $locale)->first()
            : null;
        $row?->delete();

        ActivityLogger::tryLog(
            'legal.page_reverted',
            ($request->user()?->name ?? 'Admin').' reverted '.$slug.' ('.$locale.') to built-in translations.',
            null,
            ['slug' => $slug, 'locale' => $locale]
        );

        return redirect()
            ->route('admin.legal.edit', ['slug' => $slug, 'locale' => $locale])
            ->with('success', 'Reverted to the built-in translated page for '.$locale.'.');
    }
}

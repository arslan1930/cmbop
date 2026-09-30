<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Support\AdminBlog;
use App\Support\PublicI18n;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesBlogPost
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function blogRules(bool $updating = false): array
    {
        $rules = [
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'author' => 'nullable|string|max:120',
            'tags' => 'nullable|string',
            'status' => 'required|in:draft,published',
            'primary_locale' => 'nullable|string|in:'.implode(',', $this->publicLocales()),
        ];

        if ($updating) {
            $rules['remove_featured_image'] = 'nullable|boolean';
        }

        foreach ($this->publicLocales() as $locale) {
            $titleRule = $locale === 'en' ? 'required' : 'nullable';
            $contentRule = $locale === 'en' ? 'required' : 'nullable';

            $rules["translations.{$locale}.title"] = $titleRule.'|string|max:255';
            $rules["translations.{$locale}.slug"] = 'nullable|string|max:255';
            $rules["translations.{$locale}.excerpt"] = 'nullable|string|max:300';
            $rules["translations.{$locale}.meta_title"] = 'nullable|string|max:70';
            $rules["translations.{$locale}.meta_description"] = 'nullable|string|max:180';
            $rules["translations.{$locale}.content"] = $contentRule.'|string';
            $rules["translations.{$locale}.is_published"] = 'nullable|boolean';
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeTranslationInput();
    }

    protected function normalizeTranslationInput(): void
    {
        $translations = (array) $this->input('translations', []);
        $en = is_array($translations['en'] ?? null) ? $translations['en'] : [];

        $en['title'] = search_text($en['title'] ?? '');
        $en['slug'] = search_text($en['slug'] ?? '');
        if (($en['excerpt'] ?? null) !== null) {
            $en['excerpt'] = search_text($en['excerpt']);
        }
        if (! is_string($en['content'] ?? null)) {
            $en['content'] = search_text($en['content'] ?? '');
        }

        if ($en['title'] === '' && filled($this->input('title'))) {
            $en['title'] = search_text($this->input('title'));
        }
        if ($en['slug'] === '' && filled($this->input('slug'))) {
            $en['slug'] = search_text($this->input('slug'));
        }
        if (($en['excerpt'] ?? '') === '' && filled($this->input('excerpt'))) {
            $en['excerpt'] = search_text($this->input('excerpt'));
        }
        if (($en['content'] ?? '') === '' && filled($this->input('content'))) {
            $en['content'] = search_text($this->input('content'));
        }

        $translations['en'] = $en;

        foreach ($translations as $locale => $row) {
            if (! is_array($row)) {
                unset($translations[$locale]);

                continue;
            }
            if (array_key_exists('is_published', $row)) {
                $published = $row['is_published'];
                if (is_array($published)) {
                    $published = $published === [] ? false : end($published);
                }
                $row['is_published'] = AdminBlog::normalizeIncomplete($published) ? '1' : '0';
            }
            $translations[$locale] = $row;
        }

        $this->merge(['translations' => $translations]);
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->hasFile('featured_image')) {
            try {
                $this->session()->flash('warning', 'Choose the featured image again.');
            } catch (\Throwable) {
            }
        }

        parent::failedValidation($validator);
    }

    /**
     * @return list<string>
     */
    protected function publicLocales(): array
    {
        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'supported')) {
            return PublicI18n::supported();
        }

        return array_values(array_filter(
            (array) config('i18n.supported', ['en']),
            static fn ($locale) => is_string($locale) && $locale !== ''
        )) ?: ['en'];
    }
}

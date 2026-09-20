<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Support\PublicI18n;

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
        $translations = (array) $this->input('translations', []);
        $en = (array) ($translations['en'] ?? []);

        if (($en['title'] ?? '') === '' && filled($this->input('title'))) {
            $en['title'] = (string) $this->input('title');
        }
        if (($en['slug'] ?? '') === '' && filled($this->input('slug'))) {
            $en['slug'] = (string) $this->input('slug');
        }
        if (($en['excerpt'] ?? '') === '' && filled($this->input('excerpt'))) {
            $en['excerpt'] = (string) $this->input('excerpt');
        }
        if (($en['content'] ?? '') === '' && filled($this->input('content'))) {
            $en['content'] = (string) $this->input('content');
        }

        $translations['en'] = $en;
        $this->merge(['translations' => $translations]);
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

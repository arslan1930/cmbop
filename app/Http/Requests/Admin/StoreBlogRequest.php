<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesBlogPost;
use App\Support\AdminBlog;
use Illuminate\Foundation\Http\FormRequest;

class StoreBlogRequest extends FormRequest
{
    use ValidatesBlogPost;

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return array_merge($this->blogRules(false), [
            'intent' => 'nullable|string|in:draft,publish',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeTranslationInput();
        $intent = search_text($this->input('intent'));
        $this->merge([
            'status' => AdminBlog::resolveStoreStatus($this),
            'intent' => in_array($intent, ['draft', 'publish'], true) ? $intent : null,
        ]);
    }
}

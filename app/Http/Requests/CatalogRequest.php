<?php

// App\Http\Requests\CatalogRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'verified' => 'nullable|boolean',
            'da_min' => 'nullable|integer|min:0|max:100',
            'da_max' => 'nullable|integer|min:0|max:100',
            'dr_min' => 'nullable|integer|min:0|max:100',
            'dr_max' => 'nullable|integer|min:0|max:100',
            'traffic_min' => 'nullable|integer|min:0',
            'traffic_max' => 'nullable|integer|min:0',
            'language' => 'nullable|string|size:2',
        ];
    }
}
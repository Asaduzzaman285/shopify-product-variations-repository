<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShopifyProductRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'variations' => 'required|array|min:1',
            'variations.*.title' => 'required|string|max:255',
            'variations.*.price' => 'required|numeric|min:0',
            'variations.*.inventory_quantity' => 'nullable|integer|min:0',
            'variations.*.images' => 'nullable|array',
            'variations.*.images.*.src' => 'required_with:variations.*.images|url',
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\AiModelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'chat_id' => ['nullable', 'uuid'],
            'ai_model_id' => ['required', 'integer', Rule::exists('ai_models', 'id')->where('is_active', true)->where('type', AiModelType::Image->value)],
            // Свой предел у модели — проверяется по её схеме
            'prompt' => ['required', 'string', 'max:20000'],
            // Референсы из файлового поля; чужие не найдутся — выборка по владельцу
            'file_ids' => ['nullable', 'array', 'max:16'],
            'file_ids.*' => ['uuid', 'distinct'],
            'params' => ['nullable', 'array'],
            'params.aspect_ratio' => ['nullable', 'string', 'max:10'],
            'params.image_resolution' => ['nullable', 'string', 'max:10'],
            'params.quality' => ['nullable', 'string', 'max:20'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\AiModelType;
use App\Enums\ReasoningEffort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    public const MAX_MODELS = 4;

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:20000'],
            'chat_id' => ['nullable', 'uuid'],
            // Мульти-запрос: одна и та же реплика уходит сразу нескольким моделям
            'ai_model_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_MODELS],
            'ai_model_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('ai_models', 'id')->where('is_active', true)->where('type', AiModelType::Text->value),
            ],
            'max_tokens' => ['nullable', 'integer', 'between:256,32768'],
            'reasoning_effort' => ['nullable', Rule::enum(ReasoningEffort::class)],
        ];
    }
}

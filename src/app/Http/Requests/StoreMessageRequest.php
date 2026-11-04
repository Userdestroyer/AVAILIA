<?php

namespace App\Http\Requests;

use App\Enums\AiModelType;
use App\Enums\ReasoningEffort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:20000'],
            'chat_id' => ['nullable', 'uuid'],
            'ai_model_id' => [
                'required',
                Rule::exists('ai_models', 'id')->where('is_active', true)->where('type', AiModelType::Text->value),
            ],
            'max_tokens' => ['nullable', 'integer', 'between:256,32768'],
            'reasoning_effort' => ['nullable', Rule::enum(ReasoningEffort::class)],
        ];
    }
}

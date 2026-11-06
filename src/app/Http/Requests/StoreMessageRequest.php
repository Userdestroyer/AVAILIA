<?php

namespace App\Http\Requests;

use App\Enums\AiModelType;
use App\Enums\ReasoningEffort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    public const MAX_MODELS = 4;

    /**
     * Домены как ни введи — https://www.cbr.ru/путь или CBR.ru — приводим к виду www.cbr.ru / cbr.ru.
     */
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('allowed_domains'))) {
            $this->merge([
                'allowed_domains' => collect($this->input('allowed_domains'))
                    ->map(fn ($domain) => strtolower(trim((string) $domain)))
                    ->map(fn ($domain) => preg_replace('#^[a-z]+://#', '', $domain))
                    ->map(fn ($domain) => explode('/', $domain)[0])
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
            ]);
        }
    }

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
            'web_search' => ['boolean'],
            // Поиск дорогой (каждый ~1 ₽ + найденные страницы как ввод) — не больше 5 за ответ
            'max_web_searches' => ['nullable', 'integer', 'between:1,5'],
            'allowed_domains' => ['nullable', 'array', 'max:10'],
            'allowed_domains.*' => ['string', 'regex:/^[a-z0-9-]+(\.[a-z0-9-]+)+$/'],
            'reasoning_effort' => ['nullable', Rule::enum(ReasoningEffort::class)],
        ];
    }
}

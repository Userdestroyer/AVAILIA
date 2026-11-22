<?php

namespace App\Http\Requests;

use App\Enums\AiModelType;
use App\Enums\ReasoningEffort;
use App\Services\Ai\Data\ChatOptions;
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

    /**
     * Параметры ответа — те же у нового вопроса и у регенерации.
     *
     * @return array<string, mixed>
     */
    public static function optionRules(): array
    {
        return [
            'max_tokens' => ['nullable', 'integer', 'between:256,32768'],
            'web_search' => ['boolean'],
            // Поиск платный (каждый 0,5–1,5 ₽ + найденные страницы как ввод) — не больше 20 за ответ
            'max_web_searches' => ['nullable', 'integer', 'between:1,20'],
            'allowed_domains' => ['nullable', 'array', 'max:10'],
            'allowed_domains.*' => ['string', 'regex:/^[a-z0-9-]+(\.[a-z0-9-]+)+$/'],
            'reasoning_effort' => ['nullable', Rule::enum(ReasoningEffort::class)],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
        ];
    }

    /**
     * Параметры ответа из запроса.
     */
    public function options(): ChatOptions
    {
        return new ChatOptions(
            maxTokens: $this->input('max_tokens'),
            reasoningEffort: $this->enum('reasoning_effort', ReasoningEffort::class),
            temperature: $this->filled('temperature') ? $this->float('temperature') : null,
            webSearch: $this->boolean('web_search'),
            maxWebSearches: $this->input('max_web_searches'),
            allowedDomains: $this->input('allowed_domains') ?? [],
        );
    }

    public function rules(): array
    {
        return [
            // Скриншот можно отправить и без подписи
            'content' => ['nullable', 'required_without:file_ids', 'string', 'max:20000'],
            'chat_id' => ['nullable', 'uuid'],
            // Мульти-запрос: одна и та же реплика уходит сразу нескольким моделям
            'ai_model_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_MODELS],
            'ai_model_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('ai_models', 'id')->where('is_active', true)->where('type', AiModelType::Text->value),
            ],
            ...self::optionRules(),
            // Картриджи контекста; чужие id просто не найдутся — выборка идёт по владельцу
            'plate_ids' => ['nullable', 'array', 'max:30'],
            'plate_ids.*' => ['integer'],
            // Файлы из файлового поля; чужие не найдутся — выборка по владельцу
            'file_ids' => ['nullable', 'array', 'max:'.config('files.per_message')],
            'file_ids.*' => ['uuid', 'distinct'],
        ];
    }
}

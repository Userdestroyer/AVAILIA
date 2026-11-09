<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageRequest;
use App\Support\Appearance;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    private const CHAT_KEYS = [
        'mode', 'model_id', 'multi_ids', 'token_step', 'effort', 'deep_thinking', 'temperature', 'web_search', 'max_web_searches', 'domains',
    ];

    /**
     * Фронт уже применил настройку сам — здесь только сохраняем, отвечаем пустым телом.
     */
    public function update(Request $request): Response
    {
        $request->validate([
            'theme' => ['sometimes', Rule::in(Appearance::OPTIONS['theme'])],
            'style' => ['sometimes', Rule::in(Appearance::OPTIONS['style'])],
            'font' => ['sometimes', Rule::in(Appearance::OPTIONS['font'])],
            // Последний выбор в панели чата — восстанавливается при перезагрузке и на других устройствах
            'chat' => ['sometimes', 'array'],
            'chat.mode' => ['required_with:chat', Rule::in(['single', 'multi'])],
            'chat.model_id' => ['nullable', 'integer'],
            'chat.multi_ids' => ['nullable', 'array', 'max:'.StoreMessageRequest::MAX_MODELS],
            'chat.multi_ids.*' => ['integer'],
            'chat.token_step' => ['nullable', 'integer', 'between:0,7'],
            'chat.effort' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'chat.deep_thinking' => ['boolean'],
            'chat.web_search' => ['boolean'],
            'chat.max_web_searches' => ['nullable', 'integer', 'between:1,20'],
            'chat.temperature' => ['nullable', 'numeric', 'between:0,2'],
            'chat.domains' => ['nullable', 'string', 'max:500'],
        ]);

        // Только известные ключи — в JSON не попадёт ничего лишнего
        $settings = array_filter([
            'theme' => $request->input('theme'),
            'style' => $request->input('style'),
            'font' => $request->input('font'),
            'chat' => $request->has('chat') ? Arr::only($request->input('chat'), self::CHAT_KEYS) : null,
        ], fn ($value) => $value !== null);

        $user = $request->user();
        $user->update(['settings' => [...($user->settings ?? []), ...$settings]]);

        return response()->noContent();
    }
}

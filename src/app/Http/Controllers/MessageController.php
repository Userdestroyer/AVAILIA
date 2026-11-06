<?php

namespace App\Http\Controllers;

use App\Enums\ReasoningEffort;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\AiModel;
use App\Services\Ai\Data\ChatOptions;
use App\Services\Chat\ChatStream;
use Illuminate\Http\JsonResponse;

class MessageController extends Controller
{
    public function store(StoreMessageRequest $request, ChatStream $stream): JsonResponse
    {
        $user = $request->user();

        // Каждая модель мульти-запроса — отдельный запрос в счёт дневного лимита
        $modelIds = array_map('intval', $request->input('ai_model_ids'));
        $limit = $user->effectivePlan()->daily_request_limit;
        if ($limit !== null && $user->requestsToday() + count($modelIds) > $limit) {
            $left = max(0, $limit - $user->requestsToday());

            return response()->json(['message' => "Дневной лимит запросов: осталось {$left} из {$limit}."], 429);
        }

        // Чужой или несуществующий чат — 404, а не новый чат
        $chat = $request->filled('chat_id') ? $user->chats()->findOrFail($request->input('chat_id')) : null;

        $started = $stream->start(
            $user,
            $chat,
            // Порядок — как выбрал юзер: первая модель — основная
            AiModel::findMany($modelIds)->sortBy(fn (AiModel $model) => array_search($model->id, $modelIds))->values(),
            $request->string('content')->trim(),
            new ChatOptions(
                maxTokens: $request->input('max_tokens'),
                reasoningEffort: $request->enum('reasoning_effort', ReasoningEffort::class),
                webSearch: $request->boolean('web_search'),
                maxWebSearches: $request->input('max_web_searches'),
                allowedDomains: $request->input('allowed_domains') ?? [],
            ),
        );

        return response()->json([
            'chat' => $started['chat']->only('id', 'title'),
            'user_message' => MessageResource::make($started['userMessage']),
            'answers' => array_map(fn (array $answer) => [
                'message' => MessageResource::make($answer['message']),
                'stream_token' => $answer['token'],
            ], $started['answers']),
        ], 201);
    }
}

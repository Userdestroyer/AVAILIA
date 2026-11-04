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

        $limit = $user->effectivePlan()->daily_request_limit;
        if ($limit !== null && $user->requestsToday() >= $limit) {
            return response()->json(['message' => "Дневной лимит запросов исчерпан ({$limit}). Возвращайтесь завтра."], 429);
        }

        // Чужой или несуществующий чат — 404, а не новый чат
        $chat = $request->filled('chat_id') ? $user->chats()->findOrFail($request->input('chat_id')) : null;

        $started = $stream->start(
            $user,
            $chat,
            AiModel::findOrFail($request->integer('ai_model_id')),
            $request->string('content')->trim(),
            new ChatOptions(
                maxTokens: $request->input('max_tokens'),
                reasoningEffort: $request->enum('reasoning_effort', ReasoningEffort::class),
            ),
        );

        return response()->json([
            'chat' => $started['chat']->only('id', 'title'),
            'user_message' => MessageResource::make($started['userMessage']),
            'assistant_message' => MessageResource::make($started['assistantMessage']),
            'stream_token' => $started['token'],
        ], 201);
    }
}

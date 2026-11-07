<?php

namespace App\Http\Controllers;

use App\Enums\ReasoningEffort;
use App\Http\Requests\StoreMessageRequest;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Services\Ai\Data\ChatOptions;
use App\Services\Ai\ProviderRegistry;
use App\Services\Chat\ChatStream;
use Illuminate\Http\JsonResponse;

class MessageController extends Controller
{
    /**
     * Мягкое удаление: из чата и из истории для модели пропадает, в учёте и лимите остаётся.
     * Вопрос удаляется вместе с ответами — иначе они «прилипли» бы к предыдущему ходу.
     */
    public function destroy(Request $request, Message $message): Response
    {
        abort_unless($message->chat?->user_id === $request->user()->id, 404);

        // Пока ответ пишется, удалить нельзя: гейтвей ещё отчитается по нему
        abort_if(in_array($message->status, [MessageStatus::Pending, MessageStatus::Streaming], true), 409, 'Ответ ещё пишется');

        DB::transaction(function () use ($message) {
            if ($message->role === MessageRole::User) {
                $message->answers()->delete();
            }
            $message->delete();
        });

        return response()->noContent();
    }

    /**
     * Браузер сообщает, когда увидел первый токен (от нажатия «Отправить») — скорость глазами человека.
     * Пишется один раз: повтор не перезапишет первое измерение.
     */
    public function metrics(Request $request, Message $message): Response
    {
        abort_unless($message->chat?->user_id === $request->user()->id && $message->role === MessageRole::Assistant, 404);
        $validated = $request->validate(['client_ttft_ms' => ['required', 'integer', 'min:0', 'max:600000']]);

        if ($message->client_ttft_ms === null) {
            $message->update($validated);
        }

        return response()->noContent();
    }

    public function store(StoreMessageRequest $request, ChatStream $stream, ProviderRegistry $providers): JsonResponse
    {
        $user = $request->user();

        // Каждая модель мульти-запроса — отдельный запрос в счёт дневного лимита
        $modelIds = array_map('intval', $request->input('ai_model_ids'));
        $limit = $user->effectivePlan()->daily_request_limit;
        if ($limit !== null && $user->requestsToday() + count($modelIds) > $limit) {
            $left = max(0, $limit - $user->requestsToday());

            return response()->json(['message' => "Дневной лимит запросов: осталось {$left} из {$limit}."], 429);
        }

        // Модель есть в каталоге, но у текущего провайдера не подключена — отправлять некуда
        $offers = $providers->offerings($modelIds);
        if ($offers->count() !== count($modelIds)) {
            return response()->json(['message' => 'Эта модель сейчас недоступна у провайдера — выберите другую.'], 422);
        }

        // Чужой или несуществующий чат — 404, а не новый чат
        $chat = $request->filled('chat_id') ? $user->chats()->findOrFail($request->input('chat_id')) : null;

        // Только свои картриджи: фильтр по владельцу — в самом запросе, чужой id сюда не попадёт
        $plates = $user->plates()
            ->whereIn('id', $request->input('plate_ids') ?? [])
            ->with('tags')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $started = $stream->start(
            $user,
            $chat,
            // Порядок — как выбрал юзер: первая модель — основная
            collect($modelIds)->map(fn (int $id) => $offers[$id]),
            $request->string('content')->trim(),
            new ChatOptions(
                maxTokens: $request->input('max_tokens'),
                reasoningEffort: $request->enum('reasoning_effort', ReasoningEffort::class),
                temperature: $request->filled('temperature') ? $request->float('temperature') : null,
                webSearch: $request->boolean('web_search'),
                maxWebSearches: $request->input('max_web_searches'),
                allowedDomains: $request->input('allowed_domains') ?? [],
            ),
            $plates,
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

<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegenerateMessageRequest;
use App\Http\Requests\StoreMessageRequest;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Services\Ai\ProviderRegistry;
use App\Enums\UsageScope;
use App\Models\UsageWindow;
use App\Services\Chat\ChatStream;
use App\Services\Chat\ContextPrompt;
use App\Models\User;
use App\Services\Limits\UsageMeter;
use Illuminate\Http\JsonResponse;

class MessageController extends Controller
{
    // Вариантов ответа одной модели на вопрос: исходный и до стольких перегенераций
    public const MAX_REGENERATIONS = 5;

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

            // Удалили показанный вариант — показываем последний из оставшихся
            if ($message->role === MessageRole::Assistant && $message->is_current) {
                $this->variants($message)->latest('id')->first()?->update(['is_current' => true]);
            }
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

    /**
     * Ещё один вариант ответа той же модели на тот же вопрос (стрелка «вправо» на последнем варианте).
     */
    public function regenerate(RegenerateMessageRequest $request, Message $message, ChatStream $stream, ProviderRegistry $providers, UsageMeter $meter): JsonResponse
    {
        $user = $request->user();
        abort_unless($message->chat?->user_id === $user->id && $message->role === MessageRole::Assistant, 404);

        if ($this->variants($message)->whereIn('status', [MessageStatus::Pending, MessageStatus::Streaming])->exists()) {
            return response()->json(['message' => 'Вариант ещё пишется'], 409);
        }
        if ($this->variants($message)->count() > self::MAX_REGENERATIONS) {
            return response()->json(['message' => 'Не больше '.self::MAX_REGENERATIONS.' перегенераций — попробуйте спросить иначе.'], 422);
        }
        if ($exhausted = $this->exhausted($user, $meter)) {
            return $exhausted;
        }

        $offer = $message->aiModel ? $providers->offering($message->aiModel) : null;
        if (! $offer) {
            return response()->json(['message' => 'Эта модель сейчас недоступна у провайдера — выберите другую.'], 422);
        }

        $started = $stream->regenerate($message, $offer, $request->options(), ContextPrompt::recorded($user, $message->parent));

        return response()->json([
            'message' => MessageResource::make($started['message']),
            'stream_token' => $started['token'],
        ], 201);
    }

    /**
     * Какой вариант ответа показывать и отдавать модели в историю.
     */
    public function select(Request $request, Message $message): Response
    {
        abort_unless($message->chat?->user_id === $request->user()->id && $message->role === MessageRole::Assistant, 404);

        DB::transaction(function () use ($message) {
            $this->variants($message)->update(['is_current' => false]);
            $message->update(['is_current' => true]);
        });

        return response()->noContent();
    }

    /**
     * Вопрос целиком: картриджи в тех версиях, что ушли с ним в модель.
     */
    public function context(Request $request, Message $message): JsonResponse
    {
        abort_unless($message->chat?->user_id === $request->user()->id && $message->role === MessageRole::User, 404);

        return response()->json([
            'plates' => ContextPrompt::recorded($request->user(), $message)
                ->map(fn ($plate) => ['plate_id' => $plate->id, 'title' => $plate->title, 'content' => $plate->content]),
        ]);
    }

    public function store(StoreMessageRequest $request, ChatStream $stream, ProviderRegistry $providers, UsageMeter $meter): JsonResponse
    {
        $user = $request->user();

        $modelIds = array_map('intval', $request->input('ai_model_ids'));

        if ($exhausted = $this->exhausted($user, $meter)) {
            return $exhausted;
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

        // Только свои файлы и в том порядке, как их приложили; чужой id — как несуществующий
        $fileIds = $request->input('file_ids') ?? [];
        $files = $user->files()->whereIn('id', $fileIds)->get()->keyBy('id');
        if ($files->count() !== count($fileIds)) {
            return response()->json(['message' => 'Приложенный файл не найден — прикрепите его заново.'], 422);
        }

        $started = $stream->start(
            $user,
            $chat,
            // Порядок — как выбрал юзер: первая модель — основная
            collect($modelIds)->map(fn (int $id) => $offers[$id]),
            (string) $request->string('content')->trim(),
            $request->options(),
            $plates,
            collect($fileIds)->map(fn (string $id) => $files[$id])->values(),
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

    /**
     * Все варианты ответа той же модели на тот же вопрос (включая этот).
     */
    private function variants(Message $answer)
    {
        return Message::where('chat_id', $answer->chat_id)
            ->where('parent_id', $answer->parent_id)
            ->where('ai_model_id', $answer->ai_model_id)
            ->where('role', MessageRole::Assistant);
    }

    /**
     * Окна «сессия + неделя»: проверка — две строки по индексу, путь к первому токену не удлиняется.
     */
    private function exhausted(User $user, UsageMeter $meter): ?JsonResponse
    {
        $exceeded = $meter->check($user, UsageScope::Text);
        if (! $exceeded) {
            return null;
        }

        $window = $exceeded['kind'] === UsageWindow::SESSION ? 'сессии' : 'недели';
        $at = $exceeded['resets_at']->copy()->timezone(config('app.display_timezone', 'Europe/Moscow'));
        $when = $at->isToday() ? $at->format('H:i') : $at->translatedFormat('j M в H:i');

        return response()->json([
            'message' => "Энергия {$window} закончилась — восстановится в {$when}.",
            'limit' => ['kind' => $exceeded['kind'], 'resets_at' => $exceeded['resets_at']->toIso8601String()],
        ], 429);
    }
}

<?php

namespace App\Services\Images;

use App\Enums\AiModelType;
use App\Enums\FileSource;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Jobs\GenerateImage;
use App\Jobs\GenerateChatTitle;
use App\Models\Chat;
use App\Models\Message;
use App\Models\ProviderModel;
use App\Models\TokenTransaction;
use App\Models\User;
use App\Models\UserFile;
use App\Services\Files\FileStore;
use App\Services\Limits\TokenWallet;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Генерация картинок через Polza Media API: задание уходит асинхронно, статус опрашивает задача
 * в очереди, результат скачивается в файловое поле юзера («Сгенерированное») — у Polza он живёт 7 дней.
 * Токены контента (1 токен = 1 ₽ себестоимости): при старте удерживаются по прайсу модели,
 * по факту пересчитываются по стоимости от провайдера; не удалось — возвращаются целиком.
 */
class ImageGenerator
{
    // Параметры, которые выставляет юзер; остальное — по умолчанию модели
    private const PARAMS = ['aspect_ratio', 'image_resolution', 'quality'];

    public function __construct(
        private TokenWallet $wallet,
        private FileStore $files,
    ) {}

    /**
     * Только то, что модель принимает, и из её значений; не задано — умолчание модели (для точной цены).
     *
     * @param  array<string, mixed>  $requested
     * @return array<string, string>
     */
    public static function params(ProviderModel $offer, array $requested): array
    {
        $schema = $offer->parameters ?? [];
        $params = [];

        foreach (self::PARAMS as $key) {
            $values = $schema[$key]['values'] ?? null;
            $value = $requested[$key] ?? $schema[$key]['default'] ?? null;
            if ($values && $value !== null && in_array($value, $values, true)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    public static function referenceLimit(ProviderModel $offer): int
    {
        return (int) ($offer->parameters['images']['max'] ?? 0);
    }

    /**
     * Сколько токенов удержать: прайс модели, а неизвестен — запасная оценка.
     *
     * @param  array<string, string>  $params
     */
    public static function hold(ProviderModel $offer, array $params, int $references): int
    {
        return (int) ceil(ImagePrice::estimate($offer->pricing ?? [], $params, $references) ?? config('images.fallback_price'));
    }

    /**
     * Вопрос и ответ-заготовка в чате картинок, токены удержаны, задание — в очередь.
     *
     * @param  Collection<int, UserFile>  $references  референсы — уже только свои картинки
     * @param  array<string, string>  $params  уже приведённые к модели (self::params)
     * @return array{chat: Chat, question: Message, answer: Message}
     */
    public function start(User $user, ?Chat $chat, ProviderModel $offer, string $prompt, Collection $references, array $params): array
    {
        $hold = self::hold($offer, $params, $references->count());
        // Тариф без лимита контента — токены не тратятся, себестоимость всё равно пишется
        $limited = $user->effectivePlan()->content_tokens_monthly !== null;

        $started = DB::transaction(function () use ($user, $chat, $offer, $prompt, $references, $params, $hold, $limited) {
            if ($limited && ! $this->wallet->spend($user, $hold, TokenTransaction::GENERATION, "Картинка · {$offer->aiModel->name}")) {
                throw ValidationException::withMessages([
                    'tokens' => "Не хватает токенов контента: нужно ≈ {$hold}, на балансе {$user->content_tokens}.",
                ]);
            }

            if ($chat) {
                $chat->update(['ai_model_id' => $offer->ai_model_id]);
                $chat->touch();
            } else {
                $chat = $user->chats()->create([
                    'ai_model_id' => $offer->ai_model_id,
                    'mode' => AiModelType::Image,
                    'title' => GenerateChatTitle::fallback($prompt),
                ]);
            }

            $question = $chat->messages()->create(['role' => MessageRole::User, 'content' => $prompt]);
            if ($references->isNotEmpty()) {
                $question->files()->attach($references->values()->mapWithKeys(fn (UserFile $file, int $sort) => [$file->id => ['sort' => $sort]])->all());
            }
            $question->setRelation('files', $references->values());

            $answer = $chat->messages()->create([
                'parent_id' => $question->id,
                'role' => MessageRole::Assistant,
                'status' => MessageStatus::Pending,
                'ai_model_id' => $offer->ai_model_id,
                'provider_id' => $offer->provider_id,
                'is_current' => true,
                'generation' => ['model' => $offer->external_id, 'params' => $params, 'hold' => $limited ? $hold : 0, 'estimate' => $hold],
            ]);
            $answer->setRelation('files', collect());

            return ['chat' => $chat, 'question' => $question, 'answer' => $answer];
        });

        GenerateImage::dispatch($started['answer']->id);

        return $started;
    }

    /**
     * Шаг задания: отправить, если ещё не ушло, иначе узнать статус; готово — забрать, нет — опросить позже.
     */
    public function advance(int $answerId): void
    {
        $answer = Message::with(['chat.user', 'parent.files'])->find($answerId);
        if (! $answer || in_array($answer->status, [MessageStatus::Done, MessageStatus::Failed], true)) {
            return;
        }

        $generation = $answer->generation ?? [];

        // Готово, но в прошлый раз не скачалось — у провайдера картинка живёт 7 дней, качаем снова
        if (! empty($generation['result'])) {
            $this->finish($answer, $generation['result']);

            return;
        }

        try {
            if (empty($generation['external_id'])) {
                $result = $this->request()->post('/media', $this->body($answer))->throw()->json();
                $generation['external_id'] = $result['id'] ?? null;
                $answer->update(['status' => MessageStatus::Streaming, 'generation' => $generation]);
            } else {
                $result = $this->request()->get('/media/'.$generation['external_id'])->throw()->json();
            }
        } catch (Throwable $e) {
            // Не дошли до провайдера: задания там нет — отказ; не смогли спросить статус — спросим ещё
            if (empty($generation['external_id'])) {
                $this->fail($answer, 'Провайдер не принял задание: '.Str::limit($e->getMessage(), 200));

                return;
            }
            Log::warning('image generation poll failed', ['message_id' => $answer->id, 'error' => $e->getMessage()]);
            $result = ['status' => 'processing'];
        }

        match ($result['status'] ?? 'pending') {
            'completed' => $this->finish($answer, $result),
            'failed' => $this->fail($answer, 'Модель не нарисовала: '.Str::limit((string) (is_array($result['error'] ?? null) ? ($result['error']['message'] ?? 'ошибка') : ($result['error'] ?? 'ошибка')), 200)),
            default => $this->later($answer, 'Генерация не уложилась в '.config('images.timeout_minutes').' минут'),
        };
    }

    /**
     * Спросить ещё раз через несколько секунд — или сдаться, если время вышло.
     *
     * @param  array<string, mixed>|null  $result  что уже известно от провайдера (стоимость — для учёта)
     */
    private function later(Message $answer, string $timeout, ?array $result = null): void
    {
        if ($answer->created_at->lt(now()->subMinutes(config('images.timeout_minutes')))) {
            $this->fail($answer, $timeout, $result['usage']['cost_rub'] ?? $result['usage']['cost'] ?? null);

            return;
        }

        GenerateImage::dispatch($answer->id)->delay(now()->addSeconds(config('images.poll_seconds')));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Message $answer): array
    {
        $generation = $answer->generation;
        // Референсы — base64: наружу файлы юзера по ссылке не отдаём
        $images = $answer->parent->files->filter->isImage()
            ->map(fn (UserFile $file) => ['type' => 'base64', 'data' => $file->dataUri()])
            ->values()->all();

        return [
            'model' => $generation['model'],
            'input' => array_filter([
                'prompt' => (string) $answer->parent->content,
                ...$generation['params'] ?? [],
                'images' => $images ?: null,
            ], fn ($value) => $value !== null),
            'async' => true,
        ];
    }

    /**
     * Готово: картинки — в файловое поле юзера и к ответу, токены — по факту.
     *
     * @param  array<string, mixed>  $result
     */
    private function finish(Message $answer, array $result): void
    {
        $user = $answer->chat->user;
        $data = $result['data'] ?? [];
        $urls = collect(isset($data['url']) ? [$data] : (array_is_list($data) ? $data : ($data['images'] ?? [])))
            ->map(fn ($item) => is_array($item) ? ($item['url'] ?? null) : $item)
            ->filter()
            ->values();

        try {
            $files = $urls->map(fn (string $url, int $index) => $this->files->storeContents(
                $user,
                Http::timeout(60)->retry(2, 500)->get($url)->throw()->body(),
                Str::slug(Str::limit((string) $answer->parent->content, 40, ''), '-').($urls->count() > 1 ? '-'.($index + 1) : '').'.png',
                FileSource::Generated,
            ));
        } catch (Throwable $e) {
            // Запоминаем готовый результат — следующий шаг сразу качает, не спрашивая статус
            Log::warning('image download failed', ['message_id' => $answer->id, 'error' => $e->getMessage()]);
            $answer->update(['generation' => [...$answer->generation, 'result' => Arr::only($result, ['status', 'data', 'usage'])]]);
            $this->later($answer, 'Картинка готова, но не скачалась: '.Str::limit($e->getMessage(), 200), $result);

            return;
        }
        if ($files->isEmpty()) {
            $this->fail($answer, 'Провайдер не вернул картинку');

            return;
        }

        $generation = $answer->generation;
        $cost = $result['usage']['cost_rub'] ?? $result['usage']['cost'] ?? null;
        // Без лимита контента токены не тратились — для показа считаем по факту
        $spent = $generation['hold'] ? $this->settle($user, (int) $generation['hold'], $cost) : (is_numeric($cost) ? (int) ceil((float) $cost) : $generation['estimate']);

        DB::transaction(function () use ($answer, $files, $generation, $cost, $spent) {
            $answer->files()->attach($files->mapWithKeys(fn (UserFile $file, int $sort) => [$file->id => ['sort' => $sort]])->all());
            $answer->update([
                'status' => MessageStatus::Done,
                'cost' => is_numeric($cost) ? number_format((float) $cost, 8, '.', '') : null,
                'cost_currency' => 'RUB',
                'duration_ms' => (int) $answer->created_at->diffInMilliseconds(now()),
                'generation' => [...Arr::except($generation, 'result'), 'spent' => $spent],
            ]);
        });
    }

    /**
     * Пересчёт удержанного по факту: лишнее вернуть, недостающее досписать (сколько есть на балансе).
     */
    private function settle(User $user, int $hold, mixed $cost): int
    {
        if (! is_numeric($cost)) {
            return $hold;
        }

        $actual = (int) ceil((float) $cost);
        if ($actual < $hold) {
            $this->wallet->grant($user, $hold - $actual, TokenTransaction::REFUND, comment: 'Картинка дешевле оценки');
        } elseif ($actual > $hold) {
            $extra = min($actual - $hold, max(0, (int) $user->fresh()->content_tokens));
            if ($extra > 0) {
                $this->wallet->grant($user, -$extra, TokenTransaction::GENERATION, comment: 'Картинка дороже оценки');
            }
        }

        return $actual;
    }

    /**
     * Не удалось: токены юзеру — целиком; себестоимость, если провайдер её уже списал, — для учёта.
     */
    private function fail(Message $answer, string $error, mixed $cost = null): void
    {
        $generation = $answer->generation ?? [];

        DB::transaction(function () use ($answer, $error, $generation, $cost) {
            $answer->update([
                'status' => MessageStatus::Failed,
                'error' => $error,
                'cost' => is_numeric($cost) ? number_format((float) $cost, 8, '.', '') : null,
                'cost_currency' => 'RUB',
                'generation' => [...Arr::except($generation, 'result'), 'spent' => 0],
            ]);
            if (! empty($generation['hold'])) {
                $this->wallet->grant($answer->chat->user, (int) $generation['hold'], TokenTransaction::REFUND, comment: 'Картинка не получилась');
            }
        });
    }

    private function request()
    {
        $config = config('ai.providers.polza');

        return Http::withToken($config['key'])->baseUrl(rtrim($config['url'], '/'))->acceptJson()->timeout(60);
    }
}

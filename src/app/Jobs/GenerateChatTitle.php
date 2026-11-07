<?php

namespace App\Jobs;

use App\Models\Chat;
use App\Services\Ai\ServiceModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Название нового чата от сервисной модели — вместо обрезанного первого сообщения.
 * Ставится в очередь при создании чата: идёт параллельно ответу и обычно готово к его концу.
 */
class GenerateChatTitle implements ShouldQueue
{
    use Queueable;

    private const SYSTEM = 'Придумай короткое название чата (2–6 слов) по первому сообщению пользователя. '
        .'Ответь только названием: без кавычек, без точки в конце, на языке сообщения.';

    public function __construct(
        public string $chatId,
        public string $content,
    ) {}

    public function handle(ServiceModel $service): void
    {
        $chat = Chat::with('user')->find($this->chatId);
        // Чат удалили или уже переименовали — не трогаем
        if (! $chat || $chat->title !== self::fallback($this->content)) {
            return;
        }

        $title = $service->complete($chat->user, 'chat_title', self::SYSTEM, Str::limit($this->content, 2000), maxTokens: 40);
        $title = trim((string) $title, " \t\n\r\0\x0B\"'«»“”.");

        if ($title !== '') {
            $chat->update(['title' => Str::limit($title, 80)]);
        }
    }

    /**
     * Название до ответа сервисной модели: начало первого сообщения.
     */
    public static function fallback(string $content): string
    {
        return Str::limit($content, 60);
    }
}

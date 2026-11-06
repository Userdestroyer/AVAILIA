<?php

namespace App\Http\Resources;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/**
 * @mixin \App\Models\Message
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAnswer = $this->role === MessageRole::Assistant;
        $lost = $this->isLost();

        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'role' => $this->role,
            'status' => $lost ? MessageStatus::Failed : $this->status,
            'content' => $this->content ?? '',
            'error' => $lost ? 'Ответ не был получен' : $this->error,
            'model' => $isAnswer && $this->aiModel ? [
                'name' => $this->aiModel->name,
                'vendor' => $this->aiModel->vendor->slug,
            ] : null,
            'cost' => $isAnswer && $this->status === MessageStatus::Done ? $this->cost() : null,
            // Поиски модели — пузырьки над ответом
            'searches' => $isAnswer ? ($this->search_log ?? []) : [],
        ];
    }

    /**
     * Гейтвей так и не отчитался (упал или не достучался до Laravel) — генерация давно бы закончилась.
     */
    private function isLost(): bool
    {
        return in_array($this->status, [MessageStatus::Pending, MessageStatus::Streaming], true)
            && $this->created_at?->lt(now()->subMinutes(15));
    }

    /**
     * Себестоимость в валюте владельца чата; без курса — в исходной валюте.
     */
    private function cost(): array
    {
        $currency = $this->chat->user->currency;

        try {
            return ['amount' => $this->costIn($currency), 'currency' => $currency];
        } catch (RuntimeException) {
            return ['amount' => $this->cost, 'currency' => $this->cost_currency];
        }
    }
}

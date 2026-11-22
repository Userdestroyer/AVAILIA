<?php

namespace App\Http\Requests;

/**
 * Ещё один вариант ответа: параметры — как у нового вопроса (текущие из панели); домены нормализуются так же.
 */
class RegenerateMessageRequest extends StoreMessageRequest
{
    public function rules(): array
    {
        return self::optionRules();
    }
}

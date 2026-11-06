<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Services\Chat\ChatStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Вызовы от Go-гейтвея внутри docker-сети. Без сессии и CSRF — только по общему секрету.
 */
class GatewayController extends Controller
{
    public function complete(Request $request, Message $message, ChatStream $stream): JsonResponse
    {
        $secret = (string) config('ai.gateway.secret');
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->header('X-Gateway-Secret')), 403);

        $result = $request->validate([
            'status' => ['required', 'in:done,failed'],
            'format' => ['nullable', 'in:chat,responses,messages'],
            'content' => ['nullable', 'string'],
            'usage' => ['nullable', 'array'],
            'web_searches' => ['nullable', 'integer', 'min:0', 'max:100'],
            'searches' => ['nullable', 'array', 'max:50'],
            'searches.*.id' => ['required', 'string', 'max:200'],
            'searches.*.status' => ['required', 'in:searching,done,failed'],
            'searches.*.query' => ['nullable', 'string', 'max:1000'],
            'error' => ['nullable', 'string'],
        ]);

        return response()->json([
            'message' => MessageResource::make($stream->complete($message, $result)),
        ]);
    }
}

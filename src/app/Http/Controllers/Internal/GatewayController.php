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
            'content' => ['nullable', 'string'],
            'usage' => ['nullable', 'array'],
            'error' => ['nullable', 'string'],
        ]);

        return response()->json([
            'message' => MessageResource::make($stream->complete($message, $result)),
        ]);
    }
}

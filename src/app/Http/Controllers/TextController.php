<?php

namespace App\Http\Controllers;

use App\Enums\AiModelType;
use App\Http\Resources\MessageResource;
use App\Models\Chat;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TextController extends Controller
{
    public function index(): Response
    {
        return $this->render();
    }

    public function show(Request $request, string $chat): Response
    {
        // Чужой чат — 404, чтобы не подтверждать его существование
        $chat = $request->user()->chats()->findOrFail($chat);

        return $this->render($chat);
    }

    private function render(?Chat $chat = null): Response
    {
        $messages = $chat?->messages()->with(['aiModel.vendor', 'chat.user'])->orderBy('id')->get();

        return Inertia::render('Text/Index', [
            'vendors' => $this->vendors(),
            'chat' => $chat?->only('id', 'title', 'ai_model_id'),
            'messages' => $messages ? MessageResource::collection($messages)->resolve() : [],
        ]);
    }

    private function vendors()
    {
        return Vendor::where('is_active', true)
            ->orderBy('sort')
            ->with(['models' => fn ($query) => $query
                ->where('type', AiModelType::Text)
                ->where('is_active', true)
                ->orderBy('sort'),
            ])
            ->get()
            ->filter(fn (Vendor $vendor) => $vendor->models->isNotEmpty())
            ->map(fn (Vendor $vendor) => [
                'slug' => $vendor->slug,
                'product' => $vendor->product ?? $vendor->name,
                'models' => $vendor->models->map->only(
                    'id', 'name', 'description', 'price_input', 'price_output', 'price_currency',
                ),
            ])
            ->values();
    }
}

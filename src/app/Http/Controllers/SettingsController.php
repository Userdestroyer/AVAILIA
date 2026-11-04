<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    /**
     * Фронт уже применил настройку сам — здесь только сохраняем, отвечаем пустым телом.
     */
    public function update(Request $request): Response
    {
        $validated = $request->validate([
            'theme' => ['sometimes', Rule::in(['light', 'dark'])],
        ]);

        $user = $request->user();
        $user->update(['settings' => [...($user->settings ?? []), ...$validated]]);

        return response()->noContent();
    }
}

<!DOCTYPE html>
@php
    // Тема известна до первого кадра — без вспышки: выбранная в аккаунте, иначе в этом браузере (кука)
    $theme = auth()->user()?->interfaceSettings()['theme'] ?? request()->cookie('theme');
    $theme = in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}">
    <head>
        <meta charset="utf-8">
        {{-- resizes-content: клавиатура на Android сжимает экран, а не сдвигает страницу — поле ввода остаётся над ней --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, interactive-widget=resizes-content">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="min-h-dvh antialiased">
        @inertia
    </body>
</html>

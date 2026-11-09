<!DOCTYPE html>
@php
    // Оформление известно до первого кадра — без вспышки (см. App\Support\Appearance)
    $look = App\Support\Appearance::resolve(auth()->user(), request());
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-style="{{ $look['style'] }}" data-theme="{{ $look['theme'] }}" data-font="{{ $look['font'] }}">
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

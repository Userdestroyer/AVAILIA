<!DOCTYPE html>
@php
    // Тема известна до первого кадра — без вспышки светлой темы при загрузке
    $theme = auth()->user()?->interfaceSettings()['theme'] ?? request()->cookie('theme');
    $theme = in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="min-h-screen antialiased">
        @inertia
    </body>
</html>

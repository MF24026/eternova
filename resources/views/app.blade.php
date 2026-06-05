<!DOCTYPE html>
<html lang="es" class="">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Eternova') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700|noto-serif:400,500,600&display=swap" rel="stylesheet">
    @vite('resources/js/main.ts')
</head>
<body class="antialiased">
    <div id="app"></div>
</body>
</html>

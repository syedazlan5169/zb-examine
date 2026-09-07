<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <title>{{ __('app.name') }}</title>
</head>

<body class="min-h-screen bg-gray-100 p-8">
    <div class="mx-auto max-w-xl rounded-xl bg-white p-8 shadow">
        <h1 class="text-3xl font-bold">
            {{ __('app.welcome') }}
        </h1>

        <p class="mt-4">
            {{ __('app.current_language') }}:
            <strong>{{ app()->getLocale() }}</strong>
        </p>

        <div class="mt-6 flex gap-3">
            <a
                href="{{ route('language.switch', 'ms') }}"
                class="rounded bg-gray-900 px-4 py-2 text-white"
            >
                {{ __('app.language.malay') }}
            </a>

            <a
                href="{{ route('language.switch', 'en') }}"
                class="rounded border px-4 py-2"
            >
                {{ __('app.language.english') }}
            </a>
        </div>
    </div>
</body>
</html>

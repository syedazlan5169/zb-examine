<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111827">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <title>@yield('title', __('app.name'))</title>
</head>

<body class="min-h-screen bg-gray-100 text-gray-900">
    <header class="border-b border-gray-200 bg-white">
        <div class="mx-auto flex max-w-xl items-center justify-between px-4 py-4">
            <a href="{{ route('examinations.create') }}" class="text-lg font-bold leading-tight">
                {{ __('app.name') }}
            </a>

            <nav aria-label="{{ __('app.current_language') }}" class="flex items-center gap-2 text-sm font-medium">
                @auth
                    <form method="POST" action="{{ route('auth.logout') }}">
                        @csrf
                        <button type="submit" class="rounded-md px-3 py-2 text-gray-600 hover:bg-gray-100">
                            {{ __('auth.logout') }}
                        </button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="rounded-md px-3 py-2 text-gray-600 hover:bg-gray-100">
                        {{ __('auth.login') }}
                    </a>
                @endauth

                <a
                    href="{{ route('language.switch', 'ms') }}"
                    class="rounded-md px-3 py-2 {{ app()->getLocale() === 'ms' ? 'bg-gray-900 text-white' : 'text-gray-600' }}"
                >
                    {{ __('app.language.malay') }}
                </a>

                <a
                    href="{{ route('language.switch', 'en') }}"
                    class="rounded-md px-3 py-2 {{ app()->getLocale() === 'en' ? 'bg-gray-900 text-white' : 'text-gray-600' }}"
                >
                    {{ __('app.language.english') }}
                </a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-xl px-4 py-6 sm:px-6">
        @yield('content')
    </main>
</body>
</html>

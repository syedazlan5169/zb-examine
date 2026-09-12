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
        <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-3 px-3 py-2 sm:px-6 sm:py-3">
            <a href="{{ route('home') }}" class="min-w-0 text-base font-bold leading-tight sm:text-lg">
                <span class="block truncate">{{ __('app.name') }}</span>
            </a>

            <nav aria-label="{{ __('navigation.primary') }}" class="hidden items-center gap-2 lg:flex">
                @include('layouts.partials.navigation-links', [
                    'linkClass' => 'rounded-md px-2.5 py-2 text-sm font-medium',
                ])
            </nav>

            <button
                type="button"
                id="mobile-navigation-trigger"
                aria-controls="mobile-navigation-drawer"
                aria-expanded="false"
                aria-label="{{ __('navigation.open_menu') }}"
                data-open-label="{{ __('navigation.open_menu') }}"
                data-close-label="{{ __('navigation.close_menu') }}"
                data-mobile-navigation-trigger
                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md border border-gray-300 text-gray-900 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 lg:hidden"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="h-6 w-6 fill-none stroke-current stroke-2">
                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
        </div>
    </header>

    <div id="mobile-navigation-drawer" data-mobile-navigation hidden class="fixed inset-0 z-40 lg:hidden">
        <div data-mobile-navigation-backdrop class="absolute inset-0 bg-gray-900/40"></div>
        <aside
            role="dialog"
            aria-modal="true"
            aria-labelledby="mobile-navigation-title"
            class="absolute inset-y-0 right-0 flex w-[min(22rem,calc(100%-2rem))] flex-col overflow-y-auto border-l border-gray-200 bg-white shadow-xl"
        >
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <h2 id="mobile-navigation-title" class="min-w-0 truncate text-base font-bold">{{ __('app.name') }}</h2>
                <button
                    type="button"
                    aria-label="{{ __('navigation.close_menu') }}"
                    data-mobile-navigation-close
                    class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md border border-gray-300 text-gray-900 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="h-6 w-6 fill-none stroke-current stroke-2">
                        <path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" />
                    </svg>
                </button>
            </div>

            <nav aria-label="{{ __('navigation.primary') }}" class="flex flex-1 flex-col gap-1 p-4 text-sm font-medium">
                @include('layouts.partials.navigation-links', [
                    'linkClass' => 'rounded-md px-3 py-3',
                ])
            </nav>
        </aside>
    </div>

    <main class="mx-auto @yield('content_width', 'max-w-xl') px-4 py-6 sm:px-6">
        @yield('content')
    </main>
</body>
</html>

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
        <div class="mx-auto w-full max-w-6xl px-3 py-2 sm:px-6 sm:py-3">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <a href="{{ route('home') }}" class="text-base font-bold leading-tight sm:text-lg">
                    {{ __('app.name') }}
                </a>

                <nav aria-label="{{ __('app.current_language') }}" class="flex flex-col gap-2 md:flex-row md:items-center md:justify-end">
                    <div class="flex items-center justify-end gap-1 self-end md:self-auto">
                        <a
                            href="{{ route('language.switch', 'ms') }}"
                            class="rounded-md px-2 py-1.5 text-xs font-medium {{ app()->getLocale() === 'ms' ? 'bg-gray-900 text-white' : 'text-gray-600' }} focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                        >
                            MY
                        </a>

                        <a
                            href="{{ route('language.switch', 'en') }}"
                            class="rounded-md px-2 py-1.5 text-xs font-medium {{ app()->getLocale() === 'en' ? 'bg-gray-900 text-white' : 'text-gray-600' }} focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                        >
                            EN
                        </a>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 text-sm font-medium">
                        @auth
                            <a href="{{ route('examinations.create') }}" class="rounded-md px-2.5 py-2 text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                {{ __('home.new_submission') }}
                            </a>
                            <a href="{{ route('profile.edit') }}" class="rounded-md px-2.5 py-2 text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                {{ __('profile.title') }}
                            </a>

                            @if (auth()->user()->role === \App\Enums\UserRole::Agent)
                                <a href="{{ route('agent.examinations.index') }}" class="rounded-md px-2.5 py-2 text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                    {{ __('examination.agent.navigation') }}
                                </a>

                            @endif

                            @can('viewAny', \App\Models\User::class)
                                <a href="{{ route('admin.users.index') }}" class="rounded-md px-2.5 py-2 text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                    {{ __('users.navigation') }}
                                </a>
                            @endcan

                            @can('viewAny', \App\Models\Examination::class)
                                <a href="{{ route('examinations.index') }}" class="rounded-md px-2.5 py-2 text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                    {{ __('examination.navigation') }}
                                </a>
                                <a href="{{ route('reports.index') }}" class="rounded-md px-2.5 py-2 text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                    {{ __('reports.navigation') }}
                                </a>
                            @endcan

                            <form method="POST" action="{{ route('auth.logout') }}">
                                @csrf
                                <button type="submit" class="rounded-md px-2.5 py-2 text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                    {{ __('auth.logout') }}
                                </button>
                            </form>
                        @else
                            <a href="{{ route('examinations.create') }}" class="rounded-md px-2.5 py-2 text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                {{ __('home.new_submission') }}
                            </a>
                            <a href="{{ route('login') }}" class="rounded-md px-2.5 py-2 text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                {{ __('auth.login') }}
                            </a>
                            <a href="{{ route('register') }}" class="rounded-md px-2.5 py-2 text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                {{ __('auth.register') }}
                            </a>
                        @endauth
                    </div>
                </nav>
            </div>
        </div>
    </header>

    <main class="mx-auto @yield('content_width', 'max-w-xl') px-4 py-6 sm:px-6">
        @yield('content')
    </main>
</body>
</html>

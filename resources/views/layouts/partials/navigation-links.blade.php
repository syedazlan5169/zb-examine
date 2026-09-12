@php
    $linkClass ??= 'rounded-md px-2.5 py-2 text-sm font-medium';
    $activeClass = 'bg-gray-900 text-white';
    $inactiveClass = 'text-gray-600 hover:bg-gray-100';
    $focusClass = 'focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2';
@endphp

@auth
    <a
        href="{{ route('examinations.create') }}"
        aria-current="{{ request()->routeIs('examinations.create') ? 'page' : 'false' }}"
        class="{{ $linkClass }} {{ request()->routeIs('examinations.create') ? $activeClass : $inactiveClass }} {{ $focusClass }}"
    >
        {{ __('home.new_submission') }}
    </a>

    <a
        href="{{ route('profile.edit') }}"
        aria-current="{{ request()->routeIs('profile.*') ? 'page' : 'false' }}"
        class="{{ $linkClass }} {{ request()->routeIs('profile.*') ? $activeClass : $inactiveClass }} {{ $focusClass }}"
    >
        {{ __('profile.title') }}
    </a>

    @if (auth()->user()->role === \App\Enums\UserRole::Agent)
        <a
            href="{{ route('agent.examinations.index') }}"
            aria-current="{{ request()->routeIs('agent.examinations.*') ? 'page' : 'false' }}"
            class="{{ $linkClass }} {{ request()->routeIs('agent.examinations.*') ? $activeClass : $inactiveClass }} {{ $focusClass }}"
        >
            {{ __('examination.agent.navigation') }}
        </a>
    @endif

    @can('viewAny', \App\Models\User::class)
        <a
            href="{{ route('admin.users.index') }}"
            aria-current="{{ request()->routeIs('admin.users.*') ? 'page' : 'false' }}"
            class="{{ $linkClass }} {{ request()->routeIs('admin.users.*') ? $activeClass : $inactiveClass }} {{ $focusClass }}"
        >
            {{ __('users.navigation') }}
        </a>
    @endcan

    @can('viewAny', \App\Models\Examination::class)
        <a
            href="{{ route('examinations.index') }}"
            aria-current="{{ request()->routeIs('examinations.index', 'examinations.show', 'examinations.photos.*') ? 'page' : 'false' }}"
            class="{{ $linkClass }} {{ request()->routeIs('examinations.index', 'examinations.show', 'examinations.photos.*') ? $activeClass : $inactiveClass }} {{ $focusClass }}"
        >
            {{ __('examination.navigation') }}
        </a>
        <a
            href="{{ route('reports.index') }}"
            aria-current="{{ request()->routeIs('reports.*') ? 'page' : 'false' }}"
            class="{{ $linkClass }} {{ request()->routeIs('reports.*') ? $activeClass : $inactiveClass }} {{ $focusClass }}"
        >
            {{ __('reports.navigation') }}
        </a>
    @endcan

    <form method="POST" action="{{ route('auth.logout') }}" class="{{ $linkClass }}">
        @csrf
        <button type="submit" class="w-full text-left text-gray-600 hover:text-gray-900 {{ $focusClass }}">
            {{ __('auth.logout') }}
        </button>
    </form>
@else
    <a
        href="{{ route('examinations.create') }}"
        aria-current="{{ request()->routeIs('examinations.create') ? 'page' : 'false' }}"
        class="{{ $linkClass }} {{ request()->routeIs('examinations.create') ? $activeClass : $inactiveClass }} {{ $focusClass }}"
    >
        {{ __('home.new_submission') }}
    </a>
    <a href="{{ route('login') }}" class="{{ $linkClass }} {{ $inactiveClass }} {{ $focusClass }}">
        {{ __('auth.login') }}
    </a>
    <a href="{{ route('register') }}" class="{{ $linkClass }} {{ $inactiveClass }} {{ $focusClass }}">
        {{ __('auth.register') }}
    </a>
@endauth

<div class="flex items-center gap-1 border-t border-gray-200 pt-2 lg:border-t-0 lg:pt-0" aria-label="{{ __('app.current_language') }}">
    <a
        href="{{ route('language.switch', 'ms') }}"
        aria-current="{{ app()->getLocale() === 'ms' ? 'page' : 'false' }}"
        class="rounded-md px-2 py-1.5 text-xs font-medium {{ app()->getLocale() === 'ms' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100' }} {{ $focusClass }}"
    >
        MY
    </a>
    <a
        href="{{ route('language.switch', 'en') }}"
        aria-current="{{ app()->getLocale() === 'en' ? 'page' : 'false' }}"
        class="rounded-md px-2 py-1.5 text-xs font-medium {{ app()->getLocale() === 'en' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100' }} {{ $focusClass }}"
    >
        EN
    </a>
</div>
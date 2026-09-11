@extends('layouts.app')

@section('title', __('auth.login').' — '.__('app.name'))

@section('content')
    <div class="mx-auto max-w-md">
        <h1 class="text-2xl font-bold">{{ __('auth.login') }}</h1>
        <p class="mt-1 text-sm text-gray-600">{{ __('app.name') }}</p>

        <form method="POST" action="{{ route('auth.login.store') }}" class="mt-6 space-y-5">
            @csrf

            <div>
                <label for="username" class="mb-1 block text-sm font-medium text-gray-900">
                    {{ __('auth.username') }}
                </label>
                <input
                    type="text"
                    name="username"
                    id="username"
                    value="{{ old('username') }}"
                    autocomplete="username"
                    required
                    autofocus
                    @error('username') aria-invalid="true" aria-describedby="username-error" @enderror
                    class="w-full rounded-lg border-2 px-4 py-3 text-base focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900 {{ $errors->has('username') ? 'border-red-600' : 'border-gray-300' }}"
                >
                @error('username')
                    <p id="username-error" class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-gray-900">
                    {{ __('auth.password') }}
                </label>
                <input
                    type="password"
                    name="password"
                    id="password"
                    autocomplete="current-password"
                    required
                    @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                    class="w-full rounded-lg border-2 px-4 py-3 text-base focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900 {{ $errors->has('password') ? 'border-red-600' : 'border-gray-300' }}"
                >
                @error('password')
                    <p id="password-error" class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full rounded-lg bg-gray-900 px-4 py-3 text-base font-semibold text-white hover:bg-gray-700">
                {{ __('auth.sign_in') }}
            </button>
        </form>

        <p class="mt-5 text-center text-sm text-gray-600">
            {{ __('auth.no_account') }}
            <a href="{{ route('register') }}" class="font-semibold text-gray-900 underline">{{ __('auth.register') }}</a>
        </p>
    </div>
@endsection
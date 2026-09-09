@extends('layouts.app')

@section('title', __('auth.login').' — '.__('app.name'))

@section('content')
    <div class="mx-auto max-w-md">
        <h1 class="text-2xl font-bold">{{ __('auth.login') }}</h1>
        <p class="mt-1 text-sm text-gray-600">{{ __('app.name') }}</p>

        <form method="POST" action="{{ route('auth.login.store') }}" class="mt-6 space-y-5">
            @csrf

            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-gray-900">
                    {{ __('auth.email') }}
                </label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    value="{{ old('email') }}"
                    autocomplete="email"
                    required
                    autofocus
                    @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                    class="w-full rounded-lg border-2 px-4 py-3 text-base focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900 {{ $errors->has('email') ? 'border-red-600' : 'border-gray-300' }}"
                >
                @error('email')
                    <p id="email-error" class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p>
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
    </div>
@endsection
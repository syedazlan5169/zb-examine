@extends('layouts.app')

@section('title', __('auth.registration').' — '.__('app.name'))

@section('content')
    <x-ui.card class="mx-auto max-w-md shadow-sm">
        <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('app.name') }}</p>
        <h1 class="mt-2 text-2xl font-bold text-gray-950">{{ __('auth.registration') }}</h1>

        <form method="POST" action="{{ route('auth.register.store') }}" class="mt-6 space-y-5">
            @csrf
            <x-text-input name="name" :label="__('users.name')" autocomplete="name" />
            <x-text-input name="username" :label="__('auth.username')" autocomplete="username" />
            <x-text-input name="email" :label="__('users.email')" type="email" autocomplete="email" :required="false" />
            <x-text-input name="password" :label="__('auth.password')" type="password" autocomplete="new-password" />
            <x-text-input name="password_confirmation" :label="__('profile.confirm_password')" type="password" autocomplete="new-password" />

            <x-ui.button type="submit" class="w-full">
                {{ __('auth.register') }}
            </x-ui.button>
        </form>
        <p class="mt-5 text-center text-sm text-gray-600">
            {{ __('auth.have_account') }}
            <a href="{{ route('login') }}" class="font-semibold text-gray-900 underline focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">{{ __('auth.login') }}</a>
        </p>
    </x-ui.card>
@endsection
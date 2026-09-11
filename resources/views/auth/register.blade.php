@extends('layouts.app')

@section('title', __('auth.registration').' — '.__('app.name'))

@section('content')
    <div class="mx-auto max-w-md">
        <h1 class="text-2xl font-bold">{{ __('auth.registration') }}</h1>

        <form method="POST" action="{{ route('auth.register.store') }}" class="mt-6 space-y-5">
            @csrf
            <x-text-input name="name" :label="__('users.name')" autocomplete="name" />
            <x-text-input name="username" :label="__('auth.username')" autocomplete="username" />
            <x-text-input name="email" :label="__('users.email')" type="email" autocomplete="email" :required="false" />
            <x-text-input name="password" :label="__('auth.password')" type="password" autocomplete="new-password" />
            <x-text-input name="password_confirmation" :label="__('profile.confirm_password')" type="password" autocomplete="new-password" />

            <button type="submit" class="w-full rounded-lg bg-gray-900 px-4 py-3 text-base font-semibold text-white hover:bg-gray-700">
                {{ __('auth.register') }}
            </button>
        </form>
    </div>
@endsection
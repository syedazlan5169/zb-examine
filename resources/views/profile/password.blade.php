@extends('layouts.app')

@section('title', __('profile.password_change').' — '.__('app.name'))

@section('content')
    <x-ui.card class="mx-auto max-w-md">
        <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('app.name') }}</p>
        <h1 class="mt-2 text-2xl font-bold text-gray-950">{{ __('profile.password_change') }}</h1>

        @if (session('status'))
            <x-ui.alert type="success" class="my-6">{{ session('status') }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('profile.password.update') }}" class="mt-6 space-y-5">
            @csrf
            @method('PATCH')
            <x-text-input name="current_password" :label="__('profile.current_password')" type="password" autocomplete="current-password" />
            <x-text-input name="password" :label="__('profile.new_password')" type="password" autocomplete="new-password" />
            <x-text-input name="password_confirmation" :label="__('profile.confirm_password')" type="password" autocomplete="new-password" />
            <button type="submit" class="w-full rounded-lg bg-gray-900 px-4 py-3 text-base font-semibold text-white hover:bg-gray-700">{{ __('profile.save') }}</button>
        </form>
    </x-ui.card>
@endsection
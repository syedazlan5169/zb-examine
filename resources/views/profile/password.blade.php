@extends('layouts.app')

@section('title', __('profile.password_change').' — '.__('app.name'))

@section('content')
    <div class="mx-auto max-w-md">
        <h1 class="text-2xl font-bold">{{ __('profile.password_change') }}</h1>

        @if (session('status'))
            <div class="my-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('profile.password.update') }}" class="mt-6 space-y-5">
            @csrf
            @method('PATCH')
            <x-text-input name="current_password" :label="__('profile.current_password')" type="password" autocomplete="current-password" />
            <x-text-input name="password" :label="__('profile.new_password')" type="password" autocomplete="new-password" />
            <x-text-input name="password_confirmation" :label="__('profile.confirm_password')" type="password" autocomplete="new-password" />
            <button type="submit" class="w-full rounded-lg bg-gray-900 px-4 py-3 text-base font-semibold text-white hover:bg-gray-700">{{ __('profile.save') }}</button>
        </form>
    </div>
@endsection
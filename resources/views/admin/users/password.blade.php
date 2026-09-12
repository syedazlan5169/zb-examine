@extends('layouts.app')

@section('title', __('users.reset_password').' — '.__('app.name'))

@section('content')
    <div class="mb-6">
        <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('app.name') }}</p>
        <h1 class="mt-2 text-2xl font-bold text-gray-950">{{ __('users.reset_password') }}</h1>
    </div>
    <p class="mt-1 text-sm text-gray-600">{{ $user->name }} ({{ $user->username }})</p>
    <x-ui.card class="max-w-2xl">
    <form method="POST" action="{{ route('admin.users.password.update', $user) }}" class="space-y-5">
        @csrf
        @method('PATCH')
        <x-text-input name="password" :label="__('profile.new_password')" type="password" autocomplete="new-password" />
        <x-text-input name="password_confirmation" :label="__('profile.confirm_password')" type="password" autocomplete="new-password" />
        <x-ui.button type="submit">{{ __('users.reset_password') }}</x-ui.button>
    </form>
    </x-ui.card>
@endsection
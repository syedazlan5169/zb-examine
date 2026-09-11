@extends('layouts.app')

@section('title', __('users.reset_password').' — '.__('app.name'))

@section('content')
    <h1 class="text-2xl font-bold">{{ __('users.reset_password') }}</h1>
    <p class="mt-1 text-sm text-gray-600">{{ $user->name }} ({{ $user->username }})</p>
    <form method="POST" action="{{ route('admin.users.password.update', $user) }}" class="mt-6 space-y-5">
        @csrf
        @method('PATCH')
        <x-text-input name="password" :label="__('profile.new_password')" type="password" autocomplete="new-password" />
        <x-text-input name="password_confirmation" :label="__('profile.confirm_password')" type="password" autocomplete="new-password" />
        <button type="submit" class="rounded-lg bg-gray-900 px-4 py-3 text-sm font-semibold text-white">{{ __('users.reset_password') }}</button>
    </form>
@endsection
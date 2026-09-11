@extends('layouts.app')

@section('title', __('users.create').' — '.__('app.name'))

@section('content')
    <h1 class="text-2xl font-bold">{{ __('users.create') }}</h1>
    <form method="POST" action="{{ route('admin.users.store') }}" class="mt-6 space-y-5">
        @csrf
        <x-text-input name="name" :label="__('users.name')" autocomplete="name" />
        <x-text-input name="username" :label="__('auth.username')" autocomplete="username" />
        <x-text-input name="email" :label="__('users.email')" type="email" autocomplete="email" :required="false" />
        @include('admin.users._role-status-fields')
        <x-text-input name="password" :label="__('auth.password')" type="password" autocomplete="new-password" />
        <x-text-input name="password_confirmation" :label="__('profile.confirm_password')" type="password" autocomplete="new-password" />
        <button type="submit" class="rounded-lg bg-gray-900 px-4 py-3 text-sm font-semibold text-white">{{ __('users.save') }}</button>
    </form>
@endsection
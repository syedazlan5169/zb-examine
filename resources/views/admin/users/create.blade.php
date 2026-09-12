@extends('layouts.app')

@section('title', __('users.create').' — '.__('app.name'))

@section('content')
    <div class="mb-6">
        <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('app.name') }}</p>
        <h1 class="mt-2 text-2xl font-bold text-gray-950">{{ __('users.create') }}</h1>
    </div>
    <x-ui.card class="max-w-2xl">
    <form method="POST" action="{{ route('admin.users.store') }}" class="mt-6 space-y-5">
        @csrf
        <x-text-input name="name" :label="__('users.name')" autocomplete="name" />
        <x-text-input name="username" :label="__('auth.username')" autocomplete="username" />
        <x-text-input name="email" :label="__('users.email')" type="email" autocomplete="email" :required="false" />
        @include('admin.users._role-status-fields')
        <x-text-input name="password" :label="__('auth.password')" type="password" autocomplete="new-password" />
        <x-text-input name="password_confirmation" :label="__('profile.confirm_password')" type="password" autocomplete="new-password" />
        <x-ui.button type="submit">{{ __('users.save') }}</x-ui.button>
    </form>
    </x-ui.card>
@endsection
@extends('layouts.app')

@section('title', __('users.edit').' — '.__('app.name'))

@section('content')
    <div class="mb-6">
        <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('app.name') }}</p>
        <h1 class="mt-2 text-2xl font-bold text-gray-950">{{ __('users.edit') }}</h1>
    </div>
    @if (session('status'))
        <x-ui.alert type="success" class="my-5">{{ session('status') }}</x-ui.alert>
    @endif
    @error('account')
        <x-ui.alert type="error" class="my-5">{{ $message }}</x-ui.alert>
    @enderror
    <x-ui.card class="max-w-2xl">
    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-5">
        @csrf
        @method('PATCH')
        <x-text-input name="name" :label="__('users.name')" :value="$user->name" autocomplete="name" />
        <x-text-input name="username" :label="__('auth.username')" :value="$user->username" autocomplete="username" />
        <x-text-input name="email" :label="__('users.email')" type="email" :value="$user->email" autocomplete="email" :required="false" />
        @include('admin.users._role-status-fields', ['user' => $user])
        <x-ui.button type="submit">{{ __('users.save') }}</x-ui.button>
    </form>
    </x-ui.card>
    @if (auth()->id() !== $user->id)
        <p class="mt-5 text-sm"><a href="{{ route('admin.users.password.edit', $user) }}" class="font-semibold text-gray-900 underline">{{ __('users.reset_password') }}</a></p>
    @endif
@endsection
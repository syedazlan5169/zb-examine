@extends('layouts.app')

@section('title', __('users.edit').' — '.__('app.name'))

@section('content')
    <h1 class="text-2xl font-bold">{{ __('users.edit') }}</h1>
    @if (session('status'))
        <div class="my-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
    @endif
    @error('account')
        <div class="my-5 rounded-lg border-2 border-red-600 bg-red-50 px-4 py-3 text-red-800">{{ $message }}</div>
    @enderror
    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="mt-6 space-y-5">
        @csrf
        @method('PATCH')
        <x-text-input name="name" :label="__('users.name')" :value="$user->name" autocomplete="name" />
        <x-text-input name="username" :label="__('auth.username')" :value="$user->username" autocomplete="username" />
        <x-text-input name="email" :label="__('users.email')" type="email" :value="$user->email" autocomplete="email" :required="false" />
        @include('admin.users._role-status-fields', ['user' => $user])
        <button type="submit" class="rounded-lg bg-gray-900 px-4 py-3 text-sm font-semibold text-white">{{ __('users.save') }}</button>
    </form>
    @if (auth()->id() !== $user->id)
        <p class="mt-5 text-sm"><a href="{{ route('admin.users.password.edit', $user) }}" class="font-semibold text-gray-900 underline">{{ __('users.reset_password') }}</a></p>
    @endif
@endsection
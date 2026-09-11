@extends('layouts.app')

@section('title', __('users.title').' — '.__('app.name'))
@section('content_width', 'max-w-6xl')

@section('content')
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 class="text-2xl font-bold">{{ __('users.title') }}</h1>
        <a href="{{ route('admin.users.create') }}" class="rounded-lg bg-gray-900 px-4 py-3 text-center text-sm font-semibold text-white">{{ __('users.create') }}</a>
    </div>

    @if (session('status'))
        <div class="my-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
    @endif

    <form method="GET" class="my-6 grid gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-4">
        <x-text-input name="search" :label="__('users.search')" :value="$search" :required="false" />
        <div>
            <label for="role" class="mb-1 block text-sm font-medium text-gray-900">{{ __('users.role') }}</label>
            <select name="role" id="role" class="w-full rounded-lg border-2 border-gray-300 px-4 py-3 text-base">
                <option value="">-</option>
                @foreach (\App\Enums\UserRole::cases() as $role)
                    <option value="{{ $role->value }}" @selected(request('role') === $role->value)>{{ __('users.roles.'.$role->value) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="is_active" class="mb-1 block text-sm font-medium text-gray-900">{{ __('users.active') }}</label>
            <select name="is_active" id="is_active" class="w-full rounded-lg border-2 border-gray-300 px-4 py-3 text-base">
                <option value="">-</option>
                <option value="1" @selected(request('is_active') === '1')>{{ __('users.active') }}</option>
                <option value="0" @selected(request('is_active') === '0')>{{ __('users.inactive') }}</option>
            </select>
        </div>
        <button type="submit" class="self-end rounded-lg bg-gray-900 px-4 py-3 text-sm font-semibold text-white">{{ __('users.filter') }}</button>
    </form>

    <div class="grid gap-3">
        @forelse ($users as $user)
            <a href="{{ route('admin.users.edit', $user) }}" class="block rounded-lg border border-gray-200 bg-white p-4 hover:border-gray-400">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="font-semibold">{{ $user->name }}</h2>
                        <p class="text-sm text-gray-600">{{ $user->username }}{{ $user->email ? ' · '.$user->email : '' }}</p>
                    </div>
                    <div class="text-sm text-gray-600">{{ __('users.roles.'.$user->role->value) }} · {{ $user->is_active ? __('users.active') : __('users.inactive') }}</div>
                </div>
            </a>
        @empty
            <p class="rounded-lg border border-gray-200 bg-white p-5 text-gray-600">{{ __('examination.agent.empty_state') }}</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $users->links() }}</div>
@endsection
@extends('layouts.app')

@section('title', __('users.title').' — '.__('app.name'))
@section('content_width', 'max-w-6xl')

@section('content')
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('app.name') }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-950">{{ __('users.title') }}</h1>
        </div>
        <x-ui.button href="{{ route('admin.users.create') }}">{{ __('users.create') }}</x-ui.button>
    </div>

    @if (session('status'))
        <x-ui.alert type="success" class="my-5">{{ session('status') }}</x-ui.alert>
    @endif

    <x-ui.card class="my-6" >
    <form method="GET" class="grid gap-3 sm:grid-cols-4">
        <x-text-input name="search" :label="__('users.search')" :value="$search" :required="false" />
        <div>
            <label for="role" class="mb-1 block text-sm font-medium text-gray-900">{{ __('users.role') }}</label>
            <select name="role" id="role" class="min-h-11 w-full rounded-md border-2 border-gray-300 px-4 py-3 text-base">
                <option value="">-</option>
                @foreach (\App\Enums\UserRole::cases() as $role)
                    <option value="{{ $role->value }}" @selected(request('role') === $role->value)>{{ __('users.roles.'.$role->value) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="is_active" class="mb-1 block text-sm font-medium text-gray-900">{{ __('users.active') }}</label>
            <select name="is_active" id="is_active" class="min-h-11 w-full rounded-md border-2 border-gray-300 px-4 py-3 text-base">
                <option value="">-</option>
                <option value="1" @selected(request('is_active') === '1')>{{ __('users.active') }}</option>
                <option value="0" @selected(request('is_active') === '0')>{{ __('users.inactive') }}</option>
            </select>
        </div>
        <x-ui.button type="submit" class="self-end">{{ __('users.filter') }}</x-ui.button>
    </form>
    </x-ui.card>

    <div class="grid gap-3">
        @forelse ($users as $user)
            <a href="{{ route('admin.users.edit', $user) }}" class="block rounded-md border border-gray-200 bg-white p-4 hover:border-gray-400 focus:outline focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="break-words font-semibold">{{ $user->name }}</h2>
                        <p class="break-words text-sm text-gray-600">{{ $user->username }}{{ $user->email ? ' · '.$user->email : '' }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-sm">
                        <x-ui.badge>{{ __('users.roles.'.$user->role->value) }}</x-ui.badge>
                        <x-ui.badge :variant="$user->is_active ? 'success' : 'danger'">{{ $user->is_active ? __('users.active') : __('users.inactive') }}</x-ui.badge>
                    </div>
                </div>
            </a>
        @empty
            <x-ui.empty-state :title="__('examination.agent.empty_state')" />
        @endforelse
    </div>

    <div class="mt-6">{{ $users->links() }}</div>
@endsection
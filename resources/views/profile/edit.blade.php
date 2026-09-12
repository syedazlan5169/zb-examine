@extends('layouts.app')

@section('title', __('profile.title').' — '.__('app.name'))

@section('content')
    <div class="mb-6">
        <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('app.name') }}</p>
        <h1 class="mt-2 text-2xl font-bold text-gray-950">{{ $user->role === \App\Enums\UserRole::Agent ? __('profile.agent_profile') : __('profile.account') }}</h1>
        <p class="mt-1 text-sm text-gray-600">{{ __('profile.subtitle') }}</p>
    </div>

    @if (session('status'))
        <x-ui.alert type="success" class="mb-6">{{ session('status') }}</x-ui.alert>
    @endif

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-6">{{ __('profile.validation_summary') }}</x-ui.alert>
    @endif

    <x-ui.card>
    <form method="POST" action="{{ route('profile.update') }}" class="space-y-5">
        @csrf
        @method('PATCH')

        <x-text-input name="name" :label="$user->role === \App\Enums\UserRole::Agent ? __('examination.fields.agent_name') : __('users.name')" :value="$user->name" autocomplete="name" />
        <x-text-input name="email" :label="__('profile.email')" type="email" :value="$user->email" autocomplete="email" :required="false" />

        @if ($user->role === \App\Enums\UserRole::Agent)
            <x-text-input name="phone" :label="__('examination.fields.agent_phone')" type="tel" :value="$user->phone" autocomplete="tel" inputmode="tel" :required="false" />
            <x-text-input name="agent_code" :label="__('examination.fields.agent_code')" :value="$user->agent_code" autocomplete="off" :required="false" />
            <x-text-input name="company_name" :label="__('examination.fields.agent_company_name')" :value="$user->company_name" autocomplete="organization" :required="false" />
            <x-text-input name="station_code" :label="__('examination.fields.agent_station_code')" :value="$user->station_code" autocomplete="off" :required="false" />
        @endif

        <div class="pt-2">
            <x-ui.button type="submit">
                {{ __('profile.save') }}
            </x-ui.button>
        </div>
    </form>
    </x-ui.card>

    <p class="mt-5 text-sm">
        <a href="{{ route('profile.password.edit') }}" class="font-semibold text-gray-900 underline">{{ __('profile.password_change') }}</a>
    </p>
@endsection

@extends('layouts.app')

@section('title', __('profile.title').' — '.__('app.name'))

@section('content')
    <h1 class="text-2xl font-bold">{{ $user->role === \App\Enums\UserRole::Agent ? __('profile.agent_profile') : __('profile.account') }}</h1>
    <p class="mt-1 mb-6 text-sm text-gray-600">{{ __('profile.subtitle') }}</p>

    @if (session('status'))
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-lg border-2 border-red-600 bg-red-50 px-4 py-3 text-red-800">
            <p class="font-semibold">{{ __('profile.validation_summary') }}</p>
        </div>
    @endif

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
            <button type="submit" class="rounded-lg bg-gray-900 px-4 py-3 text-sm font-semibold text-white hover:bg-gray-700">
                {{ __('profile.save') }}
            </button>
        </div>
    </form>

    <p class="mt-5 text-sm">
        <a href="{{ route('profile.password.edit') }}" class="font-semibold text-gray-900 underline">{{ __('profile.password_change') }}</a>
    </p>
@endsection

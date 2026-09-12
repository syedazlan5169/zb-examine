@extends('layouts.app')

@section('title', __('home.title').' — '.__('app.name'))

@section('content')
    <div class="mx-auto max-w-2xl">
        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm sm:p-10">
        <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('app.name') }}</p>
        <h1 class="mt-3 max-w-xl text-3xl font-bold tracking-tight text-gray-950 sm:text-4xl">{{ __('home.title') }}</h1>
        <p class="mt-4 max-w-xl text-base leading-7 text-gray-600">{{ __('home.description') }}</p>

        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
            <x-ui.button href="{{ route('examinations.create') }}" class="sm:px-6">
                {{ __('home.new_submission') }}
            </x-ui.button>
            <x-ui.button href="{{ route('login') }}" variant="secondary">
                {{ __('auth.login') }}
            </x-ui.button>
        </div>

        <p class="mt-6 border-t border-gray-200 pt-5 text-sm text-gray-600">
            {{ __('home.existing_account') }}
            <a href="{{ route('register') }}" class="font-semibold text-gray-900 underline focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                {{ __('home.register') }}
            </a>
        </p>
        </div>
    </div>
@endsection

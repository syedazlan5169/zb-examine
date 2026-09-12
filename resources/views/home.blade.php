@extends('layouts.app')

@section('title', __('home.title').' — '.__('app.name'))

@section('content')
    <div class="mx-auto max-w-xl text-center">
        <p class="text-sm font-semibold uppercase tracking-wide text-gray-600">{{ __('app.name') }}</p>
        <h1 class="mt-3 text-2xl font-bold sm:text-3xl">{{ __('home.title') }}</h1>
        <p class="mx-auto mt-3 max-w-lg text-sm leading-6 text-gray-600">{{ __('home.description') }}</p>

        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <a href="{{ route('examinations.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-gray-900 px-5 py-3 text-base font-semibold text-white hover:bg-gray-800 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900">
                {{ __('home.new_submission') }}
            </a>
            <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-3 text-base font-semibold text-gray-900 hover:bg-gray-50 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900">
                {{ __('auth.login') }}
            </a>
        </div>

        <p class="mt-6 text-sm text-gray-600">
            {{ __('home.existing_account') }}
            <a href="{{ route('register') }}" class="font-semibold text-gray-900 underline focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                {{ __('home.register') }}
            </a>
        </p>
    </div>
@endsection

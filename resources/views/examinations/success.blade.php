@extends('layouts.app')

@section('title', __('examination.success.heading').' — '.__('app.name'))

@section('content')
    <div class="rounded-lg border border-green-300 bg-green-50 px-5 py-8 text-center shadow-sm sm:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-green-700">{{ __('app.name') }}</p>
        <h1 class="mt-2 text-2xl font-bold text-green-950">{{ __('examination.success.heading') }}</h1>
        <p class="mx-auto mt-3 max-w-lg text-green-800">{{ __('examination.success.message') }}</p>

        <p class="mt-6 text-sm font-semibold tracking-wide text-green-700 uppercase">
            {{ __('examination.success.submission_number_label') }}
        </p>
        <p id="submission-number" data-draft-actor="{{ auth()->check() ? 'user-'.auth()->id() : 'guest' }}" class="mt-2 break-all text-3xl font-extrabold tracking-tight text-green-950 sm:text-4xl">
            {{ $submissionNo }}
        </p>

        <x-ui.button
            type="button"
            id="copy-submission-number"
            data-copy-target="submission-number"
            data-default-label="{{ __('examination.actions.copy_number') }}"
            data-copied-label="{{ __('examination.actions.copied') }}"
            variant="secondary"
            class="mt-4 border-green-700 text-green-800 hover:bg-green-100 focus:ring-green-900"
        >
            {{ __('examination.actions.copy_number') }}
        </x-ui.button>
    </div>

    <x-ui.button href="{{ route('examinations.create') }}" class="mt-6 w-full py-4 text-base sm:text-lg">
        {{ __('examination.actions.new_submission') }}
    </x-ui.button>
@endsection

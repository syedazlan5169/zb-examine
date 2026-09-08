@extends('layouts.app')

@section('title', __('examination.success.heading').' — '.__('app.name'))

@section('content')
    <div class="rounded-xl border-2 border-green-600 bg-green-50 px-6 py-8 text-center">
        <h1 class="text-2xl font-bold text-green-900">{{ __('examination.success.heading') }}</h1>
        <p class="mt-2 text-green-800">{{ __('examination.success.message') }}</p>

        <p class="mt-6 text-sm font-semibold tracking-wide text-green-700 uppercase">
            {{ __('examination.success.submission_number_label') }}
        </p>
        <p id="submission-number" class="mt-1 text-4xl font-extrabold break-all text-green-900">
            {{ $submissionNo }}
        </p>

        <button
            type="button"
            id="copy-submission-number"
            data-copy-target="submission-number"
            data-default-label="{{ __('examination.actions.copy_number') }}"
            data-copied-label="{{ __('examination.actions.copied') }}"
            class="mt-4 rounded-lg border-2 border-green-700 px-4 py-2 text-sm font-medium text-green-800 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-green-900"
        >
            {{ __('examination.actions.copy_number') }}
        </button>
    </div>

    <a
        href="{{ route('examinations.create') }}"
        class="mt-8 block w-full rounded-lg bg-gray-900 px-6 py-4 text-center text-lg font-semibold text-white focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900"
    >
        {{ __('examination.actions.new_submission') }}
    </a>
@endsection

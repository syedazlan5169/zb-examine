@php
    $config = [
        'sessionStorageKey' => 'zb-examine.photo-upload-session.v1',
        'maxPhotos' => 10,
        'messages' => [
            'title' => __('photo_upload_workbench.title'),
            'subtitle' => __('photo_upload_workbench.subtitle'),
            'takePhoto' => __('photo_upload_workbench.take_photo'),
            'chooseExisting' => __('photo_upload_workbench.choose_existing'),
            'countLabel' => __('photo_upload_workbench.count_label'),
            'empty' => __('photo_upload_workbench.empty'),
            'noPreview' => __('photo_upload_workbench.no_preview'),
            'remove' => __('photo_upload_workbench.remove'),
            'retry' => __('photo_upload_workbench.retry'),
            'retryRemove' => __('photo_upload_workbench.retry_remove'),
            'recover' => __('photo_upload_workbench.recover'),
            'uploaded' => __('photo_upload_workbench.uploaded'),
            'queued' => __('photo_upload_workbench.queued'),
            'working' => __('photo_upload_workbench.working'),
            'photoLabel' => __('photo_upload_workbench.photo_label'),
            'sessionReset' => __('photo_upload_workbench.session_reset'),
            'sessionFinalized' => __('photo_upload_workbench.session_finalized'),
            'maxReached' => __('photo_upload_workbench.max_reached'),
            'extrasIgnored' => __('photo_upload_workbench.extras_ignored'),
            'genericError' => __('photo_upload_workbench.generic_error'),
            'retryLater' => __('photo_upload_workbench.retry_later'),
            'originalLabel' => __('photo_upload_workbench.original_label'),
            'optimizedLabel' => __('photo_upload_workbench.optimized_label'),
            'stageLabel' => __('photo_upload_workbench.stage_label'),
            'slowTestMode' => __('photo_upload_workbench.slow_test_mode'),
            'state' => [
                'queued' => __('photo_upload_workbench.state.queued'),
                'processing' => __('photo_upload_workbench.state.processing'),
                'allocating' => __('photo_upload_workbench.state.allocating'),
                'uploading' => __('photo_upload_workbench.state.uploading'),
                'completing' => __('photo_upload_workbench.state.completing'),
                'uploaded' => __('photo_upload_workbench.state.uploaded'),
                'failed' => __('photo_upload_workbench.state.failed'),
                'needs_reselection' => __('photo_upload_workbench.state.needs_reselection'),
                'removing' => __('photo_upload_workbench.state.removing'),
                'remove_failed' => __('photo_upload_workbench.state.remove_failed'),
                'retry_cleanup' => __('photo_upload_workbench.state.retry_cleanup'),
                'retry_cleanup_failed' => __('photo_upload_workbench.state.retry_cleanup_failed'),
            ],
        ],
    ];
@endphp

@extends('layouts.app')

@section('title', __('photo_upload_workbench.title'))

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <strong>{{ __('photo_upload_workbench.warning_title') }}</strong>
            <span class="ml-2">{{ __('photo_upload_workbench.warning_text') }}</span>
        </div>

        <div id="photo-upload-workbench" data-config='@json($config)' class="space-y-5">
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">{{ __('photo_upload_workbench.title') }}</h1>
                        <p class="text-sm text-gray-600">{{ __('photo_upload_workbench.subtitle') }}</p>
                    </div>
                    <div data-role="summary" class="rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-700">0 / 10 photos</div>
                </div>

                <label class="mb-4 flex items-center gap-2 text-xs font-medium text-gray-600">
                    <input type="checkbox" data-role="slow-mode-toggle" class="h-4 w-4 rounded border-gray-300" />
                    {{ __('photo_upload_workbench.slow_test_mode') }}
                </label>

                <div class="mb-4 flex gap-3">
                    <button type="button" data-action="camera-trigger" class="flex-1 rounded-lg bg-gray-900 px-4 py-3 text-base font-semibold text-white">
                        {{ __('photo_upload_workbench.take_photo') }}
                    </button>
                    <button type="button" data-action="library-trigger" class="flex-1 rounded-lg border border-gray-300 bg-white px-4 py-3 text-base font-semibold text-gray-900">
                        {{ __('photo_upload_workbench.choose_existing') }}
                    </button>
                </div>

                <input data-role="camera-input" type="file" accept="image/*" capture="environment" class="hidden" />
                <input data-role="library-input" type="file" accept="image/*" multiple class="hidden" />

                <p data-role="status-text" class="min-h-6 text-sm text-gray-600">{{ __('photo_upload_workbench.empty') }}</p>

                <div data-role="photo-list" class="mt-4 space-y-3"></div>
            </div>
        </div>
    </div>

    @vite(['resources/js/photo-upload-workbench.js'])
@endsection

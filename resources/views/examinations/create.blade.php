@extends('layouts.app')

@section('title', __('examination.title').' — '.__('app.name'))

@section('content')
    @php
        $hasServerValues = session()->has('_old_input') || $errors->any();
        $draftActor = auth()->check() ? 'user-'.auth()->id() : 'guest';
        $agentDefaultsForDraft = [
            'agent_name' => $agentDefaults['agent_name'] ?? '',
            'agent_phone' => $agentDefaults['agent_phone'] ?? '',
            'agent_code' => $agentDefaults['agent_code'] ?? '',
            'agent_company_name' => $agentDefaults['agent_company_name'] ?? '',
            'agent_station_code' => $agentDefaults['agent_station_code'] ?? '',
        ];
    @endphp

    <div class="mb-6">
        <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ __('app.name') }}</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-gray-950 sm:text-3xl">{{ __('examination.title') }}</h1>
    </div>

    @if (session('submission_error'))
        <x-ui.alert type="error" class="mb-6">{{ session('submission_error') }}</x-ui.alert>
    @endif

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-6">{{ __('examination.errors.validation_summary') }}</x-ui.alert>
    @endif

    <form
        method="POST"
        action="{{ route('examinations.store') }}"
        id="examination-form"
        data-loading-text="{{ __('examination.actions.submitting') }}"
        data-draft-actor="{{ $draftActor }}"
        data-draft-version="1"
        data-server-values="{{ $hasServerValues ? 'true' : 'false' }}"
        data-agent-defaults='@json($agentDefaultsForDraft)'
        data-draft-saved-label="{{ __('examination.draft.saved') }}"
        data-draft-restored-label="{{ __('examination.draft.restored') }}"
        data-draft-cleared-label="{{ __('examination.draft.cleared') }}"
        class="space-y-8 rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-6"
        novalidate
    >
        @csrf

        <section>
            <h2 class="mb-5 border-b border-gray-200 pb-3 text-lg font-bold text-gray-950">
                {{ __('examination.sections.agent_information') }}
            </h2>

            <div class="space-y-5">
                <x-text-input name="agent_name" :label="__('examination.fields.agent_name')" :value="$agentDefaults['agent_name'] ?? ''" autocomplete="name" />
                <x-text-input name="agent_phone" :label="__('examination.fields.agent_phone')" type="tel" :value="$agentDefaults['agent_phone'] ?? ''" autocomplete="tel" inputmode="tel" />
                <x-text-input name="agent_code" :label="__('examination.fields.agent_code')" :value="$agentDefaults['agent_code'] ?? ''" autocomplete="off" />
                <x-text-input name="agent_company_name" :label="__('examination.fields.agent_company_name')" :value="$agentDefaults['agent_company_name'] ?? ''" autocomplete="organization" />
                <x-text-input name="agent_station_code" :label="__('examination.fields.agent_station_code')" :value="$agentDefaults['agent_station_code'] ?? ''" autocomplete="off" />
            </div>
        </section>

        <section>
            <h2 class="mb-5 border-b border-gray-200 pb-3 text-lg font-bold text-gray-950">
                {{ __('examination.sections.examination_information') }}
            </h2>

            <div class="space-y-6">
                <x-radio-card-group
                    name="location"
                    :enum="\App\Enums\ExaminationLocation::class"
                    translation-prefix="examination.options.location"
                    :legend="__('examination.fields.location')"
                />

                <div>
                    <label for="form_type" class="mb-1 block text-sm font-medium text-gray-900">
                        {{ __('examination.fields.form_type') }}
                        <span aria-hidden="true" class="text-red-600">*</span>
                    </label>

                    <select
                        name="form_type"
                        id="form_type"
                        required
                        @error('form_type') aria-invalid="true" aria-describedby="form_type-error" @enderror
                        class="w-full rounded-lg border-2 px-4 py-3 text-base focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900 {{ $errors->has('form_type') ? 'border-red-600' : 'border-gray-300' }}"
                    >
                        <option value="" disabled {{ old('form_type') ? '' : 'selected' }}>{{ __('examination.choose_option') }}</option>
                        @foreach (\App\Enums\FormType::cases() as $case)
                            <option value="{{ $case->value }}" {{ old('form_type') === $case->value ? 'selected' : '' }}>
                                {{ $case->label() }}
                            </option>
                        @endforeach
                    </select>

                    @error('form_type')
                        <p id="form_type-error" class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                <div id="form_type_other_wrapper">
                    <x-text-input name="form_type_other" :label="__('examination.fields.form_type_other')" :required="false" />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-900">
                        {{ __('examination.fields.customs_form_numbers') }}
                        <span aria-hidden="true" class="text-red-600">*</span>
                    </label>

                    @php
                        $customsFormNumbersOld = old('customs_form_numbers', ['']);
                        if (! is_array($customsFormNumbersOld) || $customsFormNumbersOld === []) {
                            $customsFormNumbersOld = [''];
                        }
                    @endphp

                    <div id="customs-form-numbers-list" class="space-y-2">
                        @foreach ($customsFormNumbersOld as $index => $customsFormNumberValue)
                            <div data-role="customs-form-number-row" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                                <div class="flex-1">
                                    <input
                                        type="text"
                                        name="customs_form_numbers[]"
                                        value="{{ $customsFormNumberValue }}"
                                        required
                                        aria-label="{{ __('examination.fields.customs_form_numbers') }}"
                                        @error("customs_form_numbers.{$index}") aria-invalid="true" @enderror
                                        class="w-full rounded-lg border-2 px-4 py-3 text-base focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900 {{ $errors->has("customs_form_numbers.{$index}") ? 'border-red-600' : 'border-gray-300' }}"
                                    />
                                    @error("customs_form_numbers.{$index}")
                                        <p class="mt-1 text-sm font-medium text-red-700" data-role="customs-form-number-error">{{ $message }}</p>
                                    @else
                                        <p class="mt-1 hidden text-sm font-medium text-red-700" data-role="customs-form-number-error"></p>
                                    @enderror
                                </div>
                                <button
                                    type="button"
                                    data-action="remove-customs-form-number"
                                    {{ $index === 0 ? 'hidden' : '' }}
                                    class="min-h-11 shrink-0 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 sm:min-h-0"
                                >
                                    {{ __('examination.customs_form_numbers.remove') }}
                                </button>
                            </div>
                        @endforeach
                    </div>

                    <p class="mt-2 text-sm text-gray-600">
                        {{ __('examination.help.customs_form_numbers') }}
                    </p>

                    @error('customs_form_numbers')
                        <p class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror

                    <button
                        type="button"
                        id="customs-form-numbers-add"
                        data-duplicate-message="{{ __('examination.customs_form_numbers.duplicate') }}"
                        class="mt-2 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-900"
                    >
                        {{ __('examination.customs_form_numbers.add') }}
                    </button>
                </div>

                <x-radio-card-group
                    name="container_status"
                    :enum="\App\Enums\ContainerStatus::class"
                    translation-prefix="examination.options.container_status"
                    :legend="__('examination.fields.container_status')"
                />

                <div>
                    <label for="reason" class="mb-1 block text-sm font-medium text-gray-900">
                        {{ __('examination.fields.reason') }}
                    </label>

                    <select
                        name="reason"
                        id="reason"
                        @error('reason') aria-invalid="true" aria-describedby="reason-error" @enderror
                        class="w-full rounded-lg border-2 px-4 py-3 text-base focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900 {{ $errors->has('reason') ? 'border-red-600' : 'border-gray-300' }}"
                    >
                        <option value="" {{ old('reason') ? '' : 'selected' }}>{{ __('examination.reason_placeholder') }}</option>
                        @foreach (\App\Enums\ExaminationReason::cases() as $case)
                            <option value="{{ $case->value }}" {{ old('reason') === $case->value ? 'selected' : '' }}>
                                {{ $case->label() }}
                            </option>
                        @endforeach
                    </select>

                    @error('reason')
                        <p id="reason-error" class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                <div id="reason_other_wrapper">
                    <x-text-input name="reason_other" :label="__('examination.fields.reason_other')" :required="false" />
                </div>

                <x-radio-card-group
                    name="attending_officer_type"
                    :enum="\App\Enums\AttendingOfficerType::class"
                    translation-prefix="examination.options.attending_officer_type"
                    :legend="__('examination.fields.attending_officer_type')"
                />
            </div>
        </section>

        <section>
            <h2 class="mb-5 border-b border-gray-200 pb-3 text-lg font-bold text-gray-950">
                {{ __('examination_photos.section_title') }}
            </h2>

            @php
                $photoConfig = [
                    'maxPhotos' => 10,
                    'showDiagnostics' => false,
                    'messages' => [
                        'takePhoto' => __('examination_photos.take_photo'),
                        'chooseExisting' => __('examination_photos.choose_existing'),
                        'countLabel' => __('examination_photos.count_label'),
                        'empty' => __('examination_photos.empty'),
                        'noPreview' => __('examination_photos.no_preview'),
                        'remove' => __('examination_photos.remove'),
                        'retry' => __('examination_photos.retry'),
                        'retryRemove' => __('examination_photos.retry_remove'),
                        'recover' => __('examination_photos.recover'),
                        'uploaded' => __('examination_photos.uploaded'),
                        'queued' => __('examination_photos.queued'),
                        'working' => __('examination_photos.working'),
                        'photoLabel' => __('examination_photos.photo_label'),
                        'sessionReset' => __('examination_photos.session_reset'),
                        'sessionFinalized' => __('examination_photos.session_finalized'),
                        'maxReached' => __('examination_photos.max_reached'),
                        'extrasIgnored' => __('examination_photos.extras_ignored'),
                        'genericError' => __('examination_photos.generic_error'),
                        'retryLater' => __('examination_photos.retry_later'),
                        'state' => [
                            'queued' => __('examination_photos.state.queued'),
                            'processing' => __('examination_photos.state.processing'),
                            'allocating' => __('examination_photos.state.allocating'),
                            'uploading' => __('examination_photos.state.uploading'),
                            'completing' => __('examination_photos.state.completing'),
                            'uploaded' => __('examination_photos.state.uploaded'),
                            'failed' => __('examination_photos.state.failed'),
                            'needs_reselection' => __('examination_photos.state.needs_reselection'),
                            'removing' => __('examination_photos.state.removing'),
                            'remove_failed' => __('examination_photos.state.remove_failed'),
                            'retry_cleanup' => __('examination_photos.state.retry_cleanup'),
                            'retry_cleanup_failed' => __('examination_photos.state.retry_cleanup_failed'),
                        ],
                    ],
                ];
            @endphp

            <div id="examination-photos" data-config='@json($photoConfig)' class="space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <div data-role="summary" class="rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-700">0 / 10 {{ __('examination_photos.count_label') }}</div>
                </div>

                <div class="flex gap-3">
                    <button type="button" data-action="camera-trigger" class="flex-1 rounded-lg bg-gray-900 px-4 py-3 text-base font-semibold text-white">
                        {{ __('examination_photos.take_photo') }}
                    </button>
                    <button type="button" data-action="library-trigger" class="flex-1 rounded-lg border border-gray-300 bg-white px-4 py-3 text-base font-semibold text-gray-900">
                        {{ __('examination_photos.choose_existing') }}
                    </button>
                </div>

                <input data-role="camera-input" type="file" accept="image/*" capture="environment" class="hidden" />
                <input data-role="library-input" type="file" accept="image/*" multiple class="hidden" />

                <p data-role="status-text" class="min-h-6 text-sm text-gray-600">{{ __('examination_photos.empty') }}</p>

                <div data-role="photo-list" class="space-y-3"></div>
            </div>

            <p id="examination-photos-not-ready" role="alert" hidden class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
                {{ __('examination_photos.not_ready') }}
            </p>

            <input type="hidden" name="photo_upload_session_public_id" id="photo_upload_session_public_id" />
            <input type="hidden" name="photo_upload_token" id="photo_upload_token" />
        </section>

        <x-ui.button type="submit" id="examination-submit" class="w-full py-4 text-base sm:text-lg">
            {{ __('examination.actions.submit') }}
        </x-ui.button>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <p id="examination-draft-status" role="status" aria-live="polite" class="min-h-5 text-sm text-gray-600"></p>
            <x-ui.button type="button" variant="ghost" id="examination-draft-clear">
                {{ __('examination.draft.clear') }}
            </x-ui.button>
        </div>
    </form>
@endsection

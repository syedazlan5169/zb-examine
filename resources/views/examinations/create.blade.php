@extends('layouts.app')

@section('title', __('examination.title').' — '.__('app.name'))

@section('content')
    <h1 class="text-2xl font-bold">{{ __('examination.title') }}</h1>
    <p class="mt-1 mb-6 text-sm text-gray-600">{{ __('app.name') }}</p>

    @if (session('submission_error'))
        <div role="alert" class="mb-6 rounded-lg border-2 border-red-600 bg-red-50 px-4 py-3 text-red-800">
            <p class="font-semibold">{{ session('submission_error') }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-lg border-2 border-red-600 bg-red-50 px-4 py-3 text-red-800">
            <p class="font-semibold">{{ __('examination.errors.validation_summary') }}</p>
        </div>
    @endif

    <form
        method="POST"
        action="{{ route('examinations.store') }}"
        id="examination-form"
        data-loading-text="{{ __('examination.actions.submitting') }}"
        novalidate
    >
        @csrf

        <section class="mb-8">
            <h2 class="mb-4 border-b border-gray-300 pb-2 text-lg font-semibold">
                {{ __('examination.sections.agent_information') }}
            </h2>

            <div class="space-y-5">
                <x-text-input name="agent_name" :label="__('examination.fields.agent_name')" autocomplete="name" />
                <x-text-input name="agent_phone" :label="__('examination.fields.agent_phone')" type="tel" autocomplete="tel" inputmode="tel" />
                <x-text-input name="agent_code" :label="__('examination.fields.agent_code')" autocomplete="off" />
                <x-text-input name="agent_company_name" :label="__('examination.fields.agent_company_name')" autocomplete="organization" />
                <x-text-input name="agent_station_code" :label="__('examination.fields.agent_station_code')" autocomplete="off" />
            </div>
        </section>

        <section class="mb-8">
            <h2 class="mb-4 border-b border-gray-300 pb-2 text-lg font-semibold">
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
                                {{ __("examination.options.form_type.{$case->value}") }}
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
                    <label for="customs_form_numbers" class="mb-1 block text-sm font-medium text-gray-900">
                        {{ __('examination.fields.customs_form_numbers') }}
                        <span aria-hidden="true" class="text-red-600">*</span>
                    </label>

                    <textarea
                        name="customs_form_numbers"
                        id="customs_form_numbers"
                        required
                        rows="2"
                        autocapitalize="characters"
                        @error('customs_form_numbers') aria-invalid="true" aria-describedby="customs_form_numbers-error customs_form_numbers-help" @else aria-describedby="customs_form_numbers-help" @enderror
                        class="w-full rounded-lg border-2 px-4 py-3 text-base focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900 {{ $errors->has('customs_form_numbers') ? 'border-red-600' : 'border-gray-300' }}"
                    >{{ old('customs_form_numbers') }}</textarea>

                    <p id="customs_form_numbers-help" class="mt-1 text-sm text-gray-600">
                        {{ __('examination.help.customs_form_numbers') }}
                    </p>

                    @error('customs_form_numbers')
                        <p id="customs_form_numbers-error" class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p>
                    @enderror
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
                                {{ __("examination.options.reason.{$case->value}") }}
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

        {{-- Reserved for the future Photos section (Step 3B) — intentionally empty. --}}
        <section data-future-section="photos"></section>

        <button
            type="submit"
            id="examination-submit"
            class="w-full rounded-lg bg-gray-900 px-6 py-4 text-lg font-semibold text-white focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900 disabled:cursor-not-allowed disabled:opacity-60"
        >
            {{ __('examination.actions.submit') }}
        </button>
    </form>
@endsection

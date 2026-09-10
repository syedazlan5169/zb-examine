@extends('layouts.app')

@section('title', __('examination.agent.detail_title').' — '.__('app.name'))
@section('content_width', 'max-w-5xl')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="text-2xl font-bold">{{ __('examination.agent.detail_title') }}</h1>
            <a href="{{ route('agent.examinations.index') }}" class="font-semibold text-blue-600 hover:underline">
                ← {{ __('examination.agent.back_to_list') }}
            </a>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Submission Section -->
            <section class="rounded-lg border border-gray-200 p-4 sm:p-6">
                <h2 class="border-b border-gray-200 pb-2 text-lg font-semibold">{{ __('examination.sections.submission') }}</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                        <dt class="text-gray-600">{{ __('examination.fields.submission_number') }}</dt>
                        <dd class="break-all font-semibold sm:text-right">{{ $examination->submission_no }}</dd>
                    </div>
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                        <dt class="text-gray-600">{{ __('examination.staff.submitted_at') }}</dt>
                        <dd class="font-semibold sm:text-right">
                            <time datetime="{{ $examination->submitted_at->toIso8601String() }}">
                                {{ $examination->submitted_at->copy()->setTimezone(config('zb-examine.business_timezone'))->format('d/m/Y H:i') }}
                            </time>
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- Agent Information Section -->
            <section class="rounded-lg border border-gray-200 p-4 sm:p-6">
                <h2 class="border-b border-gray-200 pb-2 text-lg font-semibold">{{ __('examination.sections.agent_information') }}</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    @foreach ([
                        'agent_name' => __('examination.fields.agent_name'),
                        'agent_phone' => __('examination.fields.agent_phone'),
                        'agent_code' => __('examination.fields.agent_code'),
                        'agent_company_name' => __('examination.fields.agent_company_name'),
                        'agent_station_code' => __('examination.fields.agent_station_code'),
                    ] as $field => $label)
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                            <dt class="text-gray-600">{{ $label }}</dt>
                            <dd class="break-words sm:text-right">{{ $examination->{$field} ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <!-- Examination Information Section -->
            <section class="rounded-lg border border-gray-200 p-4 sm:p-6 lg:col-span-2">
                <h2 class="border-b border-gray-200 pb-2 text-lg font-semibold">{{ __('examination.sections.examination_information') }}</h2>
                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-2">
                        <dt class="text-gray-600">{{ __('examination.fields.location') }}</dt>
                        <dd class="break-words sm:text-right">{{ $examination->location->label() }}</dd>
                    </div>
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-2">
                        <dt class="text-gray-600">{{ __('examination.fields.container_status') }}</dt>
                        <dd class="break-words sm:text-right">{{ $examination->container_status->label() }}</dd>
                    </div>
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-2">
                        <dt class="text-gray-600">{{ __('examination.fields.attending_officer_type') }}</dt>
                        <dd class="break-words sm:text-right">{{ $examination->attending_officer_type->label() }}</dd>
                    </div>
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-2">
                        <dt class="text-gray-600">{{ __('examination.fields.form_type') }}</dt>
                        <dd class="break-words sm:text-right">{{ $examination->form_type->label() }}{{ $examination->form_type->value === 'other' && $examination->form_type_other ? ' — '.$examination->form_type_other : '' }}</dd>
                    </div>
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-2">
                        <dt class="text-gray-600">{{ __('examination.fields.reason') }}</dt>
                        <dd class="break-words sm:text-right">{{ $examination->reason ? $examination->reason->label().($examination->reason->value === 'other' && $examination->reason_other ? ' — '.$examination->reason_other : '') : __('examination.reason_placeholder') }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Customs Forms Section -->
            <section class="rounded-lg border border-gray-200 p-4 sm:p-6 lg:col-span-2">
                <h2 class="border-b border-gray-200 pb-2 text-lg font-semibold">{{ __('examination.staff.customs_forms') }}</h2>
                @if ($examination->customsFormNumbers->isEmpty())
                    <p class="mt-3 text-sm text-gray-600">{{ __('examination.staff.no_customs_forms') }}</p>
                @else
                    <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm">
                        @foreach ($examination->customsFormNumbers as $formNumber)
                            <li>{{ $formNumber->number }}</li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <!-- Evidence Section -->
            <section class="rounded-lg border border-gray-200 p-4 sm:p-6 lg:col-span-2">
                <h2 class="border-b border-gray-200 pb-2 text-lg font-semibold">{{ __('examination.staff.evidence') }}</h2>
                @if ($examination->photos->isEmpty())
                    <p class="mt-3 text-sm text-gray-600">{{ __('examination.staff.no_photos') }}</p>
                @else
                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($examination->photos as $photo)
                            <a href="{{ route('examinations.photos.preview', [$examination, $photo]) }}" target="_blank" rel="noopener noreferrer" class="block overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                                <img
                                    src="{{ route('examinations.photos.preview', [$examination, $photo]) }}"
                                    alt="Evidence photo {{ $photo->display_order }}"
                                    class="aspect-[4/3] w-full object-cover transition hover:opacity-90"
                                >
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>
@endsection

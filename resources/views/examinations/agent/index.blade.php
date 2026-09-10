@extends('layouts.app')

@section('title', __('examination.agent.list_title').' — '.__('app.name'))
@section('content_width', 'max-w-5xl')

@section('content')
    <div class="rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
        <h1 class="mb-6 text-2xl font-bold">{{ __('examination.agent.list_title') }}</h1>

        @if ($examinations->count() > 0)
            <div class="space-y-3 md:hidden">
                @foreach ($examinations as $examination)
                    <a href="{{ route('agent.examinations.show', $examination) }}" class="block rounded-lg border border-gray-200 bg-gray-50 p-4 text-left transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        <div class="flex items-start justify-between gap-3">
                            <span class="text-base font-bold text-blue-600 whitespace-nowrap">{{ $examination->submission_no }}</span>
                            <time datetime="{{ $examination->submitted_at->toIso8601String() }}" class="text-right text-xs text-gray-500">
                                {{ $examination->submitted_at->copy()->setTimezone(config('zb-examine.business_timezone'))->format('d/m/Y H:i') }}
                            </time>
                        </div>

                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-gray-600">{{ __('examination.fields.location') }}</dt>
                                <dd class="text-right text-gray-900">{{ $examination->location->label() }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-gray-600">{{ __('examination.fields.container_status') }}</dt>
                                <dd class="text-right font-medium text-gray-900">{{ $examination->container_status->label() }}</dd>
                            </div>
                        </dl>
                    </a>
                @endforeach
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="border-b-2 border-gray-200">
                            <th class="px-3 py-3 text-left text-sm font-semibold">{{ __('examination.fields.submission_number') }}</th>
                            <th class="px-3 py-3 text-left text-sm font-semibold">{{ __('examination.staff.submitted_at') }}</th>
                            <th class="px-3 py-3 text-left text-sm font-semibold">{{ __('examination.fields.location') }}</th>
                            <th class="px-3 py-3 text-left text-sm font-semibold">{{ __('examination.fields.container_status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($examinations as $examination)
                            <tr class="transition hover:bg-gray-50">
                                <td class="px-3 py-3">
                                    <a href="{{ route('agent.examinations.show', $examination) }}" class="font-semibold text-blue-600 hover:underline">
                                        {{ $examination->submission_no }}
                                    </a>
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-600">
                                    <time datetime="{{ $examination->submitted_at->toIso8601String() }}">
                                        {{ $examination->submitted_at->copy()->setTimezone(config('zb-examine.business_timezone'))->format('d/m/Y H:i') }}
                                    </time>
                                </td>
                                <td class="px-3 py-3 text-sm">
                                    {{ $examination->location->label() }}
                                </td>
                                <td class="px-3 py-3 text-sm">
                                    {{ $examination->container_status->label() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($examinations->hasPages())
                <nav class="mt-6 flex items-center justify-between gap-3 text-sm" aria-label="{{ __('examination.agent.list_title') }}">
                    @if ($examinations->onFirstPage())
                        <span class="text-gray-400">{{ __('examination.staff.previous') }}</span>
                    @else
                        <a class="font-semibold underline" href="{{ $examinations->previousPageUrl() }}">{{ __('examination.staff.previous') }}</a>
                    @endif
                    <span>{{ __('examination.staff.page', ['current' => $examinations->currentPage(), 'last' => $examinations->lastPage()]) }}</span>
                    @if ($examinations->hasMorePages())
                        <a class="font-semibold underline" href="{{ $examinations->nextPageUrl() }}">{{ __('examination.staff.next') }}</a>
                    @else
                        <span class="text-gray-400">{{ __('examination.staff.next') }}</span>
                    @endif
                </nav>
            @endif
        @else
            <p class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-center text-sm text-gray-600">
                {{ __('examination.agent.empty_state') }}
            </p>
        @endif
    </div>
@endsection

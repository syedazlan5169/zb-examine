@extends('layouts.app')

@section('title', __('reports.title'))
@section('content_width', 'max-w-7xl')

@php
    $previousPeriod = $period->localStart->subMonth();
    $nextPeriod = $period->localStart->addMonth();
    $periodQuery = ['year' => $period->year, 'month' => $period->month];
@endphp

@section('content')
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">{{ __('reports.title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ $period->label() }}</p>
            </div>

            <a href="{{ route('reports.export', $periodQuery) }}" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900">
                {{ __('reports.actions.export') }}
            </a>
        </div>

        <form method="GET" action="{{ route('reports.index') }}" class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4">
            <div class="flex min-w-32 flex-1 flex-col gap-1">
                <label for="month" class="text-sm font-semibold">{{ __('reports.filters.month') }}</label>
                <select id="month" name="month" class="min-h-11 rounded-lg border-2 border-gray-300 px-3 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900">
                    @for ($month = 1; $month <= 12; $month++)
                        <option value="{{ $month }}" @selected($period->month === $month)>{{ Carbon\CarbonImmutable::create($period->year, $month, 1)->translatedFormat('F') }}</option>
                    @endfor
                </select>
            </div>
            <div class="flex min-w-32 flex-1 flex-col gap-1">
                <label for="year" class="text-sm font-semibold">{{ __('reports.filters.year') }}</label>
                <input id="year" name="year" type="number" min="2000" max="{{ now($period->timezone)->year + 1 }}" value="{{ $period->year }}" class="min-h-11 rounded-lg border-2 border-gray-300 px-3 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900">
            </div>
            <button type="submit" class="min-h-11 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900">{{ __('reports.filters.view') }}</button>
            <div class="flex w-full gap-2 sm:ml-auto sm:w-auto">
                <a href="{{ route('reports.index', ['year' => $previousPeriod->year, 'month' => $previousPeriod->month]) }}" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold hover:bg-gray-50 sm:flex-none">{{ __('reports.filters.previous') }}</a>
                <a href="{{ route('reports.index', ['year' => $nextPeriod->year, 'month' => $nextPeriod->month]) }}" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold hover:bg-gray-50 sm:flex-none">{{ __('reports.filters.next') }}</a>
            </div>
        </form>

        <section aria-labelledby="summary-heading">
            <h2 id="summary-heading" class="mb-3 text-lg font-bold">{{ __('reports.summary.title') }}</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['label' => __('reports.summary.total_submissions'), 'value' => $summary['total_submissions']],
                    ['label' => __('reports.summary.unique_agents'), 'value' => $summary['unique_agents']],
                    ['label' => __('reports.summary.evidence_photos'), 'value' => $summary['evidence_photos']],
                    ['label' => __('reports.summary.average_per_active_day'), 'value' => number_format($summary['average_per_active_day'], 1)],
                ] as $card)
                    <x-ui.card>
                        <dt class="text-sm text-gray-600">{{ $card['label'] }}</dt>
                        <dd class="mt-2 text-2xl font-bold">{{ $card['value'] }}</dd>
                    </x-ui.card>
                @endforeach
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-ui.card title="{{ __('reports.daily.title') }}" aria-labelledby="daily-heading">
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-64 text-left text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase text-gray-600">
                            <tr><th class="px-2 py-2">{{ __('reports.daily.date') }}</th><th class="px-2 py-2 text-right">{{ __('reports.daily.submissions') }}</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($dailyActivity as $day)
                                <tr><td class="px-2 py-2">{{ Carbon\CarbonImmutable::parse($day['date'], $period->timezone)->format('d/m/Y') }}</td><td class="px-2 py-2 text-right font-semibold">{{ $day['submissions'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <x-ui.card title="{{ __('reports.agents.title') }}" aria-labelledby="agents-heading">
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[34rem] text-left text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase text-gray-600">
                            <tr><th class="px-2 py-2">{{ __('reports.agents.name') }}</th><th class="px-2 py-2">{{ __('reports.agents.code') }}</th><th class="px-2 py-2">{{ __('reports.agents.company') }}</th><th class="px-2 py-2">{{ __('reports.agents.station') }}</th><th class="px-2 py-2 text-right">{{ __('reports.statement.submissions') }}</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($agentSummary as $agent)
                                <tr><td class="px-2 py-2">{{ $agent['agent_name'] }}</td><td class="px-2 py-2">{{ $agent['agent_code'] }}</td><td class="px-2 py-2">{{ $agent['company'] }}</td><td class="px-2 py-2">{{ $agent['station'] }}</td><td class="px-2 py-2 text-right font-semibold">{{ $agent['submissions'] }}</td></tr>
                            @empty
                                <tr><td colspan="5" class="px-2 py-4 text-center text-gray-600">{{ __('reports.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        <section class="rounded-lg border border-gray-200 bg-white p-4" aria-labelledby="statement-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="statement-heading" class="text-lg font-bold">{{ __('reports.statement.title') }}</h2>
                <p class="text-sm text-gray-600">{{ __('reports.statement.result_count', ['count' => $statement->total()]) }}</p>
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[72rem] text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase text-gray-600">
                        <tr>
                            @foreach (['number', 'submission_number', 'submission_date', 'submission_time', 'agent_name', 'agent_code', 'company', 'station', 'location', 'customs_forms', 'form_type', 'container_status', 'reason', 'attending_officer_type', 'evidence_photos'] as $column)
                                <th class="whitespace-nowrap px-2 py-2">{{ __('reports.statement.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($statement as $examination)
                            @php
                                $submittedAt = $examination->submitted_at->setTimezone($period->timezone);
                                $reason = $examination->reason?->label() ?? __('examination.reason_placeholder');
                            @endphp
                            <tr>
                                <td class="px-2 py-2">{{ $statement->firstItem() + $loop->index }}</td>
                                <td class="px-2 py-2 font-semibold">{{ $examination->submission_no }}</td>
                                <td class="whitespace-nowrap px-2 py-2">{{ $submittedAt->format('d/m/Y') }}</td>
                                <td class="whitespace-nowrap px-2 py-2">{{ $submittedAt->format('H:i') }}</td>
                                <td class="px-2 py-2">{{ $examination->agent_name }}</td>
                                <td class="px-2 py-2">{{ $examination->agent_code }}</td>
                                <td class="px-2 py-2">{{ $examination->agent_company_name }}</td>
                                <td class="px-2 py-2">{{ $examination->agent_station_code }}</td>
                                <td class="px-2 py-2">{{ $examination->location->label() }}</td>
                                <td class="px-2 py-2">{{ $examination->customsFormNumbers->pluck('number')->implode(', ') }}</td>
                                <td class="px-2 py-2">{{ $examination->form_type->label() }}{{ $examination->form_type_other ? ' - '.$examination->form_type_other : '' }}</td>
                                <td class="px-2 py-2">{{ $examination->container_status->label() }}</td>
                                <td class="px-2 py-2">{{ $reason }}{{ $examination->reason_other ? ' - '.$examination->reason_other : '' }}</td>
                                <td class="px-2 py-2">{{ $examination->attending_officer_type->label() }}</td>
                                <td class="px-2 py-2 text-right">{{ $examination->photos_count }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="15" class="px-3 py-8 text-center text-gray-600">{{ __('reports.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($statement->hasPages())
                <div class="mt-4">{{ $statement->links() }}</div>
            @endif
        </section>
    </div>
@endsection
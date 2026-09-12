<div class="flex min-h-[calc(100vh-9rem)] flex-col gap-4 lg:h-[calc(100vh-9rem)] lg:grid lg:grid-cols-[minmax(280px,25%)_minmax(0,1fr)] lg:gap-5">
    <aside class="flex min-h-0 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white">
        <div class="flex-none border-b border-gray-200 bg-gray-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('app.name') }}</p>
            <h1 class="mt-1 text-xl font-bold text-gray-950">{{ __('examination.staff.list_title') }}</h1>
            @if (session('status'))
                <x-ui.alert type="success" class="mt-3">{{ session('status') }}</x-ui.alert>
            @endif
            <form method="GET" action="{{ route('examinations.index') }}" class="mt-4 flex flex-wrap items-end gap-2">
                <div class="min-w-0 flex-1">
                    <label for="search" class="sr-only">{{ __('examination.staff.search') }}</label>
                    <input
                        id="search"
                        name="search"
                        value="{{ $search }}"
                        type="search"
                        maxlength="100"
                        placeholder="{{ __('examination.staff.search_placeholder') }}"
                        class="w-full rounded-lg border-2 border-gray-300 px-3 py-2 text-sm focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900"
                    >
                </div>
                <label for="today" class="flex min-h-11 items-center gap-2 px-1 text-sm text-gray-700">
                    <input type="hidden" name="today" value="0">
                    <input
                        id="today"
                        name="today"
                        type="checkbox"
                        value="1"
                        @checked($today)
                        class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-2 focus:ring-blue-500"
                    >
                    {{ __('examination.staff.today_only') }}
                </label>
                <button
                    type="submit"
                    aria-label="{{ __('examination.staff.search') }}"
                    title="{{ __('examination.staff.search') }}"
                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-900 text-white hover:bg-gray-800 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5 fill-none stroke-current stroke-2">
                        <circle cx="11" cy="11" r="6" />
                        <path stroke-linecap="round" d="m16 16 4 4" />
                    </svg>
                </button>
            </form>
        </div>

        <div
            class="min-h-0 flex-1 overflow-y-auto"
            data-mobile-examination-list
            data-mobile-page-size="10"
            data-server-previous-url="{{ $examinations->previousPageUrl() ?? '' }}"
            data-server-next-url="{{ $examinations->nextPageUrl() ?? '' }}"
        >
            @if ($examinations->count() > 0)
                <div class="divide-y divide-gray-200" data-mobile-examination-rows>
                    @foreach ($examinations as $sidebarExamination)
                        @php
                            $sidebarQuery = array_filter([
                                'search' => $search !== '' ? $search : null,
                                'today' => $today ? '1' : '0',
                                'page' => $examinations->currentPage() > 1 ? $examinations->currentPage() : null,
                            ], static fn ($value): bool => $value !== null);
                            $isSelected = $selectedExamination?->is($sidebarExamination) ?? false;
                        @endphp
                        <a
                            href="{{ route('examinations.show', [$sidebarExamination, ...$sidebarQuery]) }}"
                            data-mobile-detail-link
                            data-mobile-examination-row
                            @if ($isSelected) aria-current="page" data-selected="true" @endif
                            class="block px-4 py-3 transition hover:bg-gray-50 {{ $isSelected ? 'border-l-4 border-gray-900 bg-gray-100 pl-3' : '' }}"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <span class="font-semibold text-gray-950">{{ $sidebarExamination->submission_no }}</span>
                                <time datetime="{{ $sidebarExamination->submitted_at->toIso8601String() }}" class="shrink-0 text-xs text-gray-600">
                                {{ $sidebarExamination->submitted_at->copy()->setTimezone(config('zb-examine.business_timezone'))->format('d/m/Y H:i') }}
                                </time>
                            </div>
                            <p class="mt-1 truncate text-sm text-gray-700">{{ $sidebarExamination->agent_name }} · {{ $sidebarExamination->agent_company_name }}</p>
                        </a>
                    @endforeach
                </div>
            @else
                <x-ui.empty-state :title="$search !== '' ? __('examination.staff.no_search_results') : ($today ? __('examination.staff.no_examinations_today') : __('examination.staff.no_examinations') )" />
            @endif
        </div>

        @if ($examinations->hasPages())
            <nav class="hidden flex-none items-center justify-between gap-3 border-t border-gray-200 p-3 text-sm lg:flex" aria-label="{{ __('examination.staff.list_title') }}">
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
        <nav data-mobile-examination-pagination hidden class="flex flex-none items-center justify-between gap-3 border-t border-gray-200 p-3 text-sm lg:hidden" aria-label="{{ __('examination.staff.list_title') }}">
            <button type="button" data-mobile-examination-previous class="font-semibold underline disabled:cursor-not-allowed disabled:text-gray-400 disabled:no-underline">{{ __('examination.staff.previous') }}</button>
            <span data-mobile-examination-page></span>
            <button type="button" data-mobile-examination-next class="font-semibold underline disabled:cursor-not-allowed disabled:text-gray-400 disabled:no-underline">{{ __('examination.staff.next') }}</button>
        </nav>
    </aside>

    <section class="flex min-h-0 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white">
        @if ($selectedExamination)
            <div class="flex min-h-0 flex-1 flex-col p-4 lg:p-5">
                <div class="flex min-h-0 flex-none flex-col lg:max-h-[42%] lg:overflow-y-auto">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('examination.staff.detail_title') }}</p>
                        <h2 class="mt-1 break-all text-2xl font-bold text-gray-950">{{ $selectedExamination->submission_no }}</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ $selectedExamination->submitted_at->copy()->setTimezone(config('zb-examine.business_timezone'))->format('d/m/Y H:i') }}</p>
                    </div>
                    @can('delete', $selectedExamination)
                        <button type="button" data-delete-trigger class="rounded-md bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-800 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-red-900">
                            {{ __('examination.staff.delete') }}
                        </button>
                    @endcan
                    <a href="{{ route('examinations.index', array_filter(['search' => $search !== '' ? $search : null, 'today' => $today ? '1' : '0', 'page' => $examinations->currentPage() > 1 ? $examinations->currentPage() : null], static fn ($value): bool => $value !== null)) }}" class="font-semibold underline lg:hidden">{{ __('examination.staff.back_to_list') }}</a>
                </div>

                <div id="examination-details" class="mt-3 grid flex-none scroll-mt-4 gap-2 lg:grid-cols-2">
                    <section class="rounded-lg border border-gray-200 p-3">
                        <h3 class="border-b border-gray-200 pb-1 text-base font-semibold">{{ __('examination.sections.submission') }}</h3>
                        <dl class="mt-2 space-y-1 text-sm">
                            <div><dt class="text-gray-600">{{ __('examination.fields.submission_number') }}</dt><dd class="break-all font-semibold">{{ $selectedExamination->submission_no }}</dd></div>
                            <div><dt class="text-gray-600">{{ __('examination.staff.submitted_at') }}</dt><dd>{{ $selectedExamination->submitted_at->copy()->setTimezone(config('zb-examine.business_timezone'))->format('d/m/Y H:i') }}</dd></div>
                        </dl>
                    </section>

                    <section class="rounded-lg border border-gray-200 p-3">
                        <h3 class="border-b border-gray-200 pb-1 text-base font-semibold">{{ __('examination.sections.agent_information') }}</h3>
                        <dl class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                            @foreach ([
                                'agent_name' => __('examination.fields.agent_name'),
                                'agent_phone' => __('examination.fields.agent_phone'),
                                'agent_code' => __('examination.fields.agent_code'),
                                'agent_company_name' => __('examination.fields.agent_company_name'),
                                'agent_station_code' => __('examination.fields.agent_station_code'),
                            ] as $field => $label)
                                <div><dt class="text-gray-600">{{ $label }}</dt><dd>{{ $selectedExamination->{$field} ?: '—' }}</dd></div>
                            @endforeach
                        </dl>
                    </section>

                    <section class="rounded-lg border border-gray-200 p-3 lg:col-span-2">
                        <h3 class="border-b border-gray-200 pb-1 text-base font-semibold">{{ __('examination.sections.examination_information') }}</h3>
                        <dl class="mt-2 grid gap-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ([
                                'location' => __('examination.fields.location'),
                                'container_status' => __('examination.fields.container_status'),
                                'attending_officer_type' => __('examination.fields.attending_officer_type'),
                            ] as $field => $label)
                                <div><dt class="text-gray-600">{{ $label }}</dt><dd>{{ $selectedExamination->{$field}->label() }}</dd></div>
                            @endforeach
                            <div><dt class="text-gray-600">{{ __('examination.fields.form_type') }}</dt><dd>{{ $selectedExamination->form_type->label() }}{{ $selectedExamination->form_type->value === 'other' && $selectedExamination->form_type_other ? ' — '.$selectedExamination->form_type_other : '' }}</dd></div>
                            <div><dt class="text-gray-600">{{ __('examination.fields.reason') }}</dt><dd>{{ $selectedExamination->reason ? $selectedExamination->reason->label().($selectedExamination->reason->value === 'other' && $selectedExamination->reason_other ? ' — '.$selectedExamination->reason_other : '') : __('examination.reason_placeholder') }}</dd></div>
                        </dl>
                    </section>

                    <section class="rounded-lg border border-gray-200 p-3 lg:col-span-2">
                        <h3 class="border-b border-gray-200 pb-1 text-base font-semibold">{{ __('examination.staff.customs_forms') }}</h3>
                        @if ($selectedExamination->customsFormNumbers->isEmpty())
                            <p class="mt-2 text-sm text-gray-600">{{ __('examination.staff.no_customs_forms') }}</p>
                        @else
                            <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm">
                                @foreach ($selectedExamination->customsFormNumbers as $formNumber)
                                    <li>{{ $formNumber->number }}</li>
                                @endforeach
                            </ol>
                        @endif
                    </section>
                </div>
                </div>

                <section class="mt-3 flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-gray-200 p-3">
                    <h3 class="flex-none border-b border-gray-200 pb-2 text-lg font-semibold">{{ __('examination.staff.evidence') }}</h3>
                    @if ($selectedExamination->photos->isEmpty())
                        <p class="mt-3 text-sm text-gray-600">{{ __('examination.staff.no_photos') }}</p>
                    @else
                        <div class="mt-3 min-h-0 flex-1 overflow-y-auto">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                @foreach ($selectedExamination->photos as $photo)
                                    <a href="{{ route('examinations.photos.preview', [$selectedExamination, $photo]) }}" target="_blank" rel="noopener noreferrer" class="block aspect-[4/3] overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                                        <img src="{{ route('examinations.photos.preview', [$selectedExamination, $photo]) }}" alt="{{ __('examination.staff.evidence').' '.($loop->iteration) }}" class="h-full w-full object-contain" loading="lazy">
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </section>
            </div>

            @can('delete', $selectedExamination)
                <div id="delete-examination-modal" data-delete-modal hidden role="dialog" aria-modal="true" aria-labelledby="delete-examination-title" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4">
                    <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                        <h2 id="delete-examination-title" class="text-xl font-bold">{{ __('examination.staff.delete_title') }}</h2>
                        <p class="mt-4 text-sm text-gray-700">{{ __('examination.fields.submission_number') }}: <strong>{{ $selectedExamination->submission_no }}</strong></p>
                        <p class="mt-2 text-sm text-gray-700">{{ __('examination.staff.delete_confirmation') }}</p>
                        <div class="mt-6 flex justify-end gap-3">
                            <button type="button" data-delete-cancel class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 focus:outline focus:ring-2 focus:ring-gray-900">{{ __('examination.staff.cancel') }}</button>
                            <form method="POST" action="{{ route('examinations.destroy', $selectedExamination) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" data-delete-confirm class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white focus:outline focus:ring-2 focus:ring-red-900">{{ __('examination.staff.delete') }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            @endcan
        @else
            <div class="flex flex-1 items-center justify-center p-8 text-center text-gray-600">
                {{ __('examination.staff.select_examination') }}
            </div>
        @endif
    </section>
</div>
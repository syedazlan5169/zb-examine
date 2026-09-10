<div class="flex min-h-[calc(100vh-9rem)] flex-col gap-4 lg:h-[calc(100vh-9rem)] lg:grid lg:grid-cols-[minmax(280px,25%)_minmax(0,1fr)] lg:gap-5">
    <aside class="flex min-h-0 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white">
        <div class="flex-none border-b border-gray-200 p-4">
            <h1 class="text-xl font-bold">{{ __('examination.staff.list_title') }}</h1>
            <form method="GET" action="{{ route('examinations.index') }}" class="mt-4 flex gap-2">
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

        <div class="min-h-0 flex-1 overflow-y-auto">
            @if ($examinations->count() > 0)
                <div class="divide-y divide-gray-200">
                    @foreach ($examinations as $sidebarExamination)
                        @php
                            $sidebarQuery = array_filter([
                                'search' => $search !== '' ? $search : null,
                                'page' => $examinations->currentPage() > 1 ? $examinations->currentPage() : null,
                            ], static fn ($value): bool => $value !== null);
                            $isSelected = $selectedExamination?->is($sidebarExamination) ?? false;
                        @endphp
                        <a
                            href="{{ route('examinations.show', [$sidebarExamination, ...$sidebarQuery]) }}"
                            @if ($isSelected) aria-current="page" data-selected="true" @endif
                            class="block px-4 py-4 transition hover:bg-gray-50 {{ $isSelected ? 'border-l-4 border-gray-900 bg-gray-100 pl-3' : '' }}"
                        >
                            <time datetime="{{ $sidebarExamination->submitted_at->toIso8601String() }}" class="block text-xs text-gray-600">
                                {{ $sidebarExamination->submitted_at->copy()->setTimezone(config('zb-examine.business_timezone'))->format('d/m/Y H:i') }}
                            </time>
                            <span class="mt-1 block font-semibold">{{ $sidebarExamination->submission_no }}</span>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="p-5 text-sm text-gray-600">
                    {{ $search !== '' ? __('examination.staff.no_search_results') : __('examination.staff.no_examinations') }}
                </p>
            @endif
        </div>

        @if ($examinations->hasPages())
            <nav class="flex flex-none items-center justify-between gap-3 border-t border-gray-200 p-3 text-sm" aria-label="{{ __('examination.staff.list_title') }}">
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
    </aside>

    <section class="flex min-h-0 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white">
        @if ($selectedExamination)
            <div class="flex min-h-0 flex-1 flex-col p-4 lg:p-5">
                <div class="flex min-h-0 flex-none flex-col lg:max-h-[42%] lg:overflow-y-auto">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-2xl font-bold">{{ __('examination.staff.detail_title') }}</h2>
                    <a href="{{ route('examinations.index', array_filter(['search' => $search !== '' ? $search : null, 'page' => $examinations->currentPage() > 1 ? $examinations->currentPage() : null], static fn ($value): bool => $value !== null)) }}" class="font-semibold underline lg:hidden">{{ __('examination.staff.back_to_list') }}</a>
                </div>

                <div class="mt-3 grid flex-none gap-2 lg:grid-cols-2">
                    <section class="rounded-lg border border-gray-200 p-3">
                        <h3 class="border-b border-gray-200 pb-1 text-base font-semibold">{{ __('examination.sections.submission') }}</h3>
                        <dl class="mt-2 space-y-1 text-sm">
                            <div><dt class="text-gray-600">{{ __('examination.fields.submission_number') }}</dt><dd class="font-semibold">{{ $selectedExamination->submission_no }}</dd></div>
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
                                <div><dt class="text-gray-600">{{ $label }}</dt><dd>{{ __("examination.options.{$field}.".$selectedExamination->{$field}->value) }}</dd></div>
                            @endforeach
                            <div><dt class="text-gray-600">{{ __('examination.fields.form_type') }}</dt><dd>{{ __("examination.options.form_type.".$selectedExamination->form_type->value) }}{{ $selectedExamination->form_type->value === 'other' && $selectedExamination->form_type_other ? ' — '.$selectedExamination->form_type_other : '' }}</dd></div>
                            <div><dt class="text-gray-600">{{ __('examination.fields.reason') }}</dt><dd>{{ $selectedExamination->reason ? __("examination.options.reason.".$selectedExamination->reason->value).($selectedExamination->reason->value === 'other' && $selectedExamination->reason_other ? ' — '.$selectedExamination->reason_other : '') : __('examination.reason_placeholder') }}</dd></div>
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
        @else
            <div class="flex flex-1 items-center justify-center p-8 text-center text-gray-600">
                {{ __('examination.staff.select_examination') }}
            </div>
        @endif
    </section>
</div>
@props(['title' => null, 'description' => null])

<section {{ $attributes->class('rounded-lg border border-gray-200 bg-white p-4') }}>
    @if ($title)
        <h2 class="text-lg font-bold">{{ $title }}</h2>
    @endif
    @if ($description)
        <p class="mt-1 text-sm text-gray-600">{{ $description }}</p>
    @endif
    <div @class(['mt-4' => $title || $description])>
        {{ $slot }}
    </div>
</section>

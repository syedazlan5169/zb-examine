@props(['title', 'description' => null])

<div {{ $attributes->class('px-5 py-8 text-center text-sm text-gray-600') }}>
    <p class="font-semibold text-gray-900">{{ $title }}</p>
    @if ($description)
        <p class="mt-1">{{ $description }}</p>
    @endif
</div>

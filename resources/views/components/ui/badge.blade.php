@props(['variant' => 'neutral'])

@php
    $variants = [
        'neutral' => 'border-gray-200 bg-gray-50 text-gray-700',
        'success' => 'border-green-200 bg-green-50 text-green-800',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-800',
        'danger' => 'border-red-200 bg-red-50 text-red-800',
    ];
@endphp

<span {{ $attributes->class('inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold '.($variants[$variant] ?? $variants['neutral'])) }}>{{ $slot }}</span>

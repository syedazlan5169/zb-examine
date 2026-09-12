@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
])

@php
    $variants = [
        'primary' => 'bg-gray-900 text-white hover:bg-gray-800',
        'secondary' => 'border border-gray-300 bg-white text-gray-900 hover:bg-gray-50',
        'danger' => 'bg-red-700 text-white hover:bg-red-800',
        'ghost' => 'text-gray-700 hover:bg-gray-100',
    ];
    $classes = 'inline-flex min-h-11 items-center justify-center rounded-md px-4 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-gray-900 disabled:cursor-not-allowed disabled:opacity-60 '.($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif

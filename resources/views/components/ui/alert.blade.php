@props(['type' => 'information'])

@php
    $styles = [
        'success' => 'border-green-300 bg-green-50 text-green-800',
        'error' => 'border-red-300 bg-red-50 text-red-800',
        'warning' => 'border-amber-300 bg-amber-50 text-amber-800',
        'information' => 'border-blue-300 bg-blue-50 text-blue-800',
    ];
@endphp

<div role="{{ $type === 'error' ? 'alert' : 'status' }}" {{ $attributes->class('rounded-md border px-3 py-2 text-sm font-medium '.($styles[$type] ?? $styles['information'])) }}>
    {{ $slot }}
</div>

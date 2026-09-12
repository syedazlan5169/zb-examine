@props(['name', 'label', 'checked' => false])

<label for="{{ $name }}" class="inline-flex min-h-11 items-center gap-2 text-sm text-gray-700">
    <input
        type="checkbox"
        name="{{ $name }}"
        id="{{ $name }}"
        value="1"
        @checked(old($name, $checked))
        {{ $attributes->class('h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-2 focus:ring-blue-500') }}
    >
    <span>{{ $label }}</span>
</label>

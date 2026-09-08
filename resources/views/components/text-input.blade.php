@props([
    'name',
    'label',
    'type' => 'text',
    'autocomplete' => null,
    'inputmode' => null,
    'required' => true,
])

<div>
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium text-gray-900">
        {{ $label }}
        @if ($required)
            <span aria-hidden="true" class="text-red-600">*</span>
        @endif
    </label>

    <input
        type="{{ $type }}"
        name="{{ $name }}"
        id="{{ $name }}"
        value="{{ old($name) }}"
        @if ($required) required @endif
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if ($inputmode) inputmode="{{ $inputmode }}" @endif
        @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
        {{ $attributes->class([
            'w-full rounded-lg border-2 px-4 py-3 text-base focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900',
            'border-red-600' => $errors->has($name),
            'border-gray-300' => ! $errors->has($name),
        ]) }}
    >

    @error($name)
        <p id="{{ $name }}-error" class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p>
    @enderror
</div>

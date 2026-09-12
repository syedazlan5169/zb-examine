@props([
    'name',
    'label',
    'options' => [],
    'value' => null,
    'required' => false,
])

<div>
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium text-gray-900">
        {{ $label }}
        @if ($required)
            <span aria-hidden="true" class="text-red-600">*</span>
        @endif
    </label>
    <select
        name="{{ $name }}"
        id="{{ $name }}"
        @required($required)
        @if ($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
        {{ $attributes->class([
            'min-h-11 w-full rounded-md border-2 px-3 text-base focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-gray-900',
            'border-red-600' => $errors->has($name),
            'border-gray-300' => ! $errors->has($name),
        ]) }}
    >
        {{ $slot }}
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected(old($name, $value) == $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
    @error($name)
        <p id="{{ $name }}-error" class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p>
    @enderror
</div>

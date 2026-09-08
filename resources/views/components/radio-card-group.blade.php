@props([
    'name',
    'enum',
    'translationPrefix',
    'legend',
    'required' => true,
])

@php
    $options = collect($enum::cases())->mapWithKeys(
        fn ($case) => [$case->value => __("{$translationPrefix}.{$case->value}")]
    );
@endphp

<fieldset>
    <legend class="mb-2 block text-sm font-medium text-gray-900">
        {{ $legend }}
        @if ($required)
            <span aria-hidden="true" class="text-red-600">*</span>
        @endif
    </legend>

    <div class="grid grid-cols-1 gap-3 {{ $options->count() > 2 ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
        @foreach ($options as $value => $label)
            <label
                class="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg border-2 border-gray-300 px-4 py-3 text-base has-[:checked]:border-gray-900 has-[:checked]:bg-gray-900 has-[:checked]:text-white has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-gray-900"
            >
                <input
                    type="radio"
                    name="{{ $name }}"
                    value="{{ $value }}"
                    class="h-5 w-5 shrink-0"
                    {{ old($name) === $value ? 'checked' : '' }}
                    @if ($required) required @endif
                >
                <span>{{ $label }}</span>
            </label>
        @endforeach
    </div>

    @error($name)
        <p class="mt-1 text-sm font-medium text-red-700">{{ $message }}</p>
    @enderror
</fieldset>

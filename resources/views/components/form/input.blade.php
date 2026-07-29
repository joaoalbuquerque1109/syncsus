@props([
    'name',
    'label',
    'type' => 'text',
    'required' => false,
    'autocomplete' => null,
])

@php($inputId = $attributes->get('id', $name))
@php($hasError = $errors->has($name))

<div>
    <label class="field-label" for="{{ $inputId }}">
        {{ $label }}
        @if($required)
            <span class="text-red-600" aria-hidden="true">*</span>
            <span class="sr-only">(obrigatório)</span>
        @endif
    </label>
    <input
        id="{{ $inputId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if($required) required @endif
        @if($hasError) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif
        {{ $attributes->except(['id'])->class(['field-control', 'field-control-invalid' => $hasError]) }}
    >
    @error($name)
        <p id="{{ $inputId }}-error" class="mt-1.5 text-sm font-medium text-red-700">{{ $message }}</p>
    @enderror
</div>

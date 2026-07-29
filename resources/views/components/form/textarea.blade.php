@props(['name', 'label', 'required' => false, 'rows' => 4])

@php($inputId = $attributes->get('id', $name))
@php($hasError = $errors->has($name))

<div>
    <label class="field-label" for="{{ $inputId }}">
        {{ $label }} @if($required)<span class="text-red-600">*</span>@endif
    </label>
    <textarea
        id="{{ $inputId }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        @if($required) required @endif
        @if($hasError) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif
        {{ $attributes->except(['id'])->class(['field-control', 'field-control-invalid' => $hasError]) }}
    >{{ $slot }}</textarea>
    @error($name)<p id="{{ $inputId }}-error" class="mt-1.5 text-sm font-medium text-red-700">{{ $message }}</p>@enderror
</div>

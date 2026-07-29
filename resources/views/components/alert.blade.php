@props(['type' => 'info'])

@php
    $isError = in_array($type, ['error', 'danger'], true);
    $classes = match ($type) {
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
        'error', 'danger' => 'border-red-200 bg-red-50 text-red-900',
        default => 'border-blue-200 bg-blue-50 text-blue-900',
    };
@endphp

<div
    role="{{ $isError ? 'alert' : 'status' }}"
    @if($isError || $type === 'warning') data-minimum-visible-ms="5000" @endif
    {{ $attributes->class("rounded-lg border px-4 py-3 text-sm {$classes}") }}
>
    {{ $slot }}
</div>

@props(['type' => 'info'])

@php
    $classes = match ($type) {
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
        'error' => 'border-red-200 bg-red-50 text-red-900',
        default => 'border-blue-200 bg-blue-50 text-blue-900',
    };
@endphp

<div role="{{ $type === 'error' ? 'alert' : 'status' }}" {{ $attributes->class("rounded-lg border px-4 py-3 text-sm {$classes}") }}>
    {{ $slot }}
</div>

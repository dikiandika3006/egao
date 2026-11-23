@props(['size' => 'md'])

<div {{ $attributes->class('flex items-center gap-2.5 leading-none select-none') }}>
    <img src="{{ asset('images/logo.png') }}" alt="" @class([
        'shrink-0 object-contain',
        'size-12' => $size === 'md',
        'size-10' => $size === 'sm',
        'size-16' => $size === 'lg',
    ])>
    <img src="{{ asset('images/logo-wordmark.png') }}" alt="Egao Dental Care" @class([
        'w-auto object-contain',
        'h-10' => $size === 'md',
        'h-8' => $size === 'sm',
        'h-13' => $size === 'lg',
    ])>
</div>

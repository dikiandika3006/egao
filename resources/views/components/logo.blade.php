@props(['size' => 'md'])

<div {{ $attributes->class('flex items-center gap-2.5 leading-none select-none') }}>
    <img src="{{ asset('images/logo.jpg') }}" alt="Logo Egao Dental Care" @class([
        'shrink-0 rounded-full object-contain',
        'size-12' => $size === 'md',
        'size-10' => $size === 'sm',
        'size-16' => $size === 'lg',
    ])>
    <div>
        <div @class([
            'font-bold tracking-tight text-brand-600',
            'text-[34px]' => $size === 'md',
            'text-[30px]' => $size === 'sm',
            'text-5xl' => $size === 'lg',
        ])>egao</div>
        <div @class([
            'mt-1.5 font-medium text-brand-700 uppercase',
            'text-[10px] tracking-[0.3em]' => $size !== 'sm',
            'text-[9px] tracking-[0.08em]' => $size === 'sm',
        ])>Dental Care</div>
    </div>
</div>

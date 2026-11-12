@props(['title', 'close' => 'closeForm', 'wide' => false])
<div class="fixed inset-0 z-50 flex items-end justify-center bg-brand-800/40 sm:items-center sm:p-4" wire:keydown.escape.window="{{ $close }}" role="dialog" aria-modal="true">
    <div @class([
        'card max-h-[92dvh] w-full overflow-y-auto rounded-b-none p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] sm:rounded-2xl lg:p-6',
        'sm:max-w-3xl' => $wide,
        'sm:max-w-lg' => ! $wide,
    ])>
        <div class="flex items-start justify-between gap-4">
            <h2 class="text-lg font-semibold">{{ $title }}</h2>
            <button type="button" wire:click="{{ $close }}" class="btn-ghost min-h-9 px-3" aria-label="Tutup">✕</button>
        </div>
        <div class="mt-5">{{ $slot }}</div>
    </div>
</div>

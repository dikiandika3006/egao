@props(['title', 'subtitle' => null])
<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0">
        <h1 class="text-[26px] font-semibold tracking-tight lg:text-[32px]">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-2 max-w-2xl text-sm text-muted lg:text-[15px]">{{ $subtitle }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</div>

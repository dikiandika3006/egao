<div class="mx-auto max-w-md">
    <h1 class="text-[26px] font-semibold tracking-tight">Lainnya</h1>
    @foreach (\App\Support\Navigation::GROUPS + ['bantuan' => 'Bantuan'] as $group => $heading)
        @if ($groupItems = array_filter($items, fn ($item) => $item['group'] === $group))
            <p class="eyebrow mt-6 px-1 text-[10px]">{{ $heading }}</p>
            <ul class="card mt-2 divide-y divide-line">
                @foreach ($groupItems as $key => $item)
                    <li>
                        <a href="{{ \App\Support\Navigation::url($item) }}" wire:navigate class="flex min-h-14 items-center gap-3 px-4 text-[15px] font-medium">
                            <x-nav-icon :name="$key" />
                            <span class="flex-1">{{ $item['label'] }}</span>
                            <span class="text-muted" aria-hidden="true">→</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    @endforeach

    <form method="POST" action="{{ route('logout') }}" class="card mt-4">
        @csrf
        <button class="flex min-h-14 w-full items-center gap-3 px-4 text-[15px] font-medium text-rose-ink">
            <x-nav-icon name="keluar" />
            <span class="flex-1 text-left">Keluar</span>
        </button>
    </form>
</div>

<div class="mx-auto max-w-md">
    <h1 class="text-[26px] font-semibold tracking-tight">Lainnya</h1>
    <ul class="card mt-6 divide-y divide-line">
        @foreach ($items as $key => $item)
            <li>
                <a href="{{ route($item['route']) }}" wire:navigate class="flex min-h-14 items-center gap-3 px-4 text-[15px] font-medium">
                    <x-nav-icon :name="$key" />
                    <span class="flex-1">{{ $item['label'] }}</span>
                    <span class="text-muted" aria-hidden="true">→</span>
                </a>
            </li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('logout') }}" class="card mt-4">
        @csrf
        <button class="flex min-h-14 w-full items-center gap-3 px-4 text-[15px] font-medium text-rose-ink">
            <x-nav-icon name="keluar" />
            <span class="flex-1 text-left">Keluar</span>
        </button>
    </form>
</div>

{{-- Bonus & potongan bulan ini untuk baris yang dibuka (FR-GJI-04). --}}
<div wire:click.stop>
    <p class="text-xs font-semibold text-muted uppercase">Bonus & potongan</p>
    <ul class="mt-2 space-y-1.5 text-[13px]">
        @forelse ($adjustments as $a)
            <li wire:key="adj-{{ $a->id }}" class="flex items-center justify-between gap-2">
                <span>
                    <span @class(['font-medium', 'text-brand-600' => $a->type !== 'potongan', 'text-rose-ink' => $a->type === 'potongan'])>{{ $a->type === 'potongan' ? '−' : '+' }}@rupiah($a->amount)</span>
                    <span class="text-muted">{{ $a->typeLabel() }}{{ $a->note ? ' — '.$a->note : '' }}</span>
                </span>
                <button type="button" wire:click="removeAdjustment({{ $a->id }})" wire:confirm="Hapus {{ strtolower($a->typeLabel()) }} ini?" class="text-xs text-rose-ink hover:underline">Hapus</button>
            </li>
        @empty
            <li class="text-muted">Tanpa bonus & potongan.</li>
        @endforelse
    </ul>

    <form wire:submit="addAdjustment" class="mt-3 grid grid-cols-[140px_1fr] gap-2">
        <select wire:model="adjustment.type" class="input min-h-10 text-[13px]">
            @foreach (\App\Models\PayrollAdjustment::TYPES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <x-money model="adjustment.amount" placeholder="Nominal" class="min-h-10 text-[13px]" />
        <input type="text" wire:model="adjustment.note" maxlength="255" placeholder="Keterangan, mis. kasbon" class="input col-span-2 min-h-10 text-[13px]">
        @error('adjustment.amount') <p class="col-span-2 text-xs text-rose-ink">{{ $message }}</p> @enderror
        <button type="submit" class="btn-soft col-span-2 min-h-10">Tambahkan</button>
    </form>
</div>

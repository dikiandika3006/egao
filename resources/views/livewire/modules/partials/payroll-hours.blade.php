{{-- Jam hadir & lembur bulan ini untuk baris yang dibuka (dasar UD per jam & lembur per jam). --}}
<form wire:submit="saveHours" wire:click.stop class="rounded-xl border border-line bg-white/70 p-3">
    <p class="text-xs font-semibold text-muted uppercase">Jam kerja bulan ini</p>
    <div @class(['mt-2 grid gap-2', 'grid-cols-2' => $showWork && $showOvertime, 'grid-cols-1' => ! ($showWork && $showOvertime)])>
        @if ($showWork)
            <div>
                <label class="label">Jam hadir</label>
                <input type="text" inputmode="decimal" wire:model="hours.work_hours" placeholder="0" class="input min-h-10 text-[13px]">
                <p class="mt-1 text-[11px] text-muted">× @rupiah($hourlyRate)/jam</p>
                @error('hours.work_hours') <p class="mt-1 text-xs text-rose-ink">{{ $message }}</p> @enderror
            </div>
        @endif
        @if ($showOvertime)
            <div>
                <label class="label">Jam lembur</label>
                <input type="text" inputmode="decimal" wire:model="hours.overtime_hours" placeholder="0" class="input min-h-10 text-[13px]">
                <p class="mt-1 text-[11px] text-muted">× @rupiah($overtimeRate)/jam</p>
                @error('hours.overtime_hours') <p class="mt-1 text-xs text-rose-ink">{{ $message }}</p> @enderror
            </div>
        @endif
    </div>
    <button type="submit" class="btn-soft mt-2 min-h-10 w-full">Simpan jam</button>
</form>

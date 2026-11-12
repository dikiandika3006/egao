{{-- Pilihan cabang di form saat topbar "Semua cabang". Pakai: <x-branch-pick :context="$context" /> --}}
@props(['context', 'label' => 'Cabang'])
@if ($context->isAll())
    <div {{ $attributes }}>
        <label class="label">{{ $label }}</label>
        <select wire:model.live="branchPick" class="input">
            <option value="">Pilih cabang…</option>
            @foreach ($context->options() as $b)
                <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
        </select>
        <x-error for="branchPick" />
    </div>
@endif

{{--
    Input nominal rupiah berformat ribuan (100.000). Nilai ke Livewire tetap angka bulat (atau null bila kosong).
    Pakai: <x-money model="pay.amount" />  ·  <x-money model="pay.cash" live />  ·  <x-money model="x" :prefix="false" />
--}}
@props(['model', 'live' => false, 'prefix' => 'Rp'])
<div class="relative" x-data="{
        v: $wire.entangle(@js($model), @js((bool) $live)),
        fmt(n) { return (n === null || n === '' || isNaN(n)) ? '' : Number(n).toLocaleString('id-ID') },
        set(e) {
            const digits = e.target.value.replace(/\D/g, '').slice(0, 13);
            this.v = digits === '' ? null : parseInt(digits, 10);
            e.target.value = this.fmt(this.v);
        },
    }">
    @if ($prefix)
        <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-sm text-muted">{{ $prefix }}</span>
    @endif
    <input type="text" inputmode="numeric" autocomplete="off" x-bind:value="fmt(v)" x-on:input="set($event)"
        {{ $attributes->class(['input tabular-nums', 'pl-11' => $prefix]) }}>
</div>

@php use App\Enums\VisitStatus; @endphp
@switch($visit->status)
    @case(VisitStatus::Menunggu)
        <button type="button" wire:click="callPatient({{ $visit->id }})" wire:loading.attr="disabled" class="link-arrow">Panggil <span aria-hidden="true">→</span></button>
        @break
    @case(VisitStatus::Dipanggil)
    @case(VisitStatus::Diperiksa)
        @if (app(\App\Support\BranchContext::class)->is(\App\Enums\Role::Dokter, \App\Enums\Role::Owner))
            <a href="{{ route('rekam-medis.periksa', $visit) }}" wire:navigate class="link-arrow">{{ $visit->status === VisitStatus::Dipanggil ? 'Periksa' : 'Lihat' }} <span aria-hidden="true">→</span></a>
        @else
            <span class="text-xs text-muted">Di ruang periksa</span>
        @endif
        @break
    @case(VisitStatus::MenungguPembayaran)
        <a href="{{ route('kasir') }}" wire:navigate class="link-arrow">Ke kasir <span aria-hidden="true">→</span></a>
        @break
    @default
        <span class="text-xs text-muted">{{ $visit->completed_at?->format('H.i') }}</span>
@endswitch

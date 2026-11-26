@php use App\Enums\VisitStatus; @endphp
@switch($visit->status)
    @case(VisitStatus::Menunggu)
    @case(VisitStatus::Dipanggil)
    @case(VisitStatus::Diperiksa)
        {{-- Yang memeriksa hanya dokter (Owner akses penuh); Admin/Asisten melihat status saja. --}}
        @if (app(\App\Support\BranchContext::class)->is(\App\Enums\Role::Dokter, \App\Enums\Role::Owner))
            <a href="{{ route('rekam-medis.periksa', $visit) }}" wire:navigate class="link-arrow">{{ $visit->status === VisitStatus::Diperiksa ? 'Lanjut periksa' : 'Periksa' }} <span aria-hidden="true">→</span></a>
        @else
            <span class="text-xs text-muted">{{ $visit->status === VisitStatus::Diperiksa ? 'Sedang diperiksa' : 'Menunggu' }}</span>
        @endif
        @break
    @case(VisitStatus::MenungguPembayaran)
        @if (\App\Support\Access::can('kasir', 'lihat'))
            <a href="{{ route('kasir') }}" wire:navigate class="link-arrow">Ke kasir <span aria-hidden="true">→</span></a>
        @else
            <span class="text-xs text-muted">Menunggu pembayaran</span>
        @endif
        @break
    @default
        <span class="text-xs text-muted">{{ $visit->completed_at?->format('H.i') }}</span>
@endswitch

@php use App\Enums\VisitStatus; @endphp
@switch($visit->status)
    @case(VisitStatus::Menunggu)
    @case(VisitStatus::Dipanggil)
    @case(VisitStatus::Diperiksa)
        {{-- Yang memeriksa: Dokter, Owner, atau Admin/Asisten yang diizinkan Rekam medis › Edit; lainnya melihat status saja. --}}
        @if (app(\App\Services\ExamService::class)->canExamine())
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

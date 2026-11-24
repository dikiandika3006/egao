<?php

namespace App\Support;

use App\Models\Procedure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saran tindakan dari diagnosis ICD-10 di layar Pemeriksaan.
 *
 * Dua sumber: (1) peta awalan kode ICD → kata kunci nama tindakan, (2) riwayat klinik: tindakan yang
 * paling sering ditagihkan bersama kode itu. Saran hanya membantu; dokter tetap memilih sendiri.
 */
class TreatmentSuggestions
{
    /** Awalan kode ICD-10 (paling spesifik menang lebih dulu) → kata kunci nama tindakan. */
    public const MAP = [
        'K03.6' => ['Scaling'],
        'K00.6' => ['Cabut gigi anak', 'Cabut Anak'],
        'K01' => ['Odontektomi', 'Exo Open', 'Cabut gigi'],
        'K02' => ['Tambal gigi', 'Tambal'],
        'K03' => ['Tambal gigi'],
        'K04' => ['Preparasi Saluran Akar', 'Pengisian Saluran Akar', 'Devital', 'Cabut gigi'],
        'K05' => ['Scaling', 'Kuretase', 'Gingivektomi'],
        'K06' => ['Gingivektomi', 'Scaling'],
        'K07' => ['Konsultasi', 'Pasang behel', 'Kontrol behel'],
        'K08' => ['Gigi tiruan', 'Gigi Palsu', 'Cabut gigi'],
        'K12' => ['Konsultasi'],
        'S02.5' => ['Spilinting', 'Tambal gigi'],
        'Z01.2' => ['Pemeriksaan rutin', 'Scaling', 'Topikal Fluoride'],
    ];

    public const LIMIT = 4;

    /** Minimal berapa kali tindakan ditagihkan bersama diagnosis itu agar ikut disarankan. */
    public const MIN_HISTORY = 2;

    /**
     * @param  list<string>  $codes  kode ICD-10 diagnosis kunjungan
     * @param  Collection<int, Procedure>  $options  tindakan yang bertarif di cabang kunjungan (keyBy id)
     * @param  list<int>  $exclude  tindakan yang sudah ada di daftar
     * @return Collection<int, Procedure>
     */
    public static function for(array $codes, Collection $options, array $exclude = []): Collection
    {
        if ($codes === [] || $options->isEmpty()) {
            return collect();
        }

        $ids = collect(static::fromHistory($codes, $options->keys()->all()))
            ->merge(static::fromMap($codes, $options));

        return $ids->unique()
            ->reject(fn ($id) => in_array($id, $exclude, true))
            ->take(static::LIMIT)
            ->map(fn ($id) => $options->get($id))
            ->filter()
            ->values();
    }

    /** @return list<int> */
    private static function fromMap(array $codes, Collection $options): array
    {
        $ids = [];
        foreach ($codes as $code) {
            $prefixes = array_filter(array_keys(self::MAP), fn ($p) => str_starts_with($code, $p));
            usort($prefixes, fn ($a, $b) => strlen($b) <=> strlen($a));

            foreach ($prefixes as $prefix) {
                foreach (self::MAP[$prefix] as $keyword) {
                    $match = $options->first(fn (Procedure $p) => mb_stripos($p->name, $keyword) === 0)
                        ?? $options->first(fn (Procedure $p) => mb_stripos($p->name, $keyword) !== false);
                    if ($match) {
                        $ids[] = $match->id;
                    }
                }
            }
        }

        return $ids;
    }

    /** @return list<int> */
    private static function fromHistory(array $codes, array $procedureIds): array
    {
        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('diagnoses', 'diagnoses.visit_id', '=', 'invoices.visit_id')
            ->whereIn('diagnoses.icd10_code', $codes)
            ->whereIn('invoice_items.procedure_id', $procedureIds)
            ->groupBy('invoice_items.procedure_id')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_HISTORY])
            ->orderByRaw('COUNT(*) DESC')
            ->limit(self::LIMIT)
            ->pluck('invoice_items.procedure_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}

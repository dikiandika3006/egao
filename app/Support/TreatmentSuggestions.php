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

    /** Kode kondisi peta gigi → saran diagnosis ICD-10 (Tambalan & Mahkota = kondisi lama, tanpa saran). */
    public const CONDITION_ICD = [
        'KAL' => 'K03.6',
        'KAR' => 'K02.9',
        'HLG' => 'K08.1',
        'IMP' => 'K01.1',
        'SIS' => 'K08.3',
        'GOY' => 'K05.3',
    ];

    public const LIMIT = 4;

    /**
     * Saran diagnosis dari temuan peta gigi.
     *
     * @param  list<string>  $conditionCodes  kode kondisi temuan kunjungan ini
     * @param  list<string>  $existing  kode ICD yang sudah dipilih
     * @return list<string>
     */
    public static function diagnosesFor(array $conditionCodes, array $existing = []): array
    {
        return collect($conditionCodes)
            ->map(fn ($c) => self::CONDITION_ICD[$c] ?? null)
            ->filter()->unique()
            ->reject(fn ($code) => in_array($code, $existing, true))
            ->values()->all();
    }

    /**
     * Gigi temuan yang relevan untuk tindakan saran: kondisinya menyarankan diagnosis (kategori ICD sama) yang
     * menghasilkan tindakan itu. Hanya diisi bila tepat satu gigi, supaya jumlah tagihan tidak salah.
     *
     * @param  list<array{tooth:int, code:?string}>  $findings  temuan + kode kondisinya
     * @param  list<string>  $codes  diagnosis kunjungan
     */
    public static function toothFor(int $procedureId, array $findings, array $codes, Collection $options): ?string
    {
        $teeth = [];
        foreach ($codes as $code) {
            if (! static::for([$code], $options)->contains('id', $procedureId)) {
                continue;
            }
            foreach ($findings as $f) {
                $icd = self::CONDITION_ICD[$f['code'] ?? ''] ?? null;
                if ($icd && substr($icd, 0, 3) === substr($code, 0, 3)) {
                    $teeth[$f['tooth']] = true;
                }
            }
        }

        return count($teeth) === 1 ? (string) array_key_first($teeth) : null;
    }

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

<?php

use App\Support\Format;

it('memformat rupiah', function () {
    expect(Format::rupiah(1250000))->toBe('Rp1.250.000')
        ->and(Format::rupiah(0))->toBe('Rp0')
        ->and(Format::rupiah(-475000))->toBe('−Rp475.000');
});

it('membuat terbilang untuk kwitansi', function (int $n, string $expected) {
    expect(Format::terbilang($n))->toBe($expected);
})->with([
    [11, 'sebelas'],
    [100, 'seratus'],
    [1000, 'seribu'],
    [300000, 'tiga ratus ribu'],
    [1250000, 'satu juta dua ratus lima puluh ribu'],
    [2115000, 'dua juta seratus lima belas ribu'],
]);

it('memformat jam dan juta', function () {
    expect(Format::jam('10:00:00'))->toBe('10.00')
        ->and(Format::juta(2100000))->toBe('2,1');
});

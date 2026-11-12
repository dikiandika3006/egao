<?php

namespace Database\Seeders;

use App\Models\Icd10Code;
use App\Models\ToothConditionType;
use Illuminate\Database\Seeder;

/**
 * Master klinis: kondisi gigi (warna odontogram) dan kode ICD-10 gigi & mulut (K00–K14)
 * plus beberapa kode Z umum. Daftar lengkap ICD-10 dapat diimpor kemudian (FR-SET-05).
 */
class ClinicalMasterSeeder extends Seeder
{
    public function run(int $clinicId = 1): void
    {
        $conditions = [
            ['KAL', 'Kalkulus', '#385d91'],
            ['KAR', 'Karies', '#c2410c'],
            ['TMB', 'Tambalan', '#535f91'],
            ['MHK', 'Mahkota', '#a16207'],
            ['HLG', 'Gigi hilang', '#6d776b'],
            ['IMP', 'Impaksi', '#9b3b4e'],
            ['SIS', 'Sisa akar', '#7c2d12'],
            ['GOY', 'Goyang', '#b45309'],
        ];

        foreach ($conditions as $i => [$code, $name, $color]) {
            ToothConditionType::updateOrCreate(
                ['clinic_id' => $clinicId, 'code' => $code],
                ['name' => $name, 'color' => $color, 'sort' => $i],
            );
        }

        $codes = [
            ['K00.0', 'Anodontia', 'Anodonsia'],
            ['K00.6', 'Disturbances in tooth eruption', 'Gangguan erupsi gigi'],
            ['K01.0', 'Embedded teeth', 'Gigi tertanam'],
            ['K01.1', 'Impacted teeth', 'Gigi impaksi'],
            ['K02.0', 'Caries limited to enamel', 'Karies email'],
            ['K02.1', 'Caries of dentine', 'Karies dentin'],
            ['K02.2', 'Caries of cementum', 'Karies sementum'],
            ['K02.3', 'Arrested dental caries', 'Karies terhenti'],
            ['K02.9', 'Dental caries, unspecified', 'Karies gigi'],
            ['K03.0', 'Excessive attrition of teeth', 'Atrisi gigi berlebih'],
            ['K03.1', 'Abrasion of teeth', 'Abrasi gigi'],
            ['K03.2', 'Erosion of teeth', 'Erosi gigi'],
            ['K03.6', 'Deposits [accretions] on teeth', 'Deposit pada gigi (karang gigi / kalkulus)'],
            ['K03.7', 'Posteruptive colour changes of dental hard tissues', 'Perubahan warna gigi'],
            ['K04.0', 'Pulpitis', 'Pulpitis'],
            ['K04.1', 'Necrosis of pulp', 'Nekrosis pulpa'],
            ['K04.4', 'Acute apical periodontitis of pulpal origin', 'Periodontitis apikal akut'],
            ['K04.5', 'Chronic apical periodontitis', 'Periodontitis apikal kronis'],
            ['K04.6', 'Periapical abscess with sinus', 'Abses periapikal dengan sinus'],
            ['K04.7', 'Periapical abscess without sinus', 'Abses periapikal tanpa sinus'],
            ['K05.0', 'Acute gingivitis', 'Gingivitis akut'],
            ['K05.1', 'Chronic gingivitis', 'Gingivitis kronis (gusi berdarah)'],
            ['K05.2', 'Acute periodontitis', 'Periodontitis akut'],
            ['K05.3', 'Chronic periodontitis', 'Periodontitis kronis'],
            ['K06.0', 'Gingival recession', 'Resesi gingiva'],
            ['K07.2', 'Anomalies of dental arch relationship', 'Maloklusi lengkung gigi'],
            ['K07.3', 'Anomalies of tooth position', 'Gigi berjejal / malposisi'],
            ['K08.1', 'Loss of teeth due to accident, extraction or local periodontal disease', 'Kehilangan gigi'],
            ['K08.3', 'Retained dental root', 'Sisa akar gigi'],
            ['K10.3', 'Alveolitis of jaws', 'Alveolitis (dry socket)'],
            ['K12.0', 'Recurrent oral aphthae', 'Sariawan berulang (stomatitis aftosa)'],
            ['K13.0', 'Diseases of lips', 'Penyakit bibir'],
            ['K14.0', 'Glossitis', 'Glositis'],
            ['Z01.2', 'Dental examination', 'Pemeriksaan gigi rutin'],
            ['Z46.3', 'Fitting and adjustment of dental prosthetic device', 'Pemasangan/penyesuaian gigi tiruan'],
            ['Z46.4', 'Fitting and adjustment of orthodontic device', 'Pemasangan/kontrol alat ortodonti (behel)'],
        ];

        foreach ($codes as [$code, $en, $id]) {
            Icd10Code::updateOrCreate(['code' => $code], ['name_en' => $en, 'name_id' => $id, 'is_dental' => true]);
        }
    }
}

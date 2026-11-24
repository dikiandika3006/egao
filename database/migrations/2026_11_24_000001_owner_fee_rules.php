<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan Owner 24 Nov 2026 (bagi hasil, gaji, kasir, stok bahan). Hanya menambah kolom/tabel; data lama tidak berubah.
 * - procedures: tarif terkunci, harga boleh di bawah standar (dengan alasan), fee asisten nominal.
 * - invoice_items: alasan harga turun, biaya bahan beku, penanda baris biaya admin.
 * - items: margin harga jual.
 * - procedure_materials: resep bahan per tindakan (takaran pecahan).
 * - item_usage_balances: saldo pemakaian pecahan per barang per cabang (stok dipotong tiap genap 1 satuan).
 * - doctor_shift_attendances: tanda hadir dokter per shift (dasar uang duduk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->boolean('price_locked')->default(false)->after('fee_minus_lab');
            $table->boolean('allow_lower_price')->default(false)->after('price_locked');
            $table->unsignedBigInteger('assistant_fee_amount')->nullable()->after('allow_lower_price');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('price_reason')->nullable()->after('standard_price');
            $table->unsignedBigInteger('material_cost')->default(0)->after('price_reason');
            $table->boolean('is_admin_fee')->default(false)->after('material_cost');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->decimal('margin_percent', 6, 2)->nullable()->after('sell_price');
        });

        Schema::create('procedure_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 10, 4); // satuan barang per 1 tindakan, mis. 0,05 tube
            $table->timestamps();

            $table->unique(['procedure_id', 'item_id']);
        });

        Schema::create('item_usage_balances', function (Blueprint $table) {
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->decimal('pending', 12, 4)->default(0); // pemakaian yang belum genap 1 satuan
            $table->primary(['item_id', 'branch_id']);
        });

        // Pemakaian bahan yang sudah diposting per tagihan (agar simpan ulang hanya memposting selisih).
        Schema::table('invoices', function (Blueprint $table) {
            $table->json('materials_posted')->nullable()->after('stock_posted_at');
        });

        Schema::create('doctor_shift_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->unsignedTinyInteger('shift'); // 1 | 2
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['doctor_id', 'date', 'shift']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_shift_attendances');
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('materials_posted'));
        Schema::dropIfExists('item_usage_balances');
        Schema::dropIfExists('procedure_materials');
        Schema::table('items', fn (Blueprint $t) => $t->dropColumn('margin_percent'));
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn(['price_reason', 'material_cost', 'is_admin_fee']));
        Schema::table('procedures', fn (Blueprint $t) => $t->dropColumn(['price_locked', 'allow_lower_price', 'assistant_fee_amount']));
    }
};

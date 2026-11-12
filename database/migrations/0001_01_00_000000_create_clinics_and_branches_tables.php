<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('responsible_doctor')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name');
            $table->string('city')->nullable();
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('color', 20)->default('batu'); // kunci warna UI: batu | malang
            $table->time('opens_at')->default('10:00');
            $table->time('closes_at')->default('17:00');
            $table->unsignedInteger('receipt_start_number')->default(1);
            $table->string('receipt_footer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['clinic_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
        Schema::dropIfExists('clinics');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('export_cadastros_imobiliarios', function (Blueprint $table) {
            $table->integer('IID_BCI')->primary();
            $table->integer('ISTATUS')->nullable();
            $table->integer('IID_DISTRITO')->nullable();
            $table->string('VSETOR', 2)->nullable();
            $table->string('VQUADRA', 5)->nullable();
            $table->string('VLOTE', 4)->nullable();
            $table->string('VUNIDADE', 3)->nullable();
            $table->string('VINSCANTERIOR', 20)->nullable();
            $table->integer('ICODLOGRADOURO')->nullable();
            $table->integer('INUMERO')->nullable();
            $table->string('VCOMPLEMENTO', 30)->nullable();
            $table->integer('ICODBAIRRO')->nullable();
            $table->integer('IID_CONTRIBUINTE')->nullable();
            $table->integer('IID_CONTRIBUINTEMORADOR')->nullable();
            $table->decimal('NAREALOTE', 15, 2)->nullable();
            $table->decimal('NAREAEDIFICACAO', 15, 2)->nullable();
            $table->decimal('NFRACAOIDEAL', 15, 2)->nullable();
            $table->integer('INUMPAVIMENTOS')->nullable();

            $table->boolean('synced')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_cadastros_imobiliarios');
    }
};

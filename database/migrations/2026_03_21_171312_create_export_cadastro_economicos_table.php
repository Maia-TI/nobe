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
        Schema::create('export_cadastro_economicos', function (Blueprint $table) {
            $table->bigInteger('IID_CADECONOMICO')->primary();
            $table->bigInteger('IID_CONTRIBUINTE')->index();
            $table->integer('ISITUACAO')->nullable();
            $table->string('VINSCMUNICIPAL', 15)->nullable();
            $table->string('VANOINSCMUNICIPAL', 4)->nullable();
            $table->string('VOBSERVACOES', 250)->nullable();
            $table->boolean('synced')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_cadastro_economicos');
    }
};

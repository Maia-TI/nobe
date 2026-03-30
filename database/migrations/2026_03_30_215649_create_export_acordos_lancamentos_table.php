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
        Schema::create('export_acordos_lancamentos', function (Blueprint $table) {
            $table->bigInteger('IID_LANCAMENTO')->primary();
            $table->bigInteger('IID_ACORDO')->index();
            $table->string('VANOEXERCICIO', 4)->nullable();
            $table->decimal('NVALIMPOSTOCALC', 15, 2)->nullable();
            $table->integer('STATUS')->nullable();
            $table->string('DESCRICAO', 250)->nullable();
            $table->boolean('synced')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_acordos_lancamentos');
    }
};

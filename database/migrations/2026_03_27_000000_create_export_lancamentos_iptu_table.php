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
        Schema::create('export_lancamentos_iptu', function (Blueprint $table) {
            $table->bigInteger('CODLANCAMENTO')->primary();
            $table->bigInteger('CODBCI')->index();
            $table->string('ANOEXERCICIO', 4)->nullable();
            
            $table->decimal('VVT', 15, 2)->nullable();
            $table->decimal('VVE', 15, 2)->nullable();
            $table->decimal('VVIMOVEL', 15, 2)->nullable();
            
            $table->decimal('TSU1', 15, 2)->nullable();
            $table->decimal('TSU2', 15, 2)->nullable();
            $table->decimal('TSU3', 15, 2)->nullable();
            
            $table->decimal('VALIPTU', 15, 2)->nullable();
            $table->decimal('VALIMPOSTO', 15, 2)->nullable();
            $table->decimal('ALIQUOTAIPTU', 15, 2)->nullable();
            
            $table->text('INFORMACOESCALCULO')->nullable();

            $table->boolean('synced')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_lancamentos_iptu');
    }
};

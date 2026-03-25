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
        Schema::create('export_quitacoes_dams', function (Blueprint $table) {
            $table->bigInteger('IIDENTDAM_MIGRACAO')->primary();
            $table->date('DDTPAGTO')->nullable();
            $table->decimal('NVALPAGO', 15, 3)->default(0);
            $table->integer('IID_BANCO')->nullable();
            $table->date('DDTCREDITO')->nullable();
            $table->string('VAGENCIACONTA', 20)->nullable();
            
            $table->boolean('synced')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_quitacoes_dams');
    }
};

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
        Schema::create('export_acordos', function (Blueprint $table) {
            $table->id();
            $table->integer('IID_ACORDO')->nullable();
            $table->date('DDTACORDO')->nullable();
            $table->integer('IID_CONTRIBUINTE')->nullable();
            $table->integer('IID_RECEITA')->nullable();
            $table->string('VDESCRICAO', 250)->nullable();
            $table->boolean('synced')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_acordos');
    }
};

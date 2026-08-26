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
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->longText('fields')->nullable();
            $table->string('page_url')->nullable();
            $table->foreignId('widget_id')->constrained('widgets')->cascadeOnDelete();
            $table->string('ip_address')->nullable();
            $table->boolean('is_from_mobile')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};

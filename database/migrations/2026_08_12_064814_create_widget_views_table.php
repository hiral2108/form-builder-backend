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
        Schema::create('widget_views', function (Blueprint $table) {
            $table->id();
            $table->integer('mobile_view')->default('0');
            $table->integer('desktop_view')->default('0');
            $table->integer('mobile_click')->default('0');
            $table->integer('desktop_click')->default('0');
            $table->dateTime('created_date')->nullable();
            $table->string('widget_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('widget_views');
    }
};

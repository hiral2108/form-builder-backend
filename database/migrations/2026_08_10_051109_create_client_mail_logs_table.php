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
        Schema::create('client_mail_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to_mail')->nullable();
            $table->longText('subject')->nullable();
            $table->longText('content')->nullable();
            $table->string('unique_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_mail_logs');
    }
};

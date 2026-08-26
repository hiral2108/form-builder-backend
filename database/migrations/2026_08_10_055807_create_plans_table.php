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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->float('month_price');
            $table->float('year_price');
            $table->float('avg_price');
            $table->integer('visitors');
            $table->string('upgraded_mail_title')->nullable();
            $table->longText('upgraded_mail_text')->nullable();
            $table->string('downgraded_mail_title')->nullable();
            $table->longText('downgraded_mail_text')->nullable();
            $table->string('limit_title')->nullable();
            $table->longText('limit_text')->nullable();
            $table->string('subscription_title')->nullable();
            $table->longText('subscription_text')->nullable();
            $table->tinyInteger('is_deleted')->default('0');
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};

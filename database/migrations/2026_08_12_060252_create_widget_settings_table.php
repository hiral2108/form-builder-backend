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
        Schema::create('widget_settings', function (Blueprint $table) {
            $table->id();
            $table->longText('form_field_setting')->nullable();
            $table->longText('form_style_setting')->nullable();
            $table->longText('display_rule_setting')->nullable();
            $table->longText('submission_setting')->nullable();
            $table->longText('time_delay_setting')->nullable();
            $table->longText('scroll_based_setting')->nullable();
            $table->longText('page_rule_setting')->nullable();
            $table->longText('date_time_setting')->nullable();
            $table->longText('day_hour_setting')->nullable();
            $table->longText('country_rule_setting')->nullable();
            $table->string('widget_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('widget_settings');
    }
};

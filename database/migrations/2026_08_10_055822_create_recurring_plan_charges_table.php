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
        Schema::create('recurring_plan_charges', function (Blueprint $table) {
            $table->id();
            $table->integer('store');
            $table->bigInteger('charge_id');
            $table->integer('plan_id');
            $table->string('api_client_id');
            $table->string('plan_type');
            $table->integer('price');
            $table->string('status');
            $table->text('return_url');
            $table->string('billing_on')->nullable();
            $table->string('created_at');
            $table->string('updated_at');
            $table->tinyInteger('test')->nullable();
            $table->string('activated_on')->nullable();
            $table->string('trial_ends_on')->nullable();
            $table->string('cancelled_on')->nullable();
            $table->string('trial_days');
            $table->text('decorated_return_url');
            $table->text('confirmation_url');
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamp('created_date');
            $table->timestamp('updated_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_plan_charges');
    }
};

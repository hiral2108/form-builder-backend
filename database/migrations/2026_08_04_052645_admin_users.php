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
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('identifier')->nullable();
            $table->string('platform')->nullable();
            $table->date('next_reset_date')->nullable();
            $table->integer('is_sent_visitor_limit_mail')->nullable()->default(0);
            $table->integer('visitors')->default(0);
            $table->integer('plan_id')->default(0);
            $table->string('shop_owner_name')->nullable();
            $table->string('shop_url');
            $table->string('shop_hash')->nullable();
            $table->string('domain')->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->tinyInteger('mail_status')->default(0);
            $table->string('main_domain')->nullable();
            $table->string('shopify_plan')->nullable();
            $table->text('token');
            $table->string('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_closed')->default(0);
            $table->string('charge_id')->nullable()->default(0);
            $table->string('plan_type')->nullable();
            $table->char('plan_created_at')->nullable();
            $table->char('max_visitors_at')->nullable();
            $table->bigInteger('current_charges')->nullable()->default(0);
            $table->tinyInteger('show_maxvisit_hitpopup')->default(0);
            $table->date('review_notice_at')->nullable();
            $table->integer('show_review')->default(0);
            $table->integer('rating_star')->default(0);
            $table->string('host')->nullable();
            $table->date('last_login_at')->nullable();
            $table->string('plan_secret_key')->nullable();
            $table->tinyInteger('widget_mail_status')->default(0);
            $table->tinyInteger('is_expired')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};

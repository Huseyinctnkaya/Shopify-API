<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_shopify_connections', function (Blueprint $table) {
            $table->id();
            $table->string('shop');
            $table->string('shop_name');
            $table->text('access_token');
            $table->string('scope')->nullable();
            $table->string('claim_token', 40)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_shopify_connections');
    }
};

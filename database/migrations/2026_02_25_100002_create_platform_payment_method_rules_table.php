<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_payment_method_rules', function (Blueprint $table) {
            $table->id();
            $table->string('method_key', 50);
            $table->string('provider_key', 30);
            $table->string('market_key', 10)->nullable();
            $table->string('plan_key', 30)->nullable();
            $table->string('interval', 20)->nullable(); // monthly, yearly
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('constraints')->nullable();
            $table->timestamps();

            $table->unique(
                ['method_key', 'provider_key', 'market_key', 'plan_key', 'interval'],
                'payment_rule_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payment_method_rules');
    }
};

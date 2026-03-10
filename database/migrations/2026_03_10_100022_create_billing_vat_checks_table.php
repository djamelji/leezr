<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-310: VIES VAT validation cache.
 *
 * Stores results of EU VIES validation calls to avoid redundant SOAP requests.
 * Cache TTL: 7 days (expires_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_vat_checks', function (Blueprint $table) {
            $table->id();
            $table->string('vat_number', 30);
            $table->char('country_code', 2);
            $table->boolean('is_valid');
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->timestamp('checked_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['vat_number', 'country_code']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_vat_checks');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_rule_sets', function (Blueprint $table) {
            $table->id();
            $table->string('market_key', 10);
            $table->string('domain', 50);
            $table->string('rule_key', 100);
            $table->json('value');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('source', 30); // law, convention, custom
            $table->string('reference')->nullable(); // legal reference text
            $table->timestamps();

            $table->unique(['market_key', 'domain', 'rule_key', 'effective_from'], 'mrs_unique');
            $table->foreign('market_key')->references('key')->on('markets')->cascadeOnDelete();
            $table->index(['market_key', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_rule_sets');
    }
};

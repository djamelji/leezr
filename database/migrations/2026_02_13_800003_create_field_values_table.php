<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_definition_id')->constrained()->cascadeOnDelete();
            $table->morphs('model');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(
                ['field_definition_id', 'model_type', 'model_id'],
                'field_val_def_model_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_values');
    }
};

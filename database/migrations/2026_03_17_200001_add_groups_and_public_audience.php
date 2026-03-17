<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentation_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('icon')->default('tabler-folder');
            $table->string('audience')->default('company'); // platform, company, public, both
            $table->integer('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->foreignId('created_by_platform_user_id')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['audience', 'is_published']);
        });

        Schema::create('documentation_search_logs', function (Blueprint $table) {
            $table->id();
            $table->string('query');
            $table->integer('results_count')->default(0);
            $table->string('audience'); // public, company, platform
            $table->string('user_type')->nullable(); // company_user, platform_admin
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['results_count', 'created_at']);
            $table->index('query');
        });

        // Add group_id FK to documentation_topics
        Schema::table('documentation_topics', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('icon')
                ->constrained('documentation_groups')->nullOnDelete();
        });

        // Activate core.documentation for all existing companies
        $this->activateModuleForExistingCompanies();
    }

    public function down(): void
    {
        Schema::table('documentation_topics', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
        });
        Schema::dropIfExists('documentation_search_logs');
        Schema::dropIfExists('documentation_groups');
    }

    private function activateModuleForExistingCompanies(): void
    {
        $companies = \App\Core\Models\Company::all();

        foreach ($companies as $company) {
            \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(
                [
                    'company_id' => $company->id,
                    'module_key' => 'core.documentation',
                ],
                [
                    'reason' => 'migration_default',
                    'enabled' => true,
                ],
            );
        }
    }
};

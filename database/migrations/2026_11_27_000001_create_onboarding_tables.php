<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-796 (L6) — dossier d'intégration (candidat sans compte) + traçabilité de collecte. Additif.
return new class extends Migration
{
    public function up(): void
    {
        // Prérequis C : finalité, base juridique DÉCLARÉE (jamais déduite), information fournie, retrait.
        Schema::table('person_field_disclosures', function (Blueprint $table) {
            if (! Schema::hasColumn('person_field_disclosures', 'purpose')) {
                $table->string('purpose', 60)->nullable()->after('source');
                $table->string('legal_basis', 40)->nullable()->after('purpose');       // déclarée par l'entreprise, non vérifiée par Leezr
                $table->string('notice_version', 40)->nullable()->after('legal_basis');
                $table->unsignedBigInteger('onboarding_invitation_id')->nullable()->after('notice_version');
                $table->timestamp('withdrawal_requested_at')->nullable()->after('onboarding_invitation_id');
                $table->string('withdrawal_reason', 200)->nullable()->after('withdrawal_requested_at');
            }
        });

        if (! Schema::hasTable('onboarding_invitations')) {
            Schema::create('onboarding_invitations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('employee_id');                    // fiche RH (préparatoire ou existante) — jamais une 2e
                $table->unsignedBigInteger('job_role_id')->nullable();
                $table->string('status', 30)->default('draft');
                $table->string('token_hash', 64)->nullable()->unique();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->string('candidate_email', 191)->nullable();            // canal de vérification distinct (code)
                $table->json('snapshot');                                       // éléments demandés (photographie versionnée)
                $table->unsignedInteger('snapshot_version')->default(1);
                $table->json('snapshot_history')->nullable();
                $table->string('purpose', 60);
                $table->string('legal_basis', 40)->nullable();
                $table->string('notice_version', 40)->nullable();
                $table->text('notice_text')->nullable();
                $table->unsignedSmallInteger('retention_days')->default(30);
                $table->string('code_hash', 64)->nullable();
                $table->timestamp('code_expires_at')->nullable();
                $table->unsignedTinyInteger('code_attempts')->default(0);
                $table->unsignedSmallInteger('open_attempts')->default(0);
                $table->text('hr_message')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('validated_at')->nullable();
                $table->unsignedBigInteger('validated_by')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('purged_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status'], 'onb_inv_company_status_idx');
                $table->index(['employee_id'], 'onb_inv_employee_idx');
            });
        }

        if (! Schema::hasTable('onboarding_sessions')) {
            Schema::create('onboarding_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('onboarding_invitation_id')->constrained('onboarding_invitations')->cascadeOnDelete();
                $table->string('session_hash', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('verified_at')->nullable();                  // code reçu sur un canal distinct
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('onboarding_values')) {
            Schema::create('onboarding_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('onboarding_invitation_id')->constrained('onboarding_invitations')->cascadeOnDelete();
                $table->foreignId('field_definition_id')->constrained('field_definitions')->cascadeOnDelete();
                $table->json('value')->nullable();
                $table->string('status', 20)->default('pending');               // pending | accepted | kept_existing | rejected
                $table->timestamp('decided_at')->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamps();

                $table->unique(['onboarding_invitation_id', 'field_definition_id'], 'onb_values_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_values');
        Schema::dropIfExists('onboarding_sessions');
        Schema::dropIfExists('onboarding_invitations');
        Schema::table('person_field_disclosures', function (Blueprint $table) {
            foreach (['purpose', 'legal_basis', 'notice_version', 'onboarding_invitation_id', 'withdrawal_requested_at', 'withdrawal_reason'] as $c) {
                if (Schema::hasColumn('person_field_disclosures', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};

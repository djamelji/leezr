<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-819 (Company 2.0) — Workforce Parrainage : an employee recommends a relative or shares an application
 * link ; the candidate consents and applies ; authorised HR review and convert a retained application into
 * the EXISTING hiring engine. Minimal data, expiring links (hashed tokens), configurable retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_referral_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('mode', 16)->default('both');            // recommend | share | both
            $t->json('job_role_ids')->nullable();                // postes ouverts au parrainage
            $t->boolean('notify_reviewers')->default(true);
            $t->string('reward_note', 1000)->nullable();         // information affichée, jamais un paiement
            $t->unsignedSmallInteger('retention_days')->default(180);
            $t->unsignedSmallInteger('link_days')->default(30);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('workforce_referrals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('referrer_user_id');
            $t->unsignedBigInteger('referrer_employee_id')->nullable();
            $t->foreignId('job_role_id')->nullable()->constrained('workforce_job_roles')->nullOnDelete();
            $t->string('channel', 16);                           // recommendation | link
            $t->string('status', 24);                            // link_shared | awaiting_consent | submitted | in_review | info_requested | accepted | declined | converted | withdrawn | expired
            $t->string('first_name', 100)->nullable();
            $t->string('last_name', 100)->nullable();
            $t->string('email', 191)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('referrer_note', 1000)->nullable();       // mot du parrain (visible des RH seulement)
            $t->string('candidate_message', 2000)->nullable();
            $t->timestamp('consent_at')->nullable();
            $t->string('consent_version', 16)->nullable();
            $t->string('token_hash', 64)->nullable()->unique();
            $t->timestamp('link_expires_at')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 2000)->nullable();         // interne RH, jamais montré au parrain
            $t->string('info_request', 2000)->nullable();        // complément demandé au candidat
            $t->unsignedBigInteger('onboarding_invitation_id')->nullable();
            $t->timestamp('purge_after')->nullable();
            $t->timestamp('anonymized_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'referrer_user_id']);
            $t->index(['company_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_referrals');
        Schema::dropIfExists('workforce_referral_settings');
    }
};

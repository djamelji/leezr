// @ts-check
// W-SIGNATURE-1 (ADR-651) — parcours NAVIGATEUR RÉEL de signature électronique.
// Aucun faux succès : la finalisation (completed + archivage + doc signed) est pilotée par la
// vraie action du signataire via son lien sécurisé. Le token est minté côté serveur (artisan),
// jamais extrait d'un email ni exposé par un endpoint de test en prod.
import { test, expect } from '@playwright/test'
import { execSync } from 'node:child_process'
import { writeFileSync } from 'node:fs'
import { loginCompany } from './helpers'

const REPO = '/Users/djamel/sites/leezr'
const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

/** Mint un document émis + une demande envoyée + un token signataire, retourne {token, requestId}. */
function mintSignatureLink() {
  const php = `<?php
use App\\Core\\Documents\\DocumentTemplate; use App\\Core\\Documents\\GeneratedDocument;
use App\\Modules\\Workforce\\Signature\\UseCases\\CreateSignatureRequestUseCase;
use App\\Modules\\Workforce\\Signature\\UseCases\\SendSignatureRequestUseCase;
use App\\Modules\\Workforce\\Signature\\Services\\SignatureTokenService;
use App\\Modules\\Workforce\\Signature\\Models\\SignatureSigner;
use Illuminate\\Support\\Facades\\Storage;
$cid=1; $uid=App\\Core\\Models\\User::where('email','owner@leezr.test')->first()->id;
$tpl=DocumentTemplate::create(['company_id'=>$cid,'code'=>'e2e-'.uniqid(),'name'=>'Attestation E2E','subject_type'=>'employee','body_html'=>'<p>x</p>','variables_schema'=>[],'version'=>1,'is_active'=>true]);
$p='documents/generated/'.$cid.'/2026/10/'.uniqid().'.pdf'; Storage::disk('local')->put($p,'%PDF-1.4 e2e');
$doc=GeneratedDocument::create(['company_id'=>$cid,'document_template_id'=>$tpl->id,'template_version'=>1,'subject_type'=>'employee','subject_id'=>1,'generated_by'=>$uid,'generated_at'=>now(),'file_path'=>$p,'file_size_bytes'=>12,'generation_snapshot'=>['x'=>1],'signature_status'=>'none']);
$req=app(CreateSignatureRequestUseCase::class)->execute($cid,$uid,$doc->id,[['role'=>'employee','name'=>'E2E Signer','email'=>'e2e@example.test','order'=>1]],null,'sequential',null);
app(SendSignatureRequestUseCase::class)->execute($cid,$uid,$req->id);
$s=SignatureSigner::where('signature_request_id',$req->id)->first();
$t=SignatureTokenService::generate(); $s->token_hash=$t['hash']; $s->token_expires_at=$req->expires_at; $s->status='invited'; $s->save();
echo 'RESULT='.$t['raw'].':'.$req->id.PHP_EOL;
`
  const file = `${SCRATCH}/mint-sig.php`
  writeFileSync(file, php)
  // --execute require : aucun '$' dans la commande shell (le PHP vit dans le fichier).
  const out = execSync(`php artisan tinker --execute="require '${file}';"`, { cwd: REPO }).toString()
  const m = out.match(/RESULT=([a-f0-9]+):(\d+)/)
  if (!m) throw new Error('Could not mint signature link: ' + out)

  return { token: m[1], requestId: Number(m[2]) }
}

test('full browser signing journey — no false success', async ({ page }) => {
  test.setTimeout(120000)
  const { token, requestId } = mintSignatureLink()

  // 1. L'entreprise voit la demande "En attente".
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/signature', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1500)
  await expect(page.locator('body')).toContainText('Attestation E2E')

  // 2. Le signataire ouvre son lien sécurisé et signe réellement.
  const signer = await page.context().newPage()
  await signer.goto('/sign/' + token, { waitUntil: 'domcontentloaded' })
  await signer.waitForTimeout(1500)
  await expect(signer.locator('body')).toContainText('E2E Signer')
  // Avertissement non certifié présent (pas de confusion avec une signature qualifiée).
  await expect(signer.locator('body')).toContainText(/non certifiée/i)
  await signer.getByRole('button', { name: /Signer le document/i }).click()
  // Finalisation réelle pilotée par l'action du signataire (empreinte + preuves vérifiées serveur).
  await expect(signer.locator('body')).toContainText(/Document signé/i, { timeout: 15000 })
  await signer.close()

  // 3. De retour entreprise : la demande est "Terminée" et le document signé est téléchargeable.
  await page.goto('/company/workforce/signature', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1500)
  await page.getByText('Attestation E2E').first().click()
  await page.waitForTimeout(1200)
  await expect(page.locator('.v-navigation-drawer')).toContainText(/Terminée/i)
  await expect(page.locator('.v-navigation-drawer')).toContainText(/Télécharger le document signé/i)
  // Rejet du rejeu + toutes les voies d'échec (expiré/révoqué/hors-séquence/double/preuve) : PHPUnit WorkforceSignatureTest.
})

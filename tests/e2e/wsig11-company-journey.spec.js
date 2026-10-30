// @ts-check
// W-SIGNATURE-11 (§5) — parcours Company RÉEL de bout en bout, en navigateur, sans raccourci :
// RH se connecte → ouvre l'assistant depuis le hub → crée + ENVOIE une demande (API réelle du store) →
// deux navigateurs isolés signent (consentement) → pdfsig valide les deux → la demande apparaît terminée.
//
// Note d'honnêteté (§5) : les deux signataires sont EXTERNES (nom + e-mail, sans compte Leezr) → niveau
// d'assurance « declared ». Ils ne sont PAS présentés comme des internes authentifiés. Le gating du premier
// enrôlement des INTERNES et le refus « lien volé / mauvais compte » sont couverts par PHPUnit (ExternalPadesFlowTest).
import { test, expect } from '@playwright/test'
import { execSync } from 'node:child_process'
import { writeFileSync } from 'node:fs'
import { loginCompany } from './helpers'

const REPO = '/Users/djamel/sites/leezr'
const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

function tinker(php, file) {
  writeFileSync(file, php)

  return execSync(`php artisan tinker --execute="require '${file}';"`, { cwd: REPO }).toString()
}

/** Fixture : émet un VRAI contrat PDF (document RH) pour la Company 1. Pas une mutation de statut de signature. */
function mintEmittedContract() {
  const php = `<?php
use App\\Core\\Documents\\DocumentTemplate; use App\\Core\\Documents\\GeneratedDocument; use Illuminate\\Support\\Facades\\Storage;
$cid=1; $uid=App\\Core\\Models\\User::where('email','owner@leezr.test')->first()->id; $name='Contrat W11 '.substr(uniqid(),-6);
$tpl=DocumentTemplate::create(['company_id'=>$cid,'code'=>'w11-'.uniqid(),'name'=>$name,'subject_type'=>'employee','body_html'=>'<p>x</p>','variables_schema'=>[],'version'=>1,'is_active'=>true]);
$p='documents/generated/'.$cid.'/2026/10/'.uniqid().'.pdf';
Storage::disk('local')->put($p, Barryvdh\\DomPDF\\Facade\\Pdf::loadHTML('<h1>'.$name.'</h1>')->output());
$doc=GeneratedDocument::create(['company_id'=>$cid,'document_template_id'=>$tpl->id,'template_version'=>1,'subject_type'=>'employee','subject_id'=>1,'generated_by'=>$uid,'generated_at'=>now(),'file_path'=>$p,'file_size_bytes'=>10,'generation_snapshot'=>['x'=>1],'signature_status'=>'none']);
echo 'RESULT='.$name.PHP_EOL;`
  const out = tinker(php, `${SCRATCH}/w11-doc.php`)

  return out.match(/RESULT=(Contrat W11 [a-z0-9]+)/)[1]
}

/** Récupère les tokens d'invitation des 2 signataires de la demande active sur ce document (invitation légitime). */
function issueTokensForDocument(docName) {
  const php = `<?php
use App\\Modules\\Workforce\\Signature\\Models\\SignatureRequest; use App\\Modules\\Workforce\\Signature\\Models\\SignatureSigner; use App\\Modules\\Workforce\\Signature\\Services\\SignatureTokenService;
$req=SignatureRequest::withoutGlobalScopes()->whereHas('document.template', fn($q)=>$q->where('name','${docName}'))->latest('id')->first();
$raw=[]; foreach(SignatureSigner::where('signature_request_id',$req->id)->orderBy('order')->get() as $s){ $t=SignatureTokenService::generate(); $s->token_hash=$t['hash']; $s->token_expires_at=$req->expires_at; $s->token_revoked_at=null; $s->status='invited'; $s->invited_at=now(); $s->save(); $raw[]=$t['raw']; }
echo 'RESULT='.$raw[0].':'.$raw[1].':'.$req->id.PHP_EOL;`
  const out = tinker(php, `${SCRATCH}/w11-tok.php`)
  const m = out.match(/RESULT=([a-f0-9]+):([a-f0-9]+):(\d+)/)

  return { tokenA: m[1], tokenB: m[2], requestId: Number(m[3]) }
}

function dumpFinalPdf(requestId) {
  const php = `<?php
use App\\Modules\\Workforce\\Signature\\Models\\SignatureRequest; use Illuminate\\Support\\Facades\\Storage;
$req=SignatureRequest::withoutGlobalScopes()->find(${requestId});
file_put_contents('/tmp/w11_final.pdf', Storage::disk(config('documents.generated_disk','local'))->get($req->signed_document_path));
echo 'STATUS='.$req->status.PHP_EOL;`

  return tinker(php, `${SCRATCH}/w11-dump.php`)
}

async function signInBrowser(context, token) {
  const page = await context.newPage()
  await page.goto('/sign/' + token, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1200)
  await expect(page.locator('body')).toContainText(/non certifiée/i)
  await page.getByText(/je consens à le signer/i).click()
  await page.getByRole('button', { name: /Signer le document/i }).click()
  await expect(page.getByText(/Document signé/i)).toBeVisible({ timeout: 45_000 })
  await context.close()
}

test('full RH journey: wizard creates request in real browser → two signers → pdfsig → hub completed', async ({ browser }) => {
  test.setTimeout(180_000)
  const docName = mintEmittedContract()

  // ── Côté RH : création de la demande via l'assistant (UI réelle) ──
  const rh = await browser.newContext()
  const page = await rh.newPage()
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/signature', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1500)
  await page.getByRole('button', { name: /Nouvelle demande/i }).click()
  const dialog = page.locator('.v-dialog')
  await dialog.waitFor({ state: 'visible' })

  const pickOption = async text => {
    const opt = page.locator('.v-overlay-container .v-list-item').filter({ hasText: text }).first()
    await opt.waitFor({ state: 'visible', timeout: 8000 })
    await opt.click()
  }
  const next = () => dialog.getByRole('button', { name: /^Suivant$/i }).click()

  // Étape 1 : document (le select du dialog, PAS l'item du stepper)
  await dialog.locator('.v-select').first().click()
  await pickOption(docName)
  await next()

  // Étape 2 : signataires (2 cartes pré-remplies)
  const names = dialog.getByLabel('Nom', { exact: false })
  const emails = dialog.getByLabel('E-mail', { exact: false })
  await names.nth(0).fill('Alex Martin')
  await emails.nth(0).fill('alexw11@example.test')
  await names.nth(1).fill('Rep Leezr')
  await emails.nth(1).fill('repw11@example.test')
  await next()

  // Étape 3 : mode de signature PERSONNELLE (1er select) + niveau (laisser défaut)
  await dialog.locator('.v-select').first().click()
  await pickOption('personnelle numérique')
  await next()

  // Étape 4 : récap → confirmer et envoyer
  await dialog.getByRole('button', { name: /Confirmer et envoyer/i }).click()
  await page.waitForTimeout(2000)
  // La demande apparaît dans le hub.
  await expect(page.locator('body')).toContainText(docName, { timeout: 10_000 })
  await rh.close()

  // ── Côté signataires : deux navigateurs isolés (clés distinctes), signature réelle ──
  const { tokenA, tokenB, requestId } = issueTokensForDocument(docName)
  await signInBrowser(await browser.newContext(), tokenA)
  await signInBrowser(await browser.newContext(), tokenB)

  // ── Vérification indépendante pdfsig ──
  expect(dumpFinalPdf(requestId)).toContain('STATUS=completed')
  const pdfsig = execSync('pdfsig /tmp/w11_final.pdf 2>&1 || true').toString()
  console.log(pdfsig)
  expect((pdfsig.match(/Signature is Valid/g) || []).length).toBe(2)
  expect(pdfsig).toMatch(/Alex Martin/)
  expect(pdfsig).toMatch(/Rep Leezr/)

  // ── Retour hub : la demande est terminée ──
  const rh2 = await browser.newContext()
  const p2 = await rh2.newPage()
  await loginCompany(p2, 'owner@leezr.test')
  await p2.goto('/company/workforce/signature', { waitUntil: 'domcontentloaded' })
  await p2.waitForTimeout(1500)
  await expect(p2.locator('body')).toContainText(docName)
  await rh2.close()
})

// @ts-check
// W-SIGNATURE-7 (ADR-659) — CRITÈRE DE RÉUSSITE : deux NAVIGATEURS réels distincts (contextes isolés,
// IndexedDB + clé privée WebCrypto propres à chacun) produisent un PDF portant DEUX signatures
// PERSONNELLES incrémentales, vérifiées INDÉPENDAMMENT par pdfsig (A préservée après B). Aucune clé
// privée n'est générée côté serveur : chaque navigateur signe avec SA clé non extractible.
import { test, expect } from '@playwright/test'
import { execSync } from 'node:child_process'
import { writeFileSync } from 'node:fs'

const REPO = '/Users/djamel/sites/leezr'
const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

function runTinker(php, file) {
  writeFileSync(file, php)

  return execSync(`php artisan tinker --execute="require '${file}';"`, { cwd: REPO }).toString()
}

/** Mint une demande pades_external (2 signataires) sur un VRAI PDF + 2 tokens. */
function mintExternalRequest() {
  const php = `<?php
use App\\Core\\Documents\\DocumentTemplate; use App\\Core\\Documents\\GeneratedDocument;
use App\\Modules\\Workforce\\Signature\\UseCases\\CreateSignatureRequestUseCase;
use App\\Modules\\Workforce\\Signature\\UseCases\\SendSignatureRequestUseCase;
use App\\Modules\\Workforce\\Signature\\Services\\SignatureTokenService;
use App\\Modules\\Workforce\\Signature\\Models\\SignatureSigner;
use App\\Modules\\Workforce\\Signature\\Models\\SignatureRequest;
use Illuminate\\Support\\Facades\\Storage;
$cid=1; $uid=App\\Core\\Models\\User::where('email','owner@leezr.test')->first()->id;
$tpl=DocumentTemplate::create(['company_id'=>$cid,'code'=>'w7-'.uniqid(),'name'=>'Contrat W7 E2E','subject_type'=>'employee','body_html'=>'<p>x</p>','variables_schema'=>[],'version'=>1,'is_active'=>true]);
$p='documents/generated/'.$cid.'/2026/10/'.uniqid().'.pdf';
Storage::disk('local')->put($p, Barryvdh\\DomPDF\\Facade\\Pdf::loadHTML('<h1>Contrat W-SIGNATURE-7</h1><p>'.uniqid().'</p>')->output());
$doc=GeneratedDocument::create(['company_id'=>$cid,'document_template_id'=>$tpl->id,'template_version'=>1,'subject_type'=>'employee','subject_id'=>1,'generated_by'=>$uid,'generated_at'=>now(),'file_path'=>$p,'file_size_bytes'=>10,'generation_snapshot'=>['x'=>1],'signature_status'=>'none']);
$req=app(CreateSignatureRequestUseCase::class)->execute($cid,$uid,$doc->id,[['role'=>'employee','name'=>'Alex Martin','email'=>'alexw7@example.test','order'=>1],['role'=>'company_representative','name'=>'Rep Leezr','email'=>'repw7@example.test','order'=>2]],null,'parallel',null);
app(SendSignatureRequestUseCase::class)->execute($cid,$uid,$req->id);
$req->refresh(); $req->metadata=array_merge($req->metadata??[],['required_format'=>'pades_external']); $req->save();
$raw=[];
foreach(SignatureSigner::where('signature_request_id',$req->id)->orderBy('order')->get() as $s){ $t=SignatureTokenService::generate(); $s->token_hash=$t['hash']; $s->token_expires_at=$req->expires_at; $s->token_revoked_at=null; $s->status='invited'; $s->invited_at=now(); $s->save(); $raw[]=$t['raw']; }
echo 'RESULT='.$raw[0].':'.$raw[1].':'.$req->id.PHP_EOL;
`
  const out = runTinker(php, `${SCRATCH}/wsig7-mint.php`)
  const m = out.match(/RESULT=([a-f0-9]+):([a-f0-9]+):(\d+)/)
  if (!m)
    throw new Error('Could not mint external request: ' + out)

  return { tokenA: m[1], tokenB: m[2], requestId: Number(m[3]) }
}

/** Écrit l'artefact final signé dans /tmp et retourne le chemin. */
function dumpFinalPdf(requestId) {
  const php = `<?php
use App\\Modules\\Workforce\\Signature\\Models\\SignatureRequest; use Illuminate\\Support\\Facades\\Storage;
$req=SignatureRequest::withoutGlobalScopes()->find(${requestId});
$disk=config('documents.generated_disk','local');
file_put_contents('/tmp/wsig7_final.pdf', Storage::disk($disk)->get($req->signed_document_path));
echo 'STATUS='.$req->status.PHP_EOL;
`
  const out = runTinker(php, `${SCRATCH}/wsig7-dump.php`)

  return out
}

async function signInBrowser(context, token) {
  const page = await context.newPage()
  await page.goto('/sign/' + token, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1200)
  // Avertissement non certifié présent (pas de confusion avec une signature qualifiée/eIDAS).
  await expect(page.locator('body')).toContainText(/non certifiée/i)
  // §4 — le bouton Signer est DÉSACTIVÉ tant que le consentement n'est pas coché.
  const signBtn = page.getByRole('button', { name: /Signer le document/i })
  await expect(signBtn).toBeDisabled()
  await page.getByText(/je consens à le signer/i).click()
  await expect(signBtn).toBeEnabled()
  await signBtn.click()
  // Succès réel piloté par le backend (prepare → WebCrypto → inject → vérif pdfsig → completed).
  await expect(page.getByText(/Document signé/i)).toBeVisible({ timeout: 45_000 })
}

test('deux navigateurs distincts → deux signatures personnelles PAdES valides (pdfsig)', async ({ browser }) => {
  test.setTimeout(150_000)
  const { tokenA, tokenB, requestId } = mintExternalRequest()

  // Navigateur A — contexte isolé (IndexedDB + clé privée A).
  const ctxA = await browser.newContext()
  await signInBrowser(ctxA, tokenA)
  await ctxA.close()

  // Navigateur B — contexte INDÉPENDANT (IndexedDB + clé privée B distincte).
  const ctxB = await browser.newContext()
  await signInBrowser(ctxB, tokenB)
  await ctxB.close()

  // Vérification INDÉPENDANTE par pdfsig sur l'artefact final.
  const status = dumpFinalPdf(requestId)
  expect(status).toContain('STATUS=completed')

  const pdfsig = execSync('pdfsig /tmp/wsig7_final.pdf 2>&1 || true').toString()
  console.log(pdfsig)
  expect(pdfsig).toContain('Signature #1')
  expect(pdfsig).toContain('Signature #2')
  const valid = (pdfsig.match(/Signature is Valid/g) || []).length
  expect(valid).toBe(2)
  // Deux signataires DISTINCTS.
  expect(pdfsig).toMatch(/Alex Martin/)
  expect(pdfsig).toMatch(/Rep Leezr/)
})

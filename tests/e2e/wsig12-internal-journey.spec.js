// @ts-check
// W-SIGNATURE-12 (§2) — parcours COLLABORATEUR INTERNE authentifié, en navigateur réel.
// Les deux signataires sont liés à de VRAIS comptes de test (collaborateur via Employee→User, représentant
// via User). Garanties vérifiées en navigateur : sans session → refus ; mauvais compte → refus ; bon compte
// authentifié → signature ; deux signatures PAdES validées par pdfsig. Aucune mutation de statut en base.
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

/** Fixture : 2 comptes de test (collaborateur interne via Employee→User, représentant via User) + demande interne. */
function setupInternal() {
  const uq = Math.abs(Date.now() % 100000)
  const php = `<?php
use App\\Core\\Models\\User; use App\\Core\\Models\\Membership; use App\\Core\\Workforce\\Employee;
use App\\Core\\Documents\\DocumentTemplate; use App\\Core\\Documents\\GeneratedDocument; use Illuminate\\Support\\Facades\\Storage; use Illuminate\\Support\\Facades\\Hash;
use App\\Modules\\Workforce\\Signature\\UseCases\\CreateSignatureRequestUseCase; use App\\Modules\\Workforce\\Signature\\UseCases\\SendSignatureRequestUseCase;
use App\\Modules\\Workforce\\Signature\\Models\\SignatureSigner; use App\\Modules\\Workforce\\Signature\\Models\\SignatureRequest; use App\\Modules\\Workforce\\Signature\\Services\\SignatureTokenService;
$cid=1; $owner=User::where('email','owner@leezr.test')->first();
$emailA='collab${uq}@test.leezr'; $emailB='rep${uq}@test.leezr';
$uA=User::create(['name'=>'Collab Interne','email'=>$emailA,'password'=>Hash::make('password'),'password_set_at'=>now()]);
$uB=User::create(['name'=>'Rep Interne','email'=>$emailB,'password'=>Hash::make('password'),'password_set_at'=>now()]);
$crole=App\\Company\\RBAC\\CompanyRole::where('company_id',$cid)->first() ?? App\\Company\\RBAC\\CompanyRole::create(['company_id'=>$cid,'key'=>'w12-'.uniqid(),'name'=>'W12 Role','is_system'=>false,'is_administrative'=>false]);
Membership::create(['user_id'=>$uA->id,'company_id'=>$cid,'role'=>'user','company_role_id'=>$crole->id]);
Membership::create(['user_id'=>$uB->id,'company_id'=>$cid,'role'=>'user','company_role_id'=>$crole->id]);
$emp=Employee::create(['company_id'=>$cid,'user_id'=>$uA->id,'first_name'=>'Collab','last_name'=>'Interne','email'=>$emailA,'status'=>'active','hire_date'=>now()->subYear()]);
$name='Contrat W12 '.substr(uniqid(),-6);
$tpl=DocumentTemplate::create(['company_id'=>$cid,'code'=>'w12-'.uniqid(),'name'=>$name,'subject_type'=>'employee','body_html'=>'<p>x</p>','variables_schema'=>[],'version'=>1,'is_active'=>true]);
$p='documents/generated/'.$cid.'/2026/10/'.uniqid().'.pdf'; Storage::disk('local')->put($p, Barryvdh\\DomPDF\\Facade\\Pdf::loadHTML('<h1>'.$name.'</h1>')->output());
$doc=GeneratedDocument::create(['company_id'=>$cid,'document_template_id'=>$tpl->id,'template_version'=>1,'subject_type'=>'employee','subject_id'=>$emp->id,'generated_by'=>$owner->id,'generated_at'=>now(),'file_path'=>$p,'file_size_bytes'=>10,'generation_snapshot'=>['x'=>1],'signature_status'=>'none']);
$req=app(CreateSignatureRequestUseCase::class)->execute($cid,$owner->id,$doc->id,[
  ['role'=>'employee','name'=>'Collab Interne','email'=>$emailA,'order'=>1,'party_type'=>'employee','party_id'=>$emp->id],
  ['role'=>'company_representative','name'=>'Rep Interne','email'=>$emailB,'order'=>2,'party_type'=>'user','party_id'=>$uB->id],
],null,'parallel','internal','personal_digital');
app(SendSignatureRequestUseCase::class)->execute($cid,$owner->id,$req->id);
$req->refresh(); $req->metadata=array_merge($req->metadata??[],['required_format'=>'pades_external']); $req->save();
$raw=[]; foreach(SignatureSigner::where('signature_request_id',$req->id)->orderBy('order')->get() as $s){ $t=SignatureTokenService::generate(); $s->token_hash=$t['hash']; $s->token_expires_at=$req->expires_at; $s->token_revoked_at=null; $s->status='invited'; $s->invited_at=now(); $s->save(); $raw[]=$t['raw']; }
echo 'RESULT='.$raw[0].':'.$raw[1].':'.$req->id.':'.$emailA.':'.$emailB.PHP_EOL;`
  const out = tinker(php, `${SCRATCH}/w12-setup.php`)
  const m = out.match(/RESULT=([a-f0-9]+):([a-f0-9]+):(\d+):(\S+?):(\S+)/)
  if (!m) throw new Error('setup failed: ' + out)

  return { tokenA: m[1], tokenB: m[2], requestId: Number(m[3]), emailA: m[4], emailB: m[5] }
}

function dumpFinal(requestId) {
  const php = `<?php use App\\Modules\\Workforce\\Signature\\Models\\SignatureRequest; use Illuminate\\Support\\Facades\\Storage;
$req=SignatureRequest::withoutGlobalScopes()->find(${requestId}); file_put_contents('/tmp/w12_final.pdf', Storage::disk(config('documents.generated_disk','local'))->get($req->signed_document_path)); echo 'STATUS='.$req->status.PHP_EOL;`

  return tinker(php, `${SCRATCH}/w12-dump.php`)
}

async function attemptSign(page, token) {
  await page.goto('/sign/' + token, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1200)
  await page.getByText(/je consens à le signer/i).click()
  await page.getByRole('button', { name: /Signer le document/i }).click()
  await page.waitForTimeout(2500)
}

test('internal signer: no session refused, wrong account refused, correct account signs; two valid pdfsig', async ({ browser }) => {
  test.setTimeout(180_000)
  const { tokenA, tokenB, requestId, emailA, emailB } = setupInternal()

  // 8) Sans session → refus (pas de "Document signé").
  const anon = await browser.newContext()
  const pAnon = await anon.newPage()
  await attemptSign(pAnon, tokenA)
  await expect(pAnon.getByText(/Document signé/i)).toHaveCount(0)
  await anon.close()

  // 9) Mauvais compte (représentant connecté tente de signer pour le collaborateur) → refus.
  const wrong = await browser.newContext()
  const pWrong = await wrong.newPage()
  await loginCompany(pWrong, emailB) // compte B, pas le signataire attendu de tokenA
  await attemptSign(pWrong, tokenA)
  await expect(pWrong.getByText(/Document signé/i)).toHaveCount(0)
  await wrong.close()

  // 10-14) Bon compte (collaborateur) authentifié → signature réussie.
  const ctxA = await browser.newContext()
  const pA = await ctxA.newPage()
  await loginCompany(pA, emailA)
  await attemptSign(pA, tokenA)
  await expect(pA.getByText(/Document signé/i)).toBeVisible({ timeout: 30_000 })
  await ctxA.close()

  // 15-16) Représentant authentifié → deuxième signature.
  const ctxB = await browser.newContext()
  const pB = await ctxB.newPage()
  await loginCompany(pB, emailB)
  await attemptSign(pB, tokenB)
  await expect(pB.getByText(/Document signé/i)).toBeVisible({ timeout: 30_000 })
  await ctxB.close()

  // 17-18) pdfsig indépendant : deux signatures valides.
  expect(dumpFinal(requestId)).toContain('STATUS=completed')
  const pdfsig = execSync('pdfsig /tmp/w12_final.pdf 2>&1 || true').toString()
  console.log(pdfsig)
  expect((pdfsig.match(/Signature is Valid/g) || []).length).toBe(2)
})

// @ts-check
// W-SIGNATURE-7 (§14) — capture visuelle de la page de signature (réutilise la carte Vuexy existante).
import { test } from '@playwright/test'
import { execSync } from 'node:child_process'
import { writeFileSync } from 'node:fs'

const REPO = '/Users/djamel/sites/leezr'
const SCRATCH = '/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

function mint() {
  const php = `<?php
use App\\Core\\Documents\\DocumentTemplate; use App\\Core\\Documents\\GeneratedDocument;
use App\\Modules\\Workforce\\Signature\\UseCases\\CreateSignatureRequestUseCase;
use App\\Modules\\Workforce\\Signature\\UseCases\\SendSignatureRequestUseCase;
use App\\Modules\\Workforce\\Signature\\Services\\SignatureTokenService;
use App\\Modules\\Workforce\\Signature\\Models\\SignatureSigner;
use Illuminate\\Support\\Facades\\Storage;
$cid=1; $uid=App\\Core\\Models\\User::where('email','owner@leezr.test')->first()->id;
$tpl=DocumentTemplate::create(['company_id'=>$cid,'code'=>'vis-'.uniqid(),'name'=>'Contrat de travail','subject_type'=>'employee','body_html'=>'<p>x</p>','variables_schema'=>[],'version'=>1,'is_active'=>true]);
$p='documents/generated/'.$cid.'/2026/10/'.uniqid().'.pdf';
Storage::disk('local')->put($p, Barryvdh\\DomPDF\\Facade\\Pdf::loadHTML('<h1>Contrat</h1>')->output());
$doc=GeneratedDocument::create(['company_id'=>$cid,'document_template_id'=>$tpl->id,'template_version'=>1,'subject_type'=>'employee','subject_id'=>1,'generated_by'=>$uid,'generated_at'=>now(),'file_path'=>$p,'file_size_bytes'=>10,'generation_snapshot'=>['x'=>1],'signature_status'=>'none']);
$req=app(CreateSignatureRequestUseCase::class)->execute($cid,$uid,$doc->id,[['role'=>'employee','name'=>'Alex Martin','email'=>'vis@x.test','order'=>1]],null,'parallel',null);
app(SendSignatureRequestUseCase::class)->execute($cid,$uid,$req->id);
$req->refresh(); $req->metadata=array_merge($req->metadata??[],['required_format'=>'pades_external']); $req->save();
$s=SignatureSigner::where('signature_request_id',$req->id)->first();
$t=SignatureTokenService::generate(); $s->token_hash=$t['hash']; $s->token_expires_at=$req->expires_at; $s->status='invited'; $s->save();
echo 'RESULT='.$t['raw'].PHP_EOL;`
  writeFileSync(`${SCRATCH}/wsig7-vis.php`, php)
  const out = execSync(`php artisan tinker --execute="require '${SCRATCH}/wsig7-vis.php';"`, { cwd: REPO }).toString()

  return out.match(/RESULT=([a-f0-9]+)/)[1]
}

test('capture sign page desktop/mobile/dark', async ({ browser }) => {
  const token = mint()

  const desktop = await browser.newContext({ viewport: { width: 1366, height: 900 } })
  const p1 = await desktop.newPage()
  await p1.goto('/sign/' + token, { waitUntil: 'domcontentloaded' })
  await p1.waitForTimeout(1500)
  await p1.screenshot({ path: `${SCRATCH}/wsig7-desktop.png`, fullPage: true })
  await desktop.close()

  const mobile = await browser.newContext({ viewport: { width: 390, height: 844 } })
  const p2 = await mobile.newPage()
  await p2.goto('/sign/' + token, { waitUntil: 'domcontentloaded' })
  await p2.waitForTimeout(1500)
  await p2.screenshot({ path: `${SCRATCH}/wsig7-mobile.png`, fullPage: true })
  await mobile.close()

  const dark = await browser.newContext({ viewport: { width: 1366, height: 900 }, colorScheme: 'dark' })
  const p3 = await dark.newPage()
  await p3.goto('/sign/' + token, { waitUntil: 'domcontentloaded' })
  await p3.waitForTimeout(1500)
  await p3.screenshot({ path: `${SCRATCH}/wsig7-dark.png`, fullPage: true })
  await dark.close()
})

<?php

namespace Tests\Feature;

use App\Core\Crypto\CryptographicSealService;
use App\Core\Crypto\NativePadesSignatureService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ADR-853 — under php-fpm `open_basedir` (hosting : /usr/bin outside the site's basedir) the pdfsig detection used
 * to throw « is_executable(): open_basedir restriction in effect » → 500 on /api/workforce/signature/options. The
 * binary is now proven by running it ; the independent cryptographic verification still runs for real.
 */
class Adr853PdfsigOpenBasedirTest extends TestCase
{
    /** Runs PHP code in a child process whose open_basedir EXCLUDES the pdfsig directory (as on the hosting). */
    private function underBasedir(string $code): array
    {
        $basedir = implode(PATH_SEPARATOR, [base_path(), sys_get_temp_dir(), '/tmp', '/private/tmp', '/private/var/folders']);
        $boot = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); ';
        $p = new Process([PHP_BINARY, '-d', 'open_basedir='.$basedir, '-d', 'display_errors=stderr', '-r', $boot.$code], base_path(), ['APP_ENV' => 'testing']);
        $p->mustRun();

        return json_decode(trim($p->getOutput()), true) ?? ['raw' => $p->getOutput().$p->getErrorOutput()];
    }

    private function pdfsigInstalled(): bool
    {
        return (new NativePadesSignatureService(new CryptographicSealService()))->pdfsigAvailable();
    }

    public function test_detection_never_throws_and_finds_pdfsig_outside_open_basedir(): void
    {
        if (! $this->pdfsigInstalled()) {
            $this->markTestSkipped('pdfsig absent');
        }
        $r = $this->underBasedir('echo json_encode(["available" => app(App\Core\Crypto\NativePadesSignatureService::class)->pdfsigAvailable()]);');
        $this->assertSame(['available' => true], $r);
    }

    public function test_a_binary_that_is_not_pdfsig_is_never_accepted(): void
    {
        $r = $this->underBasedir('config(["signature.pdfsig_path" => "/bin/echo"]); $s = new App\Core\Crypto\NativePadesSignatureService(new App\Core\Crypto\CryptographicSealService()); '
            .'$m = new ReflectionMethod($s, "runnable"); echo json_encode(["echo" => $m->invoke(null, "/bin/echo"), "missing" => $m->invoke(null, "/usr/bin/no-such-pdfsig")]);');
        $this->assertSame(['echo' => false, 'missing' => false], $r);
    }

    public function test_independent_verification_still_runs_under_open_basedir(): void
    {
        if (! $this->pdfsigInstalled()) {
            $this->markTestSkipped('pdfsig absent');
        }
        Storage::fake('local');
        $signed = (new NativePadesSignatureService(new CryptographicSealService()))
            ->signPdf(\Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>ADR-853</h1>')->output());
        $file = tempnam(sys_get_temp_dir(), 'adr853_');
        file_put_contents($file, $signed);
        $tampered = tempnam(sys_get_temp_dir(), 'adr853t_');
        $bytes = $signed;
        $bytes[100] = $bytes[100] === 'A' ? 'B' : 'A'; // one byte inside the signed range
        file_put_contents($tampered, $bytes);

        $r = $this->underBasedir('$s = app(App\Core\Crypto\NativePadesSignatureService::class); $a = $s->verifyPdf(file_get_contents('.var_export($file, true).')); '
            .'$b = $s->verifyPdf(file_get_contents('.var_export($tampered, true).')); echo json_encode(["valid" => $a["all_signatures_valid"] ?? null, "count" => $a["signature_count"] ?? null, "tampered_valid" => $b["all_signatures_valid"] ?? null]);');
        @unlink($file);
        @unlink($tampered);

        $this->assertSame(1, $r['count'], json_encode($r));
        $this->assertTrue($r['valid'], 'a genuine signature verifies for real');
        $this->assertNotTrue($r['tampered_valid'], 'a tampered document never verifies');
    }
}

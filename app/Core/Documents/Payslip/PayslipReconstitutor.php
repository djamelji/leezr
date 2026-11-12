<?php

namespace App\Core\Documents\Payslip;

use App\Core\Documents\Services\DocumentIntegrityService;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

/**
 * ADR-763 (DMS M5.1) — reconstitue le PDF COMPLET d'un segment (bulletin) à partir de ses pages
 * source, DANS L'ORDRE, puis recalcule son hash. Appelé après toute modification de composition et
 * avant publication → la publication valide toujours le fichier résultant réel.
 */
class PayslipReconstitutor
{
    public function __construct(private readonly DocumentIntegrityService $integrity)
    {
    }

    /** Fusionne les pages du segment (ordre croissant) et renvoie [path, sha256]. */
    public function rebuild(PayslipImportItem $item): array
    {
        $pages = $item->pages()->get();
        if ($pages->isEmpty()) {
            return [$item->file_path, $item->file_sha256];
        }

        $out = new Fpdi();
        $bySource = $pages->groupBy('source_file_path');
        foreach ($bySource as $sourcePath => $group) {
            $abs = Storage::disk('local')->path($sourcePath);
            $out->setSourceFile($abs);
            foreach ($group->sortBy('source_page_no') as $page) {
                $tpl = $out->importPage($page->source_page_no);
                $size = $out->getTemplateSize($tpl);
                $out->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $out->useTemplate($tpl);
            }
        }

        $relPath = dirname($item->file_path).'/segment-'.$item->id.'-rebuilt.pdf';
        Storage::disk('local')->put($relPath, $out->Output('S'));
        $sha = $this->integrity->hashOf($relPath);

        $item->update([
            'file_path' => $relPath,
            'file_sha256' => $sha,
            'page_numbers' => $pages->pluck('source_page_no')->values()->all(),
            'page_range' => $this->rangeLabel($pages->pluck('source_page_no')->all()),
        ]);

        return [$relPath, $sha];
    }

    private function rangeLabel(array $pageNos): string
    {
        sort($pageNos);
        if (count($pageNos) === 1) {
            return (string) $pageNos[0];
        }

        return $pageNos[0].'-'.end($pageNos);
    }
}

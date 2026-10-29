<?php

namespace App\Core\Documents\Services;

/**
 * W-CONTRACT-2 (ADR-648) — sanitation SERVEUR du contenu WYSIWYG des modèles documentaires.
 *
 * Défense en profondeur (jamais uniquement côté front) : supprime scripts, styles exécutables,
 * iframes/objets, attributs événementiels (on*), schémas dangereux (javascript:/data:), et les
 * ressources EXTERNES non autorisées (src/href http(s) → retirés pour éviter trackers/fuites).
 * Préserve les placeholders de variables `{{ var }}` et la structure documentaire (titres, listes,
 * tableaux, paragraphes). Volontairement conservateur et sans dépendance lourde.
 */
class HtmlSanitizer
{
    /** Balises dont le contenu ENTIER est supprimé. */
    private const STRIP_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'base', 'form', 'applet'];

    public static function sanitize(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // 1. Supprime les balises dangereuses + leur contenu.
        foreach (self::STRIP_TAGS as $tag) {
            $html = preg_replace('#<'.$tag.'\b[^>]*>.*?</'.$tag.'>#is', '', $html) ?? $html;
            $html = preg_replace('#<'.$tag.'\b[^>]*/?>#is', '', $html) ?? $html; // auto-fermantes
        }

        // 2. Supprime les attributs événementiels on* (onclick, onload, …).
        $html = preg_replace('#\s on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html) ?? $html;

        // 3. Neutralise les schémas dangereux dans href/src.
        $html = preg_replace('#\b(href|src|xlink:href)\s*=\s*("|\')\s*(javascript|vbscript|data)\s*:[^"\']*\2#is', '', $html) ?? $html;

        // 4. Supprime les ressources EXTERNES (src/href http(s)://…) — anti-tracker/fuite.
        $html = preg_replace('#\b(src|href)\s*=\s*("|\')\s*https?://[^"\']*\2#is', '', $html) ?? $html;

        // 5. Supprime toute balise style résiduelle d'attribut avec expression() (vieux IE / dompdf).
        $html = preg_replace('#expression\s*\([^)]*\)#is', '', $html) ?? $html;

        return trim($html);
    }
}

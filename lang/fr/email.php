<?php

/*
 * ADR-781 (UX5) — server-side e-mail copy. Single source of truth : the "email" block of the interface
 * locale (resources/js/plugins/i18n/locales/fr.json). Placeholders {name} become Laravel's :name.
 * Before this file, billing e-mails were sent with their raw keys as subject and body.
 */
$json = json_decode((string) @file_get_contents(resource_path('js/plugins/i18n/locales/fr.json')), true);
$convert = function ($value) use (&$convert) {
    return is_array($value)
        ? array_map($convert, $value)
        : (is_string($value) ? preg_replace('/\{(\w+)\}/', ':$1', $value) : $value);
};

return $convert(is_array($json['email'] ?? null) ? $json['email'] : []);

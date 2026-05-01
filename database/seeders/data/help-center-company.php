<?php

// Help Center — Company audience (merged from part1 + part2)
// Part 1: topics 1-4 (Démarrage, Gestion, Membres, Documents)
// Part 2: topics 5-7 (Modules, Facturation, Support)

$part1 = require __DIR__.'/help-center-company-part1.php';
$part2 = require __DIR__.'/help-center-company-part2.php';

return [
    'group' => $part1['group'],
    'topics' => array_merge($part1['topics'], $part2),
];

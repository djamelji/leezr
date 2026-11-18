<?php
// Builds explicit-column INSERT statements from mysqlbinlog -v DELETE row images (before-images).
// usage: php binlog2sql.php <events.txt> <cols.tsv> <target_db>
[$_, $events, $colsFile, $db] = $argv;
$cols = [];
foreach (file($colsFile, FILE_IGNORE_NEW_LINES) as $l) {
    [$t, $pos, $name, $type, $ctype] = explode("\t", $l);
    $cols[$t][(int) $pos] = [$name, $type, $ctype];
}
$rows = [];
$cur = null;
foreach (file($events, FILE_IGNORE_NEW_LINES) as $l) {
    if (preg_match('/^### DELETE FROM `leezr`\.`([a-z_]+)`/', $l, $m)) {
        $rows[] = ['table' => $m[1], 'v' => []];
        $cur = array_key_last($rows);
        continue;
    }
    if (preg_match('/^### (INSERT|UPDATE)/', $l)) {
        $cur = null;
        continue;
    }
    if ($cur !== null && preg_match('/^###   @(\d+)=(.*)$/s', $l, $m)) {
        $rows[$cur]['v'][(int) $m[1]] = $m[2];
    }
}
function unq(string $raw): string
{
    $s = substr($raw, 1, -1);
    return preg_replace_callback('/\\\\(x[0-9a-fA-F]{2}|\\\\|\')/', fn ($m) => $m[1][0] === 'x' ? chr(hexdec(substr($m[1], 1))) : $m[1], $s);
}
$out = [];
foreach ($rows as $r) {
    $names = [];
    $vals = [];
    foreach ($cols[$r['table']] as $pos => [$name, $type, $ctype]) {
        $raw = $r['v'][$pos] ?? null;
        if ($raw === null) {
            fwrite(STDERR, "missing @$pos in {$r['table']}\n");
            exit(1);
        }
        $names[] = "`$name`";
        if ($raw === 'NULL') {
            $vals[] = 'NULL';
        } elseif ($type === 'timestamp') {
            $vals[] = "'".gmdate('Y-m-d H:i:s', (int) $raw)."'";
        } elseif ($type === 'date') {
            $vals[] = "'".str_replace(':', '-', unq($raw))."'";
        } elseif ($type === 'enum') {
            preg_match_all("/'([^']*)'/", $ctype, $em);
            $vals[] = "'".$em[1][((int) $raw) - 1]."'";
        } elseif ($raw[0] === "'") {
            $vals[] = "'".str_replace(['\\', "'"], ['\\\\', "\\'"], unq($raw))."'";
        } else {
            $vals[] = $raw;
        }
    }
    $out[] = "INSERT INTO `$db`.`{$r['table']}` (".implode(',', $names).') VALUES ('.implode(',', $vals).');';
}
echo implode("\n", $out)."\n";

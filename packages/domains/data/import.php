<?php

/**
 * Regenerate data/data.php from the public suffix list.
 *
 * The dataset maps each PSL rule to the section it came from ('ICANN',
 * 'PRIVATE', or null) — the only thing Domain asks of it.
 */
$list = file_get_contents('https://publicsuffix.org/list/public_suffix_list.dat');
if ($list === false) {
    throw new RuntimeException('Could not download public suffix list');
}

$type = null;
$domains = [];

foreach (explode("\n", $list) as $line) {
    $line = trim($line);

    if (str_contains($line, '===BEGIN ICANN DOMAINS===')) {
        $type = 'ICANN';

        continue;
    }

    if (str_contains($line, '===BEGIN PRIVATE DOMAINS===')) {
        $type = 'PRIVATE';

        continue;
    }

    if (str_contains($line, '===END ICANN DOMAINS===') || str_contains($line, '===END PRIVATE DOMAINS===')) {
        $type = null;

        continue;
    }

    if ($line === '' || str_starts_with($line, '//')) {
        continue;
    }

    $domains[$line] = $type;
}

if (! isset($domains['com'])) {
    throw new RuntimeException('.com is missing from public suffix list; it must be corrupted');
}

$lines = '';
foreach ($domains as $rule => $section) {
    $lines .= '    ' . var_export($rule, true) . ' => ' . var_export($section, true) . ",\n";
}

file_put_contents(__DIR__ . '/data.php', "<?php\n\nreturn [\n" . $lines . "];\n");

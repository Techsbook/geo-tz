#!/usr/bin/env php
<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__);
$dataDir = $baseDir . '/data';
$repo = 'evansiroky/node-geo-tz';
$branch = 'master';

$files = [
    'timezones.geojson.geo.dat',
    'timezones.geojson.index.json',
    'timezones-1970.geojson.geo.dat',
    'timezones-1970.geojson.index.json',
    'timezones-now.geojson.geo.dat',
    'timezones-now.geojson.index.json',
];

if (!is_dir($dataDir) && !mkdir($dataDir, 0777, true) && !is_dir($dataDir)) {
    fwrite(STDERR, "Failed to create data directory: {$dataDir}\n");
    exit(1);
}

$context = stream_context_create([
    'http' => [
        'header' => "User-Agent: geo-tz-php\r\n",
        'timeout' => 60,
    ],
]);

function downloadFile(string $url, string $dest, $context): void
{
    $in = fopen($url, 'rb', false, $context);
    if ($in === false) {
        throw new RuntimeException('Failed to download: ' . $url);
    }

    $out = fopen($dest, 'wb');
    if ($out === false) {
        fclose($in);
        throw new RuntimeException('Failed to write: ' . $dest);
    }

    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
}

try {
    foreach ($files as $file) {
        $url = "https://raw.githubusercontent.com/{$repo}/{$branch}/data/{$file}";
        $dest = $dataDir . '/' . $file;
        echo "Downloading {$file}...\n";
        downloadFile($url, $dest, $context);
    }

    $metaUrl = "https://api.github.com/repos/{$repo}/commits/{$branch}";
    $metaJson = file_get_contents($metaUrl, false, $context);
    $meta = $metaJson ? json_decode($metaJson, true) : null;
    $metaOut = [
        'source' => $repo,
        'branch' => $branch,
        'sha' => $meta['sha'] ?? null,
        'date' => $meta['commit']['committer']['date'] ?? null,
        'updated_at' => gmdate('c'),
    ];
    file_put_contents($dataDir . '/SOURCE.json', json_encode($metaOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    echo "Update completed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Update failed: {$e->getMessage()}\n");
    exit(1);
}

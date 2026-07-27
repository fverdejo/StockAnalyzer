<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Repository\NewsRepository;
use StockAnalyzer\Services\NewsSentimentScorer;

$path = $argv[1] ?? '';

if (!is_string($path) || $path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php bin/import-news.php path/to/news.csv\n");
    fwrite(STDERR, "CSV columns: ticker,title,source,url,published_at\n");
    exit(1);
}

$handle = fopen($path, 'rb');

if ($handle === false) {
    fwrite(STDERR, "Could not open CSV.\n");
    exit(1);
}

$repository = new NewsRepository(new Connection());
$scorer = new NewsSentimentScorer();
$header = fgetcsv($handle);
$imported = 0;

if (!is_array($header)) {
    fwrite(STDERR, "CSV is empty.\n");
    exit(1);
}

$columns = array_flip(array_map(
    static fn (mixed $value): string => strtolower(trim((string) $value, "\xEF\xBB\xBF \t\n\r\0\x0B")),
    $header
));

while (($row = fgetcsv($handle)) !== false) {
    $ticker = (string) ($row[$columns['ticker'] ?? -1] ?? '');
    $title = (string) ($row[$columns['title'] ?? -1] ?? '');
    $source = (string) ($row[$columns['source'] ?? -1] ?? 'Manual');
    $url = (string) ($row[$columns['url'] ?? -1] ?? '');
    $published = (string) ($row[$columns['published_at'] ?? -1] ?? 'now');

    if (trim($ticker) === '' || trim($title) === '') {
        continue;
    }

    $repository->add(
        $ticker,
        $title,
        $source !== '' ? $source : 'Manual',
        $url !== '' ? $url : null,
        new DateTimeImmutable($published !== '' ? $published : 'now'),
        $scorer->score($title)
    );
    $imported++;
}

fclose($handle);

echo "Imported {$imported} news items.\n";

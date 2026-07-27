<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use PDO;
use StockAnalyzer\DTO\NewsSentiment;
use StockAnalyzer\Infrastructure\Database\Connection;

class NewsRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function add(
        string $ticker,
        string $title,
        string $source,
        ?string $url,
        DateTimeImmutable $publishedAt,
        float $sentimentScore
    ): void {
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO news_items (ticker, title, source, url, published_at, sentiment_score, created_at)
             VALUES (:ticker, :title, :source, :url, :published_at, :sentiment_score, NOW())'
        );
        $statement->execute([
            'ticker' => strtoupper(trim($ticker)),
            'title' => $title,
            'source' => $source,
            'url' => $url,
            'published_at' => $publishedAt->format('Y-m-d H:i:s'),
            'sentiment_score' => $sentimentScore,
        ]);
    }

    public function sentimentForTicker(string $ticker, int $days = 7): ?NewsSentiment
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT COUNT(*) AS news_count, AVG(sentiment_score) AS average_score
             FROM news_items
             WHERE ticker = :ticker AND published_at >= DATE_SUB(NOW(), INTERVAL :days DAY)'
        );
        $statement->bindValue(':ticker', strtoupper(trim($ticker)));
        $statement->bindValue(':days', $days, PDO::PARAM_INT);
        $statement->execute();
        $aggregate = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($aggregate) || (int) ($aggregate['news_count'] ?? 0) === 0) {
            return null;
        }

        $latest = $this->connection->getPdo()->prepare(
            'SELECT title, source
             FROM news_items
             WHERE ticker = :ticker AND published_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             ORDER BY published_at DESC, id DESC
             LIMIT 1'
        );
        $latest->bindValue(':ticker', strtoupper(trim($ticker)));
        $latest->bindValue(':days', $days, PDO::PARAM_INT);
        $latest->execute();
        $row = $latest->fetch(PDO::FETCH_ASSOC);

        return new NewsSentiment(
            strtoupper(trim($ticker)),
            (float) $aggregate['average_score'],
            (int) $aggregate['news_count'],
            is_array($row) ? (string) $row['title'] : null,
            is_array($row) ? (string) $row['source'] : null
        );
    }
}

CREATE TABLE IF NOT EXISTS news_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticker VARCHAR(24) NOT NULL,
    title VARCHAR(500) NOT NULL,
    source VARCHAR(120) NOT NULL,
    url VARCHAR(1000) NULL,
    published_at DATETIME NOT NULL,
    sentiment_score DECIMAL(5, 2) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_news_ticker_published (ticker, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

<?php

declare(strict_types=1);

return [
    'default' => [
        'label' => 'Por defecto',
        'tickers' => ['AAPL', 'MSFT', 'NVDA', 'AMZN', 'GOOGL', 'META', 'TSLA', 'JPM', 'V', 'XOM'],
    ],
    'magnificent7' => [
        'label' => 'Magnificent 7',
        'tickers' => ['AAPL', 'MSFT', 'NVDA', 'AMZN', 'GOOGL', 'META', 'TSLA'],
    ],
    'dow30' => [
        'label' => 'Dow Jones 30',
        'tickers' => ['AAPL', 'AMGN', 'AMZN', 'AXP', 'BA', 'CAT', 'CRM', 'CSCO', 'CVX', 'DIS', 'GS', 'HD', 'HON', 'IBM', 'JNJ', 'JPM', 'KO', 'MCD', 'MMM', 'MRK', 'MSFT', 'NKE', 'PG', 'SHW', 'TRV', 'UNH', 'V', 'VZ', 'WMT'],
    ],
    'ibex35' => [
        'label' => 'IBEX 35 base',
        'tickers' => ['SAN.MC', 'BBVA.MC', 'IBE.MC', 'ITX.MC', 'REP.MC', 'TEF.MC', 'FER.MC', 'AMS.MC', 'AENA.MC', 'CABK.MC', 'MAP.MC', 'ENG.MC', 'ELE.MC', 'NTGY.MC', 'ANA.MC'],
    ],
    'tech40' => [
        'label' => 'Tecnologia ampliada',
        'tickers' => ['AAPL', 'MSFT', 'NVDA', 'AVGO', 'ORCL', 'AMD', 'ADBE', 'CRM', 'CSCO', 'INTC', 'IBM', 'QCOM', 'TXN', 'NOW', 'AMAT', 'MU', 'LRCX', 'PANW', 'SNOW', 'SHOP'],
    ],
];

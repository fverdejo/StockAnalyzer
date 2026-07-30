<?php

declare(strict_types=1);

// 'general' es el universo por defecto del Home. Desde v2.12,
// Application::resolveGeneralUniverseTickers() lo construye en vivo con
// las 20 acciones que mas suben y las 20 que mas bajan hoy segun el
// screener de Yahoo Finance (ver Providers\YahooMarketMoversProvider).
// La lista fija de aqui abajo solo se usa como respaldo si ese screener
// falla (endpoint no oficial, puede cambiar sin aviso).
return [
    'general' => [
        'label' => 'Busqueda general',
        'tickers' => [
            'AAPL', 'MSFT', 'NVDA', 'AMZN', 'GOOGL', 'META', 'TSLA', 'AVGO', 'BRK-B', 'JPM',
            'LLY', 'V', 'XOM', 'UNH', 'MA', 'COST', 'NFLX', 'WMT', 'PG', 'JNJ',
            'HD', 'ABBV', 'BAC', 'KO', 'CRM', 'ORCL', 'CVX', 'MRK', 'AMD', 'PEP',
            'LIN', 'TMO', 'ACN', 'MCD', 'CSCO', 'ADBE', 'IBM', 'QCOM', 'WFC', 'CAT',
            'TXN', 'INTU', 'AMGN', 'DIS', 'GS', 'ISRG', 'VZ', 'NOW', 'PFE', 'NKE',
            'SAN.MC', 'BBVA.MC', 'IBE.MC', 'ITX.MC', 'REP.MC', 'TEF.MC', 'FER.MC', 'AMS.MC', 'CABK.MC', 'ELE.MC',
        ],
    ],
    'largecap60' => [
        'label' => 'EEUU liquidas 60',
        'tickers' => [
            'AAPL', 'MSFT', 'NVDA', 'AMZN', 'GOOGL', 'META', 'TSLA', 'AVGO', 'BRK-B', 'JPM',
            'LLY', 'V', 'XOM', 'UNH', 'MA', 'COST', 'NFLX', 'WMT', 'PG', 'JNJ',
            'HD', 'ABBV', 'BAC', 'KO', 'CRM', 'ORCL', 'CVX', 'MRK', 'AMD', 'PEP',
            'LIN', 'TMO', 'ACN', 'MCD', 'CSCO', 'ADBE', 'IBM', 'GE', 'QCOM', 'WFC',
            'CAT', 'TXN', 'PM', 'INTU', 'AMGN', 'DIS', 'GS', 'ISRG', 'VZ', 'NOW',
            'RTX', 'BKNG', 'SPGI', 'PFE', 'NKE', 'HON', 'LOW', 'UPS', 'BA', 'SBUX',
        ],
    ],
    'magnificent7' => [
        'label' => '7 Magníficas',
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
    // Grupos por sector/categoria (no por indice), maximo 50 tickers cada uno.
    'financials' => [
        'label' => 'Finanzas',
        'tickers' => [
            'JPM', 'BAC', 'WFC', 'C', 'GS', 'MS', 'SCHW', 'BLK', 'AXP', 'V',
            'MA', 'PYPL', 'SPGI', 'ICE', 'CME', 'COF', 'USB', 'PNC', 'TFC', 'STT',
            'MET', 'PRU', 'AIG', 'ALL', 'TRV', 'CB', 'PGR', 'WTW', 'AON', 'BX',
            'KKR', 'APO', 'MCO', 'FIS', 'GPN', 'SYF', 'ALLY', 'RJF', 'AJG', 'AMP',
            'SAN.MC', 'BBVA.MC', 'CABK.MC', 'UNI.MC',
        ],
    ],
    'healthcare' => [
        'label' => 'Salud',
        'tickers' => [
            'LLY', 'UNH', 'JNJ', 'ABBV', 'MRK', 'TMO', 'ABT', 'PFE', 'DHR', 'AMGN',
            'ISRG', 'ELV', 'CVS', 'MDT', 'GILD', 'VRTX', 'REGN', 'CI', 'SYK', 'BSX',
            'ZTS', 'HCA', 'BDX', 'MRNA', 'IDXX', 'IQV', 'EW', 'HUM', 'BIIB', 'DXCM',
            'A', 'RMD', 'CNC', 'GEHC', 'MTD', 'WAT', 'ALGN', 'ZBH', 'INCY', 'MOH',
        ],
    ],
    'energy' => [
        'label' => 'Energia',
        'tickers' => [
            'XOM', 'CVX', 'COP', 'EOG', 'SLB', 'MPC', 'PSX', 'VLO', 'OXY', 'WMB',
            'KMI', 'BP', 'BKR', 'HAL', 'DVN', 'FANG', 'TRGP', 'OKE', 'EQT', 'SU',
            'NOV', 'APA', 'REP.MC',
        ],
    ],
    'consumer' => [
        'label' => 'Consumo',
        'tickers' => [
            'AMZN', 'WMT', 'COST', 'HD', 'MCD', 'NKE', 'SBUX', 'TGT', 'LOW', 'TJX',
            'BKNG', 'CMG', 'MAR', 'YUM', 'DG', 'ROST', 'KO', 'PEP', 'PG', 'PM',
            'MO', 'MDLZ', 'CL', 'KMB', 'GIS', 'STZ', 'EL', 'KHC', 'HSY', 'CLX',
            'ITX.MC',
        ],
    ],
    'industrials' => [
        'label' => 'Industria',
        'tickers' => [
            'CAT', 'HON', 'UNP', 'RTX', 'BA', 'GE', 'DE', 'LMT', 'ADP', 'ETN',
            'UPS', 'NOC', 'GD', 'ITW', 'EMR', 'CSX', 'WM', 'NSC', 'PH', 'TT',
            'CMI', 'PCAR', 'ROK', 'FDX', 'PAYX', 'CTAS', 'FAST', 'ODFL', 'JCI',
        ],
    ],
];

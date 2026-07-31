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
    // Composicion completa verificada contra la revision oficial del comite
    // asesor tecnico del IBEX 35 (BME, revision num. 136 del 22/06/2026, sin
    // cambios desde la num. 130 del 22/07/2024 que incluyo PUIG y excluyo MEL).
    // Los 35 tickers estan confirmados uno a uno contra el endpoint de Yahoo
    // Finance el 2026-07-31 (precio y nombre de empresa validos para todos).
    'ibex35' => [
        'label' => 'IBEX 35',
        'tickers' => [
            'SAN.MC', 'BBVA.MC', 'IBE.MC', 'ITX.MC', 'REP.MC', 'TEF.MC', 'FER.MC', 'AMS.MC', 'AENA.MC', 'CABK.MC',
            'MAP.MC', 'ENG.MC', 'ELE.MC', 'NTGY.MC', 'ANA.MC', 'ACS.MC', 'ACX.MC', 'ANE.MC', 'BKT.MC', 'CLNX.MC',
            'COL.MC', 'FDR.MC', 'GRF.MC', 'IAG.MC', 'IDR.MC', 'LOG.MC', 'MRL.MC', 'MTS.MC', 'PUIG.MC', 'RED.MC',
            'ROVI.MC', 'SAB.MC', 'SCYR.MC', 'SLR.MC', 'UNI.MC',
        ],
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
    // Grupos geograficos fuera de EEUU/Europa, anadidos 2026-07-31. Mismo
    // limite de 50 tickers/sin duplicados dentro del grupo que el resto de
    // grupos sectoriales; el solape con otros grupos (p.ej. semiconductores
    // EEUU que tambien estan en 'tech40') es aceptable, ya ocurre hoy con
    // REP.MC/ITX.MC en varios sitios.
    'china_adr' => [
        'label' => 'China / Gran China (ADR)',
        // TCEHY (Tencent) se descarta deliberadamente: es el unico ADR OTC
        // (Pink Markets) del grupo, frente al resto que cotiza directo en
        // NYSE/NASDAQ; Yahoo lo sirve pero con menor fiabilidad (el campo
        // previousClose llego a mostrar un salto injustificado del +34% en
        // la verificacion del 2026-07-31 aunque la serie diaria en si era
        // consistente). Si se quiere exposicion a Tencent, usar el listado
        // primario de Hong Kong (0700.HK) en vez de este universo.
        'tickers' => [
            'BABA', 'JD', 'PDD', 'BIDU', 'NTES', 'TCOM', 'LI', 'NIO', 'XPEV', 'BILI',
            'YUMC', 'ZTO', 'VIPS', 'HTHT', 'BEKE', 'EDU', 'TAL', 'ATHM', 'FUTU',
        ],
    ],
    'asia_pacific_adr' => [
        'label' => 'Asia-Pacifico ex-China (ADR)',
        // WNS descartado: WNS (Holdings) Ltd fue adquirida por Capgemini y
        // dejo de cotizar en NYSE el 17/10/2025 (delisting real, no rate
        // limit). SKM (SK Telecom) se mantiene: pese al incidente de
        // ciberseguridad de abril 2025, sigue cotizando con normalidad y
        // reanudo el dividendo en 2026 segun verificacion del 2026-07-31.
        'tickers' => [
            'SONY', 'TM', 'HMC', 'MUFG', 'SMFG',
            'KB', 'SHG', 'PKX', 'SKM', 'KT', 'CPNG',
            'SE', 'GRAB',
            'INFY', 'WIT', 'IBN', 'HDB', 'RDY', 'MMYT', 'SIFY', 'G',
        ],
    ],
    'latam_adr' => [
        'label' => 'Latinoamerica (ADR)',
        // Verificados activos y cotizando con normalidad a 2026-07-31,
        // incluidos STNE (StoneCo) y TV (Grupo Televisa). CIB corresponde a
        // Bancolombia, que se reorganizo como Grupo Cibest S.A. en mayo 2025
        // manteniendo el mismo ticker CIB en NYSE.
        'tickers' => [
            'VALE', 'PBR', 'ITUB', 'BBD', 'ABEV', 'XP', 'STNE', 'PAGS', 'NU', 'SUZ',
            'GGB', 'SID', 'VIV', 'TIMB', 'AMX', 'FMX', 'TV', 'CX', 'PAC', 'ASR',
            'MELI', 'EC', 'CIB', 'BAP', 'SCCO', 'ARCO',
        ],
    ],
    // Cadena de valor global de semiconductores. Se mantiene el solape con
    // 'tech40' (NVDA, AVGO, AMD, INTC, QCOM, TXN, MU, LRCX, AMAT: 9 tickers
    // en comun) a proposito: 'tech40' es tecnologia ampliada de EEUU y este
    // grupo es especificamente la cadena de valor de semiconductores a
    // nivel mundial (diseno EEUU + fabricacion/equipos Taiwan, Europa y
    // Asia), un proposito distinto que interesa comparar como bloque propio.
    'semiconductors_global' => [
        'label' => 'Semiconductores globales',
        'tickers' => [
            'NVDA', 'AVGO', 'AMD', 'INTC', 'QCOM', 'TXN', 'MU', 'LRCX', 'AMAT', 'KLAC',
            'MRVL', 'ON', 'MCHP', 'ADI', 'SWKS', 'QRVO',
            'TSM', 'ASML', 'STM', 'NXPI', 'ASX', 'UMC',
        ],
    ],
];

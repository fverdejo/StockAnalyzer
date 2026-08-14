<?php

declare(strict_types=1);

// El PRIMER universo de esta lista es el que aparece arriba del
// desplegable del Home; el que se analiza por defecto lo fija
// Application::DEFAULT_UNIVERSE ('largecap60' desde v2.86).
//
// 'general' ("Movimientos de hoy") es el unico universo dinamico: desde
// v2.12, Application::resolveMoversUniverseTickers() lo construye en vivo
// con las 20 acciones que mas suben y las 20 que mas bajan hoy segun el
// screener de Yahoo Finance (ver Providers\YahooMarketMoversProvider).
// La lista fija que lleva asociada solo se usa como respaldo si ese
// screener falla (endpoint no oficial, puede cambiar sin aviso).
return [
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
    // Universo dinamico: la lista de abajo es solo el respaldo del screener.
    'general' => [
        'label' => 'Movimientos de hoy',
        'tickers' => [
            'AAPL', 'MSFT', 'NVDA', 'AMZN', 'GOOGL', 'META', 'TSLA', 'AVGO', 'BRK-B', 'JPM',
            'LLY', 'V', 'XOM', 'UNH', 'MA', 'COST', 'NFLX', 'WMT', 'PG', 'JNJ',
            'HD', 'ABBV', 'BAC', 'KO', 'CRM', 'ORCL', 'CVX', 'MRK', 'AMD', 'PEP',
            'LIN', 'TMO', 'ACN', 'MCD', 'CSCO', 'ADBE', 'IBM', 'QCOM', 'WFC', 'CAT',
            'TXN', 'INTU', 'AMGN', 'DIS', 'GS', 'ISRG', 'VZ', 'NOW', 'PFE', 'NKE',
            'SAN.MC', 'BBVA.MC', 'IBE.MC', 'ITX.MC', 'REP.MC', 'TEF.MC', 'FER.MC', 'AMS.MC', 'CABK.MC', 'ELE.MC',
        ],
    ],
    'magnificent7' => [
        'label' => '7 Magníficas',
        'tickers' => ['AAPL', 'MSFT', 'NVDA', 'AMZN', 'GOOGL', 'META', 'TSLA'],
    ],
    // Composicion completa (30/30) verificada el 2026-08-08 contra los dos
    // ultimos cambios anunciados por S&P Dow Jones Indices:
    //   - 2024-11-08: NVDA entra por INTC y SHW entra por DOW.
    //   - 2026-06-29: GOOGL (Alphabet clase A) entra por VZ (Verizon), que
    //     salio tras 22 anos por pesar solo ~0,5% en un indice ponderado por
    //     precio. Es el unico cambio del indice desde noviembre de 2024.
    // Hasta v2.60 esta lista tenia 29 tickers: faltaba NVDA (olvido de 2024,
    // las otras dos patas de aquel cambio si estaban aplicadas) y seguia
    // VZ en lugar de GOOGL.
    // HON se mantiene en el indice pese al spin-off de Honeywell Aerospace
    // (completado el 2026-06-29, la escindida cotiza aparte como HONA en
    // Nasdaq y NO forma parte del DJIA): la matriz pasa a llamarse Honeywell
    // Technologies pero conserva el ticker HON. Ojo al leer su historico:
    // ademas del spin-off hizo un contrasplit 1x2 en esa misma fecha.
    'dow30' => [
        'label' => 'Dow Jones 30',
        'tickers' => [
            'AAPL', 'AMGN', 'AMZN', 'AXP', 'BA', 'CAT', 'CRM', 'CSCO', 'CVX', 'DIS',
            'GOOGL', 'GS', 'HD', 'HON', 'IBM', 'JNJ', 'JPM', 'KO', 'MCD', 'MMM',
            'MRK', 'MSFT', 'NKE', 'NVDA', 'PG', 'SHW', 'TRV', 'UNH', 'V', 'WMT',
        ],
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
    // OJO: la clave 'tech40' NO significa "40 tickers", son 20. El nombre es
    // historico y se conserva a proposito porque cambiarlo romperia los
    // rankings ya guardados en la tabla 'daily_rankings' y cualquier enlace
    // existente con ?universe=tech40. La etiqueta visible ("Tecnologia
    // ampliada") no promete un numero, asi que el usuario final no se ve
    // afectado; a diferencia de 'dow30' o 'ibex35', aqui no hay un indice
    // real de referencia contra el que cuadrar la lista.
    'tech40' => [
        'label' => 'Tecnologia ampliada',
        'tickers' => ['AAPL', 'MSFT', 'NVDA', 'AVGO', 'ORCL', 'AMD', 'ADBE', 'CRM', 'CSCO', 'INTC', 'IBM', 'QCOM', 'TXN', 'NOW', 'AMAT', 'MU', 'LRCX', 'PANW', 'SNOW', 'SHOP'],
    ],
    // Grupos por sector/categoria (no por indice), maximo 50 tickers cada uno.
    // 'financials' mezcla tres modelos de negocio muy distintos (banca con
    // balance apalancado y riesgo de credito, aseguradoras con riesgo de
    // suscripcion, y pagos/gestion de activos con negocio de comisiones sin
    // apenas balance). Se mantiene como alias amplio (misma lista de siempre,
    // sin cambios) para comparativas de todo el sector financiero, pero desde
    // 2026-08-01 conviven con 3 subgrupos mas homogeneos justo debajo
    // ('financials_banking', 'financials_insurance',
    // 'financials_payments_asset_mgmt') pensados para comparar "las mejores
    // del grupo" de forma mas honesta; mismo precedente que el solape
    // deliberado entre 'tech40' y 'semiconductors_global' de mas abajo.
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
    // Banca comercial, banca de inversion/brokers y bancos regionales: balance
    // apalancado financiado con depositos/deuda y riesgo de credito como
    // motor principal del negocio. SCHW (Charles Schwab), SYF (Synchrony) y
    // ALLY (Ally Financial) son entidades con banco propio (deposit-taking)
    // aunque su origen sea brokerage/tarjetas/auto, por eso van aqui y no en
    // el grupo de pagos. RJF (Raymond James) se incluye junto a GS/MS por ser
    // banca de inversion/brokerage, no gestora de activos pura. Los 4 tickers
    // '.MC' (SAN, BBVA, CABK, UNI) son bancos comerciales espanoles.
    'financials_banking' => [
        'label' => 'Financieras - Banca',
        'tickers' => [
            'JPM', 'BAC', 'WFC', 'C', 'GS', 'MS', 'SCHW', 'COF', 'USB', 'PNC',
            'TFC', 'STT', 'SYF', 'ALLY', 'RJF',
            'SAN.MC', 'BBVA.MC', 'CABK.MC', 'UNI.MC',
        ],
    ],
    // Aseguradoras (riesgo de suscripcion) y brokers de seguros (comision
    // sobre polizas colocadas, sin asumir riesgo de suscripcion ellos
    // mismos): WTW, AON y AJG son brokers, el resto son aseguradoras
    // directas. Sin tickers '.MC' en este grupo.
    'financials_insurance' => [
        'label' => 'Financieras - Seguros',
        'tickers' => [
            'MET', 'PRU', 'AIG', 'ALL', 'TRV', 'CB', 'PGR', 'WTW', 'AON', 'AJG',
        ],
    ],
    // Pagos, exchanges/datos financieros y gestion de activos: negocio de
    // comisiones intensivo en escala y capital-light frente al balance
    // apalancado de la banca. Incluye redes/procesadores de pago (V, MA,
    // PYPL, FIS, GPN), AXP (aunque emite tarjeta y presta, su negocio se
    // parece mas a la red de pagos V/MA que a un banco comercial), exchanges
    // y proveedores de rating/datos (ICE, CME, SPGI, MCO), y gestoras de
    // activos tradicionales y alternativas (BLK, AMP, BX, KKR, APO). Sin
    // tickers '.MC' en este grupo.
    'financials_payments_asset_mgmt' => [
        'label' => 'Financieras - Pagos y gestion de activos',
        'tickers' => [
            'V', 'MA', 'PYPL', 'AXP', 'FIS', 'GPN', 'SPGI', 'ICE', 'CME', 'MCO',
            'BLK', 'AMP', 'BX', 'KKR', 'APO',
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
    // 'consumer' mezcla consumo discrecional (gasto que el consumidor puede
    // aplazar/recortar: retail generalista, ocio, viajes, restauracion) con
    // consumo defensivo/staples (gasto recurrente poco sensible al ciclo:
    // alimentacion, higiene, tabaco, bebidas). Se mantiene como alias amplio
    // (misma lista de siempre, sin cambios) para comparativas de todo el
    // sector consumo, pero desde 2026-08-01 convive con 2 subgrupos mas
    // homogeneos justo debajo ('consumer_discretionary',
    // 'consumer_staples'); mismo precedente que el solape deliberado entre
    // 'tech40' y 'semiconductors_global' de mas abajo.
    'consumer' => [
        'label' => 'Consumo',
        'tickers' => [
            'AMZN', 'WMT', 'COST', 'HD', 'MCD', 'NKE', 'SBUX', 'TGT', 'LOW', 'TJX',
            'BKNG', 'CMG', 'MAR', 'YUM', 'DG', 'ROST', 'KO', 'PEP', 'PG', 'PM',
            'MO', 'MDLZ', 'CL', 'KMB', 'GIS', 'STZ', 'EL', 'KHC', 'HSY', 'CLX',
            'ITX.MC',
        ],
    ],
    // Consumo discrecional: retail generalista y de mejora del hogar,
    // restauracion, ocio/viajes y moda, gasto que el consumidor puede
    // aplazar o recortar en un ciclo bajista. ITX.MC (Inditex/Zara) es moda
    // de consumo discrecional, va aqui. Nota: WMT, COST, DG y TGT estan en
    // 'consumer_staples' y no aqui, siguiendo la clasificacion GICS vigente
    // desde 2018 que reclasifico a los grandes distribuidores/hipermercados
    // como "Consumer Staples Distribution & Retail" en vez de discrecional.
    // TGT se movio el 2026-08-05 (v2.54): se quedo aqui por descuido cuando
    // WMT/COST/DG ya se habian movido, pese a que Yahoo la clasifica igual
    // que ellas (sector=Consumer Defensive, industry=Discount Stores,
    // verificado en ddev via YahooFundamentalsFetcher/YahooParser). Ver
    // versions.md v2.54.
    'consumer_discretionary' => [
        'label' => 'Consumo discrecional',
        'tickers' => [
            'AMZN', 'HD', 'MCD', 'NKE', 'SBUX', 'LOW', 'TJX', 'BKNG', 'CMG',
            'MAR', 'YUM', 'ROST', 'ITX.MC',
        ],
    ],
    // Consumo defensivo/staples: alimentacion, higiene/hogar, bebidas y
    // tabaco, gasto recurrente poco sensible al ciclo economico. Incluye WMT,
    // COST, DG y TGT (grandes distribuidores/hipermercados y descuento, ver
    // nota en 'consumer_discretionary' sobre la reclasificacion GICS 2018) y
    // EL (Estee Lauder, cosmetica de higiene/cuidado personal clasificada
    // como staples pese a la percepcion de "lujo/discrecional"). Sin
    // tickers '.MC' en este grupo.
    'consumer_staples' => [
        'label' => 'Consumo defensivo',
        'tickers' => [
            'WMT', 'COST', 'DG', 'TGT', 'KO', 'PEP', 'PG', 'PM', 'MO', 'MDLZ',
            'CL', 'KMB', 'GIS', 'STZ', 'EL', 'KHC', 'HSY', 'CLX',
        ],
    ],
    // ADP (Automatic Data Processing) y PAYX (Paychex) se sacaron de aqui el
    // 2026-08-05: pese al nombre y la percepcion de "servicios de nomina
    // industriales", Yahoo las clasifica como sector=Technology,
    // industry=Software - Application (verificado en ddev via
    // YahooCorporateProfileProvider/assetProfile), no como Industrials. A
    // diferencia de otros solapes documentados en este fichero (semiconductores
    // EEUU tambien en 'tech40', REP.MC/ITX.MC en varios universos geograficos),
    // este no era un solape deliberado, era una etiqueta incorrecta. Ver
    // versions.md v2.52.
    'industrials' => [
        'label' => 'Industria',
        'tickers' => [
            'CAT', 'HON', 'UNP', 'RTX', 'BA', 'GE', 'DE', 'LMT', 'ETN',
            'UPS', 'NOC', 'GD', 'ITW', 'EMR', 'CSX', 'WM', 'NSC', 'PH', 'TT',
            'CMI', 'PCAR', 'ROK', 'FDX', 'CTAS', 'FAST', 'ODFL', 'JCI',
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

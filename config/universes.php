<?php

declare(strict_types=1);

// El PRIMER universo de esta lista es el que aparece arriba del
// desplegable del Home; el que se analiza por defecto lo fija
// Application::DEFAULT_UNIVERSE ('largecap60' desde v2.86).
//
// El universo dinamico 'general' ("Movimientos de hoy", las acciones que
// mas suben/bajan segun el screener de Yahoo) se retiro por completo el
// 2026-09-06 a peticion del usuario (ver versions.md, misma fecha): ya no
// es seleccionable, y con el se retiraron YahooMarketMoversProvider,
// CachedMarketMoversProvider y MarketMoversCacheRepository.
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
    // Composicion completa (503/503, incluidas las 3 empresas con doble
    // clase de accion del indice: Alphabet GOOGL/GOOG, Fox Corp FOXA/FOX y
    // News Corp NWSA/NWS) curada el 2026-09-01 desde Wikipedia
    // (en.wikipedia.org/wiki/List_of_S%26P_500_companies, tabla
    // "constituents"), con el mismo ajuste de formato ya conocido de
    // 'ibex35'/'dow30': el punto de las clases de accion se convierte en
    // guion para Yahoo (BRK.B->BRK-B, BF.B->BF-B). CBOE se extrae de una
    // plantilla de wikitext distinta a la del resto de la tabla
    // ({{BZX link|CBOE}} en vez de {{NyseSymbol|...}}), facil de perder si
    // se vuelve a generar esta lista sin revisar manualmente ese caso.
    // Verificados los 503/503 contra el endpoint de Yahoo Finance el
    // 2026-09-01 (chart/{ticker}, 0 fallos), incluidos los casos mas
    // atipicos de la lista (las 3 clases dobles, BRK-B/BF-B, y varios
    // spin-offs/cambios de ticker recientes: GEV, SOLV, VLTO, KVUE, CEG,
    // XYZ, CPAY, COR, GEHC, FDXF, HONA, PSKY, Q, SW, TKO, VMRK). De paso
    // se detecto que Marsh & McLennan cotiza hoy como MRSH (no MMC, que
    // devuelve 404) - no afecta a ningun otro universo de este fichero,
    // que no la incluye.
    // Como el resto de universos de este fichero, es una lista de HOY: la
    // composicion del S&P 500 cambia con revisiones trimestrales/ad-hoc, y
    // con ventanas de historico largas (5y/10y/max) hay sesgo de
    // supervivencia (ver roadmap.md). No es apto como universo
    // "independiente" para repetir la investigacion de fundamentales
    // (mismo regimen de mercado que 'largecap60', ver versions.md v2.105);
    // se añade para poder filtrar por el en el Home, igual que 'ibex35'.
    'sp500' => [
        'label' => 'S&P 500',
        'tickers' => [
            'A', 'AAPL', 'ABBV', 'ABNB', 'ABT', 'ACGL', 'ACN', 'ADBE', 'ADI', 'ADM',
            'ADP', 'ADSK', 'AEE', 'AEP', 'AES', 'AFL', 'AIG', 'AIZ', 'AJG', 'AKAM',
            'ALB', 'ALGN', 'ALL', 'ALLE', 'AMAT', 'AMCR', 'AMD', 'AME', 'AMGN', 'AMP',
            'AMT', 'AMZN', 'ANET', 'AON', 'AOS', 'APA', 'APD', 'APH', 'APO', 'APP',
            'APTV', 'ARE', 'ARES', 'ATO', 'AVGO', 'AVY', 'AWK', 'AXON', 'AXP', 'AZO',
            'BA', 'BAC', 'BALL', 'BAX', 'BBY', 'BDX', 'BEN', 'BF-B', 'BG', 'BIIB',
            'BKNG', 'BKR', 'BLDR', 'BLK', 'BMY', 'BNY', 'BR', 'BRK-B', 'BRO', 'BSX',
            'BX', 'BXP', 'C', 'CAH', 'CARR', 'CASY', 'CAT', 'CB', 'CBOE', 'CBRE',
            'CCI', 'CCL', 'CDNS', 'CDW', 'CEG', 'CF', 'CFG', 'CHD', 'CHRW', 'CHTR',
            'CI', 'CIEN', 'CINF', 'CL', 'CLX', 'CMCSA', 'CME', 'CMG', 'CMI', 'CMS',
            'CNC', 'CNP', 'COF', 'COHR', 'COIN', 'COO', 'COP', 'COR', 'COST', 'CPAY',
            'CPRT', 'CPT', 'CRH', 'CRL', 'CRM', 'CRWD', 'CSCO', 'CSGP', 'CSX', 'CTAS',
            'CTSH', 'CTVA', 'CVNA', 'CVS', 'CVX', 'D', 'DAL', 'DASH', 'DD', 'DDOG',
            'DE', 'DECK', 'DELL', 'DG', 'DGX', 'DHI', 'DHR', 'DIS', 'DLR', 'DLTR',
            'DOC', 'DOV', 'DOW', 'DPZ', 'DRI', 'DTE', 'DUK', 'DVA', 'DVN', 'DXCM',
            'EBAY', 'ECHO', 'ECL', 'ED', 'EFX', 'EG', 'EIX', 'EL', 'ELV', 'EME',
            'EMR', 'EOG', 'EQIX', 'EQT', 'ERIE', 'ES', 'ESS', 'ETN', 'ETR', 'EVRG',
            'EW', 'EXC', 'EXE', 'EXPD', 'EXPE', 'EXR', 'F', 'FANG', 'FAST', 'FCX',
            'FDS', 'FDX', 'FDXF', 'FE', 'FERG', 'FFIV', 'FICO', 'FIS', 'FISV', 'FITB',
            'FIX', 'FLEX', 'FOX', 'FOXA', 'FRT', 'FSLR', 'FTNT', 'FTV', 'GD', 'GDDY',
            'GE', 'GEHC', 'GEN', 'GEV', 'GILD', 'GIS', 'GL', 'GLW', 'GM', 'GNRC',
            'GOOG', 'GOOGL', 'GPC', 'GPN', 'GRMN', 'GS', 'GWW', 'HAL', 'HAS', 'HBAN',
            'HCA', 'HD', 'HIG', 'HII', 'HLT', 'HON', 'HONA', 'HOOD', 'HPE', 'HPQ',
            'HRL', 'HSIC', 'HST', 'HSY', 'HUBB', 'HUM', 'HWM', 'IBKR', 'IBM', 'ICE',
            'IDXX', 'IEX', 'IFF', 'INCY', 'INTC', 'INTU', 'INVH', 'IP', 'IQV', 'IR',
            'IRM', 'ISRG', 'IT', 'ITW', 'IVZ', 'J', 'JBHT', 'JBL', 'JCI', 'JKHY',
            'JNJ', 'JPM', 'KDP', 'KEY', 'KEYS', 'KHC', 'KIM', 'KKR', 'KLAC', 'KMB',
            'KMI', 'KO', 'KR', 'KVUE', 'L', 'LDOS', 'LEN', 'LH', 'LHX', 'LII',
            'LIN', 'LITE', 'LLY', 'LMT', 'LNT', 'LOW', 'LRCX', 'LULU', 'LUV', 'LVS',
            'LYB', 'LYV', 'MA', 'MAA', 'MAR', 'MAS', 'MCD', 'MCHP', 'MCK', 'MCO',
            'MDLZ', 'MDT', 'MET', 'META', 'MGM', 'MKC', 'MLM', 'MMM', 'MNST', 'MO',
            'MOS', 'MPC', 'MPWR', 'MRK', 'MRNA', 'MRSH', 'MRVL', 'MS', 'MSCI', 'MSFT',
            'MSI', 'MTB', 'MTD', 'MU', 'NCLH', 'NDAQ', 'NDSN', 'NEE', 'NEM', 'NFLX',
            'NI', 'NKE', 'NOC', 'NOW', 'NRG', 'NSC', 'NTAP', 'NTRS', 'NUE', 'NVDA',
            'NVR', 'NWS', 'NWSA', 'NXPI', 'O', 'ODFL', 'OKE', 'OMC', 'ON', 'ORCL',
            'ORLY', 'OTIS', 'OXY', 'PANW', 'PAYX', 'PCAR', 'PCG', 'PEG', 'PEP', 'PFE',
            'PFG', 'PG', 'PGR', 'PH', 'PHM', 'PKG', 'PLD', 'PLTR', 'PM', 'PNC',
            'PNR', 'PNW', 'PODD', 'PPG', 'PPL', 'PRU', 'PSA', 'PSKY', 'PSX', 'PTC',
            'PWR', 'PYPL', 'Q', 'QCOM', 'RCL', 'RDDT', 'REG', 'REGN', 'RF', 'RJF',
            'RL', 'RMD', 'ROK', 'ROL', 'ROP', 'ROST', 'RSG', 'RTX', 'RVTY', 'SBAC',
            'SBUX', 'SCHW', 'SHW', 'SJM', 'SLB', 'SMCI', 'SNA', 'SNDK', 'SNPS', 'SO',
            'SOLV', 'SPG', 'SPGI', 'SRE', 'STE', 'STLD', 'STT', 'STX', 'STZ', 'SW',
            'SWK', 'SWKS', 'SYF', 'SYK', 'SYY', 'T', 'TAP', 'TDG', 'TDY', 'TECH',
            'TEL', 'TER', 'TFC', 'TGT', 'TJX', 'TKO', 'TMO', 'TMUS', 'TPL', 'TPR',
            'TRGP', 'TRMB', 'TROW', 'TRV', 'TSCO', 'TSLA', 'TSN', 'TT', 'TTD', 'TTWO',
            'TXN', 'TXT', 'TYL', 'UAL', 'UBER', 'UDR', 'UHS', 'ULTA', 'UNH', 'UNP',
            'UPS', 'URI', 'USB', 'V', 'VEEV', 'VICI', 'VLO', 'VLTO', 'VMC', 'VMRK',
            'VRSK', 'VRSN', 'VRT', 'VRTX', 'VST', 'VTR', 'VTRS', 'VZ', 'WAB', 'WAT',
            'WBD', 'WDAY', 'WDC', 'WEC', 'WELL', 'WFC', 'WM', 'WMB', 'WMT', 'WRB',
            'WSM', 'WST', 'WTW', 'WY', 'WYNN', 'XEL', 'XOM', 'XYL', 'XYZ', 'YUM',
            'ZBH', 'ZBRA', 'ZTS',
        ],
    ],
    // Composicion completa curada el 2026-09-01 desde Wikipedia
    // (en.wikipedia.org/wiki/List_of_NASDAQ-100_companies, tabla
    // "constituents"), orden original de la tabla (no alfabetico). Son 102
    // tickers para 101 empresas: Alphabet aporta doble clase (GOOGL/GOOG),
    // igual que en 'sp500'. Todos verificados contra el endpoint de Yahoo
    // Finance el 2026-09-01 (102/102, 0 fallos), incluidos los casos mas
    // atipicos de la lista (SPCX = Space Exploration Technologies/SpaceX,
    // MSTR ahora cotiza como "Strategy Inc", TRI = Thomson Reuters, FER =
    // Ferrovial -no confundir con Ferrari-, HONA = Honeywell Aerospace,
    // varias incorporaciones recientes: RKLB, NBIS, CRWV, ALAB, ARM, ALNY,
    // SNDK, STX, CCEP). Fuerte solape deliberado con 'largecap60'/'tech40'
    // (aceptado, mismo precedente que 'tech40'/'semiconductors_global').
    // Mismo aviso de lista-de-hoy/sesgo de supervivencia que 'sp500'.
    'nasdaq100' => [
        'label' => 'Nasdaq 100',
        'tickers' => [
            'ADBE', 'AMD', 'ABNB', 'ALNY', 'GOOGL', 'GOOG', 'AMZN', 'AEP', 'AMGN', 'ADI',
            'AAPL', 'AMAT', 'APP', 'ARM', 'ASML', 'ALAB', 'ADSK', 'ADP', 'AXON', 'BKR',
            'BKNG', 'AVGO', 'CDNS', 'CTAS', 'CSCO', 'CCEP', 'CMCSA', 'CEG', 'CPRT', 'CRWV',
            'COST', 'CRWD', 'CSX', 'DDOG', 'DXCM', 'FANG', 'DASH', 'EXC', 'FAST', 'FER',
            'FTNT', 'GEHC', 'GILD', 'HONA', 'HON', 'IDXX', 'INTC', 'INTU', 'ISRG', 'KDP',
            'KLAC', 'KHC', 'LRCX', 'LIN', 'LITE', 'MAR', 'MRVL', 'MELI', 'META', 'MCHP',
            'MU', 'MSFT', 'MSTR', 'MDLZ', 'MPWR', 'MNST', 'NBIS', 'NFLX', 'NVDA', 'NXPI',
            'ORLY', 'ODFL', 'PCAR', 'PLTR', 'PANW', 'PAYX', 'PYPL', 'PDD', 'PEP', 'QCOM',
            'REGN', 'RKLB', 'ROP', 'ROST', 'SNDK', 'STX', 'SHOP', 'SPCX', 'SBUX', 'SNPS',
            'TMUS', 'TTWO', 'TER', 'TSLA', 'TXN', 'TRI', 'VRTX', 'WMT', 'WBD', 'WDC',
            'WDAY', 'XEL',
        ],
    ],
    // Curado el 2026-09-07 desde el CSV publico de holdings de iShares Core
    // S&P Mid-Cap ETF (IJH, fondo de replica fisica/muestreo representativo,
    // no sintetico -- mismo criterio de fuente que 'msci_world'), filtrado a
    // Asset Class=Equity (407 filas) y deduplicado (7 filas Type=SWAP que
    // repiten el ticker de una tenencia EQUITY ya contada) = 400 tickers
    // unicos. Ningun ticker fabricado de memoria: todos vienen literalmente
    // del CSV descargado. Verificados 400/400 contra el endpoint de Yahoo
    // Finance el mismo dia (cotizan hoy, EQUITY, bolsa EEUU). Un caso de
    // formato, mismo patron que BRKB->BRK-B en 'msci_world': el CSV escribe
    // "MOGA" (Moog Inc. Clase A) sin separador, corregido a mano a "MOG-A"
    // tras confirmar en Yahoo. 17 tickers son OPV/spin-offs de 2021-2025
    // (BROS, TOST, KD, ESAB, CRBG, NXT, CR, TLN, KNF, CAVA, SN, CART, BTSG,
    // AHR, ULS, SARO, SOLS) con menos de ~5 años de historico, no un fallo de
    // datos. 36/400 ya aparecian en otro universo de este fichero (sobre
    // todo 'financials'/'healthcare'/'industrials'/'msci_world'); los 364
    // restantes no se habian usado nunca en un backtest de este proyecto.
    // A DIFERENCIA de 'sp500'/'nasdaq100' (mismo aviso "no apto como
    // universo independiente" de sus comentarios): este SI se añade
    // especificamente para eso -- repetir la investigacion del "score
    // fundamental" (P3.3/v2.114, veredicto nulo) en un regimen de mercado
    // genuinamente distinto (mid-cap, no large-cap EEUU), condicion que
    // el usuario puso el 2026-08-21 antes de volver a medir. Ver
    // versions.md, entrada del 2026-09-07, para el intento previo con los
    // universos ADR que resulto invalido por datos de EODHD (filing_date
    // de relleno) y el porque de elegir IJH sobre IJR/una mezcla de ambos.
    'sp400' => [
        'label' => 'S&P MidCap 400',
        'tickers' => [
            'AA', 'AAL', 'AAON', 'ACI', 'ACM', 'ADC', 'AEIS', 'AFG', 'AGCO', 'AHR',
            'AIT', 'ALGM', 'ALK', 'ALLY', 'ALSN', 'ALV', 'AM', 'AMG', 'AMH', 'AMKR',
            'AN', 'ANF', 'APG', 'APPF', 'AR', 'ARMK', 'ARW', 'ARWR', 'ASB', 'ASH',
            'ATI', 'ATR', 'AVAV', 'AVNT', 'AVT', 'AVTR', 'AXTA', 'AYI', 'BAH', 'BBWI',
            'BC', 'BCO', 'BDC', 'BHF', 'BILL', 'BIO', 'BJ', 'BKH', 'BMRN', 'BRKR',
            'BROS', 'BRX', 'BSY', 'BTSG', 'BURL', 'BWA', 'BWXT', 'BYD', 'CACI', 'CAR',
            'CART', 'CAVA', 'CBSH', 'CBT', 'CCK', 'CDE', 'CDP', 'CELH', 'CFR', 'CG',
            'CGNX', 'CHDN', 'CHE', 'CHH', 'CHRD', 'CHWY', 'CLF', 'CLH', 'CMC', 'CNH',
            'CNM', 'CNO', 'CNX', 'COKE', 'COLB', 'COLM', 'CPRI', 'CR', 'CRBG', 'CROX',
            'CRS', 'CRUS', 'CSL', 'CTRE', 'CUBE', 'CUZ', 'CVLT', 'CW', 'CXT', 'CYTK',
            'DAR', 'DBX', 'DCI', 'DINO', 'DKS', 'DLB', 'DOCN', 'DOCS', 'DOCU', 'DT',
            'DTM', 'DUOL', 'DY', 'EEFT', 'EGP', 'EHC', 'ELAN', 'ELF', 'ELS', 'ENS',
            'ENSG', 'ENTG', 'EPR', 'EQH', 'ESAB', 'ESNT', 'EVR', 'EWBC', 'EXEL', 'EXLS',
            'EXP', 'EXPO', 'FAF', 'FBIN', 'FCFS', 'FCN', 'FFIN', 'FHI', 'FHN', 'FIVE',
            'FLG', 'FLR', 'FLS', 'FN', 'FNB', 'FND', 'FNF', 'FOUR', 'FR', 'FTI',
            'G', 'GAP', 'GATX', 'GBCI', 'GEF', 'GGG', 'GHC', 'GLPI', 'GME', 'GMED',
            'GNTX', 'GPK', 'GWRE', 'GXO', 'H', 'HAE', 'HALO', 'HGV', 'HIMS', 'HL',
            'HLI', 'HLNE', 'HOG', 'HOMB', 'HQY', 'HR', 'HRB', 'HWC', 'HXL', 'IBOC',
            'IDA', 'IDCC', 'IESC', 'ILMN', 'INGR', 'IPGP', 'IRT', 'ITT', 'JAZZ', 'JEF',
            'JLL', 'KBH', 'KBR', 'KD', 'KEX', 'KNF', 'KNSL', 'KNX', 'KRC', 'KRG',
            'KRYS', 'KTOS', 'LAD', 'LAMR', 'LEA', 'LECO', 'LFUS', 'LIVN', 'LNTH', 'LOPE',
            'LPX', 'LSCC', 'LSTR', 'M', 'MANH', 'MAT', 'MEDP', 'MIDD', 'MKSI', 'MLI',
            'MMS', 'MOG-A', 'MOH', 'MORN', 'MP', 'MSA', 'MSM', 'MTDR', 'MTG', 'MTN',
            'MTSI', 'MTZ', 'MUR', 'MUSA', 'MZTI', 'NBIX', 'NEU', 'NFG', 'NJR', 'NLY',
            'NNN', 'NOV', 'NOVT', 'NTNX', 'NVST', 'NVT', 'NWE', 'NXST', 'NXT', 'NYT',
            'OC', 'OGE', 'OGS', 'OHI', 'OKTA', 'OLED', 'OLLI', 'OLN', 'ONB', 'ONTO',
            'OPCH', 'ORA', 'ORI', 'OSK', 'OVV', 'OZK', 'P', 'PAG', 'PATH', 'PB',
            'PBF', 'PCTY', 'PEGA', 'PEN', 'PFGC', 'PII', 'PINS', 'PK', 'PLNT', 'PNFP',
            'POR', 'POST', 'PPC', 'PR', 'PRI', 'PSN', 'PVH', 'QLYS', 'R', 'RBA',
            'RBC', 'REXR', 'RGA', 'RGEN', 'RGLD', 'RH', 'RLI', 'RMBS', 'RNR', 'ROIV',
            'ROKU', 'RPM', 'RRC', 'RRX', 'RS', 'RYAN', 'RYN', 'SAIA', 'SAIC', 'SAM',
            'SANM', 'SARO', 'SBRA', 'SCI', 'SEIC', 'SF', 'SFM', 'SGI', 'SHC', 'SIGI',
            'SIRI', 'SITM', 'SLAB', 'SLGN', 'SLM', 'SMG', 'SMTC', 'SN', 'SNX', 'SOLS',
            'SON', 'SPXC', 'SR', 'SSB', 'SSD', 'ST', 'STAG', 'STRL', 'STWD', 'SUI',
            'SWX', 'SYNA', 'TCBI', 'TEX', 'THC', 'THG', 'THO', 'TKR', 'TLN', 'TNL',
            'TOL', 'TOST', 'TREX', 'TRU', 'TTC', 'TTEK', 'TTMI', 'TWLO', 'TXNM', 'TXRH',
            'UBSI', 'UFPI', 'UGI', 'ULS', 'UMBF', 'UNM', 'USFD', 'UTHR', 'VAL', 'VC',
            'VFC', 'VIAV', 'VICR', 'VLY', 'VMI', 'VNO', 'VNOM', 'VNT', 'VOYA', 'VVV',
            'WAL', 'WCC', 'WEX', 'WFRD', 'WH', 'WHR', 'WING', 'WLK', 'WMG', 'WMS',
            'WPC', 'WSO', 'WTFC', 'WTRG', 'WTS', 'WWD', 'XPO', 'XRAY', 'YETI', 'ZION',
        ],
    ],
    // Curado el 2026-09-07, mismo dia y mismo lote de descarga que 'sp400',
    // desde el CSV publico de holdings de iShares Core S&P Small-Cap ETF
    // (IJR, replica fisica/muestreo representativo). 669 filas Asset
    // Class=Equity: 601 Type=EQUITY (0 duplicados entre si) + 66
    // Type=SWAP (65 repiten el ticker de una tenencia EQUITY ya contada,
    // descartadas) + 2 Type=WARRANT sin ticker real (descartadas). Una
    // fila SWAP NO tiene equivalente EQUITY -- FG (F&G Annuities & Life),
    // exposicion 100% sintetica via swap sin tenencia fisica en el CSV,
    // pero una posicion real del fondo (confirmada tambien contra
    // Yahoo) -- se incluye: 601 + 1 = 602 tickers. Ningun ticker
    // fabricado de memoria, todos vienen literalmente del CSV. A
    // diferencia de 'sp400' (un caso de formato, MOGA->MOG-A), aqui
    // ningun ticker lleva guion/punto de clase de accion.
    // Verificados 602/602 contra el endpoint de Yahoo Finance el mismo
    // dia (cotizan hoy, EQUITY, bolsa EEUU, 0 errores). 54 tickers tienen
    // `firstTradeDate` desde 2021 (OPV/spin-offs recientes, dato real no
    // fallo de mapeo), incluidos 4 casos de 2026 (ADIG, MBGL, MFP, VGNT)
    // confirmados como holdings reales por coincidencia exacta de nombre
    // CSV/Yahoo, no ticker reciclado.
    // Solo 1/602 (QRVO) ya estaba en otro universo de este fichero
    // (`semiconductors_global`); 0 solapamiento con 'sp400' (regimen
    // small-cap genuinamente distinto de mid-cap, coherente con el
    // proposito de este universo). 601 tickers son completamente nuevos.
    // Mismo proposito que 'sp400' (a diferencia de 'sp500'/'nasdaq100',
    // que llevan escrito "no apto como universo independiente"): tercera
    // replica de la investigacion del "score fundamental" (P3.3/`v2.114`
    // large-cap nulo, `sp400` mid-cap nulo, ver versions.md 2026-09-07)
    // en un regimen de mercado genuinamente distinto (small-cap).
    'sp600' => [
        'label' => 'S&P SmallCap 600',
        'tickers' => [
            'AAMI', 'AAP', 'AAT', 'ABCB', 'ABG', 'ABM', 'ABR', 'ACA', 'ACAD', 'ACHC',
            'ACIW', 'ACLS', 'ACMR', 'ACT', 'ADAM', 'ADEA', 'ADIG', 'ADMA', 'ADNT', 'ADT',
            'ADUS', 'AEO', 'AESI', 'AGNT', 'AGO', 'AGX', 'AGYS', 'AHCO', 'AIN', 'AIR',
            'AKR', 'ALG', 'ALGT', 'ALHC', 'ALKS', 'ALRM', 'AMN', 'AMPH', 'AMR', 'AMRX',
            'AMSF', 'AMTM', 'ANDE', 'ANIP', 'AORT', 'AOSL', 'APAM', 'APLE', 'APOG', 'ARCB',
            'ARLO', 'AROC', 'ARR', 'ASO', 'ASTE', 'ASTH', 'ATEN', 'ATMU', 'AUB', 'AVA',
            'AWI', 'AWR', 'AX', 'AZTA', 'AZZ', 'BANC', 'BANF', 'BANR', 'BBT', 'BCC',
            'BCPC', 'BFAM', 'BFH', 'BFS', 'BGC', 'BHE', 'BJRI', 'BKE', 'BKU', 'BL',
            'BLFS', 'BLKB', 'BMI', 'BNL', 'BOH', 'BOOT', 'BOX', 'BRC', 'BTU', 'BXMT',
            'CACC', 'CAG', 'CAKE', 'CALM', 'CALX', 'CALY', 'CARG', 'CASH', 'CATY', 'CBRL',
            'CBU', 'CC', 'CCOI', 'CCS', 'CE', 'CENT', 'CENTA', 'CENX', 'CERT', 'CFFN',
            'CHCO', 'CHEF', 'CLSK', 'CNK', 'CNMD', 'CNR', 'CNS', 'CNXC', 'CNXN', 'COCO',
            'COHU', 'COLL', 'CON', 'CORT', 'COTY', 'CPB', 'CPF', 'CPK', 'CRC', 'CRGY',
            'CRI', 'CRK', 'CRSR', 'CRVL', 'CSR', 'CSW', 'CTS', 'CUBI', 'CURB', 'CVBF',
            'CVCO', 'CVI', 'CVSA', 'CWEN', 'CWK', 'CWST', 'CWT', 'CXM', 'CXW', 'CZR',
            'DAN', 'DAVE', 'DBD', 'DCH', 'DCOM', 'DEA', 'DEI', 'DFH', 'DFIN', 'DGII',
            'DIOD', 'DLX', 'DMC', 'DNOW', 'DORM', 'DRH', 'DV', 'DXC', 'DXPE', 'EAT',
            'EBC', 'ECG', 'ECPG', 'EFC', 'EFOR', 'EGBN', 'EIG', 'EMN', 'ENOV', 'ENPH',
            'ENR', 'ENVA', 'EPAC', 'EPAM', 'EPC', 'EPRT', 'ESE', 'ESI', 'ETSY', 'EVTC',
            'EXTR', 'EYE', 'EZPW', 'FA', 'FBK', 'FBNC', 'FBP', 'FBRT', 'FCF', 'FCPT',
            'FELE', 'FFBC', 'FG', 'FHB', 'FIBK', 'FIVN', 'FIZZ', 'FLO', 'FMC', 'FORM',
            'FOXF', 'FRPT', 'FSS', 'FTDR', 'FTRE', 'FUL', 'FULT', 'FUN', 'GBX', 'GEO',
            'GFF', 'GIII', 'GKOS', 'GNL', 'GNW', 'GO', 'GOLF', 'GPI', 'GPOR', 'GRBK',
            'GSHD', 'GT', 'GTES', 'GTM', 'GTY', 'GVA', 'HAFC', 'HASI', 'HAYW', 'HCC',
            'HCI', 'HCSG', 'HE', 'HFWA', 'HIW', 'HLIT', 'HMN', 'HNI', 'HOPE', 'HOS',
            'HP', 'HRMY', 'HSTM', 'HTH', 'HTLD', 'HTO', 'HUBG', 'HWKN', 'HZO', 'IART',
            'IBP', 'ICHR', 'ICUI', 'IIPR', 'INDB', 'INDV', 'INSP', 'INSW', 'INVA', 'INVX',
            'IOSP', 'IPAR', 'IRDM', 'ITGR', 'ITRI', 'IVT', 'JBGS', 'JBLU', 'JBSS', 'JBTM',
            'JJSF', 'JOE', 'JXN', 'KAI', 'KALU', 'KFY', 'KGS', 'KLIC', 'KMPR', 'KMT',
            'KMX', 'KN', 'KNTK', 'KOP', 'KRMN', 'KSS', 'KTB', 'KWR', 'LAUR', 'LAZ',
            'LBRT', 'LCII', 'LEU', 'LFST', 'LGIH', 'LGND', 'LIF', 'LKFN', 'LKQ', 'LMAT',
            'LNC', 'LNN', 'LPG', 'LQDA', 'LQDT', 'LRN', 'LTC', 'LTH', 'LUMN', 'LW',
            'LXP', 'LYFT', 'LZ', 'LZB', 'MAC', 'MAN', 'MARA', 'MATW', 'MATX', 'MBC',
            'MBGL', 'MBIN', 'MC', 'MCRI', 'MCY', 'MD', 'MDU', 'MFP', 'MGEE', 'MGY',
            'MHK', 'MHO', 'MIR', 'MKTX', 'MLKN', 'MMI', 'MMSI', 'MPT', 'MRCY', 'MRP',
            'MRTN', 'MSEX', 'MSGS', 'MTCH', 'MTH', 'MTRN', 'MTUS', 'MTX', 'MWA', 'MXL',
            'MYRG', 'NABL', 'NATL', 'NAVI', 'NBHC', 'NBTB', 'NE', 'NEO', 'NEOG', 'NGVT',
            'NHC', 'NHI', 'NIC', 'NMIH', 'NOG', 'NPK', 'NPO', 'NSIT', 'NSP', 'NSSC',
            'NTCT', 'NTST', 'NWBI', 'NWL', 'NWN', 'NX', 'NXRT', 'OFG', 'OGN', 'OI',
            'OII', 'OMCL', 'OPLN', 'OSIS', 'OSW', 'OTTR', 'OUT', 'PAHC', 'PARR', 'PATK',
            'PAYC', 'PAYO', 'PBH', 'PBI', 'PCRX', 'PDFS', 'PEB', 'PECO', 'PENG', 'PENN',
            'PFBC', 'PFS', 'PGNY', 'PHIN', 'PI', 'PIPR', 'PJT', 'PLAB', 'PLMR', 'PLUS',
            'PLXS', 'PMT', 'POOL', 'POWI', 'POWL', 'PPLI', 'PRDO', 'PRG', 'PRGO', 'PRGS',
            'PRIM', 'PRK', 'PRKS', 'PRLB', 'PRSU', 'PRVA', 'PSMT', 'PTCT', 'PTEN', 'PTGX',
            'PTON', 'PZZA', 'QDEL', 'QNST', 'QRVO', 'QTWO', 'RAL', 'RAMP', 'RCUS', 'RDN',
            'RDNT', 'RELY', 'RES', 'REX', 'REYN', 'REZI', 'RHI', 'RHP', 'RITM', 'RNG',
            'RNST', 'ROAD', 'ROCK', 'ROG', 'RRR', 'RSI', 'RUN', 'RUSHA', 'RXO', 'SABR',
            'SAFE', 'SAFT', 'SAH', 'SBCF', 'SBH', 'SBSI', 'SCHL', 'SCL', 'SCSC', 'SDGR',
            'SEDG', 'SEI', 'SEZL', 'SFBS', 'SFNC', 'SHAK', 'SHEN', 'SHO', 'SHOO', 'SIG',
            'SKT', 'SKY', 'SKYW', 'SLG', 'SLVM', 'SM', 'SMP', 'SMPL', 'SNDR', 'SNEX',
            'SONO', 'SPHR', 'SPNT', 'SPSC', 'SRPT', 'STAA', 'STBA', 'STC', 'STEP', 'STRA',
            'SUPN', 'SXI', 'SXT', 'TALO', 'TBBK', 'TDC', 'TDS', 'TDW', 'TENB', 'TFIN',
            'TFX', 'TGTX', 'THRM', 'TILE', 'TMDX', 'TMP', 'TNC', 'TNDM', 'TPC', 'TR',
            'TRIP', 'TRMK', 'TRN', 'TRNO', 'TRST', 'TRUP', 'UA', 'UAA', 'UCB', 'UCTT',
            'UE', 'UFCS', 'UFPT', 'UNF', 'UNFI', 'UNIT', 'UPBD', 'UPWK', 'URBN', 'USLM',
            'USPH', 'UTI', 'UTL', 'UVV', 'VAC', 'VCEL', 'VCTR', 'VCYT', 'VECO', 'VGNT',
            'VIR', 'VIRT', 'VRRM', 'VRTS', 'VSAT', 'VSEC', 'VSH', 'VSNT', 'VSTS', 'VSXY',
            'VTOL', 'VVX', 'VYX', 'WABC', 'WAFD', 'WAY', 'WD', 'WDFC', 'WEN', 'WERN',
            'WGO', 'WHD', 'WINA', 'WKC', 'WLY', 'WOR', 'WRBY', 'WRLD', 'WS', 'WSBC',
            'WSC', 'WSFS', 'WT', 'WU', 'WWW', 'XHR', 'XNCR', 'XPEL', 'YELP', 'YOU',
            'ZD', 'ZWS',
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
    // OJO al leer el historico de FDX: FedEx completo el spin-off de FedEx
    // Freight (ticker nuevo FDXF en NYSE) el 2026-06-01, con reparto de 1
    // accion de FDXF por cada 2 de FDX. A diferencia del caso HON de mas
    // arriba, este spin-off NO vino acompañado de un contrasplit de FDX, asi
    // que un salto de precio en la serie de FDX alrededor de esa fecha es un
    // efecto real del reparto de valor (no splits, no un bug del parser) y
    // no debe interpretarse como ruido de datos. FDX sigue cotizando con
    // normalidad bajo el mismo ticker, no requiere cambio en esta lista.
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
    // Universo de "solo cron" (2026-09-06, `selectable => false`, ver
    // Config\UniverseConfig::all()): ~1.251 tickers, demasiados para
    // analizarse en vivo desde el Home en una sola peticion sin arriesgar
    // timeout o rate-limit de Yahoo. No aparece en el desplegable ni es
    // aceptado por `?universe=` (Application::isValidUniverseKey()), pero
    // `bin/analyze.php --universe=msci_world` (o `--all-universes`) si lo
    // procesa, sembrando `score_history`/`fundamentals_history` con
    // regimenes geograficos genuinamente distintos a los del resto de
    // `config/universes.php` (todo EEUU + IBEX35 hasta hoy).
    //
    // Composicion: holdings reales del ETF `URTH` (iShares MSCI World,
    // replica fisica, ID de producto 239696), descargados el 2026-09-06
    // del CSV oficial publicado por BlackRock
    // (https://www.ishares.com/us/products/239696/ishares-msci-world-etf/latest-holdings.csv),
    // NO de una lista fabricada de memoria: MSCI no publica gratis la
    // composicion completa del indice real. Filtrado a `Asset
    // Class=Equity` (1253 de 1276 filas) y mapeado a ticker Yahoo por
    // columna `Exchange` (no `Location`: varias filas cotizan en una
    // bolsa distinta a su pais, ej. ArcelorMittal domiciliada en Francia
    // cotiza en Euronext Amsterdam como MT.AS, y Teva domiciliada en
    // Israel cotiza como ADR en NYSE sin sufijo). Las 23 bolsas
    // resultantes se verificaron una a una contra Yahoo real (cotizacion,
    // historico 5 años, identidad de la empresa) antes de comprometer
    // esta lista -- ver versions.md, misma fecha, para la tabla completa
    // bolsa-a-sufijo y los hallazgos concretos (puntos finales de Londres,
    // guiones de clases de accion, codigos alfanumericos nuevos de Tokio).
    // 1251 de 1253 equities mapeadas; 2 quedaron fuera por falta de un
    // ticker real utilizable (`HOLX`/Hologic con `Exchange="NO MARKET"` y
    // un residuo de `Constellation Software` con un codigo interno, no su
    // ticker real `CSU.TO`, ya incluido aqui por su propia fila).
    // `BRKB` (asi, sin separador, tal cual lo escribe iShares en su CSV
    // para Berkshire Hathaway Clase B) se corrigio a `BRK-B`, el unico
    // ticker de EEUU de todo el CSV donde el proveedor omite el separador
    // de clase que Yahoo si exige.
    //
    // Cautelas conocidas, no bloqueantes: Reino Unido cotiza en GBp
    // (peniques) e Israel en agorot -- inocuo para indicadores tecnicos
    // relativos, pero un riesgo real de bug de 100x si algo llegara a
    // recalcular ratios por-accion mezclando precio de Yahoo con
    // fundamentales de otra fuente para estos tickers (ver
    // PointInTimeFundamentalsBuilder.php:171, sin uso activo hoy fuera de
    // EEUU/.MC). Algunas cotizaciones muy recientes (Verisure en Suecia,
    // Kioxia en Japon) tienen historico corto todavia: dato real, no un
    // fallo de mapeo.
    'msci_world' => [
        'label' => 'MSCI World',
        'selectable' => false,
        'tickers' => [
            '0001.HK', '0002.HK', '0003.HK', '0006.HK', '0012.HK', '0016.HK', '0019.HK', '0027.HK', '0066.HK', '0083.HK',
            '0288.HK', '0388.HK', '0669.HK', '0823.HK', '1038.HK', '1113.HK', '1299.HK', '1308.HK', '1605.T', '1801.T',
            '1802.T', '1803.T', '1812.T', '1878.T', '1925.T', '1928.HK', '1928.T', '1997.HK', '2388.HK', '2502.T',
            '2503.T', '2587.T', '2801.T', '2802.T', '285A.T', '2914.T', '3003.T', '3382.T', '3402.T', '3407.T',
            '3659.T', '4004.T', '4062.T', '4063.T', '4091.T', '4151.T', '4188.T', '4307.T', '4452.T', '4502.T',
            '4503.T', '4507.T', '4519.T', '4523.T', '4543.T', '4568.T', '4578.T', '4612.T', '4661.T', '4684.T',
            '4689.T', '4755.T', '4768.T', '4901.T', '4911.T', '5016.T', '5019.T', '5020.T', '5108.T', '5201.T',
            '5401.T', '5706.T', '5713.T', '5801.T', '5802.T', '5803.T', '6098.T', '6146.T', '6178.T', '6273.T',
            '6301.T', '6326.T', '6361.T', '6367.T', '6383.T', '6479.T', '6501.T', '6503.T', '6504.T', '6525.T',
            '6586.T', '6594.T', '6701.T', '6702.T', '6723.T', '6752.T', '6758.T', '6762.T', '6823.HK', '6841.T',
            '6857.T', '6861.T', '6902.T', '6920.T', '6954.T', '6971.T', '6981.T', '6988.T', '7011.T', '7012.T',
            '7013.T', '7181.T', '7182.T', '7186.T', '7201.T', '7202.T', '7203.T', '7259.T', '7267.T', '7269.T',
            '7270.T', '7272.T', '7309.T', '7453.T', '7532.T', '7550.T', '7733.T', '7735.T', '7741.T', '7751.T',
            '7832.T', '7911.T', '7912.T', '7936.T', '7974.T', '8001.T', '8002.T', '8015.T', '8031.T', '8035.T',
            '8053.T', '8058.T', '8113.T', '8136.T', '8267.T', '8306.T', '8308.T', '8309.T', '8316.T', '8331.T',
            '8411.T', '8473.T', '8591.T', '8593.T', '8601.T', '8604.T', '8630.T', '8697.T', '8725.T', '8750.T',
            '8766.T', '8795.T', '8801.T', '8802.T', '8830.T', '8951.T', '9020.T', '9021.T', '9022.T', '9042.T',
            '9101.T', '9104.T', '9107.T', '9202.T', '9432.T', '9433.T', '9434.T', '9435.T', '9502.T', '9503.T',
            '9531.T', '9532.T', '9602.T', '9697.T', '9735.T', '9766.T', '9843.T', '9983.T', '9984.T', '9CI.SI',
            'A', 'A5G.IR', 'AAF.L', 'AAL.L', 'AAPL', 'ABBN.SW', 'ABBV', 'ABF.L', 'ABI.BR', 'ABN.AS',
            'ABNB', 'ABT', 'ABVX.PA', 'ABX.TO', 'AC.PA', 'ACA.PA', 'ACGL', 'ACN', 'ACS.MC', 'AD.AS',
            'ADBE', 'ADDT-B.ST', 'ADI', 'ADM', 'ADM.L', 'ADP', 'ADP.PA', 'ADS.DE', 'ADSK', 'ADYEN.AS',
            'AEE', 'AEM.TO', 'AENA.MC', 'AEP', 'AER', 'AFL', 'AFRM', 'AGI.TO', 'AGN.AS', 'AGS.BR',
            'AI.PA', 'AIA.NZ', 'AIG', 'AIR.PA', 'AJG', 'AKRBP.OL', 'AKZA.AS', 'ALA.TO', 'ALAB', 'ALC.SW',
            'ALFA.ST', 'ALL', 'ALL.AX', 'ALNY', 'ALO.PA', 'ALV.DE', 'AM.PA', 'AMAT', 'AMCR', 'AMD',
            'AME', 'AMGN', 'AMP', 'AMRZ', 'AMS.MC', 'AMT', 'AMUN.PA', 'AMZN', 'ANA.MC', 'ANET',
            'ANTO.L', 'ANZ.AX', 'AON', 'APA.AX', 'APD', 'APH', 'APO', 'APP', 'ARES', 'ARGX.BR',
            'ARX.TO', 'ASM.AS', 'ASML.AS', 'ASRNL.AS', 'ASSA-B.ST', 'ASTS', 'ASX.AX', 'ATCO-A.ST', 'ATCO-B.ST', 'ATD.TO',
            'ATI', 'ATO', 'ATRL.TO', 'AV.L', 'AVGO', 'AVOL.SW', 'AWK', 'AXON', 'AXP', 'AYV.PA',
            'AZN.L', 'AZO', 'AZRG.TA', 'BA', 'BA.L', 'BAC', 'BAER.SW', 'BALL', 'BAM.TO', 'BAMI.MI',
            'BARC.L', 'BARN.SW', 'BAS.DE', 'BATS.L', 'BAYN.DE', 'BBD-B.TO', 'BBVA.MC', 'BBY', 'BCE.TO', 'BCP.LS',
            'BCVN.SW', 'BDX', 'BE', 'BEAN.SW', 'BEI.DE', 'BEIJ-B.ST', 'BEPC.TO', 'BESI.AS', 'BG', 'BG.VI',
            'BHP.AX', 'BIIB', 'BIM.PA', 'BIRG.IR', 'BKNG', 'BKR', 'BKT.MC', 'BKW.SW', 'BLK', 'BMED.MI',
            'BMO.TO', 'BMPS.MI', 'BMW.DE', 'BMY', 'BN.PA', 'BN.TO', 'BN4.SI', 'BNP.PA', 'BNR.DE', 'BNS.TO',
            'BNY', 'BNZL.L', 'BOL.ST', 'BP.L', 'BPE.MI', 'BR', 'BRK-B', 'BRO', 'BS6.SI', 'BSX',
            'BT-A.L', 'BURL', 'BVI.PA', 'BX', 'BXB.AX', 'BZU.MI', 'C', 'C6L.SI', 'CA.PA', 'CABK.MC',
            'CAE.TO', 'CAH', 'CAP.PA', 'CARL-B.CO', 'CARR', 'CASY', 'CAT', 'CB', 'CBA.AX', 'CBK.DE',
            'CBOE', 'CBRE', 'CCEP', 'CCH.L', 'CCI', 'CCL', 'CCL-B.TO', 'CCO.TO', 'CDE', 'CDNS',
            'CDW', 'CEG', 'CEN.NZ', 'CF', 'CFG', 'CFR.SW', 'CG', 'CHD', 'CHKP', 'CHRW',
            'CHTR', 'CI', 'CICT.SI', 'CIEN', 'CINF', 'CL', 'CLAR.SI', 'CLNX.MC', 'CLS.TO', 'CM.TO',
            'CMCSA', 'CME', 'CMG', 'CMI', 'CMS', 'CNA.L', 'CNC', 'CNP', 'CNQ.TO', 'CNR.TO',
            'COF', 'COHR', 'COIN', 'COL.AX', 'COLO-B.CO', 'CON.DE', 'COO', 'COP', 'COR', 'COST',
            'COV.PA', 'CP.TO', 'CPAY', 'CPG.L', 'CPR.MI', 'CPRT', 'CPU.AX', 'CRBG', 'CRCL', 'CRDO',
            'CRH', 'CRM', 'CRS', 'CRWD', 'CRWV', 'CS.PA', 'CSCO', 'CSL.AX', 'CSU.TO', 'CSX',
            'CTAS', 'CTC-A.TO', 'CTSH', 'CTVA', 'CU.TO', 'CVC.AS', 'CVE.TO', 'CVNA', 'CVS', 'CVX',
            'CW', 'D', 'D05.SI', 'DAL', 'DANSKE.CO', 'DASH', 'DB1.DE', 'DBK.DE', 'DD', 'DDOG',
            'DE', 'DECK', 'DELL', 'DEMANT.CO', 'DG', 'DG.PA', 'DGE.L', 'DGX', 'DHER.DE', 'DHI',
            'DHL.DE', 'DHR', 'DIE.BR', 'DIM.PA', 'DIS', 'DKS', 'DLR', 'DLTR', 'DNB.OL', 'DOL.TO',
            'DOV', 'DOW', 'DPLM.L', 'DRI', 'DSCT.TA', 'DSFIR.AS', 'DSV.CO', 'DSY.PA', 'DTE', 'DTE.DE',
            'DTG.DE', 'DUK', 'DVN', 'DXCM', 'EBAY', 'EBS.VI', 'ECHO', 'ECL', 'ED', 'EDP.LS',
            'EDPR.LS', 'EDV.L', 'EFN.TO', 'EFX', 'EG', 'EIX', 'EL', 'EL.PA', 'ELE.MC', 'ELI.BR',
            'ELISA.HE', 'ELV', 'EMA.TO', 'EME', 'EMP-A.TO', 'EMR', 'EMSN.SW', 'EN.PA', 'ENB.TO', 'ENEL.MI',
            'ENGI.PA', 'ENI.MI', 'ENLT.TA', 'ENR.DE', 'ENTG', 'ENX.PA', 'EOAN.DE', 'EOG', 'EPI-A.ST', 'EPI-B.ST',
            'EQIX', 'EQNR.OL', 'EQT', 'EQT.ST', 'EQX.TO', 'ERF.PA', 'ERIC-B.ST', 'ES', 'ESLT.TA', 'ESS',
            'ESSITY-B.ST', 'ETN', 'ETR', 'EVK.DE', 'EVN.AX', 'EVO.ST', 'EVRG', 'EW', 'EXC', 'EXE',
            'EXO.AS', 'EXPD', 'EXPE', 'EXPN.L', 'EXR', 'F', 'F34.SI', 'FANG', 'FAST', 'FBK.MI',
            'FCNCA', 'FCX', 'FDX', 'FDXF', 'FE', 'FER.MC', 'FERG', 'FFH.TO', 'FFIV', 'FGR.PA',
            'FICO', 'FIS', 'FISV', 'FITB', 'FIX', 'FLEX', 'FLUT', 'FM.TO', 'FME.DE', 'FMG.AX',
            'FN', 'FNF', 'FNV.TO', 'FORTUM.HE', 'FOX', 'FOXA', 'FPH.NZ', 'FRE.DE', 'FRES.L', 'FSLR',
            'FTAI', 'FTI', 'FTNT', 'FTS.TO', 'FTV', 'FUTU', 'FWONK', 'G.MI', 'G1A.DE', 'GALD.SW',
            'GALP.LS', 'GBLB.BR', 'GD', 'GE', 'GEBN.SW', 'GEHC', 'GEN', 'GET.PA', 'GEV', 'GFC.PA',
            'GFL.TO', 'GIB-A.TO', 'GIL.TO', 'GILD', 'GIS', 'GIVN.SW', 'GJF.OL', 'GLE.PA', 'GLEN.L', 'GLW',
            'GM', 'GMAB.CO', 'GMG.AX', 'GOOG', 'GOOGL', 'GPC', 'GPN', 'GRAB', 'GRMN', 'GS',
            'GSK.L', 'GWO.TO', 'GWW', 'H', 'H.TO', 'H78.SI', 'HAG.DE', 'HAL', 'HARL.TA', 'HBAN',
            'HBAN.SW', 'HCA', 'HD', 'HEI', 'HEI.DE', 'HEIA', 'HEIA.AS', 'HEIO.AS', 'HEN.DE', 'HEN3.DE',
            'HEXA-B.ST', 'HIG', 'HLMA.L', 'HLN.L', 'HLT', 'HM-B.ST', 'HNR1.DE', 'HO.PA', 'HOLN.SW', 'HON',
            'HONA', 'HOOD', 'HOT.DE', 'HPE', 'HPQ', 'HSBA.L', 'HSY', 'HUBB', 'HUM', 'HWM',
            'IAG.AX', 'IAG.MC', 'IAG.TO', 'IBE.MC', 'IBKR', 'IBM', 'ICE', 'ICL.TA', 'IDR.MC', 'IDXX',
            'IEX', 'IFC.TO', 'IFF', 'IFT.NZ', 'IFX.DE', 'IG.MI', 'IGM.TO', 'IHG.L', 'III.L', 'ILMN',
            'IMB.L', 'IMO.TO', 'INCY', 'INDT.ST', 'INDU-A.ST', 'INDU-C.ST', 'INF.L', 'INGA.AS', 'INPST.AS', 'INSM',
            'INTC', 'INTU', 'INVE-B.ST', 'INVH', 'IOT', 'IP', 'IPN.PA', 'IQV', 'IR', 'IREN',
            'IRM', 'ISP.MI', 'ISRG', 'ITRK.L', 'ITW', 'ITX.MC', 'IVN.TO', 'J', 'J36.SI', 'JBHT',
            'JBL', 'JCI', 'JMT.LS', 'JNJ', 'JPM', 'K.TO', 'KBC.BR', 'KBX.DE', 'KDP', 'KER.PA',
            'KESKOB.HE', 'KEY', 'KEY.TO', 'KEYS', 'KGF.L', 'KHC', 'KIM', 'KKR', 'KLAC', 'KMB',
            'KMI', 'KNEBV.HE', 'KNIN.SW', 'KO', 'KOG.OL', 'KPN.AS', 'KR', 'KRX.IR', 'KRZ.IR', 'KVUE',
            'L', 'L.TO', 'LAND.L', 'LDO.MI', 'LDOS', 'LEN', 'LGEN.L', 'LH', 'LHA.DE', 'LHX',
            'LI.PA', 'LIFCO-B.ST', 'LII', 'LIN', 'LISN.SW', 'LISP.SW', 'LITE', 'LLOY.L', 'LLY', 'LMT',
            'LNG', 'LNT', 'LOGN.SW', 'LONN.SW', 'LOTB.BR', 'LOW', 'LPLA', 'LR.PA', 'LRCX', 'LSEG.L',
            'LUG.TO', 'LUMI.TA', 'LUN.TO', 'LUND-B.ST', 'LVS', 'LYB', 'LYC.AX', 'LYV', 'MA', 'MAA',
            'MAERSK-A.CO', 'MAERSK-B.CO', 'MAP.MC', 'MAR', 'MAS', 'MBG.DE', 'MC.PA', 'MCD', 'MCHP', 'MCK',
            'MCO', 'MDB', 'MDLN', 'MDLZ', 'MDT', 'MEL.NZ', 'MELI', 'MET', 'META', 'METSO.HE',
            'MFC.TO', 'MG.TO', 'MICC.AS', 'MKC', 'MKL', 'MKS.L', 'ML.PA', 'MLM', 'MMM', 'MNG.L',
            'MNST', 'MO', 'MONC.MI', 'MOWI.OL', 'MPC', 'MPL.AX', 'MPWR', 'MQG.AX', 'MRK', 'MRK.DE',
            'MRO.L', 'MRSH', 'MRU.TO', 'MRVL', 'MS', 'MSCI', 'MSFT', 'MSI', 'MSTR', 'MT.AS',
            'MTB', 'MTD', 'MTX.DE', 'MTZ', 'MU', 'MUV2.DE', 'MZTF.TA', 'NA.TO', 'NAB.AX', 'NBIS',
            'NBIX', 'NDA-FI.HE', 'NDAQ', 'NDSN', 'NEE', 'NEM', 'NEM.DE', 'NESN.SW', 'NESTE.HE', 'NET',
            'NFLX', 'NG.L', 'NHY.OL', 'NI', 'NIBE-B.ST', 'NKE', 'NLY', 'NN.AS', 'NOC', 'NOKIA.HE',
            'NOVN.SW', 'NOVO-B.CO', 'NOW', 'NRG', 'NSC', 'NSIS-B.CO', 'NST.AX', 'NTAP', 'NTGY.MC', 'NTR.TO',
            'NTRA', 'NTRS', 'NUE', 'NVDA', 'NVMI.TA', 'NVR', 'NVT', 'NWG.L', 'NWSA', 'NXPI',
            'NXT.L', 'O', 'O39.SI', 'ODFL', 'OKE', 'OKTA', 'OMC', 'OMV.VI', 'ON', 'OPCE.TA',
            'OR.PA', 'ORA.PA', 'ORCL', 'ORG.AX', 'ORK.OL', 'ORLY', 'ORNBV.HE', 'ORSTED.CO', 'OTIS', 'OXY',
            'P', 'P911.DE', 'PAAS.TO', 'PAH3.DE', 'PANW', 'PAYX', 'PCAR', 'PCG', 'PEG', 'PEP',
            'PFE', 'PFG', 'PG', 'PGHN.SW', 'PGR', 'PH', 'PHIA.AS', 'PHM', 'PHOE.TA', 'PKG',
            'PLD', 'PLS.AX', 'PLTR', 'PM', 'PME.AX', 'PNC', 'PNDORA.CO', 'PNFP', 'POLI.TA', 'POW.TO',
            'PPG', 'PPL', 'PPL.TO', 'PRU', 'PRU.L', 'PRX.AS', 'PRY.MI', 'PSA', 'PSON.L', 'PST.MI',
            'PSX', 'PTC', 'PUB.PA', 'PWR', 'PYPL', 'Q', 'QAN.AX', 'QBE.AX', 'QCOM', 'QIA.DE',
            'QSR.TO', 'RAA.DE', 'RACE.MI', 'RBA.TO', 'RBI.VI', 'RBLX', 'RCI-B.TO', 'RCL', 'RDDT', 'REA.AX',
            'REC.MI', 'RED.MC', 'REG', 'REGN', 'REL.L', 'REP.MC', 'RF', 'RHM.DE', 'RI.PA', 'RIO.AX',
            'RIO.L', 'RIVN', 'RJF', 'RKLB', 'RKT', 'RKT.L', 'RMD', 'RMS.PA', 'RNO.PA', 'RO.SW',
            'ROIV', 'ROK', 'ROL', 'ROP', 'ROP.SW', 'ROST', 'RPRX', 'RR.L', 'RS', 'RSG',
            'RTO.L', 'RTX', 'RVMD', 'RWE.DE', 'RXL.PA', 'RY.TO', 'RYA.IR', 'S32.AX', 'S63.SI', 'S68.SI',
            'SAAB-B.ST', 'SAB.MC', 'SAF.PA', 'SALM.OL', 'SAMPO.HE', 'SAN.MC', 'SAN.PA', 'SAND.ST', 'SAP.DE', 'SAP.TO',
            'SBAC', 'SBRY.L', 'SBUX', 'SCA-B.ST', 'SCG.AX', 'SCHN.SW', 'SCHP.SW', 'SCHW', 'SCMN.SW', 'SDLF.L',
            'SDR.L', 'SDZ.SW', 'SE', 'SEB-A.ST', 'SECU-B.ST', 'SGE.L', 'SGH.AX', 'SGO.PA', 'SGRO.L', 'SGSN.SW',
            'SHB-A.ST', 'SHEL.L', 'SHL.DE', 'SHOP.TO', 'SHW', 'SIE.DE', 'SIG.AX', 'SIKA.SW', 'SKA-B.ST', 'SKF-B.ST',
            'SLB', 'SLF.TO', 'SLHN.SW', 'SMCI', 'SMIN.L', 'SN', 'SN.L', 'SNA', 'SNDK', 'SNOW',
            'SNPS', 'SO', 'SOBI.ST', 'SOF.BR', 'SOFI', 'SOL.AX', 'SOON.SW', 'SPCX', 'SPG', 'SPGI',
            'SPOT', 'SPSN.SW', 'SPX.L', 'SRE', 'SREN.SW', 'SRG.MI', 'SRT3.DE', 'SSE.L', 'SSNC', 'STAN.L',
            'STE', 'STERV.HE', 'STLAM.MI', 'STLD', 'STMN.SW', 'STMPA.PA', 'STN.TO', 'STO.AX', 'STT', 'STX',
            'STZ', 'SU.PA', 'SU.TO', 'SUI', 'SUN.AX', 'SUNB', 'SVT.L', 'SW', 'SW.PA', 'SWED-A.ST',
            'SY1.DE', 'SYENS.BR', 'SYF', 'SYK', 'SYY', 'T', 'T.TO', 'TCL.AX', 'TD.TO', 'TDG',
            'TDY', 'TEAM', 'TECK-B.TO', 'TEF.MC', 'TEL', 'TEL.OL', 'TEL2-B.ST', 'TELIA.ST', 'TEN.MI', 'TER',
            'TEVA', 'TFC', 'TFII.TO', 'TGT', 'TIGO', 'TIH.TO', 'TIT.MI', 'TJX', 'TLC.AX', 'TLS.AX',
            'TLX.DE', 'TMO', 'TMUS', 'TOST', 'TOU.TO', 'TPL', 'TPR', 'TREL-B.ST', 'TRGP', 'TRI.TO',
            'TRN.MI', 'TROW', 'TRP.TO', 'TRU', 'TRV', 'TRYG.CO', 'TSCO', 'TSCO.L', 'TSEM.TA', 'TSLA',
            'TSN', 'TT', 'TTE.PA', 'TTWO', 'TUB.BR', 'TW', 'TWLO', 'TXN', 'TXT', 'U11.SI',
            'UAL', 'UBER', 'UBSG.SW', 'UCB.BR', 'UCG.MI', 'UHR.SW', 'ULTA', 'ULVR.L', 'UMG.AS', 'UNH',
            'UNI.MI', 'UNP', 'UPM.HE', 'UPS', 'URI', 'URW.PA', 'USB', 'UTHR', 'UU.L', 'V',
            'VACN.SW', 'VAR.OL', 'VCX.AX', 'VEEV', 'VER.VI', 'VICI', 'VIE.PA', 'VLO', 'VLTO', 'VMC',
            'VMRK', 'VNA.DE', 'VOD.L', 'VOLV-B.ST', 'VOW3.DE', 'VRSK', 'VRSN', 'VRT', 'VRTX', 'VST',
            'VSURE.ST', 'VTR', 'VWS.CO', 'VZ', 'WAB', 'WAT', 'WBC.AX', 'WBD', 'WCN', 'WCP.TO',
            'WDAY', 'WDC', 'WDS.AX', 'WEC', 'WELL', 'WES.AX', 'WFC', 'WISE.L', 'WKL.AS', 'WM',
            'WMB', 'WMT', 'WN.TO', 'WOW.AX', 'WPC', 'WPM.TO', 'WRB', 'WRT1V.HE', 'WSM', 'WSO',
            'WSP.TO', 'WST', 'WTC.AX', 'WTW', 'WY', 'X.TO', 'XEL', 'XOM', 'XPO', 'XRO.AX',
            'XYL', 'XYZ', 'YAR.OL', 'YUM', 'Z74.SI', 'ZAL.DE', 'ZBH', 'ZM', 'ZS', 'ZTS',
            'ZURN.SW',
        ],
    ],
];

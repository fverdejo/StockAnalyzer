<?php

declare(strict_types=1);

namespace StockAnalyzer\Config;

/**
 * Directorio local ticker -> nombre de empresa, usado unicamente para poder
 * buscar por nombre en el Home (ver versions.md v2.5). Cubre los tickers que
 * aparecen en config/universes.php; no es (ni pretende ser) un buscador de
 * mercado completo, ya que la aplicacion no tiene un proveedor de busqueda
 * de simbolos: solo permite encontrar por nombre las empresas que ya estan
 * en alguna lista configurada.
 */
class CompanyDirectory
{
    /**
     * @var array<string,string> ticker => nombre(s), alias alternativos separados por "|"
     */
    private const NAMES = [
        'AAPL' => 'Apple',
        'MSFT' => 'Microsoft',
        'NVDA' => 'NVIDIA',
        'AMZN' => 'Amazon',
        'GOOGL' => 'Alphabet|Google',
        'META' => 'Meta Platforms|Facebook|Meta',
        'TSLA' => 'Tesla',
        'AVGO' => 'Broadcom',
        'BRK-B' => 'Berkshire Hathaway|Berkshire',
        'JPM' => 'JPMorgan Chase|JPMorgan|JP Morgan',
        'LLY' => 'Eli Lilly',
        'V' => 'Visa',
        'XOM' => 'Exxon Mobil|Exxon',
        'UNH' => 'UnitedHealth Group|UnitedHealth',
        'MA' => 'Mastercard',
        'COST' => 'Costco',
        'NFLX' => 'Netflix',
        'WMT' => 'Walmart',
        'PG' => 'Procter & Gamble|Procter and Gamble|Procter',
        'JNJ' => 'Johnson & Johnson|Johnson and Johnson',
        'HD' => 'Home Depot',
        'ABBV' => 'AbbVie',
        'BAC' => 'Bank of America',
        'KO' => 'Coca-Cola|Coca Cola',
        'CRM' => 'Salesforce',
        'ORCL' => 'Oracle',
        'CVX' => 'Chevron',
        'MRK' => 'Merck',
        'AMD' => 'Advanced Micro Devices',
        'PEP' => 'PepsiCo|Pepsi',
        'LIN' => 'Linde',
        'TMO' => 'Thermo Fisher Scientific|Thermo Fisher',
        'ACN' => 'Accenture',
        'MCD' => 'McDonald\'s|McDonalds',
        'CSCO' => 'Cisco Systems|Cisco',
        'ADBE' => 'Adobe',
        'IBM' => 'IBM',
        'GE' => 'General Electric',
        'QCOM' => 'Qualcomm',
        'WFC' => 'Wells Fargo',
        'CAT' => 'Caterpillar',
        'TXN' => 'Texas Instruments',
        'PM' => 'Philip Morris International|Philip Morris',
        'INTU' => 'Intuit',
        'AMGN' => 'Amgen',
        'DIS' => 'Walt Disney|Disney',
        'GS' => 'Goldman Sachs',
        'ISRG' => 'Intuitive Surgical',
        'VZ' => 'Verizon',
        'NOW' => 'ServiceNow',
        'RTX' => 'RTX Corporation|Raytheon',
        'BKNG' => 'Booking Holdings|Booking',
        'SPGI' => 'S&P Global',
        'PFE' => 'Pfizer',
        'NKE' => 'Nike',
        'HON' => 'Honeywell',
        'LOW' => 'Lowe\'s|Lowes',
        'UPS' => 'United Parcel Service',
        'BA' => 'Boeing',
        'SBUX' => 'Starbucks',
        'AXP' => 'American Express|Amex',
        'MMM' => '3M',
        'SHW' => 'Sherwin-Williams|Sherwin Williams',
        'TRV' => 'Travelers',
        'SAN.MC' => 'Banco Santander|Santander',
        'BBVA.MC' => 'BBVA',
        'IBE.MC' => 'Iberdrola',
        'ITX.MC' => 'Inditex|Zara',
        'REP.MC' => 'Repsol',
        'TEF.MC' => 'Telefonica',
        'FER.MC' => 'Ferrovial',
        'AMS.MC' => 'Amadeus',
        'AENA.MC' => 'Aena',
        'CABK.MC' => 'CaixaBank|Caixa Bank',
        'MAP.MC' => 'Mapfre',
        'ENG.MC' => 'Enagas',
        'ELE.MC' => 'Endesa',
        'NTGY.MC' => 'Naturgy',
        'ANA.MC' => 'Acciona',
        'INTC' => 'Intel',
        'AMAT' => 'Applied Materials',
        'MU' => 'Micron Technology|Micron',
        'LRCX' => 'Lam Research',
        'PANW' => 'Palo Alto Networks|Palo Alto',
        'SNOW' => 'Snowflake',
        'SHOP' => 'Shopify',
    ];

    /**
     * @return array<string,string> ticker => nombre(s), alias separados por "|"
     */
    public static function names(): array
    {
        return self::NAMES;
    }
}

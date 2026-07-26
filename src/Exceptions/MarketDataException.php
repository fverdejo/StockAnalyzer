<?php

declare(strict_types=1);

namespace StockAnalyzer\Exceptions;

use RuntimeException;

/**
 * Fallo al obtener o interpretar datos de un proveedor de mercado
 * (Yahoo Finance u otro). Sigue siendo un RuntimeException para no romper
 * el codigo que ya captura esa clase, pero permite distinguir estos
 * errores de otros RuntimeException genericos si algun dia hace falta.
 */
class MarketDataException extends RuntimeException
{
}

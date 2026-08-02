<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

class Company
{
    /**
     * @param string $description Descripcion de a que se dedica la empresa (texto libre,
     *        ej. Yahoo assetProfile.longBusinessSummary). Opcional y con valor por defecto
     *        '' para no romper las construcciones existentes de Company que no la conocen
     *        (el chart endpoint de Yahoo, usado en cada getStock(), no la trae; solo la
     *        rellena YahooCorporateProfileProvider para la ficha de detalle, ver
     *        fiabilidad-datos-mercado, integracion Finnhub).
     */
    public function __construct(
        private readonly string $ticker,
        private readonly string $name,
        private readonly string $sector,
        private readonly string $industry,
        private readonly string $market,
        private readonly string $currency,
        private readonly string $description = ''
    ) {
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSector(): string
    {
        return $this->sector;
    }

    public function getIndustry(): string
    {
        return $this->industry;
    }

    public function getMarket(): string
    {
        return $this->market;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
<?php

namespace App\Enums;

enum JobRole: string
{
    case SalesEngineer = 'sales_engineer';
    case TradeSalesEngineer = 'trade_sales_engineer';
    case Technical = 'technical';
    case ProductDesign = 'product_design';

    public function label(): string
    {
        return match ($this) {
            JobRole::SalesEngineer => 'Sales Engineer',
            JobRole::TradeSalesEngineer => 'Trade Sales Engineer',
            JobRole::Technical => 'Technical',
            JobRole::ProductDesign => 'Product Design',
        };
    }
}

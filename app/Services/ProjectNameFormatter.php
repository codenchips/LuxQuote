<?php

namespace App\Services;

class ProjectNameFormatter
{
    public function fromSalesforce(?string $name): string
    {
        return $name ?? '';
    }
}

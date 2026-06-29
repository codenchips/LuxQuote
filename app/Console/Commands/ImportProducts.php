<?php

namespace App\Console\Commands;

use App\Services\ProductImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:import-products')]
#[Description('Fetch products from the external API and replace the local product catalogue.')]
class ImportProducts extends Command
{
    public function __construct(private readonly ProductImportService $products)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Importing products...');

        try {
            $count = $this->products->import();
        } catch (Throwable $exception) {
            $this->error('Import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(number_format($count).' products imported successfully.');

        return self::SUCCESS;
    }
}

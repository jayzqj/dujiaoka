<?php

namespace App\Console\Commands;

use App\Service\ProcurementService;
use Illuminate\Console\Command;

class ProcurementStock extends Command
{
    protected $signature = 'procurement:stock';
    protected $description = 'Refresh supplier stock snapshots';

    public function handle(ProcurementService $service)
    {
        $service->refreshHomeStock();
        return 0;
    }
}

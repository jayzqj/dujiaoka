<?php

namespace App\Models;

class ProcurementGoods extends BaseModel
{
    protected $table = 'procurement_goods';
    protected $guarded = [];
    protected $casts = [
        'procurement_enabled' => 'boolean',
        'procurement_paused' => 'boolean',
        'procurement_max_cost' => 'decimal:2',
        'supplier_stock' => 'integer',
    ];

    public function goods()
    {
        return $this->belongsTo(Goods::class);
    }
}

<?php

namespace App\Models;

class ProcurementOrder extends BaseModel
{
    protected $table = 'procurement_orders';
    protected $guarded = [];
    protected $hidden = ['procurement_config'];
    protected $casts = ['procurement_enabled' => 'boolean', 'procurement_config' => 'array'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function carmi()
    {
        return $this->belongsTo(Carmis::class, 'carmi_id');
    }
}

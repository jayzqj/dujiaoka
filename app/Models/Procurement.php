<?php

namespace App\Models;

class Procurement extends BaseModel
{
    protected $guarded = [];
    protected $hidden = ['secret'];
    protected $casts = ['cost_exceeded' => 'boolean'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}

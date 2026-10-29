<?php

namespace App\Models;


use App\Events\GoodsDeleted;
use Illuminate\Database\Eloquent\SoftDeletes;

class Goods extends BaseModel
{

    use SoftDeletes;

    protected $table = 'goods';
    protected $with = ['procurementSettings'];
    protected $hidden = ['procurementSettings'];
    protected $appends = ['procurement_enabled', 'procurement_paused', 'allow_backorder', 'stock_unknown'];

    public function procurementSettings()
    {
        return $this->hasOne(ProcurementGoods::class, 'goods_id');
    }

    public function getProcurementEnabledAttribute(): bool
    {
        return (bool) ($this->procurementSettings->procurement_enabled ?? false);
    }

    public function getProcurementPausedAttribute(): bool
    {
        return (bool) ($this->procurementSettings->procurement_paused ?? false);
    }

    public function getAllowBackorderAttribute(): bool
    {
        return (bool) $this->procurement_enabled && !$this->procurement_paused
            && (int) $this->type === self::AUTOMATIC_DELIVERY;
    }

    public function getStockUnknownAttribute(): bool
    {
        $settings = $this->procurementSettings;
        return $this->procurement_enabled && ($settings->supplier_stock === null
            || !$settings->supplier_stock_at || strtotime($settings->supplier_stock_at) < time() - 60);
    }

    protected $dispatchesEvents = [
        'deleted' => GoodsDeleted::class
    ];

    /**
     * 关联分类
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function group()
    {
        return $this->belongsTo(GoodsGroup::class, 'group_id');
    }

    /**
     * 关联优惠券
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function coupon()
    {
        return $this->belongsToMany(Coupon::class, 'coupons_goods', 'goods_id', 'coupons_id');
    }

    /**
     * 关联卡密
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function carmis()
    {
        return $this->hasMany(Carmis::class, 'goods_id');
    }

    /**
     * 库存读取器,将自动发货的库存更改为未出售卡密的数量
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function getInStockAttribute()
    {
        if (isset($this->attributes['carmis_count'])
            &&
            $this->attributes['type'] == self::AUTOMATIC_DELIVERY
        ) {
           $local = (int) $this->attributes['carmis_count'];
           return $local + ($this->allow_backorder && !$this->stock_unknown ? (int) $this->procurementSettings->supplier_stock : 0);
        }
        return $this->attributes['in_stock'];
    }

    /**
     * 获取组建映射
     *
     * @return array
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public static function getGoodsTypeMap()
    {
        return [
            self::AUTOMATIC_DELIVERY => admin_trans('goods.fields.automatic_delivery'),
            self::MANUAL_PROCESSING => admin_trans('goods.fields.manual_processing')
        ];
    }

}

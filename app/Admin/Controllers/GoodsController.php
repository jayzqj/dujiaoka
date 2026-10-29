<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Post\BatchRestore;
use App\Admin\Actions\Post\Restore;
use App\Admin\Repositories\Goods;
use App\Models\Carmis;
use App\Models\Coupon;
use App\Models\GoodsGroup as GoodsGroupModel;
use Dcat\Admin\Admin;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use App\Models\Goods as GoodsModel;

class GoodsController extends AdminController
{


    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new Goods(['group', 'coupon']), function (Grid $grid) {
            $grid->model()->orderBy('id', 'DESC');
            $grid->column('id')->sortable();
            $grid->column('picture')->image('', 100, 100);
            $grid->column('gd_name');
            $grid->column('gd_description');
            $grid->column('gd_keywords');
            $grid->column('group.gp_name', admin_trans('goods.fields.group_id'));
            $grid->column('type')
                ->using(GoodsModel::getGoodsTypeMap())
                ->label([
                    GoodsModel::AUTOMATIC_DELIVERY => Admin::color()->success(),
                    GoodsModel::MANUAL_PROCESSING => Admin::color()->info(),
                ]);
            $grid->column('retail_price');
            $grid->column('actual_price')->sortable();
            $grid->column('in_stock')->display(function () {
                // 如果为自动发货，则加载库存卡密
                if ($this->type == GoodsModel::AUTOMATIC_DELIVERY) {
                    return Carmis::query()->where('goods_id', $this->id)
                        ->where('status', Carmis::STATUS_UNSOLD)
                        ->count();
                } else {
                    return $this->in_stock;
                }
            });
            $grid->column('sales_volume');
            $grid->column('procurement_enabled', '第三方补货')->bool();
            $grid->column('procurement_paused', '采购已暂停')->bool();
            $grid->column('ord')->editable()->sortable();
            $grid->column('is_open')->switch();
            $grid->column('created_at')->sortable();
            $grid->column('updated_at');
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id');
                $filter->like('gd_name');
                $filter->equal('type')->select(GoodsModel::getGoodsTypeMap());
                $filter->equal('group_id')->select(GoodsGroupModel::query()->pluck('gp_name', 'id'));
                $filter->scope(admin_trans('dujiaoka.trashed'))->onlyTrashed();
                $filter->equal('coupon.coupons_id', admin_trans('goods.fields.coupon_id'))->select(
                    Coupon::query()->pluck('coupon', 'id')
                );
            });
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $actions->append(new Restore(GoodsModel::class));
                }
            });
            $grid->batchActions(function (Grid\Tools\BatchActions $batch) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $batch->add(new BatchRestore(GoodsModel::class));
                }
            });
        });
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     *
     * @return Show
     */
    protected function detail($id)
    {
        return Show::make($id, new Goods(), function (Show $show) {
            $show->id('id');
            $show->field('gd_name');
            $show->field('gd_description');
            $show->field('gd_keywords');
            $show->field('picture')->image();
            $show->field('retail_price');
            $show->field('actual_price');
            $show->field('in_stock');
            $show->field('ord');
            $show->field('sales_volume');
            $show->field('type')->as(function ($type) {
                if ($type == GoodsModel::AUTOMATIC_DELIVERY) {
                    return admin_trans('goods.fields.automatic_delivery');
                } else {
                    return admin_trans('goods.fields.manual_processing');
                }
            });
            $show->field('is_open')->as(function ($isOpen) {
                if ($isOpen == GoodsGroupModel::STATUS_OPEN) {
                    return admin_trans('dujiaoka.status_open');
                } else {
                    return admin_trans('dujiaoka.status_close');
                }
            });
            $show->wholesale_price_cnf()->unescape()->as(function ($wholesalePriceCnf) {
                return  "<textarea class=\"form-control field_wholesale_price_cnf _normal_\"  rows=\"10\" cols=\"30\">" . $wholesalePriceCnf . "</textarea>";
            });
            $show->other_ipu_cnf()->unescape()->as(function ($otherIpuCnf) {
                return  "<textarea class=\"form-control field_wholesale_price_cnf _normal_\"  rows=\"10\" cols=\"30\">" . $otherIpuCnf . "</textarea>";
            });
            $show->api_hook()->unescape()->as(function ($apiHook) {
                return  "<textarea class=\"form-control field_wholesale_price_cnf _normal_\"  rows=\"10\" cols=\"30\">" . $apiHook . "</textarea>";
            });;
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Form::make(new Goods(['procurementSettings']), function (Form $form) {
            $form->display('id');
            $form->text('gd_name')->required();
            $form->text('gd_description')->required();
            $form->text('gd_keywords')->required();
            $form->select('group_id')->options(
                GoodsGroupModel::query()->pluck('gp_name', 'id')
            )->required();
            $form->image('picture')->autoUpload()->uniqueName()->help(admin_trans('goods.helps.picture'));
            $form->radio('type')->options(GoodsModel::getGoodsTypeMap())->default(GoodsModel::AUTOMATIC_DELIVERY)->required();
            $form->currency('retail_price')->default(0)->help(admin_trans('goods.helps.retail_price'));
            $form->currency('actual_price')->default(0)->required();
            $form->number('in_stock')->help(admin_trans('goods.helps.in_stock'));
            $form->number('sales_volume');
            $form->number('buy_limit_num')->help(admin_trans('goods.helps.buy_limit_num'));
            $form->editor('buy_prompt');
            $form->editor('description');
            $form->textarea('other_ipu_cnf')->help(admin_trans('goods.helps.other_ipu_cnf'));
            $form->textarea('wholesale_price_cnf')->help(admin_trans('goods.helps.wholesale_price_cnf'));
            $form->textarea('api_hook');
            $form->number('ord')->default(1)->help(admin_trans('dujiaoka.ord'));
            $form->switch('is_open')->default(GoodsModel::STATUS_OPEN);
            $form->switch('procurement_settings.procurement_enabled', '本地缺货时自动采购')->default(0);
            $form->switch('procurement_settings.procurement_paused', '暂停自动采购')->default(0)->help('实际成本超限后自动开启，核实后可关闭；仍可销售本地库存');
            $form->text('procurement_settings.supplier_code', '第三方商品 code');
            $form->text('procurement_settings.supplier_race', '第三方种类 race')->help('填写原始种类键，无种类留空');
            $form->textarea('procurement_settings.supplier_sku', '固定规格 SKU')->help('JSON 对象，例如 {"版本":"标准"}；没有规格填 {}');
            $form->currency('procurement_settings.procurement_max_cost', '单张采购成本上限（CNY）')->default(0);
            $form->display('procurement_settings.supplier_stock', '最近第三方库存');
            $form->display('procurement_settings.supplier_stock_at', '库存查询时间');
            $form->saving(function (Form $form) {
                $settings = $form->input('procurement_settings');
                if (!is_array($settings)) { return; }
                $settings['supplier_stock'] = null;
                $settings['supplier_stock_at'] = null;
                $form->input('procurement_settings', $settings);
                if (empty($settings['procurement_enabled'])) { return; }
                if ((int) $form->type !== GoodsModel::AUTOMATIC_DELIVERY || empty($settings['supplier_code']) || $form->other_ipu_cnf) {
                    return $form->response()->error('第三方补货仅支持已绑定商品的自动发卡商品，不支持额外输入框');
                }
                $sku = json_decode($settings['supplier_sku'] ?? '{}');
                if (!is_object($sku)) {
                    return $form->response()->error('SKU 必须为 JSON 对象');
                }
                foreach ((array) $sku as $value) {
                    if (!is_string($value) && !is_numeric($value)) {
                        return $form->response()->error('SKU 每个规格必须为字符串或数字');
                    }
                }
                // A zero limit would reject every quote after the customer has already paid.
                if (!\App\Service\IfakaClient::money($settings['procurement_max_cost'] ?? null)
                    || bccomp($settings['procurement_max_cost'], '0', 2) <= 0) {
                    return $form->response()->error('单张采购成本上限必须为大于 0 的金额，最多两位小数');
                }
                if (!dujiaoka_config_get('ifaka_app_id') || !dujiaoka_config_get('ifaka_app_key_encrypted')
                    || !preg_match('/^[A-Za-z0-9]{2,4}$/D', (string) dujiaoka_config_get('ifaka_request_prefix', ''))
                    || !dujiaoka_config_get('customer_service_wechat')) {
                    return $form->response()->error('请先在系统设置填写第三方凭据和客服微信');
                }
                $form->buy_limit_num = 1;
            });
        });
    }
}

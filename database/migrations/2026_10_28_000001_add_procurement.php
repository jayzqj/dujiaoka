<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProcurement extends Migration
{
    public function up()
    {
        // Existing tables are never altered, including tables created manually through DBX.
        if (!Schema::hasTable('procurement_goods')) {
            Schema::create('procurement_goods', function (Blueprint $table) {
                $this->configureTable($table);
                $table->bigIncrements('id');
                $table->integer('goods_id')->unique();
                $table->boolean('procurement_enabled')->default(false);
                $table->boolean('procurement_paused')->default(false);
                $table->string('supplier_code', 190)->nullable();
                $table->string('supplier_race', 190)->nullable();
                $table->text('supplier_sku')->nullable();
                $table->decimal('procurement_max_cost', 10, 2)->default(0);
                $table->unsignedInteger('supplier_stock')->nullable();
                $table->timestamp('supplier_stock_at')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('procurement_orders')) {
            Schema::create('procurement_orders', function (Blueprint $table) {
                $this->configureTable($table);
                $table->bigIncrements('id');
                $table->bigInteger('order_id')->unique();
                $table->timestamp('paid_at')->nullable();
                $table->boolean('procurement_enabled')->default(false);
                $table->text('procurement_config')->nullable();
                $table->bigInteger('carmi_id')->nullable()->unique();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('procurements')) {
            Schema::create('procurements', function (Blueprint $table) {
                $this->configureTable($table);
                $table->bigIncrements('id');
                $table->bigInteger('order_id')->unique();
                $table->string('request_no', 16)->nullable()->unique();
                $table->string('supplier_order_no', 190)->nullable()->unique();
                $table->string('state', 24)->default('pending')->index();
                $table->decimal('amount', 12, 2)->nullable();
                $table->boolean('cost_exceeded')->default(false);
                $table->text('secret')->nullable();
                $table->string('error_code', 100)->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_unicode_ci';
    }

    public function down()
    {
        Schema::dropIfExists('procurements');
        Schema::dropIfExists('procurement_orders');
        Schema::dropIfExists('procurement_goods');
    }
}

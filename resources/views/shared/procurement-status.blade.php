@if(!empty($order['procurement_enabled']) && !empty($order['paid_at']) && $order['status'] == \App\Models\Order::STATUS_PROCESSING)
    <span hidden class="delivery-pending" data-status-url="{{ url('check-order-status', ['orderSN' => $order['order_sn']]) }}"></span>
@endif

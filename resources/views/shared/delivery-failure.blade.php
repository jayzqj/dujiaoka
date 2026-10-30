<div class="delivery-failure-card" role="status">
    <strong>出卡失败，请联系客服</strong>
    @if(dujiaoka_config_get('customer_service_wechat'))
        <p>客服微信：<strong>{{ dujiaoka_config_get('customer_service_wechat') }}</strong></p>
    @endif
</div>
<style>
    .delivery-failure-card { box-sizing:border-box; padding:24px 18px; border:1px solid #efcccc; border-radius:10px; background:#fff8f8; color:#a52a2a; font-size:16px; line-height:1.7; overflow-wrap:anywhere; }
    .delivery-failure-card p { margin:12px 0 0; }
</style>

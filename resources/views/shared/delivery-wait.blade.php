<div class="delivery-wait-card" role="status" aria-label="订单交付处理中">
    <div class="delivery-countdown" aria-hidden="true">
        <svg class="delivery-countdown-ring" viewBox="0 0 120 120" focusable="false">
            <circle class="delivery-countdown-track" cx="60" cy="60" r="52"></circle>
            <circle class="delivery-countdown-progress" cx="60" cy="60" r="52"></circle>
        </svg>
        <div class="delivery-countdown-number"><span class="delivery-countdown-value">10</span><small>秒</small></div>
    </div>
    <p class="delivery-wait-message">正在出卡</p>
</div>
<style>
    .delivery-wait-card { box-sizing:border-box; display:flex; width:100%; min-height:250px; padding:30px 16px; align-items:center; justify-content:center; flex-direction:column; gap:14px; border:1px solid #e7edf4; border-radius:10px; background:rgba(255,255,255,.88); text-align:center; }
    .delivery-countdown { position:relative; display:grid; width:142px; height:142px; place-items:center; color:#2484ed; }
    .delivery-countdown-ring { position:absolute; inset:0; width:100%; height:100%; overflow:visible; transform:rotate(-90deg); }
    .delivery-countdown-track,.delivery-countdown-progress { fill:none; stroke-width:8; }
    .delivery-countdown-track { stroke:#e6edf6; }
    .delivery-countdown-progress { stroke:#09bfd2; stroke-linecap:round; stroke-dasharray:326.73; stroke-dashoffset:0; animation:delivery-countdown-ring 10s linear 1 forwards; }
    .delivery-countdown-number { display:flex; align-items:baseline; justify-content:center; gap:3px; font:700 54px/1 system-ui,-apple-system,"Segoe UI",sans-serif; font-variant-numeric:tabular-nums; }
    .delivery-countdown-number small { font-size:16px; font-weight:600; }
    .delivery-wait-card p { margin:0; color:#64748b; font-size:16px; }
    .delivery-wait-card.delivery-wait-long .delivery-wait-message { font-size:20px; font-weight:600; color:#2484ed; }
    @keyframes delivery-countdown-ring { to { stroke-dashoffset:326.73; } }
    @media (prefers-reduced-motion:reduce) { .delivery-countdown-ring,.delivery-countdown-progress { animation:none!important; } .delivery-countdown-progress { stroke-dashoffset:0; } }
    @media (max-width:576px) { .delivery-wait-card { min-height:210px; padding:24px 12px; } .delivery-countdown { width:120px; height:120px; } }
</style>

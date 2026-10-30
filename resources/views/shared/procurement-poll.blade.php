<script>
(function () {
    var items = document.querySelectorAll('.delivery-pending');
    if (!items.length) return;
    var countdowns = document.querySelectorAll('.delivery-countdown-value');
    var startedAt = Date.now();
    var remaining = 10;
    var countdownTimer = window.setInterval(function () {
        if (document.hidden) return;
        remaining = Math.max(0, 10 - Math.floor((Date.now() - startedAt) / 1000));
        Array.prototype.forEach.call(countdowns, function (item) { item.textContent = String(remaining); });
        if (remaining === 0) {
            window.clearInterval(countdownTimer);
            Array.prototype.forEach.call(document.querySelectorAll('.delivery-wait-card'), function (card) {
                card.querySelector('.delivery-countdown').style.display = 'none';
                card.querySelector('.delivery-wait-message').textContent = '正在出卡，请稍后';
                card.classList.add('delivery-wait-long');
            });
        }
    }, 250);
    function poll() {
        Promise.all(Array.prototype.map.call(items, function (item) {
            return fetch(item.getAttribute('data-status-url'), {credentials: 'same-origin'})
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (result.code === 200 && result.delivery !== 'processing') window.location.reload();
                }).catch(function () {});
        })).then(function () { window.setTimeout(poll, 3000); });
    }
    window.setTimeout(poll, 3000);
})();
</script>

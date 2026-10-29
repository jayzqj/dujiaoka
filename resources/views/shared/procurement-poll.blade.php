<script>
(function () {
    var items = document.querySelectorAll('.delivery-pending');
    if (!items.length) return;
    var attempts = 0;
    function poll() {
        if (++attempts > 120) return;
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

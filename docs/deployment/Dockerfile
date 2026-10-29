FROM local/dujiaoka-gptplus:before-20261029-reviewed
COPY --chown=application:application runtime/ /app/
COPY procurement-worker.conf queue-worker.conf /opt/docker/etc/supervisor.d/
COPY start.sh /app/start.sh
ENV APP_ENV=production APP_DEBUG=false
CMD ["/app/start.sh"]
STOPSIGNAL SIGTERM
RUN rm -f /etc/cron.d/procurement-schedule \
    && chmod 644 /opt/docker/etc/supervisor.d/procurement-worker.conf /opt/docker/etc/supervisor.d/queue-worker.conf \
    && chmod 755 /app/start.sh \
    && sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' /app/.env \
    && rm -f /app/bootstrap/cache/config.php /tmp/procurement-app-before.tgz /tmp/procurement-preflight.php \
    && find /app/storage/framework/views -type f -name '*.php' -delete \
    && chown -R application:application /app/storage /app/bootstrap/cache
LABEL com.centurylinklabs.watchtower.enable=false
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 CMD curl -fsS --max-time 5 http://127.0.0.1/order-search >/dev/null || exit 1

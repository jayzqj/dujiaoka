# 自动购卡上线手册

> **2026-10-29 已部署完成。** 下文保留首次部署方案；其中“无挂载卷”“尚未备份”等描述是部署前状态，不应作为当前运维步骤。当前镜像、持久化目录、进程、后台配置和回滚方法见 [部署结果与运维记录](procurement-release-20261029.md)。后续重建请使用服务器上 `/opt/dujiaoka-procurement/current/compose.json`，不要用旧公共镜像重建。

> 最新后续发布已启用三个商品及列表库存同步，见 [商品对应与首页库存记录](procurement-catalog-20261029.md)。

适用于验证记录中已确认的目标环境：`https://gptplus.9527653.xyz/` 反向代理到宿主机 8081 端口，容器 `dujiaoka_gpt_plus`，应用目录 `/app`，数据库 `dujiaoka_gptplus`，PHP CLI 7.4.33。容器内由 Supervisor 管理 PHP-FPM、Nginx 和 cron，没有挂载卷。三张采购表已在目标库建好，本手册不再建表。

命令默认在宿主机执行。每一步都核对结果，与预期不符就停止，不要跳过或强行覆盖。下文的 `APP_USER` 指 PHP-FPM worker 进程的运行用户，先在容器内用 `ps aux | grep php-fpm` 确认（webdevops 镜像通常是 `application`）。所有 `artisan` 命令都以该用户执行，避免 root 生成应用无法追加写入的日志文件。

## 1. 生成部署包（开发机）

部署包只含运行时文件。代码有任何改动后都要重新生成：

```sh
git diff -- app resources > storage/app/procurement-deploy/tracked.patch
git ls-files --others --exclude-standard -- app database resources | tar czf storage/app/procurement-deploy/new-files.tar.gz -T -
```

`tracked.patch` 以提交 `6bf5f3d` 为基准，修改 22 个已有文件；`new-files.tar.gz` 含 11 个新增文件（采购模型、服务、命令、迁移、建表 SQL 和共享模板）。

## 2. 备份与上传

1. 确认容器：`docker ps --filter name=dujiaoka_gpt_plus`，`docker exec dujiaoka_gpt_plus ls /app/artisan`。
2. 备份数据库 `dujiaoka_gptplus`（如 `mysqldump --single-transaction`，按实际地址和账号执行）。
3. 备份将被修改的代码目录：

   ```sh
   mkdir -p /opt/dujiaoka-procurement/backup
   docker exec dujiaoka_gpt_plus tar czf /tmp/app-before-procurement.tgz -C /app app resources database routes config
   docker cp dujiaoka_gpt_plus:/tmp/app-before-procurement.tgz /opt/dujiaoka-procurement/backup/
   ```

4. 把两个部署文件上传到 `/opt/dujiaoka-procurement/releases/<日期>/`。之前 SSH MCP 的 `allowedRemotePaths` 只有 `/__sftp_disabled__`，上传被拒；需放开该目录，或由管理员手动 scp。

## 3. 应用代码

```sh
cd /opt/dujiaoka-procurement/releases/<日期>
docker cp tracked.patch dujiaoka_gpt_plus:/tmp/
docker cp new-files.tar.gz dujiaoka_gpt_plus:/tmp/
docker exec -w /app dujiaoka_gpt_plus git apply --check /tmp/tracked.patch
```

`--check` 失败说明线上代码与基准版本不同，先比对差异再决定，不要强行覆盖。容器内没有 git 时改用 `patch -p1 --dry-run < /tmp/tracked.patch`。检查通过后：

```sh
docker exec -w /app dujiaoka_gpt_plus git apply /tmp/tracked.patch
docker exec -w /app dujiaoka_gpt_plus tar xzf /tmp/new-files.tar.gz --no-same-owner
docker exec -u APP_USER -w /app dujiaoka_gpt_plus php artisan migrate:status
docker exec -u APP_USER -w /app dujiaoka_gpt_plus php artisan migrate --force
docker exec -u APP_USER -w /app dujiaoka_gpt_plus php artisan view:clear
```

- `migrate:status` 应只有 `2026_10_28_000001_add_procurement` 未执行；出现其他待执行迁移时先停下核实。采购表已存在，迁移只登记版本。
- 不要执行 `cache:clear` 或 `optimize:clear`：系统设置（含加密的供应商密钥）保存在缓存中，会被一并清空。
- 用 `supervisorctl status` 查出 PHP-FPM 的程序名并重启，使 opcache 加载新代码。

## 4. 采购进程与定时任务

在 Supervisor 配置目录新增 `procurement-worker.conf`。目录以 supervisord 主配置的 `[include]` 为准，webdevops 镜像一般是 `/opt/docker/etc/supervisor.d/`：

```ini
[program:procurement-worker]
command=php /app/artisan procurement:work
directory=/app
user=APP_USER
autostart=true
autorestart=true
startsecs=3
startretries=100
stopsignal=TERM
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
```

- 进程收到 TERM 后先完成当前这笔采购再退出。单笔最多发三次请求，每次最长 25 秒，`stopwaitsecs` 不要低于 90，否则重启时可能打断已提交的采购，使其转为结果未知。
- 生效：`supervisorctl reread`、`supervisorctl update`，再用 `supervisorctl status` 确认 RUNNING。数据库暂时不可用时进程会记录 `procurement_worker_iteration_failed` 并在 10 秒后重试，不会退出。

库存快照依赖每分钟执行的调度。按容器现有 cron 的配置方式增加一条（`/etc/cron.d` 格式需要用户列）：

```
* * * * * APP_USER php /app/artisan schedule:run >> /dev/null 2>&1
```

## 5. 容器重建

容器没有挂载卷。删除或按旧镜像重建容器后，新代码、Supervisor 和 cron 配置都会丢失，但数据库中的采购记录仍在，旧代码不会处理已付款的采购。验证通过后，用新代码重新构建镜像（连同上述配置），或 `docker commit` 当前容器为新镜像并同步更新创建容器的命令。完成之前不要重建该容器。

## 6. 后台配置与联调

1. 系统设置 →「第三方自动购卡」：填写 App ID、App Key、平台确认的独占请求号前缀和客服微信。
2. 选一个已核对为自动卡池发货、无额外控件、支持邮箱联系方式的第三方商品，在对应本地商品开启自动采购，填写 code、race、SKU 和大于 0 的成本上限。其余商品先保持关闭。
3. 执行 `php artisan procurement:stock`（只查库存，不下单），在商品编辑页确认「最近第三方库存」和查询时间已更新。没有更新时先排查凭据、签名和 IP 白名单。
4. 本地不放卡密，用真实支付购买一单：页面应先显示「支付成功，正在出卡」，随后出卡；后台订单详情有请求号、第三方订单号和实际成本；邮箱收到卡密。

## 7. 回滚

1. `supervisorctl stop procurement-worker`，删除该配置和 cron 条目。
2. 删除部署包中的 11 个新增文件，解压第 2 步的备份覆盖 `/app`，重启 PHP-FPM。
3. 保留三张采购表，旧代码不读取它们。不要回滚迁移，回滚会删除采购配置、订单映射和采购记录。
4. 回滚前仍处于「正在出卡」的已付款订单需人工处理，采购记录在 `procurements` 表中查看。

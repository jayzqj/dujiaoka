# 2026-10-29 审核、部署与运维记录

> 本文保留首次部署记录。后续已配置并启用三个商品，更新列表库存读取和容器停止方式；当前版本及实际配置以 [商品对应与首页库存记录](procurement-catalog-20261029.md) 为准。

## 结果与范围

已完成项目目标核对、代码审核、缺陷修复、测试、服务器备份、部署和上线验证。用户确认供应商凭据、商品映射及成本上限在部署后通过后台填写；本次保持全部商品自动采购关闭，没有发起真实采购或支付。业务启用和真实联调按下文单独进行。

| 项目 | 当前状态 |
| --- | --- |
| 网站 | `https://gptplus.9527653.xyz/` |
| 实际后台入口 | `https://gptplus.9527653.xyz/jayzqj/admin/auth/login` |
| 应用容器 | `dujiaoka_gpt_plus` |
| 新镜像 | `local/dujiaoka-gptplus:procurement-20261029` |
| 镜像 ID | `sha256:4bd98891e1b8f111c50f5107faf4182cd504cfde1633edc73d5f61a048e2158e` |
| 应用目录、运行用户 | `/app`、`application`（UID 1000） |
| 数据库 | `dujiaoka_gptplus` |
| 发布目录 | `/opt/dujiaoka-procurement/releases/20261029-reviewed` |
| 当前发布链接 | `/opt/dujiaoka-procurement/current` |
| Compose | 项目 `dujiaoka-gptplus`，当前发布目录内的 `compose.json` |
| 原容器 | `dujiaoka_gpt_plus_before_20261029_reviewed`，已停止，`restart=no` |
| 原镜像快照 | `local/dujiaoka-gptplus:before-20261029-reviewed` |

## 目标核对

- 本地库存优先；开启补货的商品每单限购一张，本地与第三方库存为零或未知仍可下单；零元订单不采购。
- 付款确认和采购记录同事务提交；独立进程采购，不在支付通知中执行供应商网络调用。
- 下单前保存不可重发状态；超时或中断转人工核对，不能重新采购。响应已保存时只恢复本地交付。
- 询价限制成本；实际成交超限但已取得卡密时正常交付并暂停后续采购。
- 保留全部旧表结构，用三张扩展表保存配置、订单快照和采购记录。
- 三套主题区分支付与交付状态，显示客服联系方式；后台提供配置、请求号和异常信息。
- 人工补发、退款及处理记录按既定 ADR 在线下完成，不增加后台补发流程。

上述实现已在代码、测试和服务器当前状态中核对。供应商真实响应、账户余额、权限和白名单，以及真实付款后的出卡效果，仍需后台配置完成后联调。

## 本轮修复与测试

1. 邮箱查单原先在传入空密码时跳过密码条件，即使开启查询密码也可能返回订单；字符串 `0` 同样被当成空值。现由查询层拒绝必填场景的空密码，并正确匹配 `0`。下单的查询密码规则改为隐式校验，缺失字段也必须验证。
2. 供应商查单密码原先等于可预测请求号，改为 16 位加密随机十六进制字符串；供应商采购参数测试同步验证其格式和与请求号的区分。
3. 线上 `APP_DEBUG=true` 改为 `false`，运行环境设为 `production`。
4. 目标容器原先没有存活的邮件队列进程。新镜像用 Supervisor 管理邮件队列，移除启动脚本中无人守护的后台 `queue:work`，避免重建后重复启动。
5. 配置采购进程、每分钟调度、容器健康检查及持久化目录；固化新镜像，防止重建回到旧代码。

沿用并复核了此前已修复的采购进程异常重试、停止信号处理、恢复流程退出、成本上限校验与库存调度锁期限。

- 默认 PHPUnit：**47 项测试，284 个断言通过**。
- 独立 MySQL 8.4.6：**6 项测试，72 个断言通过**；覆盖原始表兼容、SQL/迁移一致、并发支付与采购、事务回滚及跨进程恢复。测试结束后临时数据库删除，本地 MySQL 正常停止。
- 合计 **53 项测试，356 个断言**。供应商、支付和发送队列均模拟，不发起真实交易或邮件。
- 修改的 PHP 文件语法检查、`git diff --check` 通过。服务器新镜像的 Blade 模板全部编译成功。
- PHPUnit 仍有原有 XML schema 弃用提示，未影响测试结果。

## 备份与上线证据

发布目录的 `backup/` 保存数据库 SQL、完整应用和进程配置归档、原容器描述、系统设置缓存备份及原镜像 ID。目录仅 root 可进入，敏感备份文件权限为 0600。

线上已有文件与工作区 Git 基准在统一 CRLF/LF 后一致。补丁先在临时目录检查并应用，再构建镜像；34 个运行时文件的 SHA-256 与测试后的工作区逐一一致，正式容器上线后再次核对通过。

采购迁移 `2026_10_28_000001_add_procurement` 登记为 batch 1；上线前存在的 **23 张表**与数据库备份中的 `CREATE TABLE` 全部一致（仅忽略自增计数）。迁移只增加版本登记表，没有修改原表结构。系统设置缓存的 SHA-256 在发布前后相同，没有清空缓存或重新生成 `APP_KEY`。

前台首页、商品 `/buy/1`、订单查询页及实际后台登录页均 HTTP 200。通过进程内只读请求验证系统设置和已有商品编辑页，新增供应商及采购配置字段全部正常渲染；未修改管理员账号或建立外部登录会话。

新容器健康检查为 healthy，重启次数 0；PHP-FPM、Nginx、cron、`procurement-worker`、`queue-worker` 均 RUNNING。观察到 cron 连续按分钟调用 `schedule:run`，手工调度也执行了 `procurement:stock`。旧的验证容器已移除，原生产容器保留用于回滚。

上线检查可读取 4 个商品和 64 笔历史订单。自动采购开启数为 0、采购记录数为 0，符合本次部署范围。

发布目录保留 `runtime-manifest.txt`、`expected-hashes.json`、`before-switch-smoke.json`、`after-switch-smoke.json`、`final-smoke.json`、`schema-comparison.txt`、补丁、构建目录和日志。

## 持久化与进程

新容器保留原来的 8081→80、9096→9096 端口映射和 bridge 网络，重启策略为 `always`。持久化目录为：

| 宿主机 | 容器 |
| --- | --- |
| `/opt/dujiaoka-procurement/data/storage` | `/app/storage` |
| `/opt/dujiaoka-procurement/data/uploads` | `/app/public/uploads` |

旧容器数据已复制，写权限归 UID 1000。新代码、Supervisor 配置、cron 和启动脚本固化在新镜像内；重建必须使用本次 Compose 文件。该容器设置 `com.centurylinklabs.watchtower.enable=false`，避免旧公共镜像自动覆盖定制版本。

镜像未推送到外部仓库。镜像、数据库备份及 Compose 环境配置均包含私有站点信息，应按私有资产保管；Compose 文件权限 0600。

配置副本见 [deployment](deployment/)：`Dockerfile`、`start.sh`、`procurement-worker.conf`、`queue-worker.conf`、`procurement-schedule`。构建还需发布目录中由补丁生成的 `build/runtime/` 及原镜像快照。

两个后台进程均以 `application` 运行，自动重启，停止等待 120 秒。容器启动脚本只运行 Supervisor。

```sh
docker ps --filter name=dujiaoka_gpt_plus
docker exec dujiaoka_gpt_plus supervisorctl status
docker exec -u application -w /app dujiaoka_gpt_plus php artisan migrate:status
docker exec -u application -w /app dujiaoka_gpt_plus php artisan queue:failed
docker compose -p dujiaoka-gptplus -f /opt/dujiaoka-procurement/current/compose.json ps
```

不要执行 `cache:clear` 或 `optimize:clear`，它们会删除系统设置及加密供应商密钥。模板刷新用 `view:clear` / `view:cache`。后续代码发布应构建新镜像并更新 Compose，使 PHP-FPM 和两个后台进程使用同一版本。

## 部署后后台配置

1. 系统设置 →「第三方自动购卡」填写 App ID、App Key、平台确认的独占请求号前缀（2–4 位字母数字）和客服微信。
2. 编辑自动发卡商品，填写 code、race、SKU JSON 对象、大于 0 的单张成本上限，核对自动卡池交付、无额外控件、支持邮箱联系方式后启用采购。
3. 供应商侧核对 API 权限、IP 白名单、余额和专属请求号前缀。后台配置后不需要手动启动进程。
4. 可执行 `docker exec -u application -w /app dujiaoka_gpt_plus php artisan procurement:stock` 只查库存，再安排真实付款和采购联调。`procurement:work --once` 会真实采购，不是试运行。
5. **用户已确认本次不配置 SMTP。** 客户通过网页查单和查看卡密，邮件发送不作为自动采购启用的前提。恢复队列后，11 条积压 `MailSend` 因 SMTP 主机为空进入 `failed_jobs`（ID 1–11，2026-10-29 15:45:08–15:45:11），记录已保留，未批量重试。本次没有关闭邮件任务代码，因此不配置 SMTP 时后续邮件任务仍可能失败；如将来需要邮件通知，再单独配置和验证。

2026-10-29 后续只读核对：App ID、加密 App Key、请求号前缀和客服微信均已保存，未输出密钥。4 个商品的自动采购仍关闭。商品采购配置入口为后台 `/goods` 列表中的编辑页，在表单底部的「是否启用」之后；系统设置页只保存供应商账号与客服信息。

既有业务限制仍保留：成交价可能高于询价；提交后未知结果转人工；商品卡密须核对实际返回内容；旧 `carmis.carmi` 字符集不支持四字节字符，异常响应仍保留在采购表供人工核对。本次未扩展业务规则或升级 PHP/Laravel 主版本。

## 应急回滚

回滚前确认已付款待采购订单，由人工负责处理；旧代码不会处理采购记录。不要回滚采购迁移，也不要直接恢复旧数据库覆盖新订单。

停止新容器后可恢复保留的原容器。以下命令为应急操作，执行前核实名称没有被其他发布占用。原容器仍有此次修复前的调试和队列配置问题，恢复后需一并处理。

```sh
docker update --restart=no dujiaoka_gpt_plus
docker stop -t 120 dujiaoka_gpt_plus
docker rename dujiaoka_gpt_plus dujiaoka_gpt_plus_failed_20261029_reviewed
docker rename dujiaoka_gpt_plus_before_20261029_reviewed dujiaoka_gpt_plus
docker update --restart=always dujiaoka_gpt_plus
docker start dujiaoka_gpt_plus
```

新版本生成的上传文件和日志保留在宿主机 `data/` 下，回滚时按需核对同步；不要删除持久化目录和备份。后续发布以实际容器状态为准，不要重跑首次切换脚本覆盖已有持久化目录。

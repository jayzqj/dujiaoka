# 2026-10-29 按首页访问刷新库存

> 库存策略保持本文所述不变。当前镜像为 `local/dujiaoka-gptplus:zero-delivery-20261029`，当前发布目录为 `/opt/dujiaoka-procurement/releases/20261029-zero-delivery`；支持零元订单采购、企业微信失败通知及前台仅显示一次出卡状态，见 [最新验证记录](procurement-verification.md)。下方批量库存版本信息保留作为历史记录。

已纠正此前对需求的误解：每次进入首页只请求一次 `/shared/commodity/items`，从返回的全部商品中按 code 更新三个对应商品，不逐个请求详情。取消每分钟的库存定时任务，也没有新增 3 分钟或 5 分钟定时任务。请求成功后写入库存快照，下一次首页访问仍会重新查询；同时到来的访问通过 Redis 锁合并正在进行的刷新。

商品对应、启用状态及采购成本上限保持：本地 ID 1 / GPT Plus 为 130 元，ID 3 / GPT Go 为 50 元，ID 4 / GPT Pro 5X 为 600 元。没有修改售价、采购任务或 SMTP 设置。

## 变更

- `ProcurementService::refreshHomeStock()` 读取启用且上架的自动发卡商品，共用一次 `/items` 响应；不使用 30 秒结果缓存。
- 三个当前商品均无种类和规格，从列表各商品的 `stock` 取值。未来带 race/SKU 的商品仍查选定规格的 `/stock`，避免使用整件商品的总量。
- 查询响应中的缺失、无效库存或不匹配的商品 code 标记为未知；失败不阻断首页，下一次访问可重新获取。已修改映射的旧请求结果不能覆盖新映射。
- 库存相关只读请求使用 2 秒连接、4 秒总超时；交易请求超时配置不变。当前每次首页刷新仅发起一次列表请求。
- `Console\Kernel` 不再注册采购库存调度，镜像中删除 `/etc/cron.d/procurement-schedule`。保留 `procurement:stock` 供管理员手动刷新。
- 容器健康检查从 `/` 改为 `/order-search`，避免每 30 秒的健康检查间接刷新库存。原有 cron 服务可继续处理系统任务，但没有本站库存定时条目。

## 验证

默认 PHPUnit **54 项测试、337 个断言通过**。明确验证三个对应商品共用一次列表请求，下一次访问再次只请求一次；同时覆盖连续访问得到不同库存、供应商异常后下一次访问恢复、库存为零与未知的区别、映射变更保护、并发刷新合并，以及执行 `schedule:run` 不触发供应商请求。

线上使用计数客户端确认：

```
Action                 items requests
---------------------  -------------
schedule:run                        0
first homepage refresh              1
second homepage refresh             1
```

线上实际响应包含 5 个商品，两个连续首页数据读取合计只请求两次 `items`，更新已对应的三个商品。验证时库存分别为 1800、592、611，属于动态快照；采购上限仍为 130、50、600 元。本轮没有发起真实采购。

正式容器中修改的 `ProcurementService.php` 已校验 SHA-256，与测试版本一致；PHP 语法检查、`git diff --check` 通过。线上首页及 `/buy/1`、`/buy/3`、`/buy/4` 均返回 HTTP 200。容器 healthy。本轮验证使用实际供应商响应、请求计数和线上 HTTP 请求，未声称进行了浏览器交互测试。

## 部署

当前镜像：`local/dujiaoka-gptplus:batch-home-20261029`。

```
sha256:42761107bd949f5be4ed5d6cb232d5ec317a7876003edf8ea74bfddda834c31b
```

发布目录：`/opt/dujiaoka-procurement/releases/20261029-batch-home`，`/opt/dujiaoka-procurement/current` 已更新。继续使用 Compose 项目 `dujiaoka-gptplus`、服务 `web`、容器 `dujiaoka_gpt_plus`，沿用持久化目录及 `SIGTERM` 停止配置。

`backup/` 保存上版 Compose、容器描述和原 `ProcurementService.php`；`verification.json` 保存本轮请求计数和商品配置检查结果。镜像中没有修改数据库结构或业务配置。

后续重建使用当前发布目录的 `compose.json`；如果恢复旧版 Compose，需要同时保留 `/order-search` 健康检查并取消旧库存调度，否则会恢复后台定时查询。

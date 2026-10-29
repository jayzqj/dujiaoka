<p align="center"><img src="https://i.loli.net/2020/04/07/nAzjDJlX7oc5qEw.png" width="400"></p>

<p align="center">
<a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/license-MIT-blue" alt="license MIT"></a>
<a href="https://github.com/assimon/dujiaoka/releases/tag/2.0.4"><img src="https://img.shields.io/badge/version-2.0.4-red" alt="version 2.0.4"></a>
<a href="https://www.php.net/releases/7_4_0.php"><img src="https://img.shields.io/badge/PHP-7.4-lightgrey" alt="php74"></a>
<a href="https://shang.qq.com/wpa/qunwpa?idkey=37b6b06f7c941dae20dcd5784088905d6461064d7f33478692f0c4215546cee0"><img src="https://img.shields.io/badge/QQ%E7%BE%A4-568679748-green" alt="QQ群：568679748"></a>
</p>

## 独角数卡

开源式站长自动化售货解决方案、高效、稳定、快速！

- 框架来自：[laravel/framework](https://github.com/laravel/laravel).
- 后台管理系统：[laravel-admin](https://laravel-admin.org/).
- 前端ui [bootstrap](https://getbootstrap.com/).

核心贡献者：
- [iLay1678](https://github.com/iLay1678)

模板贡献者：
- [Julyssn](https://github.com/Julyssn) 模板`luna`作者
- [bimoe](https://github.com/bimoe) 模板`hyper`作者

鸣谢以上开源项目及贡献者，排名不分先后.

## 系统优势

采用业界流行的`laravel`框架，安全及稳定性提升。    
支持`自定义前端模板`功能   
支持`国际化多语言包`（需自行翻译）  
代码全部开源，所有扩展包采用composer加载，代码所有内容可溯源！     
长期技术更新支持！

## 写在前面
本程序有一定的上手难度（对于小白而言），需要您对linux服务器有基本的认识和操作度   
且本程序不支持虚拟主机，大概率也不支持windows服务器！  
如果您连宝塔、phpstudy、AppNode等一键可视化服务器面板也未曾使用或听说过，那么我大概率劝您放弃本程序！  
如果您觉得部署有难度，建议仔细阅读（仔细！）宝塔视频安装篇教程，里面有保姆级的安装流程和视频教程！   
认真观看部署教程我可以保证您98%可能性能部署成功！  
勤动手，多思考，善研究！

## 使用交流      
Telegram: [https://t.me/dujiaoka](https://t.me/dujiaoka)    
关注Telegram官方频道：[https://t.me/dujiaoshuka](https://t.me/dujiaoshuka) (系统更新通知，bug更新，重大事件推送)

## 🔥推荐服务器 
- （美国免备案vps，配置2核2G仅需`20.98$`≈`145RMB`一年/支持支付宝付款）[👉🏻点我直达](https://my.racknerd.com/aff.php?aff=2745&pid=681)

## 界面尝鲜
【官方unicorn模板】
![首页.png](https://i.loli.net/2021/09/14/NZIl6s9RXbHwkmA.png)

【luna模板】 
![首页.png](https://i.loli.net/2020/10/24/ElKwJFsQy4a9fZi.png)

【hyper模板】  
![首页.png](https://i.loli.net/2021/01/06/nHCSV5PdJIzT6Gy.png)

## 安装篇
- [Linux环境安装](https://github.com/assimon/dujiaoka/wiki/linux_install)
- [Docker安装](https://github.com/assimon/dujiaoka/wiki/docker_install)
- [2.x版本宝塔安装教程](https://github.com/assimon/dujiaoka/wiki/2.x_bt_install)
- [1.x版本宝塔环境安装](https://github.com/assimon/dujiaoka/wiki/1.x_bt_install)
- [常见问题锦集-你遇到的问题大部分能在这里找到解决！！](https://github.com/assimon/dujiaoka/wiki/problems)
- [系统升级](https://github.com/assimon/dujiaoka/wiki/update)
- [各支付对应后台配置](https://github.com/assimon/dujiaoka/wiki/problems#各支付对应配置)
- [视频教程及工具集合](https://pan.dujiaoka.com)

## 支付接口已集成
- [x] 支付宝当面付
- [x] 支付宝PC支付
- [x] 支付宝手机支付
- [x] [payjs微信扫码](http://payjs.cn).
- [x] [Paysapi(支付宝/微信)](https://www.paysapi.com/).
- [x] 码支付(QQ/支付宝/微信)
- [x] 微信企业扫码支付
- [x] [Paypal支付(默认美元)](https://www.paypal.com)
- [x] V免签支付
- [x] 全网易支付支持(通用彩虹版)
- [x] [stripe](https://stripe.com/)

## 基本环境要求

- (PHP + PHPCLI) version = 7.4
- Nginx version >= 1.16
- MYSQL version >= 5.6
- Redis (高性能缓存服务)
- Supervisor (一个python编写的进程管理服务)
- Composer (PHP包管理器)
- Linux (Win下未测试，建议直接Linux)

## PHP环境要求

星号(*)为必须执行的要求，其他为建议内容

- **\*安装`fileinfo`扩展**
- **\*安装`redis`扩展**
- **\*终端需支持`php-cli`，测试`php -v`(版本必须一致)**
- **\*需要开启的函数：`putenv`，`proc_open`，`pcntl_signal`，`pcntl_alarm`**
- 安装`opcache`扩展

## 第三方缺货自动购卡（ifaka）

接口文档：[ifaka 开放接口](https://apidoc.987ai.vip/#/card)。本地卡密优先，启用补货的商品每单限购一张；没有本地卡密时，订单确认与采购记录在同一事务中保存，再由独立后台进程采购。优惠券全额抵扣等零元订单确认后也会触发采购，沿用订单快照中的成本上限。库存明确为零或查询失败也允许下单。前台只显示一次出卡状态，采购详情和失败原因保留在后台及可选企业微信通知中。

### 部署与配置

1. 备份数据库，在开放新代码流量、启动采购进程前执行 `php artisan migrate --force`。本次迁移只创建缺失的 `procurement_goods`、`procurement_orders`、`procurements`，保留 `goods`、`orders`、`carmis` 等现有表结构。也可先在 DBX 中选择目标数据库 `dujiaoka_gptplus`，执行 [仅创建采购功能表的 SQL](database/sql/create_procurements_only.sql)，再运行迁移登记版本；SQL 可重复执行，迁移会跳过已经存在的表。新站网页安装会自动运行迁移。不要通过回滚迁移处理运行故障，回滚会删除采购配置、订单映射及采购记录。
2. PHP CLI 需要 `curl`、`bcmath`、`openssl`，并确保 HTTPS 证书校验正常。数据库表使用支持事务和行锁的 InnoDB。
3. 后台「系统设置 → 第三方自动购卡」填写 App ID、App Key、平台确认的独占请求号前缀（2–4 位字母数字）及客服微信。密钥加密保存在现有系统配置缓存中，留空保留原值。保持 `APP_KEY` 与配置缓存持久化，切勿在前端或日志中暴露密钥。
4. 编辑自动发卡商品，开启「本地缺货时自动采购」，配置第三方商品 `code`、固定 `race`、SKU JSON 对象及大于 0 的单张采购成本上限（CNY）。同一规格用于库存、询价、采购；仅接入已核对为自动卡池发货、无额外控件、支持邮箱联系方式的商品。商品改动不改变已经创建订单的采购映射与成本上限。
5. 用 Supervisor 常驻运行 `php artisan procurement:work`，工作目录设为项目根目录，运行用户与 PHP-FPM 一致，开启 `autostart`、`autorestart`，`stopwaitsecs` 不低于 90 秒；更新代码后重启此进程。进程收到 TERM 后先完成当前采购再退出；数据库暂时不可用时记录日志并在 10 秒后重试，不会退出。它读取数据库采购记录，不依赖 Laravel 默认队列驱动，因此 `QUEUE_CONNECTION=sync` 也不会把采购放进支付回调。已有邮件等任务仍使用原队列配置。
6. 库存由每次首页访问触发更新，无种类或规格的商品通过一次 `/shared/commodity/items` 列表请求，按 code 同步各商品库存；有 race/SKU 的商品使用规格库存接口。并发访问合并正在进行的刷新，不再定时每分钟查询；也可手动执行 `php artisan procurement:stock`。快照超过 60 秒按库存未知处理，仅显示本地数量，仍允许购买。健康检查应访问 `/order-search`，避免自动访问首页触发库存查询。

可用 `php artisan procurement:work --once` 处理一批已付款采购记录并退出；**此命令会真实采购，不是试运行**。正常请求每两秒领取待采购记录。采购进程停止期间，已付款订单保留为正在出卡，必须恢复进程才能继续处理。

目标站点的备份、补丁应用、进程配置、联调与回滚步骤见 [自动购卡上线手册](docs/procurement-deployment.md)。

2026-10-29 已部署到目标服务器，当前镜像、进程、持久化目录和验证结果见 [部署结果与运维记录](docs/procurement-release-20261029.md)。供应商信息和商品映射在部署后的后台配置；真实采购和邮件发送需在对应配置完成后联调。

后续已按用户确认启用 GPT Plus、GPT Go、GPT Pro 5X 三个商品，采购成本上限分别为 130、50、600 元；每次访问首页只发起一次 `/shared/commodity/items` 请求，更新这三个商品，取消定时库存刷新。最新发布及验证见 [首页访问刷新库存记录](docs/procurement-home-stock-20261029.md)，商品对应关系见 [商品对应与首页库存记录](docs/procurement-catalog-20261029.md)。用户确认不配置 SMTP，网页查单和卡密展示不受邮件发送影响。

商品采购配置和库存快照保存在 `procurement_goods`；客户订单的采购配置快照、付款确认时间和采购卡密关联保存在 `procurement_orders`；供应商请求号、订单号、响应卡密及处理状态保存在 `procurements`。历史商品没有映射时默认关闭自动采购；历史订单没有映射时继续按本地发卡规则处理，首次确认付款时补建订单映射。修改商品映射不会修改已创建订单的采购快照。

### 异常与核对

- 可选企业微信失败通知：在「系统设置 → 第三方自动购卡 → 采购失败企业微信 Hook URL」填写企业微信群机器人的完整 HTTPS Webhook 地址。留空不发送，清空后停止发送，与普通订单推送独立。异常通知包含订单号、商品、实付金额、失败原因、创建/付款/异常时间及采购请求号；结果未知时提醒先核对扣款，不能重复采购。零元订单也适用同样的失败通知规则。
- 失败通知通过现有队列发送，最多尝试三次，重试间隔 30 秒；通知异常不会回滚付款或重发采购。当前未配置 Hook，未进行真实企业微信群收信联调。

- 下单请求开始前先持久化请求号和状态，支付重复通知、重复任务不会重新采购。超时、非 JSON、第三方业务错误等提交后异常统一按结果未知处理，不自动查单或重购。后台订单详情可查看请求号、第三方订单号、实际成本及异常原因。
- 第三方响应先保存，再将卡密作为已售出记录绑定当前客户订单；不会进入公共未售库存。已保存响应但本地交付事务失败时只重试本地入库；订单已在别处改为非「处理中」时不再重试，转人工核实。中断超过五分钟的采购转人工核实，不重新下单。
- `data.secret` 是交付字段。字段缺失、空白及明确等待提示不算出卡成功；首版按已确认需求不检查卡密格式。文档未提供穷尽的等待提示清单，因此新商品上线前仍需核对其实际返回内容，不能保证识别所有提示文本。
- 询价超上限时不下单；实际成交超上限但已取得卡密时正常交付，同时暂停该商品后续自动采购。本地库存仍可销售，管理员核实后关闭商品的「暂停自动采购」。
- 失败页面显示客服微信。补发、退款在线下完成，不要求更新本站状态；本站异常提示不代表线下尚未处理，核对时以客服记录为准。
- 文档中的请求号防重补丁标注为生产验收未完成。上线前需向平台确认 API 权限、IP 白名单、账户余额、独占请求号前缀及所选商品。请求号依赖持久化采购序号，迁移数据库、恢复旧备份或切换站点时不可复用已有采购请求号。

### 验证

运行 `php vendor/phpunit/phpunit/phpunit` 执行默认测试集，或添加 `--filter 'ProcurementTest|IfakaClientTest'` 仅验证购卡功能。默认测试使用内存 SQLite 和模拟供应商响应，不访问支付或采购平台。另有独立的 MySQL 集成测试，验证原始旧表兼容、建表 SQL 与迁移一致、并发支付和采购，以及跨进程恢复；本地 MySQL 8.4.6 已通过验证。启用方式与完整结果见 [购卡改造验证记录](docs/procurement-verification.md)。上线前仍需按实际配置完成真实支付和采购平台联调。

## 默认后台

- 后台路径 `/admin`
- 默认管理员账号 `admin`
- 默认管理员密码 `admin`

## 免责声明

独角数卡程序是免费开源的产品，仅用于学习交流使用！       
不可用于任何违反`中华人民共和国(含台湾省)`或`使用者所在地区`法律法规的用途。      
因为作者即本人仅完成代码的开发和开源活动`(开源即任何人都可以下载使用)`，从未参与用户的任何运营和盈利活动。    
且不知晓用户后续将`程序源代码`用于何种用途，故用户使用过程中所带来的任何法律责任即由用户自己承担。      


## Thanks

Thanks JetBrains for the free open source license

<a href="https://www.jetbrains.com/?from=gev" target="_blank">
	<img src="https://i.loli.net/2021/02/08/2aejB8rwNmQR7FG.png" width = "260" align=center />
</a>


## License

独角数卡 DJK Inc [MIT license](https://opensource.org/licenses/MIT).

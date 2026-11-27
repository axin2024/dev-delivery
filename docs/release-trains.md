# 多服务发布列车

例如，订单和开票服务同时开始读取新的账单字段。把数据库变更登记成上游服务，签署这三个服务各自经过构建的制品后，发布经理可以把它们封入同一清单。订单和开票服务需要等数据库变更完成预演，再进行自己的预演。

## 启动工作台、节点与执行进程

按 README 填好数据库配置。使用 `openssl rand -hex 32` 生成独立的 `DELIVERY_TRAIN_ADAPTER_TOKEN`，写入本地 `.env`，再运行以下命令。

```sh
docker compose --profile trains up --build --wait
docker compose exec web php bin/delivery.php train-pilot harbor preflight
docker compose exec web php bin/delivery.php train-pilot harbor backfill
docker compose exec web php bin/delivery.php train-pilot harbor enable
```

先创建示例租户 `harbor`。预检列出历史基线数量、高水位及归属冲突编号。升级时可以先在 v1.1.0 数据库的隔离副本上运行新版 CLI 的 `preflight`，随后迁移结构。每次 `backfill` 最多索引 100 条历史基线，重复调用直到 `complete` 为真。进程中断后沿用上次提交的游标。启用时再逐条对账摘要。

Compose 中节点与 Web 共享回环网络，节点状态在 `train-nodes` 卷，业务记录在 `mysql-data` 卷。直接运行 PHP 时，可在相同主机启动以下两个独立进程，Web、CLI 和节点使用相同的适配器地址及令牌。

```sh
python3 examples/train-node/server.py --port 8098 --data /var/lib/dev-delivery/nodes
php bin/delivery.php train-worker
```

参考节点存储实际制品字节并在每个环境、每个节点自己的 SQLite 表上执行声明式字段迁移。接入已有部署系统时，适配器需持久保存操作编号、前后摘要、制品和回执。查询当前版本时同时返回最后写入的操作编号，避免把其他部署产生的相同摘要当作本次结果。

## 操作顺序

1. 产品负责人为项目添加服务负责人、测试和值班发布成员。发布经理登记仓库、环境和服务。服务负责人选定上游服务，对已上传且通过摘要校验的构建制品签名。
2. 产品负责人指定环境负责人。环境负责人为预发和生产逐个授权节点，生产节点按区域登记。发布经理创建列车并封板，随后可下载带 SHA-256 的清单。替换一个组件会产生新修订并撤销原窗口。
3. 预发负责人按依赖顺序逐节点执行预演。生产负责人核对制品和探针后签核，签核有效期需要覆盖预约窗口。创建三波比例为 10、40、50 的计划时，两区域共 10 个节点将被固定为 1、4、5 个目标。
4. 为每波录入变更前后的请求总数、错误数和 UTC 测量时间。每波推进绑定观测编号、清单修订和审批编号。超阈值时，只有生产环境负责人可以按这份观测办理限时豁免。
5. 执行进程持久保存待处理目标。节点响应丢失时使用原操作编号查单，再核对节点的实际摘要和最后写入编号。看板中的执行号可以展开至调用时段和节点回执。单步演练使用 `php bin/delivery.php train-step EXECUTION_ID`。
6. 需要恢复时，先暂停列车，再由生产环境负责人准备恢复。系统收集实际写入过的节点，按组件依赖倒序列出。逐项检查旧制品与现有数据的兼容性，所有节点通过后才出现恢复操作。每个操作回读实际摘要。检查发现冲突时，修复单会保存节点和不兼容字段。

## API 与回执

使用登录会话和 `X-CSRF-Token`。调用 `POST /release-trains/{id}/waves/{wave}/advance` 时发送 `Idempotency-Key`，JSON 传入 `revision`、`observation_id` 和 `approval_id`。成功返回 202、执行号及固定节点。使用 `GET /api/projects/{project}/release-trains/{id}/executions/{execution}` 查看预期、实际摘要及适配器时间线。

适配器支持 `current`、`apply`、`result`、`inspect` 和 `restore`。测试制品示例如下。

```json
{"protocol":1,"name":"schema","release":2,"add_fields":["billing_code"],"requires":["billing_code"],"forbids":[]}
```

## 停止试点与恢复备份

`php bin/delivery.php train-pilot harbor drain` 关闭新列车及新波次受理。保留执行进程，使用 `php bin/delivery.php train-pilot harbor pending` 导出待完成、回执未知或失败的操作。处理完待办，再停止 Web、执行进程及节点写入，对 MySQL 作一致性备份，同时保存节点数据卷与密钥配置。

恢复时先在隔离副本还原两组数据。检查迁移记录、清单摘要、组件签核、节点回执和归档制品，再启动执行进程。真实环境已生效的操作应使用原操作编号与外部节点核对。回退数据库快照无法撤销外部部署。

## 验收复现

Windows 11、PHP 8.2.30、MySQL 8.4.13 和 SQLite 3.50.4 的回环实测记录如下。

| 场景 | 实测结果 | 记录 |
| --- | --- | --- |
| A1–A6 服务、窗口和预演 | 三仓库清单可校验，两台 HTTP 服务同时预约仅一方成功。缺字段的预演被阻止，单组件换版后仅该组件需要重新签核 | [用例](../tests/release_trains_http.py) |
| A7–A10 分波及质量 | 三波分别写入 1、4、5 个节点。1000 个请求中 30 个错误触发暂停，空窗口保持待观测 | [节点及波次回执](train-acceptance/wave-evidence.json) |
| A11–A12 恢复 | 第二波的第 4 台健康检查失败，系统核对并恢复实际变更的 5 台。另一组旧程序遇到不兼容字段，生成修复单，回滚写入数为零 | [恢复演练](../tests/train_recovery_http.py) |
| A13 断线恢复 | 节点先写入制品再断开响应，新进程使用相同操作号查单并读取实际摘要，节点库只记录一次部署 | [待回读看板](train-acceptance/wave-unknown.png)、[恢复后的看板](train-acceptance/wave-done.png)、[原始请求及调用顺序](train-acceptance/wave-evidence.json) |
| A14 容量 | 一名客户端预热 15 次，在 60 秒内完成 500 次观测判定，拒绝和超时均为 0，门槛计算 HTTP 延迟 P95 为 59.0 毫秒 | [500 条原始延迟及环境配置](train-acceptance/train-capacity.json) |
| v1.1.0 升级 | 保留既有交付报告、审批和测试原件。迁移重放新增 0 项，备份还原后通过历史表及附件摘要核对 | [升级及归档核对](train-acceptance/upgrade-restore.json) |
| 节点与业务库联合恢复 | 在另一个数据库恢复 MySQL，再分别恢复节点 SQLite 文件。20 条已执行目标的原始制品字节、恢复后的实际摘要、操作号和回执逐一相符 | [联合恢复记录](train-acceptance/train-restore.json) |

`tests/release_trains_http.py` 在一次性 `delivery_test_*` 库中创建合成测试数据，需要两个独立的 PHP HTTP 服务和一个独立的声明式节点。A13 使用 `--drop-first-response-on prod-00`，节点在持久化提交后主动关闭连接。测试启动新的执行进程，对照节点存储、业务执行记录和浏览器看板。A14 在 50 个已签服务和 10 个环境的项目中，以 120 毫秒的间隔采集请求并计算门槛。详细环境变量、启动命令和输出路径见 [持续集成流程](../.github/workflows/acceptance.yml)。

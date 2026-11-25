# 环境晋级、查单与回滚

## 晋级顺序

1. 为已冻结候选申请开发环境部署。发布经理选择环境并填写稳定的请求键，登记各环境当前生效的部署 ID。首次发布时登记空值。
2. 发布工具验证已下载制品的 SHA-256，提交 `running` 回执，执行部署并进行健康检查，再发送终态回执。平台把成功部署记录为该环境当前生效版本。
3. 使用成功的开发部署申请预发部署。预发环境与候选的必测计划使用同一个环境 ID。生产申请引用该制品在预发中的成功部署，并要求候选审批当前有效。
4. 生产申请待处理期间，同一环境保留一个执行位置。候选用到的标准与测试证据也暂时锁定，直到发布工具确认终态。中断后按原始请求键查单，沿用相同的运行号和事件编号进行下游对账。
5. 需要回滚时，打开生产当前成功部署。平台选择该部署记录的前一个生效版本。发布经理核对目标摘要并申请回滚，发布工具部署该制品。回滚使用独立请求键，成功后产生新的生效记录，记录中可分别打开被回滚的部署和恢复来源。

平台登记部署请求与回执。外部发布工具负责安装、切流、健康检查和流量恢复。应用内的 `unknown` 状态表示结果待核实，同一环境的执行位置会保留。若工具已实际成功，可从工具端读出原运行号及制品摘要后发送成功回执，避免重复执行。

## 请求 API

路径相对于 `/api/projects/{project_id}`。所有工作台写入提交登录会话和 `X-CSRF-Token`。

| 方法与路径 | 输入或用途 |
| --- | --- |
| `POST /deployments` | `candidate_id`、`environment_id`、`expected_head_id`、`artifact_sha256`、`request_key`、`reason`。预发和生产另填 `promotion_source_id` |
| `GET /deployments/lookup` | 按 `environment_id` 和 `request_key` 查询最近状态和环境生效版本 |
| `GET /deployments/{id}` | 查看目标摘要、执行状态、环境当前生效版本和历史关联 |
| `POST /deployments/{id}/timeout` | 原 `request_key` 和 `reason`，保留原部署号并改为待核实 |
| `POST /deployments/{id}/rollback` | `environment_id`、当前生效的 `expected_head_id`、待恢复的 `artifact_sha256`、新 `request_key`、`reason` |
| `POST /deployments/{id}/receipts` | 发布经理离线导入下节格式的 JSON 回执 |
| `GET /deployments/{id}/receipts`、`/timeline` | 查询工具回执和业务时间线 |
| `POST /environments/{id}/deployment-connector` | 发布经理读取该环境的签名端点和派生密钥 |

同一环境内的请求键唯一。使用同一键及同一参数重试会返回已有记录及当前状态。切换制品、晋级来源或回滚目标需要新请求键。`expected_head_id` 为空表示环境尚无成功部署。已上线的环境须提供当前生效的部署 ID。

## 接入部署工具

设置与 Git 集成相同的 `DELIVERY_CONNECTOR_MASTER_KEY`。发布工具只保存所属环境的派生密钥。部署请求获准且制品摘要匹配后，先提交 `running` 回执。仅在这次开始回执被接受后执行变更。执行完成后，为同一个外部运行号按递增序号发送 `succeeded`、`failed` 或 `cancelled`。

以下为合成回执示例。

```json
{
  "version": 1,
  "event_id": "dispatch-production-finished",
  "deployment_id": 17,
  "environment_id": 3,
  "request_key": "dispatch-production-12",
  "artifact_sha256": "edff3aeb1d2c714edb92ef8427aee2a1eccf145f6dbed7672aca9cb3ae6dc794",
  "external_run_id": "pipeline-226",
  "sequence": 2,
  "status": "succeeded"
}
```

使用 `application/json` 提交原始请求体，`X-Delivery-Timestamp` 使用 Unix 秒。对 `时间戳.原始请求体` 计算 HMAC-SHA256，并发送 `X-Delivery-Signature`，值为 `sha256=` 加小写十六进制摘要。发送端与平台的允许时间差为 300 秒。连接器仅能为自身环境提交回执。

发布工具应按环境和请求键去重，并在执行前持久化工具运行号。重试相同事件时让正文保持逐字节一致，可更新签名时间。重放返回该事件的原始处理结果。工具重启后，按自己的运行记录查单并恢复。旧序号的迟到事件可在回执历史查看，终态只应用一次。与已知运行号、制品摘要、环境或请求键不一致的回执会被拒收。

## 回归

```sh
python tests/deployments_http.py http://127.0.0.1:8080 http://127.0.0.1:8081
```

测试使用连接同一独立 MySQL 测试库的两套 PHP 服务，通过本地临时文件模拟外部部署工具。覆盖逐级晋级、不同环境的签名隔离、提交去重、超时查单、回执重放和恢复先前制品后的字节回读。

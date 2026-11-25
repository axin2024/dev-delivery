# 仓库、提交与流水线回执

## 工作台操作

1. 产品负责人登记仓库，填入平台和稳定的平台仓库标识。
2. 在仓库详情导入分支事件，或者配置连接器持续提交。查看仓库 ID、分支和当前提交快照 ID。
3. 工程师打开分配给自己的任务并认领，关联仓库 ID 和快照 ID。一个任务可以关联多仓库的提交。
4. 打开任务的提交链接，查看该次快照上的构建、合并请求和是否仍为当前版本。在仓库详情可查询回执日志。

## 离线导入

以下合成数据可导入平台标识为 `training/dispatch` 的 GitHub 仓库登记。平台标识以工作台登记值为准。

```json
{
  "version": 1,
  "provider": "github",
  "provider_repository_id": "training/dispatch",
  "event_id": "dispatch-fixture-001",
  "type": "ref.updated",
  "data": {
    "branch": "feature/export",
    "before_sha": null,
    "before_snapshot_id": null,
    "commit_sha": "1234567890abcdef1234567890abcdef12345678",
    "forced": false,
    "message": "Add the dispatch export contract"
  }
}
```

后续分支推进传入当前快照 ID 和提交 SHA。有新提交时生成新快照。强制更新即使回到早先用过的 SHA，也会获得新快照 ID。

| 事件 | `data` 字段 |
| --- | --- |
| `ref.updated` | `branch`、`before_sha`、`before_snapshot_id`、`commit_sha`、`forced`、`message` |
| `build.updated` | `snapshot_id`、`commit_sha`、`run_id`、`run_sequence`、`status` |
| `merge.updated` | `snapshot_id`、`commit_sha`、`merge_id`、`source_version`、`state`、`title` |

构建状态支持 `queued`、`running`、`passed`、`failed` 和 `cancelled`。同一个运行号固定到一个快照，运行序号用来选择当前快照上最新的一次运行。重试构建使用新的运行号和递增序号。合并请求状态支持 `open`、`merged` 和 `closed`，接入方应提供递增的源版本。

## 连接器

部署者在 PHP 服务端配置 `DELIVERY_CONNECTOR_MASTER_KEY`，其值为 32 字节随机数的 64 位小写十六进制表示。产品负责人在仓库详情中读取该仓库的端点和派生签名密钥。把派生密钥配置在可信的流水线或适配器的密钥存储中，并确保服务端和发送端时间同步。

适配器需将托管平台事件转换为本文格式。事件请求以原始 JSON 作为请求体，`X-Delivery-Timestamp` 为 Unix 秒，`X-Delivery-Signature` 为 `sha256=<十六进制摘要>`。摘要使用派生签名密钥的字符串值对 `时间戳.原始请求体` 计算 HMAC-SHA256，时间窗口为五分钟。

自带的 PHP 发送器可在流水线中运行。设置 `DELIVERY_CONNECTOR_ENDPOINT` 为完整 HTTPS 地址，设置 `DELIVERY_CONNECTOR_SIGNING_SECRET` 为仓库签名密钥，再执行下列命令。断线后保留原事件 ID 和文件内容重试。

```sh
php bin/send-git-event.php < event.json
```

同一仓库、同一事件 ID 和同一请求体返回已保存的回执。相同事件 ID 对应不同内容时返回冲突。旧提交的成功可在旧快照追查，分支当前构建仅从当前快照读取。

## 回归

使用两个连接到相同独立测试库的 PHP HTTP 服务，均设置同一个测试连接器主密钥。测试生成临时 Git 提交，验证同名分支跨仓库隔离、绑定到实际提交、离线导入、签名校验、事件重放及强制更新后的迟到成功回执。

```sh
python tests/git_delivery_http.py http://127.0.0.1:8080 http://127.0.0.1:8081
```

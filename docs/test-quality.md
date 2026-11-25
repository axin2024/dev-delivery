# 测试计划、JUnit 报告与缺陷复测

## 工作台操作

1. 测试人员打开已冻结的需求基线，在测试管理区创建用例并选择验收标准。每次修订生成用例新版本，历史版本及关联报告可从用例详情查看。
2. 发布经理导入成功构建的回执，登记构建 ID、制品名称及产物的 SHA-256，并登记测试环境。用于发布候选时，在制品登记后上传文件校验摘要。参见 [制品校验与发布候选](release-candidates.md)。
3. 测试人员创建测试计划，选择基线、制品、环境及必要的用例版本。一个计划中的每个用例版本都必须属于该基线中的标准版本。
4. 在计划详情上传 JUnit XML、粘贴报告或录入人工结果。填写流水线执行号和递增的执行序号。计划详情按用例展示最新结果，并区分通过、失败、跳过和未执行。
5. 打开失败结果创建缺陷。工程师提交修复后，在缺陷详情登记新构建的制品。测试人员在同一环境用新制品完成复测，再用复测结果 ID 关闭缺陷。修复登记人与复测关闭人须为不同账号。

计划保留创建时选定的用例版本。修订需求或更换制品后，创建使用新目标的计划。关闭缺陷后，原始失败和复测结果仍可从缺陷详情追溯到各自的执行证据。

## 报告 API

以下路径均相对于项目资源地址 `/api/projects/{project_id}`。写操作随登录会话提交 `X-CSRF-Token`。

| 方法与路径 | 输入或用途 |
| --- | --- |
| `POST /test-plans/{id}/reports` | `application/xml` 或 `text/xml` 原始请求体，以及下表的执行标识 |
| `POST /test-plans/{id}/manual` | JSON 字段 `source_key`、`attempt`、`build_id`、`environment_id`、`artifact_sha256`、`case_version_id`、`outcome`、`summary`，可选 `duration_ms` |
| `POST /test-plans/{id}/gate` | 传入 `baseline_id`、`artifact_id`、`environment_id`，检查指定计划的目标和各用例最新结果 |
| `GET /test-plans/{id}/reports` | 分页列出执行证据 |
| `GET /test-reports/{id}/xml` | 有原始报告访问权限的项目成员下载原始 XML |
| `GET /test-cases/{id}/timeline`、`GET /defects/{id}/timeline` | 查看用例与缺陷的审计记录 |

| 报告请求头 | 值 |
| --- | --- |
| `X-Delivery-Report-Key` | 本次执行的稳定标识 |
| `X-Delivery-Attempt` | 在计划范围内唯一的正整数序号 |
| `X-Delivery-Build-Id` | 计划绑定的构建 ID |
| `X-Delivery-Environment-Id` | 计划绑定的环境 ID |
| `X-Delivery-Artifact-Sha256` | 计划绑定制品的 64 位小写十六进制摘要 |

JUnit 用例通过 `classname` 和 `name` 匹配计划中的固定用例版本。每份报告最多 2 MiB、5,000 个用例。未知用例计入未匹配数量。报告需要至少匹配一个计划内用例，同一用例在一份报告中重复出现时整份拒收。

重试时保留相同的执行标识、序号、目标和报告原始字节。平台返回已保存的证据。相同执行标识带来不同内容、两个执行共用序号，或者构建、环境、制品摘要与计划不一致时返回 HTTP 409。

门禁按各用例最新的执行序号计算。每个用例均通过才返回 `ready: true`。迟到的旧执行保留在历史中。报告会保存摘要和原始字节，原始 XML 仅供有项目访问权的测试人员、发布经理和管理员下载。

## 回归

启动两套连接到同一个独立测试库的 PHP HTTP 服务，用测试环境的连接地址运行。

```sh
python tests/testing_http.py http://127.0.0.1:8080 http://127.0.0.1:8081
```

回归覆盖并发报告重试、目标不匹配、迟到报告、用例版本修订、原始 XML 回读及不同构建上的缺陷复测。

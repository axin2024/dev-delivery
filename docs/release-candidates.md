# 制品校验与发布候选

## 工作台操作

1. 发布经理打开成功构建的 Git 快照，登记产物的构建 ID、名称和 SHA-256，随后上传原始文件。平台读取文件字节重新计算摘要，并将匹配的文件和实际长度存入 MySQL。
2. 测试人员建立绑定此制品和冻结基线的预发测试计划。正式候选的测试计划至少覆盖基线中每条标准的一个用例版本。
3. 发布经理拟定发布候选并核对范围、仓库分支、提交快照、流水线运行号、制品摘要和必过用例。文件校验通过，且候选标准仍为当前版本时，即可冻结范围并启动测试。
4. 各必过用例的最新执行全部通过后，发布经理提交审批。项目产品负责人使用自己的账号核对范围和证据后批准，批准记录保存所有结果 ID。
5. 更换基线、制品或门禁计划时，建立后继候选。清单版本递增，前一版结束评审并撤销批准，历史清单、测试报告和审批记录仍可查看。

一份候选固定使用一份预发计划。另一制品、构建或环境的结果只能用于对应计划。更高序号的报告若替换了当前必过结果，审批需要基于新证据重新提交。标准修订后，使用新基线创建后继候选。

## 审批代办

在候选待审批且门禁有效时，项目产品负责人可以选择已经加入项目的另一名产品成员，填写原因后保存代办。候选创建人和项目业务签收人不能成为受托人。留空成员可撤销当前代办。

受托人用自己的账号核对证据并批准。委托仅覆盖该候选清单和门禁摘要。门禁证据变更后需重新提交并安排代办，候选替换也会结束旧委托。审批记录和交付报告保存实际审批人和委托人。

## 热修与正式版本并行

打开生产环境当前成功部署，使用该部署建立热修候选。记录仓库中新热修分支的第一个快照，提交 SHA 使用生产部署的提交。推送修复后，连接器再记录热修提交和构建回执。候选保存生产部署、分支起点和修复制品的证据，拒收同分支构建、基点不符及审批范围内发生过强制更新的分支。

为热修配置独立预发环境和计划，以便和正式版本同时测试、审批。生产版本发生变化后，已经拟定的热修候选需要根据新的生产基点重新建立。热修生产申请使用原基点所属环境。

## API

以下路径相对于 `/api/projects/{project_id}`。所有写入提交登录会话和 `X-CSRF-Token`。

| 方法与路径 | 输入或用途 |
| --- | --- |
| `PUT /artifacts/{id}/content` | `application/octet-stream` 请求体，最多 8 MiB，若内容摘要与制品登记不一致则返回 HTTP 422 |
| `GET /artifacts/{id}/content` | 发布经理、测试人员或工程师下载已校验的文件，下载前再次校验摘要 |
| `POST /candidates` | JSON 字段 `code`、`title`、`baseline_id`、`artifact_id`、`test_plan_id`。热修加 `track=hotfix`、`production_environment_id`、`production_base_id` |
| `GET /candidates/{id}` | 查看候选清单、门禁状态、审批是否有效及前后继关系 |
| `POST /candidates/{id}/freeze`、`/start-testing`、`/submit` | JSON 字段 `manifest_digest` |
| `POST /candidates/{id}/approve` | JSON 字段 `manifest_digest`、`gate_digest`、`note`，受托审批须提交 `delegation_id` |
| `PUT /candidates/{id}/approval-delegation` | JSON 字段 `manifest_digest`、`gate_digest`、`delegate_id`、`expected_delegation_id`、`reason`。未建委托时预期 ID 为 `null`，撤销时受托人 ID 为 `null` |
| `POST /candidates/{id}/replacements` | JSON 字段 `title`、`baseline_id`、`artifact_id`、`test_plan_id`，沿用版本编码 |
| `GET /candidates/{id}/approvals`、`/timeline` | 分页读取审批记录及状态变更 |

替换候选时，后继的创建、前一版状态更新和审批撤销在同一个事务中完成。重试提交同一份替换数据会返回既有后继。提交审批和批准时都核对候选清单摘要，批准时还核对待审批的门禁摘要。

## 回归

```sh
python tests/release_candidates_http.py http://127.0.0.1:8080 http://127.0.0.1:8081
```

测试需使用连接到同一个隔离 MySQL 测试库的两套 PHP 服务。场景包含实际 Git 提交、二进制摘要校验、错配的预发计划、其他构建通过、审批后结果变化、委托失效和并发创建后继候选。两套会话同时提交同一受托审批时只保存一次。

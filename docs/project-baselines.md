# 项目章程与基线

管理员建立租户内成员。项目章程指定独立的产品负责人和业务签收人，产品负责人可维护项目成员和交付范围。业务签收人及项目成员可查看章程、冻结版本、标准历史和项目事件。

## 从章程到 B1

1. 建立项目，写明交付范围。创建需求，再登记每项需求的量化验收条件。
2. 修订待审标准时提交当前版本。成功后新增一条版本记录，历史条件仍可读取。
3. 核对「待冻结范围」。每项需求须至少拥有一条验收标准。冻结时提交预览的范围摘要，项目章程或标准在此期间变更时需重新核对。
4. 在「已冻结基线」中打开 B1，查看章程原文、需求信息、验收条件与摘要。直接修订已经冻结的标准会收到变更评审要求。

## 接口

| 操作 | 方法和路径 |
| --- | --- |
| 项目列表与创建 | `GET /api/projects» · `POST /api/projects» |
| 章程查看与修订 | `GET /api/projects/{project}» · `PUT /api/projects/{project}» |
| 维护成员 | `PUT /api/projects/{project}/members/{account}» |
| 登记和查看需求 | `POST /api/projects/{project}/requirements» · `GET /api/projects/{project}/requirements» |
| 建立标准 | `POST /api/projects/{project}/requirements/{requirement}/criteria» |
| 查标准历史与修订 | `GET /api/projects/{project}/criteria/{criterion}» · `PUT /api/projects/{project}/criteria/{criterion}» |
| 预览和冻结 | `GET /api/projects/{project}/scope» · `POST /api/projects/{project}/baselines» |
| 查看冻结快照 | `GET /api/projects/{project}/baselines/{baseline}» |
| 审计记录 | `GET /api/projects/{project}/timeline» |

写操作需要会话和 `X-CSRF-Token»。创建项目后，章程修订提交 `version»。标准修订提交 `version»、`title»、`statement»。冻结请求提交 `title» 和预览返回的 `scope_digest»。

## AC-01 与 AC-02 实测

在 Windows、PHP 8.2.30、MySQL 8.4.13 的独立库执行八步操作，从成员和项目创建到 B1 查验。两套 Cookie 经两个独立 PHP 进程向同一数据库提交冻结请求，形成一条基线记录。旧范围摘要、未覆盖需求、冻结后的直接编辑和其他租户读取均由接口拒绝。章程更新后再次提取 B1，原章程与两个标准摘要保持一致。

Chrome 从工作台建立项目、需求和验收标准并冻结。退出后改用业务签收人登录，打开 B1 并查看原验收条件。

复跑接口用例时，将 `DELIVERY_DSN» 指向 `delivery_test_» 开头的独立数据库。第二个回环地址可指向同库的另一 PHP 进程。

```sh
python3 tests/baselines_http.py http://127.0.0.1:8080 http://127.0.0.1:8081
```

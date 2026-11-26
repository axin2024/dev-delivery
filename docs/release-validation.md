# 发行验收

## 验收结果

在隔离的 MySQL 8.4.13、PHP 8.2.30 和 Windows 环境执行。HTTP 回归使用两个 PHP 进程及共用的会话目录，在本机模拟 Git 与部署工具。生产状态由合成发布回执确认。部署测试执行真实的临时文件写入，并核对回滚后恢复的制品字节。

| 用例 | 通过结果 | 入口 |
| --- | --- | --- |
| AC-01 | 冻结基线 B1 能读取原条款和对应版本 | [基线](../tests/baselines_http.py) |
| AC-02 | 直接修改冻结标准被拒，评审后历史版仍可查 | [基线](../tests/baselines_http.py) |
| AC-03 | 修订只标记关联任务和用例，窗口内的迭代可回查 | [排期](../tests/planning_http.py) |
| AC-04 | 循环依赖阻断排期，移除环后可重新排期 | [排期](../tests/planning_http.py) |
| AC-05 | 两个实际 Git 仓的同名分支各自保留提交与审计 | [研发回执](../tests/git_delivery_http.py) |
| AC-06 | 旧提交的迟到成功回执不影响新提交的构建状态 | [研发回执](../tests/git_delivery_http.py) |
| AC-07 | 旧失败与新构建复测证据同时可读，选定用例的复测只保存一次 | [测试](../tests/testing_http.py) |
| AC-08 | 其他制品、构建或环境的通过证据无法满足当前候选门禁 | [候选](../tests/release_candidates_http.py) |
| AC-09 | 独立热修与正式候选可并行评审，校验热修生产基点 | [部署](../tests/deployments_http.py) |
| AC-10 | 后继候选终止旧审批，更新的证据须重新核验 | [候选](../tests/release_candidates_http.py) |
| AC-11 | 回滚创建新记录，生产文件恢复为上一次部署的已校验字节 | [部署](../tests/deployments_http.py) |
| AC-12 | 超时后按原请求追查，实际成功只确认一次 | [部署](../tests/deployments_http.py) |
| AC-13 | 由标准回查任务、实际提交、测试、复测、环境和受托审批身份 | [签收](../tests/signoffs_http.py) |
| AC-14 | 有未处理阻断时无法归档，具名豁免完成后报告可冻结、校验和下载 | [签收](../tests/signoffs_http.py) |
| AC-15 | 逾期原因定位到业务签收等待，移交可查前后责任人 | [运营](../tests/project_operations_http.py) |
| AC-16 | 外部账号仅可查看指定包，撤回后无法继续访问或下载 | [运营](../tests/project_operations_http.py) |

Chrome [调度报表案例](../examples/dispatch/README.md)在空库完成建项目、Git 导入、JUnit 与缺陷复测、制品上传、发布批准、生产晋级、业务签收、归档下载、里程碑移交和外部授权撤回。复测表单保留重新打开报告前的选择，逐项显示拒绝原因，并可打开原始报告。实际使用两个产品账号完成委托和审批，业务签收人在报告中读到两名操作者。

## 升级与容量

从 `v1.0.0` 的公开提交 `c8ffd67946ab723556a4a88135dea8034bc3c976` 启动工作台并运行旧版调度报表案例。升级只应用迁移 010 和 011，重复执行返回空应用列表。八张历史表校验值一致，业务报告、交付包与原始 JUnit 下载的摘要在升级前后相同。

从新版本空库运行新版案例后，用 MySQL 8.4.13 的 `mysqldump` 导出 139,891 字节 SQL 并导入另一个空库。恢复库通过 CLI 和 HTTP 健康检查。十张表校验值相同，使用原账号回读的业务报告、交付包和原始 JUnit 摘要与备份前相同。恢复后的交付报告仍记录实际受托审批人。查看[实测摘要](upgrade-restore-result.json)或[备份恢复步骤](self-hosting.md#备份恢复演练)。

Windows 本机在 MySQL 32 MiB 缓冲池、最多 32 个数据库连接及 2 个 PHP HTTP 进程下运行容量验收。四个独立会话读取同一 10,000 任务迭代，每页 50 条，交替读取首页和深分页。32 次热身后记录 200 次请求，采样窗口 5.529 秒。

| 请求 | p50 | p95 | 最大值 | 错误 |
| --- | ---: | ---: | ---: | ---: |
| 200 | 108.67 ms | 141.05 ms | 201.97 ms | 0 |

本次样本满足 p95 不超过 2 秒的目标。容量用例关注迭代视图读取，包含 HTTP 传输、PHP 访问数据库和解析返回，环境和并发度如上。

```json
{"cases":"sprint-capacity","work_items":10000,"page_size":50,"workers":4,"warmup_request_count":32,"measured_request_count":200,"measured_seconds":5.529,"p50_ms":108.67,"p95_ms":141.05,"max_ms":201.97,"errors":0,"platform":"Windows"}
```

## 复跑

准备名为 `delivery_test_*` 的独立 MySQL 数据库，配置测试用密钥并应用迁移。用同一环境启动下面两个 PHP 进程，然后执行 HTTP 回归。各场景创建独立租户，数据保留在测试库。

```sh
php -S 127.0.0.1:8080 -t public public/index.php
php -S 127.0.0.1:8081 -t public public/index.php
```

```sh
for test in baselines planning git_delivery testing release_candidates deployments capacity; do
  python3 "tests/${test}_http.py" http://127.0.0.1:8080 http://127.0.0.1:8081
done
```

第 8080 与 8081 端口的命令各用一个终端。测试进程需要相同数据库环境，PHP 会话目录需共享，且测试时使用回环 HTTP 的 Cookie 设置。

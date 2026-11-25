# 发行验收

## 验收结果

在隔离的 MySQL 8.4.13、PHP 8.2.30 和 Windows 环境执行。HTTP 回归使用两个 PHP 进程及共用的会话目录，在本机模拟 Git 与部署工具。生产状态由合成发布回执确认。部署测试执行真实的临时文件写入，并核对回滚后恢复的制品字节。

| 用例 | 通过结果 | 入口 |
| --- | --- | --- |
| AC-01、02 | 冻结基线保留数据，修改需经过变更评审 | `baselines_http.py` |
| AC-03、04 | 变更只重标关联项，循环依赖阻断排期 | `planning_http.py` |
| AC-05、06 | 同名分支分仓隔离，迟到回执不覆盖当前提交 | `git_delivery_http.py` |
| AC-07、08 | 缺陷复测保留旧证据，候选按指定制品和环境判定 | `testing_http.py`、`deployments_http.py` |
| AC-09、10 | 正式与热修候选独立，替换制品使旧批准失效 | `deployments_http.py` |
| AC-11、12 | 回滚保留继承关系，超时查单重试只确认一次 | `deployments_http.py` |
| AC-13、14 | 从标准回查实际部署证据，未处理阻断项禁止归档 | `deployments_http.py` |
| AC-15、16 | 逾期环节可追溯，外部授权限指定包且能撤回 | `deployments_http.py` |

Chrome [调度报表案例](../examples/dispatch/README.md)完成建项目、Git 导入、JUnit 与缺陷复测、制品上传、发布批准、生产晋级、业务签收、归档下载、里程碑移交和外部授权撤回。

## 升级与容量

用第 001–003 号迁移的开发基线启动测试库，应用第 004–009 号迁移。第二次迁移返回空应用列表，健康检查通过。

另用 MySQL 8.4.13 的 `mysqldump` 导出包含部署记录的验收库，导入独立空库。恢复库通过 CLI 健康检查，三份签收报告和交付包的内容 SHA-256 均与原库及存储摘要一致。复跑见[备份恢复演练](self-hosting.md#备份恢复演练)。

Windows 本机在 MySQL 32 MiB 缓冲池、最多 32 个数据库连接及 2 个 PHP HTTP 进程下运行单次容量验收。四个独立会话读取同一 10,000 任务迭代，每页 50 条，交替读取首页和深分页。32 次热身后记录 200 次请求，采样窗口 3.493 秒。

| 请求 | p50 | p95 | 最大值 | 错误 |
| --- | ---: | ---: | ---: | ---: |
| 200 | 68.12 ms | 90.39 ms | 103.40 ms | 0 |

本次样本满足 p95 不超过 2 秒的目标。容量用例关注迭代视图读取，包含 HTTP 传输、PHP 访问数据库和解析返回，环境和并发度如上。

```json
{"cases":"sprint-capacity","work_items":10000,"page_size":50,"workers":4,"warmup_request_count":32,"measured_request_count":200,"measured_seconds":3.493,"p50_ms":68.12,"p95_ms":90.39,"max_ms":103.4,"errors":0,"platform":"Windows"}
```

## 复跑

准备名为 `delivery_test_*` 的独立 MySQL 数据库，配置测试用密钥并应用迁移。用同一环境启动下面两个 PHP 进程，然后执行 HTTP 回归。各场景创建独立租户，数据保留在测试库。

```sh
php -S 127.0.0.1:8080 -t public public/index.php
php -S 127.0.0.1:8081 -t public public/index.php
```

```sh
for test in baselines planning git_delivery testing deployments capacity; do
  python3 "tests/${test}_http.py" http://127.0.0.1:8080 http://127.0.0.1:8081
done
```

第 8080 与 8081 端口的命令各用一个终端。测试进程需要相同数据库环境，PHP 会话目录需共享，且测试时使用回环 HTTP 的 Cookie 设置。

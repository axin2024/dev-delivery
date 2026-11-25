# 企业研发协作与交付管理平台｜MySQL 数据与集成

## 持久化边界

MySQL 8.4 InnoDB 是本项目业务状态与授权关系的权威存储。租户身份进入业务唯一键与关联检查。媒体、文件、备份或模型大对象允许使用文件或对象存储，库内保存版本化引用、长度、摘要及所有者。派生缓存可从持久记录重新构建。

## 逻辑实体与事实来源

| 业务域 | 核心实体与必要字段 | 写入时必须维持的规则 |
|---|---|---|
| 项目与需求基线 | project(org,key), requirement(project,key), criterion_rev(req,rev,hash) | 确定产品负责人和业务签收人，冻结范围时记录每条验收标准摘要；修订保留前后差异。 |
| 变更与迭代 | change_request(base,rev,state), sprint(team,capacity), dependency(task,predecessor) | 变更沿需求到任务与测试传播，依赖带责任人和截止时间，计划冲突列出受影响迭代。 |
| 研发协作 | work_item(sprint,req), commit_ref(repo,sha), merge_request(repo,remote_id) | 任务绑定仓库和提交身份，强制更新分支产生新快照，外部回执校验来源与提交。 |
| 测试与缺陷 | test_case(criterion_rev,rev), test_run(build,case_rev,env), defect(run,state) | 用例关联标准版本，每次执行固定构建摘要与环境；失败可生成缺陷，关闭需复测依据。 |
| 候选与制品 | candidate(project,baseline,digest), artifact(candidate,sha256), scope_item(candidate,req_rev) | 候选清单绑定提交、制品哈希、需求基线和必过测试集。热修从生产基点单建候选。 |
| 发布与回滚 | deployment(env,candidate,request_key), approval(target,actor,decision) | 开发、预发、生产逐级校验同一摘要，部署工具执行外部动作，平台记录结果未知并支持查单。 |
| 验收与交付 | acceptance(candidate,criterion_rev,signer), waiver(scope,expiry), release_report(candidate,digest) | 业务方核对标准逐项签收，未通过项须有具名豁免和期限；冻结报告反查至提交与测试结果。 |
| 项目运营 | milestone(project,due), ownership(item,from_at,actor), metric_snapshot(project,window) | 按里程碑显示阻塞与待签，区分开发耗时和等待测试，人员移交保存历史责任人。 |

## 事务、索引与对账

- **身份与约束**　制品以摘要识别，检查回执绑定提供方与运行号，签收绑定基线和标准版。
- **业务索引**　按需求版本反查用例、提交和部署；按环境及发布时刻定位可回滚版本。
- **数据生命周期**　外部验收人只见指定交付包，脱敏 CI 日志与源报告采用不同权限。

冻结候选时绑定基线、制品摘要和门禁集；回执按提供方、运行 ID、提交匹配。生产发布按环境与请求号去重。

列表使用租户、业务状态、更新时间和稳定主键分页。外部事件保留来源、外部号、内容摘要、处理结果和业务对象引用。同键不同内容产生显式冲突；网络超时后按原键查单。跨存储发布应先确认文件持久，再发布其数据库引用；回收须验证没有有效引用。

## 集成契约

导入原创补丁元数据、JUnit 报告和部署回执；导出逐条标准到测试、制品、部署和签收人的追溯矩阵。

输入需求变更、基线与理由，返回受影响的标准、任务、测试和签收项，缺关联项须显式标记。

发布请求固定候选、制品与目标环境。返回部署号及查单入口；回滚关联上一次获准且实际可用的制品。

## 数据恢复与核验

在隔离环境恢复 MySQL 和引用清单，校验样例的源对象、派生关系及最终结果。恢复后应能回答以下问题。R1 保留旧证据，C2 的测试需在 H2 上重新通过；C1 可引用有效需求证据，涉及构建行为的门禁仍须满足 H2 的要求。

[MySQL 事务模型](https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-model.html)

[产品](01-产品总览-prd.md) · [功能](02-业务与交互-prd.md) · [MySQL](03-MySQL与集成-prd.md) · [验收](04-验收与运营-prd.md) · [开源](05-开源与发布-prd.md) · [状态与操作](06-状态与操作契约-prd.md) · [公开案例](07-开源项目说明与使用案例-prd.md)

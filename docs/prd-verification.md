# PRD 功能与验收索引

本页对应 [业务与交互 PRD](requirements/02-业务与交互-prd.md) 的 24 项能力。编号用例的结果、测试环境和复跑入口见[发行验收](release-validation.md)。

| 编号 | 能力 | 寻址入口 | 运行验收 |
| --- | --- | --- | --- |
| F01 | 项目章程 | [项目与成员](../src/Projects.php) | AC-01 |
| F02 | 需求拆解 | [需求与基线](../src/Baselines.php) | AC-01 |
| F03 | 验收标准版本 | [冻结及修订依据](../src/Baselines.php) | AC-01、02 |
| F04 | 变更影响分析 | [变更评审](../src/Changes.php) | AC-03、13 |
| F05 | 迭代容量 | [迭代与排期](../src/Planning.php) | AC-04、容量测试 |
| F06 | 跨团队依赖 | [依赖校验与受影响迭代](../src/Planning.php) | AC-03、04 |
| F07 | 任务认领 | [任务分配和状态](../src/Planning.php) | AC-05、06 |
| F08 | 分支与提交关联 | [仓库与任务关联](../src/GitDelivery.php) | AC-05、06 |
| F09 | 合并请求回执 | [Git 和构建事件](../src/GitEvents.php) | AC-05、06 |
| F10 | 用例设计 | [测试用例版本](../src/TestCases.php) | AC-03、07 |
| F11 | 测试计划 | [计划](../src/TestPlans.php)和[测试结果](../src/TestEvidence.php) | AC-07、08、JUnit 按项复测 |
| F12 | 缺陷复测 | [缺陷与复测证据](../src/Defects.php) | AC-07 |
| F13 | 版本范围冻结 | [候选清单](../src/ReleaseCandidates.php) | AC-08、10 |
| F14 | 制品登记 | [构建与制品校验](../src/BuildTargets.php) | AC-08、11 |
| F15 | 多分支并行 | [热修生产基点](../src/HotfixBase.php) | AC-09 |
| F16 | 环境晋级 | [部署与查单](../src/DeploymentQueue.php) | AC-11、12 |
| F17 | 分权审批 | [发布候选与审批代办](../src/ReleaseCandidates.php) | AC-08、10、13 |
| F18 | 紧急修复回滚 | [回滚申请](../src/DeploymentQueue.php)和[部署回执](../src/DeploymentReceipts.php) | AC-09、11、12 |
| F19 | 业务签收 | [逐条签收与豁免](../src/BusinessSignoff.php) | AC-13、14 |
| F20 | 交付报告 | [归档入口](../src/BusinessSignoff.php)和[证据快照](../src/SignoffTrace.php) | AC-13、14 |
| F21 | 追溯矩阵 | [从标准回查原证据](../src/SignoffTrace.php) | AC-13、14 |
| F22 | 版本风险看板 | [里程碑与产线状态](../src/Milestones.php) | AC-15 |
| F23 | 周期与等待指标 | [阶段周期和快照](../src/Milestones.php) | AC-15 |
| F24 | 权限交接 | [移交](../src/OwnershipTransfers.php)和[交付包授权](../src/ReviewPackages.php) | AC-15、16 |

[状态与操作契约](requirements/06-状态与操作契约-prd.md)要求的权限围栏、签名与离线回执、查单复核、去重、原报告回查与拒绝项反馈均由独立 MySQL 测试覆盖。工作台流程可按[调度报表案例](../examples/dispatch/README.md)重放。

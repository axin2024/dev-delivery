# 企业研发协作与交付管理平台

Dev Delivery 管理从需求基线到生产发布的交付证据。系统使用 PHP 和 MySQL 8.4。当前可运行项目章程、需求基线、变更评审、迭代排期、跨团队依赖、任务认领、Git 构建回执、测试计划、缺陷复测、发布候选审批、环境晋级和部署回滚。

## 运行

准备 PHP 8.2 以上版本及 PDO MySQL、mbstring、XMLReader 扩展。在 MySQL 8.4 中创建空库，通过 [.env.example](.env.example) 中的变量配置 Web 和 CLI 进程。仅在回环地址 HTTP 演示时使用 `DELIVERY_COOKIE_SECURE=0`。

```sh
php bin/delivery.php migrate
php bin/delivery.php health
php bin/delivery.php create-tenant harbor "港湾交付团队" admin@example.test "工作台管理员"
php -S 127.0.0.1:8080 -t public public/index.php
```

初始化命令从标准输入读取新管理员密码。启动后打开本机 8080 端口，登录并创建产品经理、业务签收人、工程成员、测试人员和发布经理。产品负责人或管理员可以创建需求、登记验收标准并冻结已核对的范围。

## 文档

- [产品总览](docs/requirements/01-产品总览-prd.md)
- [业务功能与交互](docs/requirements/02-业务与交互-prd.md)
- [MySQL 数据与集成](docs/requirements/03-MySQL与集成-prd.md)
- [验收与运营](docs/requirements/04-验收与运营-prd.md)
- [开源与发布](docs/requirements/05-开源与发布-prd.md)
- [状态与操作契约](docs/requirements/06-状态与操作契约-prd.md)
- [使用案例](docs/requirements/07-开源项目说明与使用案例-prd.md)
- [基线操作和验收](docs/project-baselines.md)
- [变更评审和迭代排期](docs/change-and-sprint-planning.md)
- [仓库、提交与流水线回执](docs/git-delivery.md)
- [测试计划、JUnit 报告与缺陷复测](docs/test-quality.md)
- [制品校验与发布候选](docs/release-candidates.md)
- [环境晋级、查单与回滚](docs/environment-deployments.md)

许可见 [Apache License 2.0](LICENSE)。

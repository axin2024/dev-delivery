# 企业研发协作与交付管理平台

Dev Delivery 是基于 PHP 和 MySQL 的自托管研发交付平台。它串联需求基线与变更、任务排期、Git 提交与构建、测试复测、制品发布、生产回滚和业务签收。团队可以按里程碑查看逾期环节与交付周期，从冻结报告回查原始证据，并向外部验收人授权指定交付包。

## 快速开始

使用 Docker Compose，从空库开始运行工作台。先复制配置模板，设置独立的数据库密码和 64 位小写十六进制连接器主密钥。本机 HTTP 演示使用 `DELIVERY_COOKIE_SECURE=0`。

```sh
cp .env.example .env
# 编辑 .env，填写密码、主密钥和本机演示的 Cookie 设置
docker compose up --build --wait
docker compose exec web php bin/delivery.php create-tenant harbor "港湾交付团队" admin@example.test "工作台管理员"
```

打开 `http://127.0.0.1:8080/`。使用租户代码 `harbor` 和新建管理员登录。创建产品负责人和业务签收人后即可登记项目和冻结范围。可以按[调度报表交付案例](examples/dispatch/README.md)生成一份有构建、复测、生产确认和签收记录的报告。

### 直接运行 PHP

准备 PHP 8.2 以上版本及 PDO MySQL、mbstring、XMLReader 扩展。在 MySQL 8.4 中创建空库，将 [.env.example](.env.example) 中的值导出到 Web 和 CLI 进程的环境。PHP 进程通过环境读取配置。内置服务器用于回环地址演示。

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
- [业务签收、具名豁免和交付报告](docs/business-signoff.md)
- [里程碑、移交与外部交付包](docs/project-operations.md)
- [自托管配置、升级与恢复](docs/self-hosting.md)
- [验收结果和复跑命令](docs/release-validation.md)
- [贡献指南](CONTRIBUTING.md) · [安全问题反馈](SECURITY.md) · [第三方声明](THIRD_PARTY_NOTICES.md)

许可见 [Apache License 2.0](LICENSE)。

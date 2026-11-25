# 自托管与恢复

## 运行时配置

| 环境变量 | 用途 |
| --- | --- |
| `DELIVERY_DSN` | 直接运行时填写 PDO MySQL DSN。Compose 使用数据库服务名、`DELIVERY_DB_NAME` 与 UTF-8 字符集组装 |
| `DELIVERY_DB_USER`、`DELIVERY_DB_PASSWORD` | 应用数据库账号及密码。应用用户的授权范围限当前数据库 |
| `DELIVERY_DB_NAME` | Compose 的数据库名称，默认 `dev_delivery` |
| `DELIVERY_COOKIE_SECURE` | 默认 `1`。只在隔离的本机 HTTP 演示中设为 `0` |
| `DELIVERY_CONNECTOR_MASTER_KEY` | 安装时生成的 32 随机字节，以 64 位小写十六进制配置。如用 OpenSSL，执行 `openssl rand -hex 32` |
| `DELIVERY_HTTP_PORT` | Compose 监听的回环端口，默认 8080 |

HTTP 健康端点为 `GET /healthz`，数据库或迁移未就绪时返回 503。Compose 的 MySQL 无宿主机端口，应用默认监听本机回环地址。通过 HTTPS 反向代理提供访问，文档根目录指向 `public/`，未知路径交给 `public/index.php`。上传上限需至少容纳 8 MiB 制品及 HTTP 请求开销。Apache 运行日志可用 `docker compose logs web` 查看。

主密钥用于派生 Git 和发布连接器的签名密钥。主密钥保存在部署凭据中并单独备份。轮换时暂停事件发送、更新消费者的派生密钥，再恢复新事件投递。应用会话存放于 PHP 会话目录，增加 Web 实例时需配置共享会话存储。部署和 Git 调用方仍通过各自稳定的请求标识对账。

## 升级和回退

先暂停写入和连接器投递，并保留当前镜像、数据库备份、环境文件与连接器主密钥。在副本上运行新版本 `php bin/delivery.php migrate`，用 CLI 和 HTTP 健康检查确认就绪，再检查历史基线、发布制品及签收归档。

正式切换使用相同顺序。Compose 可在停写后用 `docker compose up --build --wait --force-recreate migrate web` 重建迁移和应用服务。失败时用旧版本及升级前快照恢复，应用启动后按原请求键与发布工具核对停机窗口里的工作。MySQL DDL 可能分段提交，迁移前准备可以验证的恢复点。

## 备份恢复演练

备份应用数据库和连接器主密钥。制品字节、测试原文、报告以及外部交付包都存于 MySQL。以下命令在数据库可达的终端使用配置好的 `delivery-backup` 和 `delivery-restore` MySQL 登录配置，后者连接独立的恢复实例。恢复目标为提前建立的空库。

```sh
mysqldump --login-path=delivery-backup --single-transaction --no-tablespaces \
  --set-gtid-purged=OFF --column-statistics=0 dev_delivery > dev-delivery.sql
mysql --login-path=delivery-restore dev_delivery_recovery < dev-delivery.sql
```

把隔离环境指向恢复库后运行 `php bin/delivery.php health`。登录核对已有的项目、角色与环境，在同一报告上对比下载 SHA-256。也可以按报告主键和交付包 ID 比较保存值与 MySQL 的实时摘要。

```sql
SELECT tenant_id, project_id, deployment_id, digest, SHA2(content, 256)
FROM delivery_reports ORDER BY tenant_id, project_id, deployment_id;
SELECT id, digest, SHA2(content, 256)
FROM review_packages ORDER BY id;
```

## 支持范围

首个公开版本支持 PHP 8.2–8.4、MySQL 8.4 和工作台当前 JSON 接口。连接器接收版本 1 的规范化事件。Git 服务的原生事件先由部署者的工具转换。外部发布器负责制品安装、健康检查和流量切换。工作台管理申请、发布门禁、回执及结果对账。报告和上传制品单个上限为 8 MiB，单次 JUnit 原始 XML 上限见[测试接入](test-quality.md)。

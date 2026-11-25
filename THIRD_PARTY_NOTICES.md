# 第三方声明

项目代码、文档及调度报表示例按仓库 [Apache License 2.0](LICENSE) 发布。演示租户、CSV 内容、构建标识、JUnit 结果及部署回执均为项目合成素材。工作台使用系统字体，可由宿主机提供。

| 组件 | 用途 | 许可或源码 |
| --- | --- | --- |
| PHP 及 PDO MySQL、mbstring、XMLReader | 应用运行时 | [PHP 源码与许可](https://github.com/php/php-src/blob/master/LICENSE) |
| MySQL Community Server | 应用数据库 | [MySQL 源码与许可](https://github.com/mysql/mysql-server/blob/trunk/LICENSE) |
| Apache HTTP Server | Compose 中的 Web 服务 | [Apache httpd 许可证](https://github.com/apache/httpd/blob/trunk/LICENSE) |
| Playwright CLI | 可选浏览器案例 | [Playwright CLI 许可证](https://github.com/microsoft/playwright-cli/blob/main/LICENSE) |

运行时容器的系统包由其基础镜像提供。应用的 PHP 扩展要求见 [composer.json](composer.json)，容器镜像来源见 [Dockerfile](Dockerfile) 与 [compose.yaml](compose.yaml)。

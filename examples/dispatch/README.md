# 调度报表交付案例

演示需求为按日期导出调度记录。初次冻结标准要求中文列头，后续变更把导出日期纳入验收范围。案例使用两个合成构建，演示保留报告的拒绝行、勾选用例复测、负责人委托生产审批，以及业务签收后冻结报告。浏览器脚本在本机工作台导入回放记录，部署回执标记使用合成流水线。

## 从空库运行

先按 [README](../../README.md) 启动独立的本机回环服务，使用演示 Cookie 设置。在独立演示实例运行下列命令，以固定夹具密码创建新租户。已有同名演示租户时，可在一套新的 Compose 项目和数据卷中复跑。

```sh
printf '%s\n' 'Local-Only-Demo-517' |
  docker compose exec -T web php bin/delivery.php create-tenant browser-fixture '交付回放' admin@example.test '演示管理员'
npx --yes --package @playwright/cli@0.1.25 playwright-cli -s=delivery-demo open http://127.0.0.1:8080 --browser=chrome
npx --yes --package @playwright/cli@0.1.25 playwright-cli -s=delivery-demo run-code --filename=examples/dispatch/browser-scenario.js
npx --yes --package @playwright/cli@0.1.25 playwright-cli -s=delivery-demo close
```

脚本需要 Node.js 20 以上和本机 Chrome。使用直接运行的 PHP 服务时，把 `docker compose exec -T web php` 换成 `php`，并先配置数据库环境。脚本应返回 `BROWSER_ACCEPTANCE_PASSED`。

## 核对结果

用 `product@example.test` 和夹具密码登录 `browser-fixture`。打开「调度报表改造」，查看已归档报告和「里程碑运营」。报告可以回查基线版本、测试结论、已关闭缺陷与部署回执。使用同一租户的 `external_reviewer@example.test` 登录，可查看授权撤回后的外部工作台。

验收程序模拟发布工具已安装制品、响应超时、按原请求键查单后确认一次。运行 [部署与回滚测试](../../docs/environment-deployments.md#回归) 可以检查本地制品字节和终态记录。

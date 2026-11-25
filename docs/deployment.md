# 自托管部署与升级

## Docker Compose

1. 复制 `.env.example» 为 `.env»。分别设置 `LEARNING_DB_PASSWORD» 和 `LEARNING_MYSQL_ROOT_PASSWORD»。演示场景同时设置 `LEARNING_DB_NAME=learning_demo_harbor» 和 `LEARNING_COOKIE_SECURE=0»。
2. 在仓库根目录执行以下命令。MySQL 使用命名卷。一次性迁移退出成功后启动 Web 与后台处理进程。

```sh
docker compose build migrate
docker compose up -d
docker compose ps -a
curl --fail http://127.0.0.1:8080/healthz
```

默认端口仅绑定宿主机回环地址。正式部署在宿主机使用 TLS 反向代理，将请求转发至该端口，并保持 `LEARNING_COOKIE_SECURE=1»。通过 `docker compose logs web migrate worker» 检查站点、迁移和轮询日志。数据库凭据及卷的管理方式见 [配置参考](configuration.md)。

正式环境在仓库根目录执行以下命令创建管理员，密码从标准输入读取。命令在交互终端提示时会显示键入内容，支持通过密码管理器或脚本写入标准输入。

```sh
docker compose exec -T web php bin/learning.php create-tenant harbor "港湾培训中心" admin@example.test "空间管理员"
```

演示环境由 [空库教程](demo.md) 创建三个样例账号和业务数据。

## 原生 PHP

安装 PHP 8.2 或更新的 PHP 8.x、PDO MySQL、mbstring 和 MySQL 8.4。创建使用 `utf8mb4» 的独立数据库，将 [配置参考](configuration.md) 中的环境变量注入 Web 与 CLI。Compose 会读取 `.env»，原生入口通过进程环境取值。

从仓库根目录运行 `php bin/learning.php migrate» 和 `php bin/learning.php health»。Web 根目录指向 `public/»，现有静态资源直接提供，其余请求交给 `public/index.php»。Apache 配置可参考 [站点样例](../deploy/apache-site.conf)。开发时可使用 README 的本地服务命令。

使用服务管理器运行 [后台脚本](../deploy/sweep.sh)，工作目录设为仓库根目录。也可以由调度器每分钟调用 `php bin/learning.php exam-sweep» 和 `php bin/learning.php credential-sweep»。两命令的可选批次上限为 1–1000，默认 100。积压时从日志检查每批处理数，按实际负载调整频率。`/healthz» 验证 Web 与数据库迁移连通性，后台进程状态通过服务管理器和日志检查。

## 升级

记录当前标签和配置，停止入口流量及后台处理，生成 [备份](backup-and-restore.md)，在独立库演练恢复。切换到目标标签并检查变更记录，再使用目标版本迁移。

```sh
docker compose stop web worker
docker compose build migrate
docker compose run --rm migrate
docker compose up -d --force-recreate web worker
curl --fail http://127.0.0.1:8080/healthz
```

复查已有课程、班期、历史成绩与证书，再恢复外部流量。迁移按编号记录并校验文件摘要。回退时先恢复到独立数据库、用旧版本验证，再切换流量与后台进程。

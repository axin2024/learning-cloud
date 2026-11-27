# 配置参考

| 变量 | 使用方式 |
| --- | --- |
| `LEARNING_DSN` | 原生部署必填。格式 `mysql:host=127.0.0.1;port=3306;dbname=learning_cloud;charset=utf8mb4`。Compose 使用 `db` 服务和 `LEARNING_DB_NAME` 构造。 |
| `LEARNING_DB_USER` | 原生部署必填。Compose 默认 `learning_cloud`。 |
| `LEARNING_DB_PASSWORD` | 应用数据库密码。Web、迁移与后台服务均需读取。 |
| `LEARNING_COOKIE_SECURE` | 默认 `1`，通过 HTTPS 使用工作台。本机 HTTP 演示设置为 `0`。 |
| `LEARNING_VERIFY_PERSISTENT` | 默认按 PHP 工作进程复用一个独立的公开查验数据库连接。设为 `0` 后每次查验重新连接。请在数据库连接预算中预留工作进程所需连接。 |
| `LEARNING_DB_NAME` | Compose 初始化的数据库名，默认 `learning_cloud`。 |
| `LEARNING_MYSQL_ROOT_PASSWORD` | Compose 首次初始化数据库的管理密码。 |
| `LEARNING_HTTP_PORT` | Compose 发布的回环端口，默认 `8080`。 |
| `LEARNING_SWEEP_INTERVAL` | 后台脚本两轮之间等待的秒数，默认 `30`。 |
| `LEARNING_ATTENDANCE_TOKEN` | 出勤回放命令使用的供应商令牌，在工作台登记或轮换供应商时取得。 |

数据库连接时区为 UTC，考试与作业 API 使用 UTC 时间。日期报表按 UTC 自然日计算。将运行权限限制为目标库的读写权限。生产环境可单独注入具有目标库 DDL 权限的迁移账号，保持 Web 与后台进程使用运行账号。Compose 开箱配置使用初始化阶段创建的单库账号，数据库服务保存在 `mysql-data` 卷中。已有卷上的账号密码需在 MySQL 中执行轮换，再同步更新服务环境。

请将数据库配置与供应商凭据保存到部署环境的保密存储。直播供应商需映射出勤事件 ID、学员角色、平台会话号和秒数区间。操作步骤与重试契约见 [直播出勤](study-progress.md)。

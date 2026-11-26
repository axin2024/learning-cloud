# 港湾培训中心演示

示例从独立的 MySQL 空库建立「运行交接基础」和「异常交接实训」课程、两个班期和一条进阶路径。港湾设备公司的部门管理员领取一份运行部结业报表。课程文字、企业、账号和直播出勤事件均为虚构；工作台将演示供应商标为「本地出勤回放」。

## 从空库开始

需要 Python 3.9 以上版本。创建名称以 `learning_demo_` 开头的独立数据库，按 [自托管文档](deployment.md) 完成迁移并启动工作台。Compose 演示应使用新的 Compose 项目和卷，例如设置 `COMPOSE_PROJECT_NAME=learning-harbor-demo`。请在演示环境的 HTTP 回环地址使用 `LEARNING_COOKIE_SECURE=0`。

Compose 启动后从仓库根目录运行以下命令，按提示输入只用于该演示的密码。

```sh
python3 examples/harbor_demo.py http://127.0.0.1:8080 --compose
```

原生部署按 README 启动 PHP 服务，在另一终端加载相同数据库环境变量，再运行 `python3 examples/harbor_demo.py http://127.0.0.1:8080`。Windows 如需显式加载 PDO MySQL，可追加 `--php-arg=-d --php-arg=extension=pdo_mysql`。每次执行生成新的演示租户。

## 核对结果

1. 复制命令输出中的 `tenant`。打开工作台，用学员 `operator@example.test` 登录，检查已结业班期。直播出勤累计 600 秒，作业 90 分，考试 100 分。脚本重复发送同一出勤事件和同一次交卷，校验结果保持一致。
2. 查看学习路径。基础课完成后，「异常实训班」所在的第二步开放。
3. 打开 `/verify`，输入命令输出中的 `certificate`，核对证书有效状态、课程和签发方。公开核验只显示证书字段。
4. 用部门管理员 `manager@example.test` 登录同一租户，在企业下选择港湾设备公司，打开部门报表。含当天 UTC 日期的统计区间内，运行部应显示一条有效报名、一名学员和一项结业。教务 `admin@example.test` 可查看课程、两个班期、作业、考试与签发记录。

接入其他出勤样例时，可把测试事件写成逐行 JSON，用 [出勤回放命令](../bin/replay-attendance.php) 重跑。字段、收到的回执和进入人工复核的条件见 [学习进度与直播出勤](study-progress.md)。

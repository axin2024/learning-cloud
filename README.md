# 职业教育与企业培训平台

Learning Cloud 面向职业教育机构和企业培训部门，使用 PHP 和 MySQL 8.4。课程版本绑定班期，班期统一管理报名、席位、候补和换班。企业按合同采购课程席位，企业管理员按部门分配，学员激活后获得对应班期的报名资格。学员可继续阅读或播放录播，直播出勤可由已绑定的平台回传。班期作业支持按评分项给分、行内批注和授权重交。考试从发布的题库组卷，支持答题续接、到时收卷和独立补考场次。教务按学时、必修作业和考试成绩审核结业，签发可匿名查验的证书，证书到期后进入复训流程。

## 运行与演示

- [自托管部署、健康检查与升级](docs/deployment.md)
- [配置参考](docs/configuration.md)
- [从空库到一张可查验证书的演示](docs/demo.md)
- [备份与恢复](docs/backup-and-restore.md)

### 本机开发

准备 PHP 8.2 或更高版本、PDO MySQL 和 mbstring，在 MySQL 8.4 建立空库和专用数据库账号。设置 [.env.example](.env.example) 中的环境变量。仅在本机 HTTP 上演练时将 `LEARNING_COOKIE_SECURE` 设为 `0`。

```sh
php bin/learning.php migrate
php bin/learning.php health
php bin/learning.php create-tenant harbor "港湾培训中心" admin@example.test "空间管理员"
php -S 127.0.0.1:8080 -t public public/index.php
```

初始化命令从标准输入读取密码。交互终端会在收到回车前显示输入内容，自动化可通过标准输入传入密码。打开 `http://127.0.0.1:8080/`，用 `harbor` 和刚创建的管理员账号登录。在「成员」中创建学员，在「课程」中创建和发布课程修订，再打开「班期」创建席位。

详细操作、HTTP 接口与可重跑验收见 [课程与招生](docs/admissions.md)、[企业采购与席位](docs/enterprise-seats.md)、[学习进度与直播出勤](docs/study-progress.md)、[作业与辅导](docs/coursework.md)、[题库与考试](docs/exams.md)、[证书与复训](docs/credentials.md) 和 [学习路径与报表](docs/paths-and-reports.md)。

## 产品需求

- [产品总览](docs/requirements/01-产品总览-prd.md)
- [业务功能与交互](docs/requirements/02-业务与交互-prd.md)
- [MySQL 数据与集成](docs/requirements/03-MySQL与集成-prd.md)
- [验收与运营](docs/requirements/04-验收与运营-prd.md)
- [开源与发布](docs/requirements/05-开源与发布-prd.md)
- [状态与操作契约](docs/requirements/06-状态与操作契约-prd.md)
- [开源项目说明与使用案例](docs/requirements/07-开源项目说明与使用案例-prd.md)

## 功能与验收

项目覆盖租户与课程、班期与招生、企业购课、学习过程、作业与辅导、题库与考试、证书与复训、学习路径和经营报表。业务入口、实测结果和重跑方式见 [需求索引](docs/prd-verification.md) 和 [验收记录](docs/acceptance.md)。

课程以文本、外部 HTTPS 录播地址和直播出勤回调交付。直播适配由部署者把供应商事件映射到平台回调契约。企业席位以合同台账登记，付款及发票由外部系统处理。当前 Compose 配置使用单实例 Web 和本地 PHP 会话存储。

贡献、支持版本和安全反馈见 [贡献指南](CONTRIBUTING.md)、[变更记录](CHANGELOG.md) 与 [安全政策](SECURITY.md)。

## 许可

项目采用 [Apache License 2.0](LICENSE)。示例权利和第三方组件见 [素材与依赖](docs/asset-rights.md) 与 [NOTICE](NOTICE)。

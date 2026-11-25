# 职业教育与企业培训平台

Learning Cloud 是一个面向职业教育机构和企业培训部门的自托管平台，使用 PHP 和 MySQL 8.4。课程发布、班期招生、企业席位、学习认定、作业、考试、证书与运营报告围绕同一份学习资格运行。

## 产品需求

- [产品总览](docs/requirements/01-产品总览-prd.md)
- [业务功能与交互](docs/requirements/02-业务与交互-prd.md)
- [MySQL 数据与集成](docs/requirements/03-MySQL与集成-prd.md)
- [验收与运营](docs/requirements/04-验收与运营-prd.md)
- [开源与发布](docs/requirements/05-开源与发布-prd.md)
- [状态与操作契约](docs/requirements/06-状态与操作契约-prd.md)
- [开源项目说明与使用案例](docs/requirements/07-开源项目说明与使用案例-prd.md)

## 开发进度

当前仓库记录产品需求基线。第一阶段实现租户、课程发布版本、班期和报名。验收从两件事开始。已开班的学员可以继续使用绑定的课程版本。班期满员时维护候补顺序，退班释放的席位和首位候补的递补在同一事务完成。

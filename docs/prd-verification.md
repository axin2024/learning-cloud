# Learning Cloud 需求索引

功能编号来自[业务与交互 PRD](requirements/02-业务与交互-prd.md)。编号验收结果和重跑命令见[验收记录](acceptance.md)。

| 功能 | 实现入口 | 验收 |
| --- | --- | --- |
| F01 企业学习空间 | [空间与成员](../src/Accounts.php) | AC-01、AC-02 的空间隔离 |
| F02 课程章节制作 | [课程目录](../src/Catalog.php) | AC-01、AC-02 |
| F03 发布修订 | [课程版本和素材授权](../src/Catalog.php) | AC-01、AC-02 |
| F04 班期容量 | [班期与容量](../src/Admissions.php) | AC-03、AC-04、并发抢位 |
| F05 个人报名 | [报名和请求回执](../src/Admissions.php) | AC-03、并发抢位 |
| F06 候补及换班 | [递补及换班](../src/Admissions.php) · [课程等价关系](../src/CourseEquivalences.php) | AC-03、AC-04 |
| F07 购买名额 | [企业合同席位](../src/SeatPurchases.php) | AC-05、AC-06 |
| F08 部门分配 | [企业及部门名单](../src/Companies.php) · [逐行分配和重试](enterprise-seats.md) | AC-05、批次重试 |
| F09 回收未用席位 | [席位回收与激活](../src/SeatPurchases.php) | AC-06 |
| F10 直播出勤 | [出勤回调与复核](../src/LiveAttendance.php) | AC-08 |
| F11 录播进度 | [学习会话和区间](../src/StudyProgress.php) | AC-07 |
| F12 学时认定 | [区间合并和课规](../src/StudyProgress.php) | AC-07、AC-08 |
| F13 提交版本 | [作业提交历史](../src/Coursework.php) | AC-09、AC-10 |
| F14 批注评分 | [评分项和批注](../src/Coursework.php) | AC-09 |
| F15 退回重交 | [重交授权及截止](../src/Coursework.php) | AC-09、AC-10 |
| F16 试卷版本 | [题库草稿和发布](../src/ExamCatalog.php) | AC-11、冻结试卷 |
| F17 随机组卷 | [按答卷定格抽题](../src/Exams.php) | AC-11、AC-12 |
| F18 补考与阅卷 | [成绩采用与阅卷指派](exams.md) | AC-11、AC-12、指派和并发阅卷 |
| F19 学业审核 | [签发条件与并发唯一键](../src/Credentials.php) | AC-13 |
| F20 证书验真 | [最小公开属性及撤销](../src/Credentials.php) | AC-13 |
| F21 到期与复训 | [到期扫描和换证](../src/Retraining.php) | AC-14 |
| F22 部门完成率 | [企业报表范围](../src/Reports.php) | AC-15、AC-16 |
| F23 课程经营分析 | [班期报表与时点](../src/Reports.php) | AC-15、AC-16 |
| F24 学习路径 | [路径和先修条件](../src/LearningPaths.php) | AC-15 |

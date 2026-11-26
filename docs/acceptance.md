# 验收记录

2026 年 11 月 26 日复跑 1.1.0。运行环境为 Windows、PHP 8.2.30 和 MySQL 8.4.13。验收在独立数据库中进行，账号、课程、企业和直播回放事件均为合成数据。需求定义和代码入口见 [验收与运营](requirements/04-验收与运营-prd.md) 和 [需求索引](prd-verification.md)。

| 用例 | 实测结果 | 重跑入口 |
| --- | --- | --- |
| AC-01 | 新班看新发布版本，旧班学员可读取原版章节。 | [课程与招生](../tests/admission_http.py) |
| AC-02 | 未核实授权的章节阻止课程发布。 | [课程与招生](../tests/admission_http.py) |
| AC-03 | 满员退班后按候补加入顺序递补。 | [课程与招生](../tests/admission_http.py) |
| AC-04 | 换班目标满员后原报名和 85 秒历史学时仍有效。配置映射后只结转覆盖的 30 秒。 | [换班](../tests/transfers_http.py) |
| AC-05 | 100 席按 40、35、25 分配。释放名额后只重试选中的一行，原回执和其他行保持不变。 | [企业席位](../tests/enterprise_seats.py) |
| AC-06 | 只能回收未激活席位。学员退班后仍保留原已激活采购记录。 | [企业席位](../tests/enterprise_seats.py) |
| AC-07 | 重复上报同一段 600 秒学习记录，仍只记 600 秒。 | [学习进度](../tests/study_http.py) |
| AC-08 | 缺少会话号的出勤进入复核，绑定后计算有效区间。 | [学习进度](../tests/study_http.py) |
| AC-09 | 重交后保留两个提交版本和各自评分。 | [作业评分](../tests/coursework_http.py) |
| AC-10 | 截止后需要有时限的一次性授权才能重交。 | [作业评分](../tests/coursework_http.py) |
| AC-11 | 首考 55、补考 78。按课程版本策略采用相应场次成绩，保留原卷和评分。 | [考试与补考](../tests/exams_http.py) |
| AC-12 | 六连接并发交卷只保存一条提交事件。六连接并发代办阅卷也只保存一条成绩公布事件，审计记录同时保留指派人和实际阅卷人。 | [考试与补考](../tests/exams_http.py) |
| AC-13 | 学员完成 600 秒、必修作业并通过补考后，六个连接并发只签发一张证书。 | [证书](../tests/credentials_http.py) |
| AC-14 | 六次并发扫描只产生一条复训任务，可关联新报名完成换证。 | [证书](../tests/credentials_http.py) |
| AC-15 | 部门合计与期末有效报名名单均为 2，学习路径的先修门槛生效。 | [报表与路径](../tests/paths_reports_http.py) |
| AC-16 | 退班学员的原始考试成绩 100 可回读，退班和换班旧资格均从期末有效数排除。 | [报表与路径](../tests/paths_reports_http.py) |

招生并发场景从已占 5 席的 10 席班期开始，10 个独立 MySQL 连接提交 100 份报名。每轮均确认最后 5 席与 95 份候补，随后 10 次同键重试只产生一份请求回执，业务报名数保持不变。计时自连接就绪后统一放行起算，热身用时 0.8921 秒，三次复测用时 0.8624、0.8509、0.9061 秒。PHP 进程内存上限 128 MiB，MySQL 最大连接数 151，缓冲池 128 MiB。测试覆盖短时突发争用。[重跑脚本](../tests/admission_concurrency.py) · [实测数据](admission-capacity-result.json)

独立 Chrome 会话验证了名单仅修正所选失败行、席位重试只分配所选学员且可回看来源，以及两名讲师交接阅卷并发布成绩。首版的浏览器验收还覆盖教务发布、学员报名与递补、直播回执复核、作业评分、证书查验与撤销、换班、路径先修及企业 CSV 导出。本地出勤使用合成回执，可按 [学习进度文档](study-progress.md) 复现。

从空库执行 [港湾培训中心样例](demo.md)，创建两个班期并完成一次企业培训。重复出勤累计 600 秒，重复交卷返回同一份 100 分结果，作业为 90 分，证书可匿名查验，部门报表记录一项结业。从 `v1.0.1` 升级只加载迁移 009，七张历史数据表的校验值一致，原证书仍可查验。新库的样例备份恢复至独立库后，八张表的校验值一致，证书查验结果一致。[升级及恢复实测数据](upgrade-restore-result.json)

空库执行九个迁移后再次运行，返回零条待执行迁移。

## 复跑接口验收

按 [本机开发](../README.md#本机开发) 启动独立实例，使用名称以 `learning_test_` 开头的数据库。PHP CLI 与 HTTP 进程使用相同数据库环境。从仓库根目录执行测试，MySQL 并发脚本直接打开独立连接。

```sh
python3 tests/admission_http.py http://127.0.0.1:8080
python3 tests/admission_concurrency.py
python3 tests/enterprise_seats.py http://127.0.0.1:8080
python3 tests/study_http.py http://127.0.0.1:8080
python3 tests/transfers_http.py http://127.0.0.1:8080
python3 tests/coursework_http.py http://127.0.0.1:8080
python3 tests/exams_http.py http://127.0.0.1:8080
python3 tests/credentials_http.py http://127.0.0.1:8080
python3 tests/paths_reports_http.py http://127.0.0.1:8080
```

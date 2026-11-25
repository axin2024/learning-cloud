# 验收记录

2026 年 11 月 25 日验证提交 `0fa0195`。运行环境为 Windows、PHP 8.2.30、MySQL 8.4.13 和 Chrome。数据库使用独立测试库，验收帐号、课程、企业及出勤事件均为合成数据。检查入口与完整需求见 [验收与运营](requirements/04-验收与运营-prd.md)。

| 用例 | 实测结果 | 重跑入口 |
| --- | --- | --- |
| AC-01、AC-02 | 已报名的旧班仍能读到原发布版本。包含未核实授权的章节会阻止课程发布。 | `tests/admission_http.py` |
| AC-03、AC-04 | 退班后按加入顺序递补。目标满员时原报名和 85 秒历史学时保持有效。配置映射后只结转覆盖的 30 秒。 | `tests/admission_http.py`、`tests/transfers_http.py` |
| AC-05、AC-06 | 100 席分配 40、35、25。回收只作用于未激活席位，已激活名额在退班后仍保留原采购记录。 | `tests/enterprise_seats.py` |
| AC-07、AC-08 | 同一段 600 秒学习记录重复上报仍计 600 秒。缺少会话号的出勤进入复核，绑定后计算有效区间。 | `tests/study_http.py` |
| AC-09、AC-10 | 重交保留两版提交与评分。截止后仅允许使用一次有时限的重交授权。 | `tests/coursework_http.py` |
| AC-11、AC-12 | 首考 55、补考 78。六个独立连接同时交同一答卷，只生成一条交卷事件。另验收按最近场次取分及到时收卷。 | `tests/exams_http.py` |
| AC-13、AC-14 | 一名学员完成 600 秒学习、必修作业和补考后，六个连接并发只签发一张证书。六次并发扫描只产生一条复训任务，可关联新报名完成换证。 | `tests/credentials_http.py` |
| AC-15、AC-16 | 部门合计与期末有效名单均为 2。退班学员的原始考试采用分 100 可回读，退班和换班旧资格均从期末有效数排除。 | `tests/paths_reports_http.py` |

招生压力场景从预占 5 席的 10 席班期开始，10 个独立 MySQL 连接共提交 100 份报名。自放行并发请求起约 1.09 秒确认最后 5 席和 95 份候补。随后 10 次同键重试只产生一份请求回执。见 `tests/admission_concurrency.py`。

独立 Chrome 会话完成教务发布和设置、学员报名与递补、直播回执复核、作业评分、考试与补考、证书查验与撤销、跨版本换班、路径先修拦截及企业报表导出。下载的 CSV 仅含该公司合同对应的学员。本地出勤使用合成回执，可按 [学习进度文档](study-progress.md) 复现。

空库执行八个迁移后再次运行，返回零条待执行迁移。另从包含旧课程、企业合同、已关联报名的数据库升级至当前迁移，读回旧数据并成功记录升级后的首段学时。

## 复跑接口验收

按 [本地运行](../README.md#本地运行) 启动独立实例，使用名称以 `learning_test_` 开头的数据库。PHP CLI 与 HTTP 进程使用相同数据库环境。从仓库根目录执行测试，MySQL 并发脚本直接打开独立连接。

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

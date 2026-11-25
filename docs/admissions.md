# 课程与招生

## 从空库验证

1. 按 [本地运行](../README.md) 启动独立实例。用空间管理员创建一名教务和三名学员。成员编号可从「成员」列表复制，教务可用它代学员报名。
2. 在课程下新建修订，录入章节、时长和考试通过线。首次可故意保留一个「待核实」章节，发布请求会返回涉及章节的顺位。设置原创或已授权的材料及依据后再发布。
3. 新建席位为 1 的班期。三名学员依次报名，工作台里依次显示已报名、候补第 1 位和候补第 2 位。
4. 第一名学员退班后，候补第 1 位自动变为已报名。候补第 2 位变为第 1 位。已退班的学员可读取报名回执，课程材料随有效学习资格开放。
5. 发布课程 v2 并创建新班。旧班在其报名详情打开 v1 教材，新班学员打开 v2 教材。尝试换到一个已满的班期，原报名应保持有效。

## 状态和授权

- 学习空间拥有独立的成员、课程、班期和报名。空间管理员管理成员，管理员或教务发布修订、控制班期容量、管理招生名册。学员可查询并操作自己的报名。
- 课程草稿保存时检查 `edit_version`，发布后冻结内容和学习规则。班期始终绑定同一个已发布版本。未指定版本开设班期时使用当时最新的发布版本。
- 容量按已预留、已报名、学习中和已完成资格计算。候补按持久化加入顺序递补。关闭报名拒绝新申请，已进入候补队列的申请仍可在有空位时递补。
- 报名请求键按空间、操作者和班期保存。同一个键重放返回原资格的当前状态，包括退班后的状态。同一学员有有效资格时，新的键也关联原资格。教务代报名时，同键改学员返回冲突。
- 换班按班期编号固定顺序锁住两个班期。目标占位、原报名关闭、学时结转、回执留存和原班候补递补在同一事务内提交。
- 课程、班期及报名变更落库时同时写入审计记录。教务代办保留教务账号、学员账号与报名对象编号。

## 学时等价与换班回执

同版本换班结转已有的有效学时。换到另一个已发布版本时，教务先打开「课程 → 学时等价」，选择原课程版本、目标课程和目标版本，录入等价依据，再填写章节配对和各自的起始秒数。选择的等价时长必须同时落在两个章节时长范围内。每个章节在一对版本的映射中只出现一次。

例如，原章节第 20–60 秒与目标章节第 10–50 秒内容等价。原学员已有第 0–30 秒和第 40–65 秒的有效记录，换班后结转目标章节第 10–20 秒和第 30–50 秒，共 30 秒。之后的学习区间会与结转区间合并。目标班按它绑定的课程版本计算完成进度和成绩。

映射从原版本指向目标版本，反向换班需单独配置。教务可编辑区间或停用映射。换班回执保留实际使用的映射版本、依据、结转秒数以及当时已发布的原班考试及作业成绩。原成绩和答卷仍可在原报名中查看。企业合同激活的报名沿转班链保留原采购课程范围。

学员在「我的报名」选择开放的目标班期，确认后在新旧报名的「换班回执」读回关联记录。目标满员时可保留原班继续学习。重复提交已完成的换班会返回同一笔新报名。功能上线前已发生的换班可查看关联报名。

## HTTP 调用

从 `GET /api/session` 获得会话 Cookie 和 `csrf_token`。随后修改请求携带原 Cookie、`Content-Type: application/json` 和 `X-CSRF-Token`。登录和退出后使用返回的新令牌。列表接口用 `after` 接续 `next_after`。

| 方法与路径 | 用途 |
| --- | --- |
| `GET /healthz` | 核对数据库迁移是否就绪 |
| `POST /api/login`、`POST /api/logout` | 登录与退出 |
| `GET /api/accounts`、`POST /api/accounts` | 管理员查找和新建本空间成员 |
| `GET /api/courses`、`POST /api/courses` | 课程目录和创建课程 |
| `GET /api/courses/{id}` | 教务读课程与修订列表 |
| `POST /api/courses/{id}/revisions` | 创建含章节、授权、通过规则的课程草稿 |
| `GET /api/courses/{id}/revisions/{revision}` | 读取获准访问的课程版本 |
| `PUT /api/courses/{id}/revisions/{revision}` | 按编辑版本保存草稿和章节 |
| `POST /api/courses/{id}/revisions/{revision}/publish` | 校验授权依据并发布 |
| `GET /api/cohorts`、`POST /api/cohorts` | 班期列表与开设班期 |
| `GET /api/cohorts/{id}`、`PUT /api/cohorts/{id}` | 班期详情、容量与报名开关 |
| `GET /api/cohorts/{id}/enrollments` | 教务读取名册 |
| `POST /api/cohorts/{id}/enrollments` | 学员报名或教务代办，提供 `Idempotency-Key` |
| `GET /api/me/enrollments`、`GET /api/enrollments/{id}` | 本人历史与报名详情 |
| `POST /api/enrollments/{id}/withdraw` | 退班或取消候补 |
| `GET /api/course-equivalences/{source_revision}/{target_revision}` | 教务查询版本对的学时映射，未配置时返回版本 0 |
| `PUT /api/course-equivalences/{source_revision}/{target_revision}` | 教务按 `version` 提交 `enabled`、`reason` 和 `mappings` |
| `GET /api/enrollments/{id}/transfer-options` | 按报名查询开放且有课程等价关系的目标班期 |
| `GET /api/enrollments/{id}/transfer` | 读取关联换班及冻结的成绩回执 |
| `POST /api/enrollments/{id}/transfer` | 按 `target_cohort_id` 换班 |

每条映射包含 `source_chapter_id`、`target_chapter_id`、`source_start_second`、`target_start_second` 和 `seconds`。映射冲突时，响应包括当前版本号，教务重新读取后可对照修改。

## 复跑验收

使用名称以 `learning_test_` 开头的独立数据库完成迁移，并在本机启动 PHP HTTP 服务。

```sh
python3 tests/admission_http.py http://127.0.0.1:8080
python3 tests/admission_concurrency.py
python3 tests/transfers_http.py http://127.0.0.1:8080
```

HTTP 回归覆盖 AC-01 至 AC-04，也检查课程发布冲突、旧版本读回、教务代办、空间隔离和候补读权限。并发回归用 10 个独立 PHP 进程共享一个班期，预占 5 个席位后提交 100 份报名，核对最后 5 席、95 份候补及其持久回执。随后 10 个进程使用同一请求键，核对回到同一资格且只新增一份请求回执。

换班用例覆盖区间裁剪、旧班成绩快照、目标满员回滚、六个独立连接提交同笔换班、停用后的重放、企业采购范围和租户隔离。

本地验收使用 PHP 8.2.30 和 MySQL 8.4.13。空库迁移后重复执行返回零条新迁移，HTTP 回归通过。100 份并发申请产生 5 份有效报名和 95 份有序候补，10 次同键提交只写入 1 份请求回执。Chrome 中验证了授权校验、课程发布、报名、候补、退班自动递补和手机宽度下的教材读取。

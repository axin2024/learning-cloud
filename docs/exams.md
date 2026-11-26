# 题库、考试与补考

教务为课程发布试卷，在班期安排首考与补考。单选题和多选题按正确选项组合自动计分，已作答的简答题由班期讲师或助教阅卷。学员在自己的报名中参加考试，查看各场答卷、评分和当前采用的成绩。

## 教务与阅卷

1. 在「课程 → 课程题库」新建试卷。为每个分组填写题目、抽题数和每题分数。组卷后总分须为 100。选择题填写从 1 起算的正确选项，简答题填写评分要点。
2. 发布试卷后，该版题目、正确选项和评分要点固定。后续可从已发布版本复制新草稿，旧版仍可用于已有场次。
3. 在「班期 → 考试场次」选择这门课程的已发布试卷、开考时间、截止时间和答题时限。时间使用 UTC。首考之后新增的场次为补考，各场次按时间顺序安排。参加补考前，学员须已有该班期的较早场次成绩。
4. 讲师或助教在「授课班期 → 考试场次」打开已交答卷，按试卷上冻结的评分要点为简答题打分，可附评语。自动计分和人工评分合计为本场成绩。
5. 待阅卷答卷可指派给本班另一名讲师或助教。指派期间由该成员发布成绩。原指派人或教务可以撤销或更换，旧指派无法继续提交。审核记录保留指派人、实际阅卷人和原指派的处理结果。

班期使用开班时关联的课程版本中的及格线与补考策略。「取最高分」采用历场最高分，同分采用较晚场次。「取最近场次成绩」采用序号最大的已评分场次。首考和补考答卷、评分分别保存。

## 学员操作与收卷

从「我的报名 → 考试与成绩」确认开考后，服务器以本次答卷的记录固定抽题结果、分值和评分要点。答卷截止取个人答题时限与场次截止中较早者。重新进入时读回相同答卷及最近保存的答案。学员界面只返回题干、选项和本人答案，评分公布后可查看得分和评语。

「保存进度」写入当前答案。「交卷」先保存当前修改，再按最新保存版本交卷。两个页面同时编辑时，较旧页面收到冲突提示，重新打开后可以查看服务器版本。到时收卷只计入截止前已保存的答案。

部署时用同一应用环境变量定期运行以下命令，例如每分钟一次。系统可收卷已离开页面的考生。单次最多处理给定条数，重复执行或多个进程同时执行时，已收卷答卷不会再收卷。

```sh
php bin/learning.php exam-sweep 100
```

## HTTP 接口

沿用工作台会话及 CSRF 令牌。分页接口接受 `after`，返回 `next_after`。

| 方法与路径 | 用途 |
| --- | --- |
| `GET /api/exams/courses/{course}/papers` | 查看课程题库的试卷版本 |
| `POST /api/exams/courses/{course}/papers` | 新建试卷草稿 |
| `GET /api/exams/papers/{paper}` | 读取草稿或已发布的试卷 |
| `PUT /api/exams/papers/{paper}` | 使用 `edit_version` 保存草稿 |
| `POST /api/exams/papers/{paper}/publish` | 发布指定编辑版本 |
| `GET /api/exams/cohorts/{cohort}/sittings` | 教务或对应班期的教学成员查看场次 |
| `POST /api/exams/cohorts/{cohort}/sittings` | 排首考或补考，输入 `paper_id`、`title`、`opens_at`、`closes_at`、`duration_seconds` |
| `GET /api/exams/sittings/{sitting}/attempts` | 查看场次交卷与阅卷状态 |
| `GET /api/exams/enrollments/{enrollment}` | 学员查看本人场次及采用成绩 |
| `POST /api/exams/enrollments/{enrollment}/sittings/{sitting}/start` | 进入场次，重复进入返回原答卷 |
| `GET /api/exams/attempts/{attempt}` | 读取本人答卷及答题保存版本 |
| `PUT /api/exams/attempts/{attempt}/answers` | 用 `answer_version` 与 `answers` 保存本次改动 |
| `POST /api/exams/attempts/{attempt}/submit` | 使用最后保存的 `answer_version` 交卷 |
| `GET /api/exams/attempts/{attempt}/marking` | 对应班期的教学成员查看已交答卷和评分要点 |
| `PUT /api/exams/attempts/{attempt}/marking-assignment` | 传入该答卷的 `expected_assignment_id` 和 `assigned_to` 指派或更换阅卷人。首次指派用空 ID，撤销时将 `assigned_to` 设为 `null`。 |
| `POST /api/exams/attempts/{attempt}/grade` | 一次提交已作答的各简答题分数并公布成绩 |

试卷写入接受 `title` 和 `pools`。每组包含 `name`、`draw`、`points`、`questions`。单选题与多选题包含 `kind`（`single` 或 `multiple`）、`prompt`、`options`、`correct`。简答题包含 `kind`（`written`）、`prompt` 和 `rubric`。管理端读回的 `guidance` 代表归一化后的 `correct` 或 `rubric`。

答题改动包含从 1 开始的 `ordinal` 和 `answer`。选择题用选项编号数组，简答题用正文，清空题目用 `null`。评分中的 `marks` 包含 `ordinal`、`points` 和可选 `feedback`。手动交卷以场次和答卷识别，同一保存版本重试沿用交卷时间和方式，回执可同时返回后来公布的成绩。

指派有效时，阅卷提交需附 `marking_assignment_id`。同一阅卷人重试相同评分和指派编号可读回原结果。更换、撤销或发布成绩会阻止旧操作。学员答卷只显示自己的答题内容和公布后的成绩、评语。

## 复跑验收

在独立的 `learning_test_` 数据库完成迁移并启动工作台。

```sh
python3 tests/exams_http.py http://127.0.0.1:8080
```

用例覆盖试卷编辑与冻结、重新进入保留题目与答案、截止自动收卷、六个独立 MySQL 连接重复交卷只产生一条提交事件、首考 55 分与补考 78 分以及按最近场次采用较低成绩。也检查学员不能查看其他人的答卷，教学成员只可阅卷获指派的班期。

指派验收检查同班资格、跨班拒绝、撤销、更换和旧指派拒绝，随后用六个独立连接并发重试同一次代办阅卷。MySQL 中只保存一条公布成绩记录，并可读回指派人及实际阅卷人。

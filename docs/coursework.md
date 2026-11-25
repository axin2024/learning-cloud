# 作业与辅导

教务为班期设置作业要求、截止时间和评分项。发布后固定这些内容。讲师与助教在授课班期内批改，学员在自己的报名详情提交作业并查看历次答卷。

## 工作台

1. 教务在「班期 → 教学成员」选择讲师账号的成员编号，为该班期分配讲师或助教职责。撤销指派立即收回该班期的教学权限。
2. 在「班期 → 班期作业」新建草稿。评分项总分须为 100，可设置及格分以及是否列为结业必修。检查作业要求和期限后发布。
3. 学员在「我的报名 → 作业与评分」打开作业并交卷。截止时间前允许提交新版本。宽限期内允许完成首次交卷，已交卷的学员须获得一次重交授权才可在截止后提交新版本。
4. 讲师从「授课班期」打开作业和学员答卷，按评分项给分，可为答卷指定行号区间添加批注。评分发布后与该版答卷绑定。
5. 讲师可为最新版本授权重交，并设置有效期和指示。授权允许提交一个新版本。原答卷、评分、批注和授权使用情况保留在交卷历史中。

工作台中的截止时间和授权时间使用 UTC。退班或换班后，原报名上已交的作业仍可回查，原报名停止接受交卷。

## HTTP 接口

会话使用工作台 Cookie，写请求使用 CSRF 令牌。时间字段接受带 `Z` 的 UTC 时间。列表返回 `items` 和 `next_after`，可把后者作为 `after` 继续查询，教学成员列表直接返回全部指派。

| 方法与路径 | 用途 |
| --- | --- |
| `GET /api/coursework/teaching-cohorts` | 查看本人获指派的班期 |
| `GET /api/coursework/cohorts/{cohort}/staff` | 教务查看班期教学成员 |
| `PUT /api/coursework/cohorts/{cohort}/staff/{account}` | 以 `duty` 分配 `instructor` 或 `assistant`，`none` 撤销 |
| `GET`、`POST /api/coursework/cohorts/{cohort}/assignments` | 查看班期作业或创建草稿 |
| `GET`、`PUT /api/coursework/assignments/{assignment}` | 查看作业或提交带 `version` 的草稿编辑 |
| `POST /api/coursework/assignments/{assignment}/publish` | 按 `version` 发布 |
| `GET /api/coursework/assignments/{assignment}/submissions` | 查看各学员的最新答卷及得分 |
| `GET /api/coursework/assignments/{assignment}/submissions/{enrollment}` | 批改者查看该学员的历次答卷 |
| `GET /api/coursework/enrollments/{enrollment}/assignments` | 学员查看自己报名的已发布作业 |
| `GET`、`POST /api/coursework/enrollments/{enrollment}/assignments/{assignment}` | 查看本人答卷、评分及授权，或提交 `answer` |
| `POST /api/coursework/submissions/{submission}/grade` | 按作业评分项顺序提交 `marks`，可附 `feedback` 与 `annotations` |
| `POST /api/coursework/submissions/{submission}/return` | 用 `expires_at` 和 `note` 授权一次重交 |

草稿写入包含 `title`、`instructions`、`due_at`、`grace_minutes`、`required_for_completion`、`pass_score` 和 `rubric`。每个评分项包含 `label` 和 `points`。批改中的 `marks` 包含各项得分 `points` 和可选 `note`。每条批注由从 1 开始的 `start_line`、`end_line` 和 `note` 组成。

交卷请求须携带 `Idempotency-Key`。同一报名和作业内用原请求键重发相同答卷会返回原回执，更换正文则需要新的请求键。评分和仍可识别的原重交授权支持用相同参数重试。首次写入返回 HTTP 201，回放返回 HTTP 200。

## 复跑验收

在前缀为 `learning_test_` 的独立数据库中执行迁移，再启动工作台。

```sh
python3 tests/coursework_http.py http://127.0.0.1:8080
```

用例覆盖草稿发布锁、教务和班期权限、宽限期、逾期重交的单次授权、重复请求、批注行号校验、历次版本与分数留存、退班后读回以及教学权限收回。

# 学习进度与直播出勤

## 学员操作

从有效报名进入「查看课程材料」，打开章节。阅读章节可以开始或暂停计时，页面可见时累积阅读区间。录播使用课程修订中保存的 HTTPS 媒体地址，浏览器按已播放的区间上报。重新打开章节会定位到从第 0 秒起连续认定到的位置。断线期间，当前标签页按报名会话保留待发送区间，连线恢复或在同一标签页重开章节后重试。

录播和阅读区间由学员浏览器产生，部署者可结合授课方式和考试规则决定如何采纳学时。录播地址由课件提供方管理，发布时需确认 URL 可访问且素材允许相应分发。

直播章节先由教务为班期选择出勤平台。学员在章节获取会话号，交给直播平台或其出勤适配器。报名详情的「学习进度」展示累计秒数、各章节要求和课程最低学时。相同课程版本换班会继承已有进度，跨版本按配置的区间结转。原班保留历史。退班和换班后，旧报名停止累计学习时长。换班规则与回执见 [课程与招生](admissions.md#学时等价与换班回执)。

## 平台接入与复核

1. 空间管理员在「直播出勤」登记平台，复制创建时显示的接入令牌。教务在班期对应的直播章节选择该平台。
2. 适配器用 `Authorization: Bearer <token>` 向 `POST /api/live-attendance` 发送 JSON，`role` 支持 `learner`、`instructor`、`host`。秒数相对章节开场，区间左闭右开。
3. HTTP `202` 表示已记录新回执，`200` 表示读回同一事件。`status` 为 `accepted`、`review` 或 `rejected`。网络中断后保留原 `event_id` 重放；同一平台下相同事件编号绑定同一份输入。
4. 学员事件缺少会话号、归属不匹配或超出章节范围时进入复核。教务核对平台记录后，提交有效会话号和依据，或驳回并记录原因。讲师和主持人的回执保留在已拒绝列表。会话只能匹配绑定该章节的原出勤平台。
5. 令牌可轮换，旧令牌随即失效。原平台编号及其已提交事件和待复核记录继续保留。

以下回执用于演练缺少会话号的复核路径。正常回传在同一 JSON 对象加上 `session_code`。

```json
{"event_id":"orientation-2026-session-12-part-1","role":"learner","start_second":0,"end_second":160}
```

本机模拟器从标准输入读取 JSON Lines，逐条返回 HTTP 状态和回执。如果收到失败结果，修正后按原事件编号检查回执或重放。设置 `LEARNING_ATTENDANCE_TOKEN` 后运行以下命令。

```sh
php bin/replay-attendance.php http://127.0.0.1:8080 < events.jsonl
```

## 接口

除平台回传外，接口沿用工作台 Cookie 和写入请求的 CSRF 令牌。

| 方法与路径 | 用途 |
| --- | --- |
| `GET /api/study/enrollments/{enrollment}` | 查看认定时长和达标情况 |
| `POST /api/study/enrollments/{enrollment}/chapters/{chapter}/start` | 开始或恢复本章会话 |
| `POST /api/study/sessions/{session}/intervals` | 阅读、录播上报 `start_second` 和 `end_second`，要求 `Idempotency-Key`，每段最长 30 秒 |
| `GET /api/study/providers`、`POST /api/study/providers` | 查看或登记出勤平台 |
| `POST /api/study/providers/{provider}/rotate` | 轮换平台令牌 |
| `GET /api/study/cohorts/{cohort}/live`、`PUT /api/study/cohorts/{cohort}/live/{chapter}` | 查看班期绑定并用 `provider_id` 指定平台 |
| `POST /api/live-attendance` | 用平台令牌提交出勤事件 |
| `GET /api/study/attendance?state=review` | 查询待复核、已确认或已拒绝的事件 |
| `POST /api/study/attendance/{event}/review` | 提交 `decision`、`note`，确认时补入 `session_code` |

## 复跑验收

在 `learning_test_` 前缀的独立数据库完成迁移并启动服务。

```sh
python3 tests/study_http.py http://127.0.0.1:8080
```

区间及外部回执保留原提交记录。MySQL 按报名锁定写入，将相邻或重叠区间并入已认定范围。PHP 8.2.30 与 MySQL 8.4.13 本地验收覆盖重复上报 600 秒、跨区间补齐、错配平台、无归属事件复核、令牌轮换、同版换班继承和历史进度读回。

Chrome 验收覆盖阅读断线后重载补报、本机测试录像播放和暂停后记入学时、教务绑定直播平台并复核出勤。升级验收从上一提交创建课程、合同和已激活报名，迁移后读回同一批记录，并为原报名写入第一段学时。

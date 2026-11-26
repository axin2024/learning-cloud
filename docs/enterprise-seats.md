# 企业采购与席位

## 从合同到报名

1. 空间管理员在「企业购课」创建企业，并按成员编号登记企业管理员。进入「部门与名单」建立部门，把本空间已有的学员编号导入对应部门。
2. 空间管理员为已发布课程登记合同编号、有效期和采购席数。实际签约可在企业原有采购流程完成，平台保存合同编号和对应的学习名额。
3. 企业管理员打开本企业合同，选择班期，在「批量分配」按 `部门编号,学员编号` 每行录入一人。回执逐行显示已分配、已有席位或未分配及原因。合同补回可用额度后，可在原回执勾选要重试的失败行。新回执保留来源批次和来源行号。部门编号或学员编号需要更正时，可在新批次填写修正的数据。
4. 学员进入「企业席位」激活本人名额。成功后可跳到报名回执。班期已满、报名停止或合同逾期时，已分配的席位仍可由企业管理员查看。
5. 企业管理员回收未激活席位后可在合同有效期内重新分配。原席位号和回执可回看，新分配使用下一个席位号。学员激活后即消耗一份合同名额，退班会释放班期容量，合同中仍保留已激活记录。

## 事务与权限

- 合同的课程、所属企业、合同编号确定一个名额池。批次在名额池行锁下计算余量，已分配及已激活席位共同占用额度。合同起止日按 UTC 计算，首尾均可用。
- 企业管理员只可查询和操作已经授权的企业。空间管理员撤销企业管理资格后，该成员的新请求无法读取或修改对应企业。名单由空间管理员维护，成员须具有学员角色。
- 分配名额和激活报名使用不同的记录编号。激活在同一事务中锁住名额池和班期，报名和激活一起提交。同一个激活请求再次到达时返回已有报名编号。
- 企业分配批次要求 `Idempotency-Key`，同一合同、操作者和键对相同输入返回已提交回执。改动输入后请使用新键。历史回执可通过批次编号读回，后续回收不会改变原批次的结果。
- 重试同样需要 `Idempotency-Key`。以源批次和选中的行号集合为准，调换顺序仍会重放同一回执。选择已成功、格式有误或不存在的行会在新回执中标为不可重试。

## 接口

会话和写入接口沿用 [课程与招生](admissions.md) 的身份验证流程。以下 `company`、`pool`、`batch`、`seat` 均为接口返回的编号。

| 方法与路径 | 用途 |
| --- | --- |
| `GET /api/companies`、`POST /api/companies` | 查看授权企业、创建企业 |
| `GET /api/companies/{company}/administrators`、`PUT /api/companies/{company}/administrators/{account}` | 查看、授予和撤销企业管理范围，写入使用 `enabled` |
| `GET /api/companies/{company}/departments`、`POST /api/companies/{company}/departments` | 查看和创建部门 |
| `GET /api/companies/{company}/learners`、`POST /api/companies/{company}/learners` | 查看名单和导入 `{department_id, learner_ids}` |
| `GET /api/companies/{company}/purchases`、`POST /api/companies/{company}/purchases` | 浏览或登记采购合同 |
| `GET /api/companies/{company}/purchases/{pool}` | 合同详情、剩余席位及部门占用 |
| `GET /api/companies/{company}/purchases/{pool}/batches`、`POST /api/companies/{company}/purchases/{pool}/batches` | 浏览或提交分配批次，请求体包含 `cohort_id` 和 `rows` |
| `GET /api/companies/{company}/purchases/{pool}/batches/{batch}` | 读取批次逐行结果 |
| `POST /api/companies/{company}/purchases/{pool}/batches/{batch}/retry` | 提交 `{"rows":[2,5]}` 以重试指定失败行，返回带有 `retry_of` 和 `source_row` 的独立回执 |
| `GET /api/companies/{company}/purchases/{pool}/assignments` | 查看合同席位明细 |
| `POST /api/companies/{company}/purchases/{pool}/assignments/{seat}/reclaim` | 回收未激活席位 |
| `GET /api/me/seat-assignments`、`POST /api/seat-assignments/{seat}/activate` | 学员查看本人席位和激活 |

## 复跑验收

在 `learning_test_` 前缀的独立数据库完成迁移，启动本机 HTTP 服务后运行以下命令。

```sh
python3 tests/enterprise_seats.py http://127.0.0.1:8080
```

在本地 PHP 8.2.30 与 MySQL 8.4.13 上复现了三部门 40、35、25 席分配。10 个独立数据库连接提交 100 份分配时，5 份获得席位、95 份收到额度不足回执。8 次并发重放读取同一回执，额外分配、批次和分配审计记录均为零。接口测试还覆盖授权撤销、过期合同、错误课程、激活重试和未用席位回收。

Chrome 验收中，空间管理员创建企业、导入名单和登记采购，企业管理员分配席位、重开历史回执和回收未用席位，学员激活另一个席位后看到已报名。

导入名单出现多行失败时，空间管理员可以勾选需要修正的行。表单保留所选行的原始编号和部门，提交后可以返回上一批结果继续检查。席位分配的失败行使用合同回执上的选行重试。

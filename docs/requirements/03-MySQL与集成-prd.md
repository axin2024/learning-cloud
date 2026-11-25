# 职业教育与企业培训平台｜MySQL 数据与集成

## 持久化边界

MySQL 8.4 InnoDB 是本项目业务状态与授权关系的权威存储。租户身份进入业务唯一键与关联检查。媒体、文件、备份或模型大对象允许使用文件或对象存储，库内保存版本化引用、长度、摘要及所有者。派生缓存可从持久记录重新构建。

## 逻辑实体与事实来源

| 业务域 | 核心实体与必要字段 | 写入时必须维持的规则 |
|---|---|---|
| 租户与课程 | course(tenant,owner), course_rev(course,rev,rights), chapter(rev,ordinal) | 课程内容、通过要求和版权授权绑定发布版本，在学者保留原学习要求。 |
| 班期与招生 | cohort(course_rev,capacity), enrollment(cohort,learner,state) | 席位由班期统一预留，重复报名返回原资格，换班时检查目标容量及学时映射。 |
| 企业购课 | seat_pool(company,contract,total), seat_assignment(pool,learner,dept) | 企业采购限定有效期和课程，管理员只能分配本租户已购余量。 |
| 学习过程 | study_session(enrollment,chapter), progress_interval(session,start,end) | 播放器和直播提供方上报区间，合并重叠片段并按课规判断完成；掉线允许续学。 |
| 作业与辅导 | assignment(cohort,due), submission(task,learner,revision), grade(submission,marker) | 按截止时间和宽限规则处理，重交保留原提交，助教只见所授班。 |
| 题库与考试 | paper_rev(bank,rev), exam_attempt(enrollment,sitting,seed), answer(attempt,item) | 开考冻结题目与评分要点，保存答题进度，补考使用独立场次并执行成绩采用策略。 |
| 证书与复训 | credential(enrollment,serial,expiry), retraining(credential,cycle,state) | 签发前核验学时、作业和考试，吊销保留原因。到期后生成复训任务并关联原资格。 |
| 经营与企业报告 | path_step(path,prerequisite), cohort_metric(cohort,date,denominator) | 按报名与完成时间点分别统计，先修条件限定进入资格，企业只看本单位学员。 |

## 事务、索引与对账

- **身份与约束**　企业合同＋课程＋席位号唯一，报名与证书号码具备独立稳定身份。
- **业务索引**　按学员和章节汇总时段，按企业部门及班期统计有效分母。
- **数据生命周期**　学员转班、退课、证书撤销保留审计；公开验真接口屏蔽个人成绩。

分配锁名额池，交卷按场次与尝试号定稿，发证检查成绩、学时及证书唯一键。按企业、班期、完成状态分页。

列表使用租户、业务状态、更新时间和稳定主键分页。外部事件保留来源、外部号、内容摘要、处理结果和业务对象引用。同键不同内容产生显式冲突；网络超时后按原键查单。跨存储发布应先确认文件持久，再发布其数据库引用；回收须验证没有有效引用。

## 集成契约

视频平台回传区间、角色与会话号；企业导入名单，证书查询只展示获准公开的最小属性。

批量分配输入企业合同、班级和部门名单，返回逐行占用、重复或额度不足。

考试提交绑定场次、答卷和最后保存版，超时由服务器收卷；阅卷后按冻结规则计算总评。

## 数据恢复与核验

在隔离环境恢复 MySQL 和引用清单，校验样例的源对象、派生关系及最终结果。恢复后应能回答以下问题。学习时长记 10 分钟；满足班规其他条件后按补考规则发一张有效证书，部门合计不超过 100 席。

[MySQL 事务模型](https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-model.html)

[产品](01-产品总览-prd.md) · [功能](02-业务与交互-prd.md) · [MySQL](03-MySQL与集成-prd.md) · [验收](04-验收与运营-prd.md) · [开源](05-开源与发布-prd.md) · [状态与操作](06-状态与操作契约-prd.md) · [公开案例](07-开源项目说明与使用案例-prd.md)

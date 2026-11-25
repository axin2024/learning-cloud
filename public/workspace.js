"use strict";

const $ = (id) => document.getElementById(id);
const roleLabels = {admin: "空间管理员", registrar: "教务", instructor: "讲师", learner: "学员", enterprise_admin: "企业管理员"};
const stateLabels = {
    waitlisted: "候补", reserved: "已预留", enrolled: "已报名", studying: "学习中", completed: "已完成",
    withdrawn: "已退班", transferred: "已换班", open: "报名开放", closed: "已停止报名", published: "已发布", draft: "草稿",
    assigned: "待激活", activated: "已激活", reclaimed: "已回收", duplicate: "已有席位", rejected: "未分配",
};
const errorLabels = {
    invalid_credentials: "学习空间、邮箱或密码有误。", login_required: "登录已过期，请重新登录。",
    invalid_csrf_token: "页面验证已过期，请刷新后重试。", invalid_input: "请检查填写内容。",
    registrar_required: "此操作需要教务权限。", learner_or_registrar_required: "此操作需要学员或教务权限。",
    admin_required: "此操作需要空间管理员权限。", revision_not_found: "当前账号无法查看该课程版本。",
    rights_evidence_required: "发布前请补齐材料授权依据。", revision_superseded: "该草稿的版本号低于已发布版本。",
    revision_already_published: "已发布的版本已冻结，请新建修订。", revision_version_conflict: "草稿已有更新，请重新打开后编辑。",
    study_time_exceeds_course_duration: "要求学时不能超过全部章节的可学习时长。",
    course_slug_exists: "此空间已使用该课程代码。", cohort_code_exists: "此空间已使用该班期代码。",
    published_revision_required: "请先发布课程版本。", invalid_cohort_dates: "结束日期不能早于开始日期。",
    registration_closed: "该班期已停止报名。", capacity_below_occupancy: "容量不能小于已经占用的席位。",
    cohort_version_conflict: "班期状态已有更新，请刷新后再改。", target_cohort_full: "目标班期已满，原报名仍然有效。",
    same_cohort: "请选择其他班期。", course_equivalence_required: "两个班期的课程版本不同，需要课程等价配置。",
    equivalence_distinct_revisions_required: "请选择两个不同的发布版本。",
    equivalence_published_revisions_required: "源和目标都须为本学习空间的已发布版本。",
    equivalence_version_conflict: "映射已被更新。关闭窗口后重新打开，核对最新版本。",
    invalid_equivalence_mapping: "请检查章节归属、重复章节和区间长度。",
    transfer_requires_purchased_course: "企业席位只能换到采购合同对应课程的班期。",
    learning_path_not_found: "当前账号无法查看此学习路径。",
    learning_path_code_exists: "该学习路径代码已存在。",
    learning_path_version_conflict: "学习路径已更新，请重新打开草稿。",
    learning_path_already_published: "已发布的学习路径保留原有顺序。",
    learning_path_courses_must_be_distinct: "请为每一步选择不同的课程。",
    learning_path_requires_published_courses: "学习路径须使用本学习空间的已发布课程。",
    path_step_course_mismatch: "请选择与发布步骤对应的课程。",
    path_step_not_found: "未找到这一步的班期。",
    path_prerequisites_required: "请先完成目标班期要求的先修课程。",
    invalid_report_period: "请选择有效的起止日期，截止日须为今天或之前。",
    already_in_target_cohort: "该学员已在目标班期报名或候补。", account_exists: "此邮箱在当前空间已注册。",
    account_not_found: "当前空间没有该成员。", account_is_not_a_learner: "所选成员没有学员角色。",
    request_key_reused: "这个请求编号已用于其他内容，请重新发起。",
    company_not_found: "当前账号无法访问该企业。", company_code_exists: "此空间已使用该企业代码。",
    department_code_exists: "此企业已使用该部门代码。", department_not_found: "没有找到该部门。",
    invalid_contract_dates: "合同截止日期不能早于生效日期。", contract_course_exists: "这个企业合同已登记该课程的名额。",
    contract_outside_validity: "当前日期不在合同有效期内。", published_course_not_found: "请先发布课程。",
    learner_department_conflict: "成员已归属其他部门。", learner_not_in_department: "该成员未加入指定部门。",
    learner_already_assigned_elsewhere: "该成员在这个合同下已有其他班期的席位。", contract_quota_exhausted: "合同剩余名额不足。",
    cohort_not_in_contract: "班期课程与合同课程不匹配。", activated_seat_cannot_reclaim: "已激活席位不可回收。",
    seat_reclaimed: "该席位已回收。", learner_already_in_cohort: "你在此班期已有报名或候补。",
    enterprise_cohort_full: "此班期已满，企业席位仍处于待激活状态。",
    invalid_recorded_media_url: "录播链接须为不含登录信息的 HTTPS 地址。", enrollment_cannot_study: "当前报名状态无法记入新的学时。",
    live_provider_not_configured: "请教务先为这节直播绑定出勤平台。", live_provider_already_in_use: "当前章节已有学习会话，使用令牌轮换可继续对接原平台。",
    attendance_session_code_missing: "直播平台未提供学习会话号。", attendance_session_unmatched: "未找到属于此平台的匹配会话。",
    study_interval_outside_chapter: "出勤区间超出章节时长。", attendance_event_already_reviewed: "此回执已复核，请刷新。",
    invalid_attendance_session_code: "请填写 48 位小写十六进制学习会话号。", attendance_provider_code_exists: "已使用此平台代码。",
    attendance_role_not_learner: "该平台角色无需认定学员出勤。",
    teaching_cohort_not_found: "当前账号无权进入该班期的教学工作台。",
    account_is_not_teaching_staff: "所选成员需要具备讲师角色，才能分配为班期讲师或助教。",
    assignment_not_found: "当前账号无法查看这项作业。", assignment_already_published: "作业已发布，评分规则与交卷时间已冻结。",
    assignment_version_conflict: "草稿已有更新，请重新打开后编辑。", rubric_points_must_total_100: "评分项满分合计须为 100 分。",
    invalid_assignment_answer: "请填写答卷，最多 32000 字。", assignment_submission_closed: "首次提交时间已结束。",
    assignment_return_required: "已过截止时间，需等待讲师或助教授权重交。",
    enrollment_cannot_submit_coursework: "当前报名状态无法提交作业。",
    submission_already_graded: "此版本的评分已发布，请刷新查看。", submission_is_not_latest: "作业已有新版本，请从最新版本授权重交。",
    return_expiry_must_be_future: "授权截止时间须晚于当前时间。", assignment_return_already_open: "此版本已有有效的重交授权。",
    exam_paper_version_conflict: "试卷草稿已有更新，请重新打开。", exam_paper_already_published: "已发布的试卷无法编辑，可以从该版新建草稿。",
    exam_points_must_total_100: "试卷抽题后的总分须为 100。", exam_answer_key_required: "选择题须设置正确选项。",
    exam_option_must_be_one_line: "每个选项占一行。", duplicate_exam_options: "选项内容不能重复。",
    duplicate_exam_choice: "选项编号不能重复。", exam_bank_too_large: "每版题库最多 300 道题。",
    invalid_exam_window: "请检查场次开始、截止与当前时间。", exam_sittings_overlap: "补考时间须在前一场次结束之后。",
    published_course_paper_required: "请选择这门课程已发布的试卷。", exam_sitting_unavailable: "当前不在该场次的开考时段内。",
    exam_retake_requires_prior_grade: "参加补考前须获得该班期时序更早的成绩。",
    exam_answers_closed: "本场答题已经截止，请打开服务器保存的答卷。",
    exam_answer_version_conflict: "服务器有更新的答卷，请重新打开后继续。", enrollment_cannot_take_exam: "当前报名状态无法参加考试。",
    exam_attempt_not_submitted: "交卷后才能查阅答卷。", exam_attempt_already_graded: "这份答卷已完成阅卷，请重新打开。",
    credential_not_found: "当前账号无法查看这张证书。", credential_requirements_unmet: "学业条件尚未全部达标，请重新核对审核结果。",
    credential_issue_conflict: "该报名已按原有效期签发证书，请打开原证书。",
    credential_already_revoked: "证书已撤销，原原因已保留。",
    invalid_retraining_enrollment: "请选择原持证人在证书到期后报名的同一课程新班期。",
    retraining_task_not_assignable: "当前复训状态无法关联报名。", retraining_target_already_linked: "所选报名已经关联其他复训任务。",
    retraining_task_not_found: "当前账号无法查看复训任务。", retraining_access_required: "当前身份无法查看复训任务。",
};

let actor = null;
let csrf = null;
let activeView = 0;
let saveForm = null;
let leaveStudy = null;
const enrollmentKeys = new Map();

function element(tag, value, className) {
    const node = document.createElement(tag);
    if (value !== undefined && value !== null) node.textContent = String(value);
    if (className) node.className = className;
    return node;
}

function button(label, handler, style = "") {
    const control = element("button", label, style);
    control.type = "button";
    control.addEventListener("click", (event) => void run(control, () => handler(event)));
    return control;
}

function block(tag, className, ...children) {
    const node = element(tag, null, className);
    node.append(...children);
    return node;
}

function setMessage(node, message, isError = false) {
    node.hidden = !message;
    node.textContent = message;
    node.classList.toggle("error", isError);
}

function errorMessage(error) {
    if (!error.result) return "暂时无法连接服务器，请重试。";
    const {error: reason, request_id: id, details = {}} = error.result;
    const message = errorLabels[reason] || "操作未完成，请根据回执检查当前状态。";
    let detail = "";
    if (Array.isArray(details.chapters)) detail = ` 缺少授权的章节 ${details.chapters.join("、")}。`;
    if (Array.isArray(details.missing)) detail = ` 待完成 ${details.missing.map((course) => course.title).join("、")}。`;
    if (details.field) detail = ` 字段 ${details.field}。`;
    if (id && !errorLabels[reason]) detail += ` 请求编号 ${id}。`;
    return message + detail;
}

async function api(path, method = "GET", data = {}, key = null) {
    const headers = {Accept: "application/json"};
    const options = {method, headers, cache: "no-store"};
    if (method !== "GET") {
        options.body = JSON.stringify(data);
        headers["Content-Type"] = "application/json";
        headers["X-CSRF-Token"] = csrf;
        if (key) headers["Idempotency-Key"] = key;
    }
    const response = await fetch(path, options);
    const result = await response.json();
    if (!response.ok) throw Object.assign(new Error(result.error), {result});
    if (result.csrf_token) csrf = result.csrf_token;
    return result;
}

async function run(control, task, target = $("notice")) {
    control.disabled = true;
    setMessage(target, "");
    try {
        await task();
    } catch (error) {
        setMessage(target, errorMessage(error), true);
    } finally {
        control.disabled = false;
    }
}

function manages() {
    return actor.roles.includes("admin") || actor.roles.includes("registrar");
}

function displayNavigation(current) {
    const entries = [["courses", "课程", showCourses], ["cohorts", "班期", showCohorts]];
    if (manages() || actor.roles.includes("learner")) entries.push(["paths", "学习路径", showLearningPaths]);
    if (actor.roles.includes("learner")) entries.push(["mine", "我的报名", showMine], ["my-seats", "企业席位", showMySeats]);
    if (actor.roles.includes("admin") || actor.roles.includes("enterprise_admin")) entries.push(["companies", "企业购课", showCompanies]);
    if (manages()) entries.push(["live", "直播出勤", showLiveHub], ["reports", "课程经营", showCourseReports]);
    if (actor.roles.includes("instructor")) entries.push(["teaching", "授课班期", showTeachingCohorts]);
    if (manages() || actor.roles.includes("learner")) entries.push(["retraining", "复训任务", showRetraining]);
    if (actor.roles.includes("admin")) entries.push(["accounts", "成员", showAccounts]);
    $("navigation").replaceChildren(...entries.map(([screen, label, navigate]) => {
        const link = button(label, () => navigate());
        if (current === screen) link.setAttribute("aria-current", "page");
        return link;
    }));
}

async function display(screen, load) {
    finishStudy();
    const view = ++activeView;
    displayNavigation(screen);
    const content = $("content");
    content.inert = true;
    content.setAttribute("aria-busy", "true");
    try {
        const nodes = await load();
        if (view === activeView) content.replaceChildren(...nodes);
    } catch (error) {
        if (view === activeView) {
            content.replaceChildren();
            setMessage($("notice"), errorMessage(error), true);
        }
    } finally {
        if (view === activeView) {
            content.inert = false;
            content.removeAttribute("aria-busy");
        }
    }
}

function heading(title, description = "", action = null) {
    const copy = block("div", "", element("h1", title));
    if (description) copy.append(element("p", description));
    return action ? block("div", "page-heading", copy, action) : block("div", "page-heading", copy);
}

function badge(state) {
    return element("span", stateLabels[state] || state, `state ${state}`);
}

function back(label, handler) {
    return block("div", "breadcrumb", button(`← ${label}`, handler, "quiet"));
}

function table(columns, rows, emptyText) {
    if (!rows.length) return element("p", emptyText, "empty");
    const head = block("thead", "", block("tr", "", ...columns.map((column) => {
        const cell = element("th", column);
        cell.scope = "col";
        return cell;
    })));
    const body = block("tbody", "", ...rows.map((row) => block("tr", "", ...row.map((value) => {
        const td = element("td");
        td.append(value instanceof Node ? value : String(value ?? "—"));
        return td;
    }))));
    return block("div", "table-wrap", block("table", "", head, body));
}

function paginated(columns, page, rows, emptyText, after, load) {
    const surface = block("div", "surface", table(columns, rows, emptyText));
    const pager = element("div", null, "pager");
    if (after) pager.append(button("回到首页", () => load(0), "quiet"));
    if (page.next_after !== null) pager.append(button("下一页", () => load(page.next_after)));
    if (pager.childElementCount) surface.append(pager);
    return surface;
}

async function allPages(path) {
    let cursor = 0;
    const items = [];
    do {
        const page = await api(`${path}?after=${cursor}`);
        items.push(...page.items);
        cursor = page.next_after;
    } while (cursor !== null);
    return items;
}

function field(name, label, type = "text", value = "", rules = {}) {
    const input = type === "textarea" || type === "select" ? element(type) : element("input");
    input.name = name;
    if (input instanceof HTMLInputElement) input.type = type;
    if (type === "select") input.setAttribute("aria-label", label);
    const {choices = [], required = true, ...attributes} = rules;
    for (const [optionValue, text] of choices) {
        const option = element("option", text);
        option.value = optionValue;
        input.append(option);
    }
    input.required = required;
    for (const [attribute, setting] of Object.entries(attributes)) input.setAttribute(attribute, setting);
    if (type === "checkbox") input.checked = Boolean(value);
    else input.value = value;
    return block("label", "", document.createTextNode(label), input);
}

function fields(...children) {
    return block("div", "fields-row", ...children);
}

function openForm(title, children, onSave, label = "保存") {
    $("dialog-title").textContent = title;
    $("dialog-fields").replaceChildren(...children);
    $("dialog-submit").textContent = label;
    setMessage($("dialog-message"), "");
    saveForm = onSave;
    $("dialog").showModal();
}

function success(message) {
    setMessage($("notice"), message);
}

async function showCourses(after = 0) {
    await display("courses", async () => {
        const page = await api(`/api/courses?after=${after}`);
        const rows = page.items.map((course) => [
            manages() ? button(course.title || course.slug, () => showCourse(course.id), "row-link") : course.title,
            course.slug, course.revision ? `v${course.revision}` : "—", badge(course.revision ? "published" : "draft"),
        ]);
        return [heading("课程目录", "", manages() ? button("新建课程", newCourse, "primary") : null),
            paginated(["课程", "课程代码", "在用版本", "发布状态"], page, rows, "暂无课程。", after, showCourses)];
    });
}

function newCourse() {
    openForm("新建课程", [field("slug", "课程代码", "text", "", {maxlength: 64, pattern: "[a-z0-9]+([\\-_][a-z0-9]+)*"})], async (form) => {
        const course = await api("/api/courses", "POST", {slug: form.get("slug")});
        return () => showCourse(course.id);
    }, "创建课程");
}

async function showCourse(id) {
    await display("courses", async () => {
        const course = await api(`/api/courses/${id}`);
        const rows = course.revisions.map((revision) => [
            button(revision.title, () => showRevision(id, revision.id), "row-link"), `v${revision.revision}`,
            badge(revision.published_at ? "published" : "draft"), revision.published_at || "—",
        ]);
        const actions = block("div", "toolbar", button("新建修订", () => editRevision(id), "primary"),
            button("课程题库", () => showExamPapers(course)),
            button("学时等价", () => showEquivalenceSources(course)));
        return [back("课程目录", showCourses), heading(course.slug, "", actions),
            block("div", "surface", table(["版本标题", "版本", "状态", "发布时间（UTC）"], rows, "该课程尚无修订。"))];
    });
}

function minutes(seconds) {
    return `${new Intl.NumberFormat("zh-CN", {maximumFractionDigits: 2}).format(seconds / 60)} 分钟`;
}

async function showRevision(courseId, revisionId, enrollmentId = null) {
    await display(enrollmentId ? "mine" : "courses", async () => {
        const [revision, progress] = await Promise.all([api(`/api/courses/${courseId}/revisions/${revisionId}`),
            enrollmentId ? api(`/api/study/enrollments/${enrollmentId}`) : null]);
        const tools = element("div", null, "toolbar");
        if (!revision.published_at && manages()) {
            tools.append(button("编辑草稿", () => editRevision(courseId, revision)), button("发布版本", async () => {
                await api(`/api/courses/${courseId}/revisions/${revision.id}/publish`, "POST", {edit_version: revision.edit_version});
                await showRevision(courseId, revision.id);
                success(`课程 v${revision.revision} 已发布。`);
            }, "primary"));
        }
        const facts = block("div", "surface", badge(revision.published_at ? "published" : "draft"),
            block("div", "metrics", metric("最低有效学时", minutes(revision.minimum_study_seconds)), metric("考试通过线", revision.pass_score),
                metric("补考成绩规则", revision.retake_mode === "highest" ? "最高分" : "最近一次")));
        const canStudy = progress && ["enrolled", "studying"].includes(progress.enrollment_state);
        const chapters = revision.chapters.map((chapter, index) => block("article", "surface material",
            element("h3", `${chapter.ordinal}. ${chapter.title}`),
            element("p", `${{text: "阅读", recorded: "录播", live: "直播"}[chapter.kind]} · ${minutes(chapter.duration_seconds)}`, "meta"),
            element("p", chapter.content),
            ...(canStudy ? [element("p", `累计有效学时 ${minutes(progress.chapters[index].studied_seconds)}`, "meta"),
                button(chapter.kind === "live" ? "获取直播会话号" : "学习本章", () => showStudyChapter(enrollmentId, courseId, revisionId, chapter), "row-link")] : []),
            ...(!canStudy && chapter.media_url ? [mediaPlayer(chapter.media_url)] : []),
            ...(chapter.rights_basis ? [element("p", `授权依据 ${chapter.rights_reference || "待核实"}`, "meta")] : [])));
        return [enrollmentId ? back("报名详情", () => showEnrollment(enrollmentId)) : back("课程版本", () => showCourse(courseId)),
            heading(revision.title, `v${revision.revision}${revision.summary ? ` · ${revision.summary}` : ""}`, tools.childElementCount ? tools : null),
            facts, block("div", "materials", ...chapters)];
    });
}

function editRevision(courseId, revision = null) {
    const chapters = element("div", null, "form-stack");
    function addChapter(item = {}) {
        const group = block("fieldset", "form-stack", element("legend", "章节"),
            field("chapter_title", "章节标题", "text", item.title || "", {maxlength: 200}),
            field("chapter_kind", "章节类型", "select", item.kind || "text", {choices: [["text", "阅读"], ["recorded", "录播"], ["live", "直播"]]}),
            field("chapter_content", "章节内容", "textarea", item.content || "", {maxlength: 12000}),
            field("media_url", "录播地址（HTTPS，可选）", "url", item.media_url || "", {maxlength: 2048, required: false}),
            fields(field("duration_seconds", "时长（秒）", "number", item.duration_seconds ?? 600, {min: 1, max: 86400}),
                field("required_seconds", "最低认定（秒）", "number", item.required_seconds ?? 600, {min: 0, max: 86400})),
            fields(field("rights_basis", "材料授权", "select", item.rights_basis || "unverified", {
                choices: [["unverified", "待核实"], ["original", "原创"], ["licensed", "取得授权"], ["public_domain", "公共领域"]],
            }), field("rights_reference", "授权依据", "text", item.rights_reference || "", {required: false, maxlength: 500})),
            button("移除此章", () => { if (chapters.childElementCount > 1) group.remove(); }, "quiet danger"));
        if (chapters.childElementCount < 100) chapters.append(group);
    }
    for (const chapter of revision?.chapters || [{}]) addChapter(chapter);
    const inputs = [
        field("title", "版本标题", "text", revision?.title || "", {maxlength: 200}),
        field("summary", "课程简介", "textarea", revision?.summary || "", {required: false, maxlength: 4000}),
        fields(field("minimum_study_seconds", "最低有效学时（秒）", "number", revision?.minimum_study_seconds ?? 600, {min: 0, max: 10000000}),
            field("pass_score", "考试通过线", "number", revision?.pass_score ?? 60, {min: 0, max: 100})),
        field("retake_mode", "补考成绩规则", "select", revision?.retake_mode || "highest", {choices: [["highest", "最高分"], ["latest", "最近一次"]]}),
        chapters, button("添加章节", () => addChapter()),
    ];
    openForm(revision ? "编辑课程草稿" : "新建课程修订", inputs, async (form) => {
        const data = {
            title: form.get("title"), summary: form.get("summary"), minimum_study_seconds: Number(form.get("minimum_study_seconds")),
            pass_score: Number(form.get("pass_score")), retake_mode: form.get("retake_mode"),
            chapters: Array.from(chapters.children, (group) => {
                const value = (name) => group.querySelector(`[name="${name}"]`).value;
                return {title: value("chapter_title"), kind: value("chapter_kind"), content: value("chapter_content"),
                    duration_seconds: Number(value("duration_seconds")), required_seconds: Number(value("required_seconds")),
                    rights_basis: value("rights_basis"), rights_reference: value("rights_reference"), media_url: value("media_url")};
            }),
        };
        const path = `/api/courses/${courseId}/revisions`;
        const saved = revision
            ? await api(`${path}/${revision.id}`, "PUT", {...data, edit_version: revision.edit_version})
            : await api(path, "POST", data);
        return () => showRevision(courseId, saved.id);
    });
}

function metric(label, value) {
    return block("div", "metric", element("span", label), element("strong", value));
}

async function showCohorts(after = 0) {
    await display("cohorts", async () => {
        const page = await api(`/api/cohorts?after=${after}`);
        const rows = page.items.map((cohort) => [button(cohort.name, () => showCohort(cohort.id), "row-link"),
            `${cohort.title} · v${cohort.revision}`, `${cohort.starts_on} – ${cohort.ends_on}`, cohort.capacity, badge(cohort.registration_open ? "open" : "closed")]);
        return [heading("班期与招生", "", manages() ? button("新建班期", () => newCohort(), "primary") : null),
            paginated(["班期", "课程版本", "排期", "容量", "报名状态"], page, rows, "暂无班期。", after, showCohorts)];
    });
}

async function newCohort(binding = null) {
    const courses = (await allPages("/api/courses")).filter((course) => course.revision && (!binding || course.id === binding.step.course_id));
    if (!courses.length) {
        setMessage($("notice"), "发布至少一个课程版本后可以开设班期。", true);
        return;
    }
    openForm("新建班期", [
        ...(binding ? [element("p", `${binding.path.title} · 第 ${binding.step.position} 步`, "meta")] : []),
        fields(field("name", "班期名称", "text", "", {maxlength: 160}), field("code", "班期代码", "text", "", {maxlength: 64})),
        field("course_id", "课程（使用最新发布版本）", "select", binding ? binding.step.course_id : "",
            {choices: [["", "选择已发布课程"], ...courses.map((course) => [course.id, `${course.title} · v${course.revision}`])]}),
        fields(field("starts_on", "开始日期", "date"), field("ends_on", "结束日期", "date")),
        field("capacity", "席位容量", "number", 25, {min: 1, max: 10000}),
        block("div", "checks", field("registration_open", "立即开放报名", "checkbox", true, {required: false})),
    ], async (form) => {
        const data = {name: form.get("name"), code: form.get("code"), course_id: Number(form.get("course_id")),
            starts_on: form.get("starts_on"), ends_on: form.get("ends_on"), capacity: Number(form.get("capacity")), registration_open: form.has("registration_open")};
        if (binding) data.path_step_id = binding.step.id;
        const cohort = await api("/api/cohorts", "POST", data);
        return () => showCohort(cohort.id);
    }, "创建班期");
}

async function showCohort(id, after = 0) {
    await display("cohorts", async () => {
        const cohort = await api(`/api/cohorts/${id}`);
        const actions = element("div", null, "toolbar");
        const sections = [block("div", "surface", badge(cohort.registration_open ? "open" : "closed"),
            block("div", "metrics", metric("占用席位", `${cohort.occupied} / ${cohort.capacity}`), metric("候补人数", cohort.waitlisted)))];
        if (cohort.path) {
            sections[0].append(button(`${cohort.path.title} · 第 ${cohort.path.position} 步`, () => showLearningPath(cohort.path.path_id), "row-link"));
        }
        if (manages()) {
            actions.append(button("调整招生", () => editCohort(cohort)));
            actions.append(button("配置直播出勤", () => showLiveBindings(cohort)));
            actions.append(button("班期作业", () => showCohortCoursework(cohort)), button("教学成员", () => showTeachingStaff(cohort)));
            actions.append(button("考试场次", () => showExamSittings(cohort)));
            if (cohort.registration_open) actions.append(button("代学员报名", () => enrollLearner(cohort.id), "primary"));
            const page = await api(`/api/cohorts/${id}/enrollments?after=${after}`);
            const rows = page.items.map((item) => [item.learner_name, item.learner_id, badge(item.state),
                button(`查看 #${item.id}`, () => showEnrollment(item.id, cohort.id), "row-link")]);
            sections.push(element("h2", "报名名册", "section-heading"),
                paginated(["学员", "成员编号", "状态", "报名回执"], page, rows, "暂时没有报名。", after, (cursor) => showCohort(id, cursor)));
        }
        if (cohort.missing_prerequisites.length) {
            const titles = cohort.missing_prerequisites.map((course) => course.title);
            sections[0].append(element("p", `报名需先完成 ${titles.join("、")}。`, "meta"));
        } else if (actor.roles.includes("learner") && cohort.registration_open) {
            const label = cohort.occupied >= cohort.capacity ? "加入候补" : "立即报名";
            actions.append(button(label, async () => {
                const enrollment = await enroll(cohort.id);
                await showEnrollment(enrollment.id);
            }, "primary"));
        }
        return [back("班期列表", showCohorts), heading(cohort.name, `${cohort.title} · v${cohort.revision} · ${cohort.starts_on} – ${cohort.ends_on}`, actions), ...sections];
    });
}

function editCohort(cohort) {
    openForm("调整招生", [field("capacity", "席位容量", "number", cohort.capacity, {min: 1, max: 10000}),
        block("div", "checks", field("registration_open", "开放报名", "checkbox", cohort.registration_open, {required: false}))], async (form) => {
        const result = await api(`/api/cohorts/${cohort.id}`, "PUT", {version: cohort.version, capacity: Number(form.get("capacity")),
            registration_open: form.has("registration_open")});
        return async () => { await showCohort(cohort.id); success(`招生设置已更新，递补 ${result.promoted_enrollment_ids.length} 人。`); };
    });
}

async function enroll(cohortId, learnerId = null) {
    const scope = `${cohortId}/${learnerId ?? "me"}`;
    if (!enrollmentKeys.has(scope)) {
        enrollmentKeys.set(scope, Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, "0")).join(""));
    }
    const result = await api(`/api/cohorts/${cohortId}/enrollments`, "POST", learnerId === null ? {} : {learner_id: learnerId}, enrollmentKeys.get(scope));
    enrollmentKeys.delete(scope);
    return result;
}

function enrollLearner(cohortId) {
    openForm("代学员报名", [field("learner_id", "学员成员编号", "number", "", {min: 1})], async (form) => {
        const item = await enroll(cohortId, Number(form.get("learner_id")));
        return () => showEnrollment(item.id, cohortId);
    }, "确认报名");
}

async function showMine(after = 0) {
    await display("mine", async () => {
        const page = await api(`/api/me/enrollments?after=${after}`);
        const rows = page.items.map((item) => [button(item.cohort_name, () => showEnrollment(item.id), "row-link"),
            item.cohort_code, badge(item.state), item.enrolled_at]);
        return [heading("我的报名"), paginated(["班期", "班期代码", "状态", "入班时间（UTC）"], page, rows, "你还没有报名。", after, showMine)];
    });
}

async function showEnrollment(id, roster = null) {
    await display(roster ? "cohorts" : "mine", async () => {
        const item = await api(`/api/enrollments/${id}`);
        const cohort = await api(`/api/cohorts/${item.cohort_id}`);
        const actions = element("div", null, "toolbar");
        if (!["waitlisted", "reserved"].includes(item.state)) {
            actions.append(button("学习进度", () => showStudySummary(item, cohort, roster)));
        }
        if (!roster && item.enrolled_at) actions.append(button("作业与评分", () => showMyCoursework(item)));
        if (!roster && item.enrolled_at) actions.append(button("考试与成绩", () => showMyExams(item)));
        if (item.enrolled_at) actions.append(button(roster ? "结业审核" : "结业与证书", () => showCompletion(item, cohort, roster)));
        if (["enrolled", "studying", "completed"].includes(item.state)) {
            actions.append(button("查看课程材料", () => showRevision(cohort.course_id, cohort.revision_id, roster ? null : item.id), "primary"));
        }
        if (["enrolled", "studying"].includes(item.state)) actions.append(button("换班", () => chooseTransfer(item, roster)));
        if (item.transferred_from_id || item.state === "transferred") actions.append(button("换班回执", () => showTransferHistory(item, cohort, roster)));
        if (["waitlisted", "reserved", "enrolled", "studying"].includes(item.state)) {
            actions.append(button(item.state === "waitlisted" ? "取消候补" : "申请退班", () => {
                openForm(item.state === "waitlisted" ? "取消候补" : "确认退班", [element("p", `班期 ${cohort.name} · 报名 #${item.id}`)], async () => {
                    await api(`/api/enrollments/${item.id}/withdraw`, "POST");
                    return async () => { await showEnrollment(item.id, roster); success("报名已撤回，历史回执已保留。"); };
                }, "确认撤回");
            }, "danger"));
        }
        const status = block("div", "surface", badge(item.state), block("div", "metrics", metric("报名编号", `#${item.id}`),
            ...(item.waitlist_position ? [metric("候补顺位", item.waitlist_position)] : []),
            ...(item.transferred_from_id ? [metric("原报名", `#${item.transferred_from_id}`)] : [])));
        return [roster ? back("班期名册", () => showCohort(roster)) : back("我的报名", showMine),
            heading(cohort.name, `${cohort.title} · v${cohort.revision}`, actions), status];
    });
}

async function chooseTransfer(item, roster) {
    const choices = await allPages(`/api/enrollments/${item.id}/transfer-options`);
    if (!choices.length) {
        setMessage($("notice"), "暂时没有开放报名且符合换班条件的班期。", true);
        return;
    }
    const options = choices.map((option) => [option.id, `${option.name} · ${option.title} v${option.revision}`]);
    openForm("选择目标班期", [field("target_cohort_id", "目标班期", "select", "", {choices: [["", "选择班期"], ...options]})], async (form) => {
        const changed = await api(`/api/enrollments/${item.id}/transfer`, "POST", {target_cohort_id: Number(form.get("target_cohort_id"))});
        return async () => { await showEnrollment(changed.id, roster ? changed.cohort_id : null); success(`换班已完成，新报名 #${changed.id}。`); };
    }, "确认换班");
}

async function showAccounts(after = 0) {
    await display("accounts", async () => {
        const page = await api(`/api/accounts?after=${after}`);
        const rows = page.items.map((member) => [member.id, member.name, member.email, member.roles.map((role) => roleLabels[role]).join("、")]);
        return [heading("学习空间成员", "", button("添加成员", newAccount, "primary")),
            paginated(["成员编号", "姓名", "邮箱", "角色"], page, rows, "暂无成员。", after, showAccounts)];
    });
}

function newAccount() {
    const options = ["admin", "registrar", "instructor", "learner"].map((role) => {
        const check = field("roles", roleLabels[role], "checkbox", role === "learner", {required: false});
        check.querySelector("input").value = role;
        return check;
    });
    openForm("添加成员", [field("name", "姓名", "text", "", {maxlength: 160}), field("email", "邮箱", "email", "", {maxlength: 254}),
        field("password", "初始密码（至少 12 字节）", "password", "", {autocomplete: "new-password"}),
        block("fieldset", "", element("legend", "角色"), block("div", "checks", ...options))], async (form) => {
        const account = await api("/api/accounts", "POST", {name: form.get("name"), email: form.get("email"), password: form.get("password"), roles: form.getAll("roles")});
        return async () => { await showAccounts(); success(`已添加成员 ${account.name}，编号 #${account.account_id}。`); };
    }, "添加成员");
}

function showSession(summary) {
    actor = summary.actor;
    $("entry").hidden = Boolean(actor);
    $("workspace").hidden = !actor;
    if (actor) {
        $("identity").textContent = `${actor.name} · ${actor.roles.map((role) => roleLabels[role]).join("、")}`;
        if (!manages() && actor.roles.includes("instructor")) return showTeachingCohorts();
        return actor.roles.includes("learner") && !manages() ? showCohorts() : showCourses();
    }
    activeView++;
    enrollmentKeys.clear();
    courseworkKeys.clear();
    $("content").replaceChildren();
    $("login-form").elements.tenant.focus();
}

$("login-form").addEventListener("submit", (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    void run(form.querySelector("button"), async () => {
        const result = await api("/api/login", "POST", Object.fromEntries(new FormData(form)));
        form.reset();
        setMessage($("notice"), "");
        await showSession(result);
    }, $("login-message"));
});

$("logout").addEventListener("click", () => void run($("logout"), async () => {
    finishStudy();
    await showSession(await api("/api/logout", "POST"));
}));
$("dialog-close").addEventListener("click", () => $("dialog").close());
$("dialog").addEventListener("cancel", (event) => {
    if ($("dialog-submit").disabled) event.preventDefault();
});
$("dialog").addEventListener("close", () => { saveForm = null; $("dialog-fields").replaceChildren(); });
$("dialog-form").addEventListener("submit", (event) => {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const save = saveForm;
    void run($("dialog-submit"), async () => {
        $("dialog-close").disabled = true;
        try {
            const after = await save(data);
            if (after) await after();
            $("dialog").close();
        } finally {
            $("dialog-close").disabled = false;
        }
    }, $("dialog-message"));
});

api("/api/session").then(showSession).catch((error) => {
    $("entry").hidden = false;
    setMessage($("login-message"), errorMessage(error), true);
});

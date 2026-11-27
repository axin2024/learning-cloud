"use strict";

const courseworkApi = "/api/coursework";
const courseworkKeys = new Map();
const teachingDuties = {instructor: "讲师", assistant: "助教"};

function courseworkTime(value) {
    return value.slice(0, 19) + " UTC";
}

function courseworkTimeField(name, label, value) {
    return field(name, label, "datetime-local", value.replace(" ", "T").slice(0, 19), {step: 1});
}

function courseworkUtc(value) {
    return value.length === 16 ? value + ":00Z" : value + "Z";
}

async function showTeachingCohorts(after = 0) {
    await display("teaching", async () => {
        const page = await api(courseworkApi + "/teaching-cohorts?after=" + after);
        const rows = page.items.map((cohort) => [button(cohort.name, () => showCohortCoursework(cohort, true), "row-link"),
            cohort.course_title, teachingDuties[cohort.duty], button("考试场次", () => showExamSittings(cohort, true), "row-link"),
            button("岗位实训", () => showPracticums(cohort, true), "row-link")]);
        return [heading("授课班期"), paginated(["班期", "课程", "职责", "考试", "实训"], page, rows, "暂未分配授课班期。", after, showTeachingCohorts)];
    });
}

async function showTeachingStaff(cohort) {
    await display("cohorts", async () => {
        const path = courseworkApi + "/cohorts/" + cohort.id + "/staff";
        const {items} = await api(path);
        const rows = items.map((member) => [member.name, member.account_id, teachingDuties[member.duty],
            block("div", "toolbar", button("调整职责", () => editTeachingStaff(cohort, member)),
                button("撤销", () => {
                    openForm("撤销教学权限", [element("p", member.name + " · " + cohort.name)], async () => {
                        await api(path + "/" + member.account_id, "PUT", {duty: "none"});
                        return () => showTeachingStaff(cohort);
                    }, "确认撤销");
                }, "danger"))]);
        return [back(cohort.name, () => showCohort(cohort.id)), heading("教学成员", "教学权限只对本班期有效。",
            button("分配讲师或助教", () => editTeachingStaff(cohort), "primary")),
        block("div", "surface", table(["成员", "成员编号", "职责", "操作"], rows, "暂未分配教学成员。"))];
    });
}

function editTeachingStaff(cohort, member = null) {
    const controls = [
        member ? element("p", member.name) : field("account_id", "讲师账号的成员编号", "number", "", {min: 1}),
        field("duty", "班期职责", "select", member?.duty ?? "instructor", {choices: Object.entries(teachingDuties)}),
    ];
    openForm("分配教学职责", controls, async (form) => {
        const account = member?.account_id ?? Number(form.get("account_id"));
        await api(courseworkApi + "/cohorts/" + cohort.id + "/staff/" + account, "PUT", {duty: form.get("duty")});
        return () => showTeachingStaff(cohort);
    });
}

async function showCohortCoursework(cohort, teaching = false, after = 0) {
    await display(teaching ? "teaching" : "cohorts", async () => {
        const page = await api(courseworkApi + "/cohorts/" + cohort.id + "/assignments?after=" + after);
        const rows = page.items.map((task) => [button(task.title, () => showAssignment(task.id, cohort, teaching), "row-link"),
            badge(task.published_at ? "published" : "draft"), courseworkTime(task.due_at), task.required_for_completion ? "必修" : "选修"]);
        return [teaching ? back("授课班期", showTeachingCohorts) : back(cohort.name, () => showCohort(cohort.id)),
            heading("班期作业", cohort.name, manages() ? button("新建作业", () => editAssignment(cohort, teaching), "primary") : null),
            paginated(["作业", "状态", "截止时间", "结业要求"], page, rows, "暂时没有可查看的作业。", after,
                (cursor) => showCohortCoursework(cohort, teaching, cursor))];
    });
}

function courseworkCriteria(items, build, label, limit) {
    const list = block("div", "form-stack");
    const refresh = () => { add.disabled = list.children.length >= limit; };
    function append(item) {
        const entry = build(item);
        entry.append(button("移除", () => { entry.remove(); refresh(); }, "quiet"));
        list.append(entry);
        refresh();
    }
    const add = button(label, () => append({}));
    items.forEach(append);
    refresh();
    return {list, panel: block("div", "form-stack", list, add)};
}

function editAssignment(cohort, teaching, task = null) {
    const rubric = courseworkCriteria(task?.rubric ?? [{label: "", points: 100}], (item) => block("fieldset", "",
        fields(field("criterion", "评分项", "text", item.label ?? "", {maxlength: 140}),
            field("maximum", "满分", "number", item.points ?? "", {min: 1, max: 100}))), "添加评分项", 20);
    openForm(task ? "编辑作业草稿" : "新建作业", [
        field("title", "作业标题", "text", task?.title ?? "", {maxlength: 200}),
        field("instructions", "作业要求", "textarea", task?.instructions ?? "", {maxlength: 16000}),
        fields(courseworkTimeField("due_at", "提交截止时间（UTC）", task?.due_at ?? new Date(Date.now() + 604800000).toISOString()),
            field("grace_minutes", "首次提交宽限（分钟）", "number", task?.grace_minutes ?? 0, {min: 0, max: 10080})),
        fields(field("pass_score", "及格分数", "number", task?.pass_score ?? 60, {min: 0, max: 100}),
            block("div", "checks", field("required", "结业必修", "checkbox", task?.required_for_completion ?? false, {required: false}))),
        block("fieldset", "form-stack", element("legend", "评分规则 · 满分合计 100"), rubric.panel),
    ], async (form) => {
        const points = form.getAll("maximum").map(Number);
        if (points.reduce((sum, value) => sum + value, 0) !== 100) throw new Error("评分项满分合计须为 100 分。");
        const body = {
            title: form.get("title"), instructions: form.get("instructions"), due_at: courseworkUtc(form.get("due_at")),
            grace_minutes: Number(form.get("grace_minutes")), pass_score: Number(form.get("pass_score")), required_for_completion: form.has("required"),
            rubric: form.getAll("criterion").map((label, index) => ({label, points: points[index]})),
        };
        const saved = task ? await api(courseworkApi + "/assignments/" + task.id, "PUT", {...body, version: task.version})
            : await api(courseworkApi + "/cohorts/" + cohort.id + "/assignments", "POST", body);
        return () => showAssignment(saved.id, cohort, teaching);
    });
}

function assignmentGuide(task) {
    return block("section", "surface",
        element("h2", "作业要求"), element("p", task.instructions, "work-text"),
        block("div", "metrics", metric("提交截止", courseworkTime(task.due_at)),
            metric("首次提交宽限", task.grace_minutes + " 分钟"), metric("及格分数", task.pass_score)),
        table(["评分项", "满分"], task.rubric.map((item) => [item.label, item.points]), ""));
}

async function showAssignment(id, cohort, teaching, after = 0) {
    await display(teaching ? "teaching" : "cohorts", async () => {
        const path = courseworkApi + "/assignments/" + id;
        const task = await api(path);
        const actions = element("div", null, "toolbar");
        if (manages() && !task.published_at) {
            actions.append(button("编辑草稿", () => editAssignment(cohort, teaching, task)),
                button("发布作业", () => openForm("发布作业", [element("p", "发布后将固定作业要求、截止时间及评分规则。")], async () => {
                    await api(path + "/publish", "POST", {version: task.version});
                    return () => showAssignment(id, cohort, teaching);
                }, "确认发布"), "primary"));
        }
        const view = [back("班期作业", () => showCohortCoursework(cohort, teaching)), heading(task.title, cohort.name, actions), assignmentGuide(task)];
        if (task.published_at) {
            const page = await api(path + "/submissions?after=" + after);
            const rows = page.items.map((row) => [button(row.learner_name, () => showCourseworkMarking(task, row, cohort, teaching), "row-link"),
                "#" + row.revision, courseworkTime(row.submitted_at), row.late ? "逾期" : "按时", row.score ?? "待批改"]);
            view.push(element("h2", "学员交卷", "section-heading"),
                paginated(["学员", "最新版本", "交卷时间", "时效", "得分"], page, rows, "目前还没有学员交卷。", after,
                    (cursor) => showAssignment(id, cohort, teaching, cursor)));
        }
        return view;
    });
}

async function showMyCoursework(enrollment, after = 0) {
    await display("mine", async () => {
        const path = courseworkApi + "/enrollments/" + enrollment.id + "/assignments";
        const page = await api(path + "?after=" + after);
        const rows = page.items.map((task) => [button(task.title, () => showMyAssignment(enrollment, task.id), "row-link"),
            courseworkTime(task.due_at), task.revision ? "#" + task.revision : "待提交", task.score ?? "—"]);
        return [back("报名详情", () => showEnrollment(enrollment.id)), heading("作业与评分"),
            paginated(["作业", "截止时间", "最近交卷", "最新得分"], page, rows, "班期暂未发布作业。", after,
                (cursor) => showMyCoursework(enrollment, cursor))];
    });
}

async function showMyAssignment(enrollment, id, after = 0) {
    await display("mine", async () => {
        const path = courseworkApi + "/enrollments/" + enrollment.id + "/assignments/" + id;
        const page = await api(path + "?after=" + after);
        const latest = page.items.find((row) => row.revision === page.latest_revision);
        const action = page.can_submit && (!page.latest_revision || latest)
            ? button(page.latest_revision ? "提交新版本" : "提交作业", () => submitCoursework(enrollment, page.assignment, path, latest), "primary")
            : null;
        return [back("作业与评分", () => showMyCoursework(enrollment)), heading(page.assignment.title, "", action),
            assignmentGuide(page.assignment),
            ...(page.blocked_reason ? [element("p", errorLabels[page.blocked_reason], "message")] : []),
            courseworkHistory(page, after, (cursor) => showMyAssignment(enrollment, id, cursor))];
    });
}

function submitCoursework(enrollment, task, path, latest) {
    openForm("提交答卷", [field("answer", "答卷正文", "textarea", latest?.answer ?? "", {maxlength: 32000, rows: 12})], async (form) => {
        const answer = form.get("answer");
        const pending = courseworkKeys.get(path);
        const key = pending?.answer === answer ? pending.key : Array.from(crypto.getRandomValues(new Uint8Array(16)),
            (byte) => byte.toString(16).padStart(2, "0")).join("");
        courseworkKeys.set(path, {answer, key});
        const result = await api(path, "POST", {answer}, key);
        courseworkKeys.delete(path);
        return async () => { await showMyAssignment(enrollment, task.id); success("已收到第 " + result.revision + " 版作业。"); };
    }, "确认交卷");
}

async function showCourseworkMarking(task, learner, cohort, teaching, after = 0) {
    await display(teaching ? "teaching" : "cohorts", async () => {
        const path = courseworkApi + "/assignments/" + task.id + "/submissions/" + learner.enrollment_id;
        const page = await api(path + "?after=" + after);
        const reload = (cursor = after) => showCourseworkMarking(task, learner, cohort, teaching, cursor);
        return [back("学员交卷", () => showAssignment(task.id, cohort, teaching)), heading(learner.learner_name, task.title),
            courseworkHistory(page, after, reload, (row) => {
                const actions = element("div", null, "toolbar");
                if (row.score === null) actions.append(button("批改第 " + row.revision + " 版", () => gradeCoursework(task, row, reload), "primary"));
                if (page.active && row.revision === page.latest_revision) {
                    actions.append(button("授权重交", () => returnCoursework(row, reload)));
                }
                return actions.childElementCount ? actions : null;
            })];
    });
}

function courseworkHistory(page, after, load, actions = () => null) {
    const cards = block("div", "submission-grid", ...page.items.map((row) => {
        const card = block("article", "surface submission-card", element("h3", "第 " + row.revision + " 版"),
            element("p", courseworkTime(row.submitted_at) + (row.late ? " · 逾期交卷" : " · 按时交卷"), "meta"),
            element("pre", row.answer.split("\n").map((line, index) => (index + 1) + "  " + line).join("\n"), "work-text answer"));
        if (row.score !== null) {
            card.append(block("div", "metrics", metric("得分", row.score + " / 100")),
                element("p", "批改人 " + row.marker_name + " · " + courseworkTime(row.graded_at), "meta"));
            if (row.feedback) card.append(element("p", row.feedback, "work-text"));
            card.append(table(["评分项", "得分", "评语"], row.rubric_marks.map((mark, index) => [
                page.assignment.rubric[index].label, mark.points + " / " + page.assignment.rubric[index].points, mark.note]), ""));
            if (row.annotations.length) card.append(element("h4", "行内批注"),
                block("ul", "work-notes", ...row.annotations.map((note) => element("li",
                    "第 " + note.start_line + (note.start_line === note.end_line ? "" : "–" + note.end_line) + " 行 · " + note.note))));
        } else card.append(element("p", "等待批改", "muted"));
        if (row.return_note) {
            card.append(block("div", "message", element("strong", row.return_consumed_by ? "重交授权已使用" : "重交授权"),
                element("p", "有效至 " + courseworkTime(row.return_expires_at)), element("p", row.return_note, "work-text")));
        }
        const controls = actions(row);
        if (controls) card.append(controls);
        return card;
    }));
    const pager = element("div", null, "pager");
    if (after) pager.append(button("回到第一版", () => load(0), "quiet"));
    if (page.next_after !== null) pager.append(button("查看更多版本", () => load(page.next_after)));
    const history = block("section", "", element("h2", "交卷历史", "section-heading"),
        page.items.length ? cards : element("p", "还没有提交记录。", "empty"));
    if (pager.childElementCount) history.append(pager);
    return history;
}

function gradeCoursework(task, row, reload) {
    const lines = row.answer.split("\n").length;
    const annotations = courseworkCriteria([], () => block("fieldset", "form-stack",
        element("legend", "批注"),
        fields(field("start_line", "起始行", "number", 1, {min: 1, max: lines}), field("end_line", "结束行", "number", 1, {min: 1, max: lines})),
        field("annotation", "批注内容", "textarea", "", {maxlength: 2000})), "添加行内批注", 50);
    openForm("批改第 " + row.revision + " 版", [
        element("pre", row.answer.split("\n").map((line, index) => (index + 1) + "  " + line).join("\n"), "work-text answer"),
        ...task.rubric.map((criterion) => block("fieldset", "form-stack", element("legend", criterion.label),
            fields(field("points", "评分（满分 " + criterion.points + "）", "number", "", {min: 0, max: criterion.points}),
                field("mark_note", "评分项评语", "text", "", {required: false, maxlength: 2000})))),
        field("feedback", "总评", "textarea", "", {required: false, maxlength: 4000}), annotations.panel,
    ], async (form) => {
        const notes = form.getAll("mark_note");
        const first = form.getAll("start_line").map(Number);
        const last = form.getAll("end_line").map(Number);
        await api(courseworkApi + "/submissions/" + row.id + "/grade", "POST", {
            marks: form.getAll("points").map((value, index) => ({points: Number(value), note: notes[index]})),
            feedback: form.get("feedback"), annotations: form.getAll("annotation").map((note, index) => ({
                start_line: first[index], end_line: last[index], note,
            })),
        });
        return () => reload();
    }, "发布评分");
}

function returnCoursework(row, reload) {
    openForm("授权重交", [
        courseworkTimeField("expires_at", "授权截止时间（UTC）", new Date(Date.now() + 86400000).toISOString()),
        field("note", "重交要求", "textarea", "", {maxlength: 4000}),
    ], async (form) => {
        await api(courseworkApi + "/submissions/" + row.id + "/return", "POST",
            {expires_at: courseworkUtc(form.get("expires_at")), note: form.get("note")});
        return () => reload();
    }, "确认授权");
}

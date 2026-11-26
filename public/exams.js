"use strict";

const examsApi = "/api/exams";
const examKinds = {single: "单选题", multiple: "多选题", written: "简答题"};
const examPolicies = {highest: "取最高分", latest: "取最近场次成绩"};

async function showExamPapers(course, after = 0) {
    await display("courses", async () => {
        const page = await api(examsApi + "/courses/" + course.id + "/papers?after=" + after);
        const rows = page.items.map((paper) => [button(paper.title, () => showExamPaper(course, paper.id), "row-link"),
            paper.revision, badge(paper.published_at ? "published" : "draft")]);
        return [back(course.slug, () => showCourse(course.id)), heading("课程题库", course.slug,
            button("新建试卷", () => editExamPaper(course), "primary")),
        paginated(["试卷", "版本", "状态"], page, rows, "还没有试卷。", after, (cursor) => showExamPapers(course, cursor))];
    });
}

async function showExamPaper(course, id) {
    await display("courses", async () => {
        const path = examsApi + "/papers/" + id;
        const paper = await api(path);
        const actions = block("div", "toolbar");
        if (paper.published_at) {
            actions.append(button("从此版新建", () => editExamPaper(course, paper, true), "primary"));
        } else {
            actions.append(button("编辑试卷", () => editExamPaper(course, paper)),
                button("发布试卷", () => openForm("发布试卷", [element("p", "发布后将固定题目、抽题规则和评分要点。")], async () => {
                    await api(path + "/publish", "POST", {edit_version: paper.edit_version});
                    return () => showExamPaper(course, id);
                }, "确认发布"), "primary"));
        }
        const pools = paper.pools.map((pool) => block("section", "surface", element("h2", pool.name),
            element("p", "从 " + pool.questions.length + " 题抽取 " + pool.draw + " 题，每题 " + pool.points + " 分", "meta"),
            table(["题干", "题型", "选项或评分要点", "正确选项"], pool.questions.map((question) => [
                element("p", question.prompt, "work-text"), examKinds[question.kind],
                element("p", question.options ? question.options.map((option, index) => (index + 1) + ". " + option).join("\n") : question.guidance, "work-text"),
                question.options ? question.guidance.join("、") : "—",
            ]), "")));
        return [back("课程题库", () => showExamPapers(course)), heading(paper.title, "第 " + paper.revision + " 版", actions), ...pools];
    });
}

function examQuestionFields(question, remove) {
    const kind = field("kind", "题型", "select", question.kind ?? "single", {choices: Object.entries(examKinds)});
    const control = kind.querySelector("select");
    const options = block("div", "form-stack",
        field("options", "选项（每行一个，从 1 编号）", "textarea", question.options?.join("\n") ?? ""),
        field("correct", "正确选项编号（逗号分隔）", "text", question.options ? question.guidance.join(",") : ""));
    const rubric = field("rubric", "评分要点", "textarea", question.kind === "written" ? question.guidance : "", {maxlength: 2500});
    const refresh = () => {
        const written = control.value === "written";
        options.hidden = written;
        options.querySelectorAll("input, textarea").forEach((input) => { input.disabled = written; });
        rubric.hidden = !written;
        rubric.querySelector("textarea").disabled = !written;
    };
    control.addEventListener("change", refresh);
    refresh();
    return block("fieldset", "form-stack exam-question-fields", element("legend", "题目"), kind,
        field("prompt", "题干", "textarea", question.prompt ?? "", {maxlength: 2500}), options, rubric, button("移除题目", remove, "quiet"));
}

function examPoolFields(pool, remove) {
    const questions = block("div", "form-stack");
    function addQuestion(item = {}) {
        const entry = examQuestionFields(item, () => { entry.remove(); add.disabled = false; });
        questions.append(entry);
        add.disabled = questions.children.length >= 100;
    }
    const add = button("添加题目", () => addQuestion());
    (pool.questions ?? [{}]).forEach(addQuestion);
    return block("fieldset", "form-stack exam-pool-fields", element("legend", "抽题分组"),
        field("pool_name", "分组名称", "text", pool.name ?? "", {maxlength: 120}),
        fields(field("draw", "抽题数", "number", pool.draw ?? 1, {min: 1, max: 100}),
            field("points", "每题分数", "number", pool.points ?? 100, {min: 1, max: 100})),
        questions, block("div", "toolbar", add, button("移除分组", remove, "quiet")));
}

function editExamPaper(course, paper = null, clone = false) {
    const list = block("div", "form-stack");
    function addPool(pool = {}) {
        const entry = examPoolFields(pool, () => { entry.remove(); add.disabled = false; });
        list.append(entry);
        add.disabled = list.children.length >= 30;
    }
    const add = button("添加抽题分组", () => addPool());
    (paper?.pools ?? [{}]).forEach(addPool);
    openForm(paper && !clone ? "编辑试卷" : "新建试卷", [
        field("title", "试卷名称", "text", paper?.title ?? "", {maxlength: 200}), list, add,
    ], async (form) => {
        const value = (parent, name) => parent.querySelector('[name="' + name + '"]').value;
        const pools = Array.from(list.children, (pool) => ({
            name: value(pool, "pool_name"), draw: Number(value(pool, "draw")), points: Number(value(pool, "points")),
            questions: Array.from(pool.querySelectorAll(".exam-question-fields"), (question) => {
                const entry = {kind: value(question, "kind"), prompt: value(question, "prompt")};
                if (entry.kind === "written") return {...entry, rubric: value(question, "rubric")};
                return {...entry, options: value(question, "options").split("\n").map((line) => line.trim()).filter(Boolean),
                    correct: value(question, "correct").split(/[,，]/).map((number) => Number(number.trim()))};
            }),
        }));
        const body = {title: form.get("title"), pools};
        const result = paper && !clone
            ? await api(examsApi + "/papers/" + paper.id, "PUT", {...body, edit_version: paper.edit_version})
            : await api(examsApi + "/courses/" + course.id + "/papers", "POST", body);
        return () => showExamPaper(course, result.id);
    });
}

async function showExamSittings(cohort, teaching = false) {
    await display(teaching ? "teaching" : "cohorts", async () => {
        const {items} = await api(examsApi + "/cohorts/" + cohort.id + "/sittings");
        const rows = items.map((item) => [button(item.title, () => showExamRoster(cohort, item.id, teaching), "row-link"),
            item.sequence === 1 ? "首考" : "补考 " + (item.sequence - 1),
            item.paper_title + " · 第 " + item.paper_revision + " 版", courseworkTime(item.opens_at), courseworkTime(item.closes_at),
            examPolicies[item.retake_mode]]);
        return [teaching ? back("授课班期", showTeachingCohorts) : back(cohort.name, () => showCohort(cohort.id)),
            heading("考试场次", cohort.name, manages() ? button("安排考试", () => scheduleExam(cohort, items.at(-1)), "primary") : null),
            block("div", "surface", table(["场次", "类型", "试卷", "开考", "截止", "成绩规则"], rows, "该班期还没有安排考试。"))];
    });
}

async function scheduleExam(cohort, last) {
    const choices = (await allPages(examsApi + "/courses/" + cohort.course_id + "/papers"))
        .filter((paper) => paper.published_at).map((paper) => [paper.id, "第 " + paper.revision + " 版 · " + paper.title]);
    if (!choices.length) {
        setMessage($("notice"), "请先在课程题库发布一版试卷。", true);
        return;
    }
    const now = Date.now();
    const priorClose = last ? Date.parse(last.closes_at.replace(" ", "T").slice(0, 23) + "Z") : now;
    const start = Math.max(now, Math.ceil(priorClose / 1000) * 1000) + 60000;
    openForm(last ? "安排补考" : "安排首考", [
        field("title", "场次名称", "text", "", {maxlength: 200}),
        field("paper_id", "试卷版本", "select", choices.at(-1)[0], {choices}),
        fields(courseworkTimeField("opens_at", "开考时间（UTC）", new Date(start).toISOString()),
            courseworkTimeField("closes_at", "截止时间（UTC）", new Date(start + 3600000).toISOString())),
        field("duration_minutes", "答题时限（分钟）", "number", 60, {min: 1, max: 240}),
    ], async (form) => {
        const sitting = await api(examsApi + "/cohorts/" + cohort.id + "/sittings", "POST", {
            title: form.get("title"), paper_id: Number(form.get("paper_id")), opens_at: courseworkUtc(form.get("opens_at")),
            closes_at: courseworkUtc(form.get("closes_at")), duration_seconds: Number(form.get("duration_minutes")) * 60,
        });
        return () => showExamRoster(cohort, sitting.id, false);
    });
}

async function showExamRoster(cohort, sitting, teaching, after = 0) {
    await display(teaching ? "teaching" : "cohorts", async () => {
        const page = await api(examsApi + "/sittings/" + sitting + "/attempts?after=" + after);
        const rows = page.items.map((attempt) => [
            attempt.submitted_at ? button(attempt.learner_name, () => showExamMarking(cohort, sitting, teaching, attempt), "row-link") : attempt.learner_name,
            attempt.submitted_at ? courseworkTime(attempt.submitted_at) : "答题中",
            attempt.submission_mode === "expired" ? "到时收卷" : attempt.submitted_at ? "主动交卷" : "—",
            attempt.score ?? (attempt.submitted_at ? "待阅卷" : "—"),
        ]);
        return [back("考试场次", () => showExamSittings(cohort, teaching)), heading(page.sitting.title, cohort.name),
            paginated(["学员", "已收卷", "收卷方式", "得分"], page, rows, "暂时没有人开考。", after,
                (cursor) => showExamRoster(cohort, sitting, teaching, cursor))];
    });
}

async function showMyExams(enrollment) {
    await display("mine", async () => {
        const {items, effective_result: result} = await api(examsApi + "/enrollments/" + enrollment.id);
        const rows = items.map((item) => [item.title, item.sequence === 1 ? "首考" : "补考 " + (item.sequence - 1),
            courseworkTime(item.opens_at), courseworkTime(item.closes_at), item.score ?? (item.submitted_at ? "待阅卷" : "—"),
            item.attempt_id ? button(item.submitted_at ? "查看答卷" : "打开答卷", () => showExamAttempt(enrollment, item.attempt_id), "row-link")
                : item.can_start ? button("开始考试", () => confirmExamStart(enrollment, item), "primary") : "暂不可参加",
        ]);
        const view = [back("报名详情", () => showEnrollment(enrollment.id)), heading("考试与成绩")];
        if (result) view.push(block("section", "surface", block("div", "metrics", metric("有效成绩", result.score + " / 100"),
            metric("及格线", result.pass_score), metric("成绩规则", examPolicies[result.retake_mode]), metric("考试结果", result.passed ? "通过" : "未通过"))));
        return [...view, block("div", "surface", table(["场次", "类型", "开考", "截止", "成绩", "答卷"], rows, "目前还没有考试场次。"))];
    });
}

function confirmExamStart(enrollment, item) {
    openForm("开始考试", [element("p", "开考后开始计时。请在 " + Math.ceil(item.duration_seconds / 60)
        + " 分钟内完成，并留意本场截止时间 " + courseworkTime(item.closes_at) + "。")],
    async () => {
        const result = await api(examsApi + "/enrollments/" + enrollment.id + "/sittings/" + item.id + "/start", "POST");
        return () => showExamAttempt(enrollment, result.id);
    }, "确认开考");
}

function examItemCard(item, contents) {
    return block("fieldset", "surface form-stack", element("legend", "第 " + item.ordinal + " 题 · " + examKinds[item.kind] + " · " + item.points + " 分"),
        element("p", item.prompt, "work-text"), ...contents);
}

function examReadOnlyItem(item, marker) {
    const answer = item.options
        ? block("ol", "work-notes", ...item.options.map((option, index) =>
            element("li", option + (item.answer?.includes(index + 1) ? "（已选）" : ""))))
        : element("p", item.answer ?? "未作答", "work-text answer");
    const details = [answer];
    if (item.options && !item.answer) details.push(element("p", "未作答", "muted"));
    if (item.awarded !== undefined && item.awarded !== null) details.push(element("p", "得分 " + item.awarded + " / " + item.points));
    if (marker) details.push(element("p", item.kind === "written" ? "评分要点 · " + item.guidance : "正确选项 · " + item.guidance.join("、"), "work-text"));
    if (item.feedback) details.push(element("p", item.feedback, "work-text"));
    return examItemCard(item, details);
}

function examEditableItem(item) {
    if (item.kind === "written") {
        const label = field("answer", "作答", "textarea", item.answer ?? "", {required: false, maxlength: 5000});
        return {item, node: examItemCard(item, [label]), read: () => label.querySelector("textarea").value.trim() || null};
    }
    const options = block("div", "exam-options", ...item.options.map((option, index) =>
        field("question-" + item.ordinal, (index + 1) + ". " + option, item.kind === "single" ? "radio" : "checkbox", "", {required: false})));
    const controls = Array.from(options.querySelectorAll("input"));
    controls.forEach((input, index) => { input.checked = item.answer?.includes(index + 1) ?? false; });
    const clear = button("清空本题", () => { for (const input of controls) input.checked = false; }, "quiet");
    return {item, node: examItemCard(item, [options, clear]), read: () => {
        const answers = controls.flatMap((input, index) => input.checked ? [index + 1] : []);
        return answers.length ? answers : null;
    }};
}

async function showExamAttempt(enrollment, id) {
    await display("mine", async () => {
        const path = examsApi + "/attempts/" + id;
        const attempt = await api(path);
        const end = back("考试与成绩", () => showMyExams(enrollment));
        const title = heading(attempt.title, "试卷第 " + attempt.paper_revision + " 版 · 收卷截止 " + courseworkTime(attempt.deadline_at));
        if (attempt.can_save) return [end, title, examAnswerSheet(enrollment, path, attempt)];
        const status = attempt.submitted_at
            ? attempt.score === null ? "已交卷，等待阅卷。" : "得分 " + attempt.score + " / 100"
            : "本场已停止答题。";
        const expired = !attempt.submitted_at && attempt.server_time >= attempt.deadline_at;
        const refresh = expired ? button("查看到时收卷结果", async () => {
            try { await api(path + "/submit", "POST", {answer_version: attempt.answer_version}); }
            catch (error) { if (error.result?.error !== "exam_answer_version_conflict") throw error; }
            await showExamAttempt(enrollment, id);
        }) : null;
        return [end, title, block("section", "surface toolbar", element("p", status), ...(refresh ? [refresh] : [])),
            ...attempt.items.map((item) => examReadOnlyItem(item, false))];
    });
}

function examAnswerSheet(enrollment, path, attempt) {
    const view = activeView;
    const items = attempt.items.map(examEditableItem);
    const status = element("p", "当前为服务器已保存的答卷。", "meta");
    const remaining = element("strong", "");
    const timer = Date.parse(attempt.deadline_at.replace(" ", "T").slice(0, 23) + "Z")
        - Date.parse(attempt.server_time.replace(" ", "T").slice(0, 23) + "Z");
    const started = performance.now();
    const initial = new Map(items.map(({item}) => [item.ordinal, JSON.stringify(item.answer)]));
    let version = attempt.answer_version;
    let busy = false;
    const update = async (submit) => {
        if (busy) return;
        busy = true;
        inputs.inert = true;
        try {
            const changes = items.map(({item, read}) => ({ordinal: item.ordinal, answer: read()}))
                .filter(({ordinal, answer}) => JSON.stringify(answer) !== initial.get(ordinal));
            if (changes.length) {
                const saved = await api(path + "/answers", "PUT", {answer_version: version, answers: changes});
                version = saved.answer_version;
                changes.forEach(({ordinal, answer}) => initial.set(ordinal, JSON.stringify(answer)));
            }
            setMessage(status, "已保存答题进度。");
            if (submit) {
                await api(path + "/submit", "POST", {answer_version: version});
                if (panel.isConnected && view === activeView) await showExamAttempt(enrollment, attempt.id);
            }
        } finally {
            busy = false;
            inputs.inert = false;
        }
    };
    const save = button("保存进度", () => update(false));
    const submit = button("交卷", () => openForm("确认交卷", [element("p", "保存当前答案后交卷，交卷后无法再修改。")],
        async () => () => update(true), "确认交卷"), "primary");
    const inputs = block("div", "", ...items.map(({node}) => node));
    const panel = block("section", "", block("div", "surface exam-toolbar", block("div", "toolbar",
        block("span", "", document.createTextNode("剩余 "), remaining), block("div", "toolbar", save, submit)), status), inputs);
    const tick = () => {
        const seconds = Math.max(0, Math.ceil((timer - (performance.now() - started)) / 1000));
        remaining.textContent = Math.floor(seconds / 60) + " 分 " + seconds % 60 + " 秒";
        return seconds;
    };
    tick();
    const interval = window.setInterval(() => {
        if (!panel.isConnected || view !== activeView) {
            window.clearInterval(interval);
            return;
        }
        if (tick() !== 0 || busy) return;
        window.clearInterval(interval);
        inputs.inert = true;
        save.disabled = true;
        submit.disabled = true;
        setMessage(status, "答题时间已结束，正在读取收卷状态。");
        void api(path + "/submit", "POST", {answer_version: version})
            .catch(() => null).then(() => {
                if (panel.isConnected && view === activeView) return showExamAttempt(enrollment, attempt.id);
            });
    }, 500);
    return panel;
}

async function showExamMarking(cohort, sitting, teaching, row) {
    await display(teaching ? "teaching" : "cohorts", async () => {
        const path = examsApi + "/attempts/" + row.id;
        const attempt = await api(path + "/marking");
        const assignment = attempt.marking_assignment;
        const activeAssignment = assignment && assignment.closed_at === null;
        const refresh = () => showExamMarking(cohort, sitting, teaching, row);
        const action = attempt.can_mark ? button("批改简答题", () => {
            const questions = attempt.items.filter((item) => item.kind === "written" && item.answer !== null);
            const controls = questions.map((item) => examItemCard(item, [
                element("p", "评分要点 · " + item.guidance, "work-text"), element("p", item.answer, "work-text answer"),
                fields(field("points", "得分（满分 " + item.points + "）", "number", "", {min: 0, max: item.points}),
                    field("feedback", "阅卷评语", "textarea", "", {required: false, maxlength: 4000})),
            ]));
            openForm("批改简答题", controls, async (form) => {
                const scores = form.getAll("points");
                const notes = form.getAll("feedback");
                const body = {marks: questions.map((item, index) => ({
                    ordinal: item.ordinal, points: Number(scores[index]), feedback: notes[index],
                }))};
                if (activeAssignment) body.marking_assignment_id = assignment.id;
                await api(path + "/grade", "POST", body);
                return refresh;
            }, "公布成绩");
        }, "primary") : null;
        const actions = block("div", "toolbar");
        if (action) actions.append(action);
        if (attempt.can_assign_marking && attempt.marking_candidates.length) {
            actions.append(button(activeAssignment ? "更换阅卷人" : "指派阅卷人", () => {
                const choices = attempt.marking_candidates.map((candidate) => [candidate.id, candidate.name]);
                openForm("指派阅卷人", [field("assigned_to", "阅卷人", "select", choices[0][0], {choices})], async (form) => {
                    await api(path + "/marking-assignment", "PUT", {
                        assigned_to: Number(form.get("assigned_to")), expected_assignment_id: assignment?.id ?? null,
                    });
                    return refresh;
                }, "确认指派");
            }));
        }
        if (attempt.can_assign_marking && activeAssignment) {
            actions.append(button("撤销阅卷指派", () => {
                openForm("撤销阅卷指派", [element("p", `当前阅卷人 ${assignment.assigned_to_name}`)], async () => {
                    await api(path + "/marking-assignment", "PUT", {assigned_to: null, expected_assignment_id: assignment.id});
                    return refresh;
                }, "确认撤销");
            }));
        }
        const status = attempt.score === null ? "待阅卷" : "得分 " + attempt.score + " / 100";
        const content = [back("场次答卷", () => showExamRoster(cohort, sitting, teaching)), heading(row.learner_name, attempt.title + " · " + status,
            actions.childElementCount ? actions : null)];
        if (assignment) {
            const assignmentLabels = {revoked: "已撤销", completed: "已完成"};
            const annotation = `${assignment.assigned_by_name} 指派 ${assignment.assigned_to_name} · ${assignmentLabels[assignment.closed_reason] ?? "待阅卷"}`;
            content.push(block("div", "surface", element("p", annotation, "work-text")));
        }
        return [...content, ...attempt.items.map((item) => examReadOnlyItem(item, true))];
    });
}

"use strict";

async function showEquivalenceSources(course) {
    await display("courses", async () => {
        const rows = course.revisions.filter((revision) => revision.published_at).map((revision) =>
            [button(revision.title, () => showEquivalenceCourses(course, revision), "row-link"), `v${revision.revision}`]);
        return [back("课程版本", () => showCourse(course.id)), heading("选择原课程版本", course.slug),
            block("div", "surface", table(["已发布版本", "版本号"], rows, "发布课程版本后可配置学时等价。"))];
    });
}

async function showEquivalenceCourses(course, source) {
    await display("courses", async () => {
        const choices = (await allPages("/api/courses")).filter((item) => item.revision);
        const rows = choices.map((item) => [
            button(item.title, () => showEquivalenceTargets(course, source, item), "row-link"), item.slug,
        ]);
        return [back("原课程版本", () => showEquivalenceSources(course)), heading("选择目标课程", `${source.title} · v${source.revision}`),
            block("div", "surface", table(["课程", "课程代码"], rows, "暂无已发布课程。"))];
    });
}

async function showEquivalenceTargets(course, source, targetCourse) {
    await display("courses", async () => {
        const target = await api(`/api/courses/${targetCourse.id}`);
        const rows = target.revisions.filter((revision) => revision.published_at && revision.id !== source.id).map((revision) =>
            [button(revision.title, () => showEquivalence(course, source, target, revision), "row-link"), `v${revision.revision}`]);
        return [back("目标课程", () => showEquivalenceCourses(course, source)), heading("选择目标版本", target.slug),
            block("div", "surface", table(["已发布版本", "版本号"], rows, "该课程暂无其他已发布版本。"))];
    });
}

async function showEquivalence(course, source, targetCourse, target) {
    await display("courses", async () => {
        const path = `/api/course-equivalences/${source.id}/${target.id}`;
        const [rule, from, to] = await Promise.all([api(path),
            api(`/api/courses/${course.id}/revisions/${source.id}`), api(`/api/courses/${targetCourse.id}/revisions/${target.id}`)]);
        const chapterNames = new Map([...from.chapters, ...to.chapters].map((chapter) => [chapter.id, chapter.title]));
        const rows = rule.mappings.map((row) => [
            chapterNames.get(row.source_chapter_id), `${row.source_start_second}–${row.source_start_second + row.seconds} 秒`,
            chapterNames.get(row.target_chapter_id), `${row.target_start_second}–${row.target_start_second + row.seconds} 秒`,
        ]);
        const edit = button(rule.id ? "编辑映射" : "配置映射", () => editEquivalence(rule, from, to, async (input) => {
            await api(path, "PUT", input);
            return () => showEquivalence(course, source, targetCourse, target);
        }), "primary");
        const status = rule.id ? `${rule.enabled ? "已启用" : "已停用"} · 映射 v${rule.version}` : "待配置";
        return [back("目标版本", () => showEquivalenceTargets(course, source, targetCourse)), heading("学时等价映射",
            `${source.title} v${source.revision} → ${target.title} v${target.revision}`, edit),
            block("div", "surface", element("h3", status), ...(rule.reason ? [element("p", rule.reason, "work-text")] : []),
                table(["原章节", "原区间", "目标章节", "目标区间"], rows, "还没有可结转的章节。"))];
    });
}

function editEquivalence(rule, source, target, save) {
    const groups = element("div", null, "form-stack");
    const chapterChoices = (version) => [["", "选择章节"], ...version.chapters.map((item) =>
        [item.id, `${item.ordinal}. ${item.title}（${item.duration_seconds} 秒）`])];
    const sourceChoices = chapterChoices(source);
    const targetChoices = chapterChoices(target);
    const limit = Math.min(source.chapters.length, target.chapters.length);
    function addMapping(mapping = {}) {
        if (groups.childElementCount >= limit) return;
        const group = block("fieldset", "form-stack", element("legend", "章节等价区间"),
            fields(field("source_chapter_id", "原章节", "select", mapping.source_chapter_id || "", {choices: sourceChoices}),
                field("target_chapter_id", "目标章节", "select", mapping.target_chapter_id || "", {choices: targetChoices})),
            fields(field("source_start_second", "原起点（秒）", "number", mapping.source_start_second ?? 0, {min: 0, max: 86399}),
                field("target_start_second", "目标起点（秒）", "number", mapping.target_start_second ?? 0, {min: 0, max: 86399}),
                field("seconds", "等价时长（秒）", "number", mapping.seconds ?? "", {min: 1, max: 86400})),
            button("移除此映射", () => { if (groups.childElementCount > 1) group.remove(); }, "quiet danger"));
        groups.append(group);
    }
    for (const mapping of rule.mappings.length ? rule.mappings : [{}]) addMapping(mapping);
    openForm("编辑学时等价映射", [
        element("p", "每章可用于一条映射。换班时只结转原区间内已有的有效学时。", "meta"),
        field("enabled", "允许使用此映射换班", "checkbox", rule.id ? rule.enabled : true, {required: false}),
        field("reason", "等价依据", "textarea", rule.reason, {maxlength: 1000}),
        groups, button("添加章节映射", () => addMapping()),
    ], (form) => save({version: rule.version, enabled: form.has("enabled"), reason: form.get("reason"),
        mappings: Array.from(groups.children, (group) => Object.fromEntries(
            Array.from(group.querySelectorAll("select, input"), (input) => [input.name, Number(input.value)]))),
    }));
}

async function showTransferHistory(item, cohort, roster) {
    await display(roster ? "cohorts" : "mine", async () => {
        const {items} = await api(`/api/enrollments/${item.id}/transfer`);
        const receipts = items.map((record) => {
            const rule = record.equivalence_snapshot;
            const grades = record.grade_snapshot;
            const from = button(`原报名 #${record.source_enrollment_id}`,
                () => showEnrollment(record.source_enrollment_id, roster ? record.source_cohort_id : null), "row-link");
            const to = button(`新报名 #${record.target_enrollment_id}`,
                () => showEnrollment(record.target_enrollment_id, roster ? record.target_cohort_id : null), "row-link");
            const scores = grades ? [
                ...grades.exams.map((exam) => ["考试", exam.title, `第 ${exam.sequence} 场`, exam.score]),
                ...grades.coursework.map((work) => ["作业", work.title, `提交 v${work.revision}`, work.score]),
            ] : [];
            const details = grades ? [
                element("p", `结转 ${record.credited_seconds} 秒 · ${rule ? `等价映射 v${rule.version}` : "同一课程版本"}`, "meta"),
                ...(rule ? [element("p", rule.reason, "work-text")] : []),
                table(["类型", "原报名成绩", "场次或版本", "得分"], scores, "换班时原报名没有已发布成绩。"),
            ] : [element("p", "这次换班早于成绩快照功能上线。原报名保留其学习记录。", "meta")];
            return block("article", "surface", block("div", "toolbar", from, to),
                element("p", `${record.transferred_at} UTC`, "meta"), ...details);
        });
        return [back("报名详情", () => showEnrollment(item.id, roster)), heading("换班回执", cohort.name), ...receipts];
    });
}

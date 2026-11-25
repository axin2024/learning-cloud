"use strict";

async function showLearningPaths(after = 0) {
    await display("paths", async () => {
        const page = await api(`/api/learning-paths?after=${after}`);
        const rows = page.items.map((path) => [button(path.title, () => showLearningPath(path.id), "row-link"),
            path.code, badge(path.published_at ? "published" : "draft")]);
        return [heading("学习路径", "", manages() ? button("新建学习路径", () => editLearningPath(), "primary") : null),
            paginated(["路径", "路径代码", "状态"], page, rows, "暂无学习路径。", after, showLearningPaths)];
    });
}

async function showLearningPath(id) {
    await display("paths", async () => {
        const path = await api(`/api/learning-paths/${id}`);
        const actions = manages() && !path.published_at ? block("div", "toolbar",
            button("编辑路径", () => editLearningPath(path)),
            button("发布路径", async () => {
                await api(`/api/learning-paths/${id}/publish`, "POST", {version: path.version});
                await showLearningPath(id);
                success("学习路径已发布。");
            }, "primary")) : null;
        const learner = actor.roles.includes("learner");
        const rows = path.steps.map((step) => [
            step.position, step.title, ...(learner ? [step.completed ? "已完成" : step.eligible ? "可学习" : "待完成先修课程"] : []),
            path.published_at ? button("查看班期", () => showPathCohorts(path, step), "row-link") : "—",
        ]);
        return [back("学习路径", showLearningPaths), heading(path.title, path.description, actions),
            block("div", "surface", badge(path.published_at ? "published" : "draft"),
                table(["顺序", "课程", ...(learner ? ["我的进度"] : []), "班期"], rows, "暂无步骤。"))];
    });
}

async function showPathCohorts(path, step, after = 0) {
    await display("paths", async () => {
        const page = await api(`/api/learning-paths/${path.id}/steps/${step.id}/cohorts?after=${after}`);
        const rows = page.items.map((cohort) => [button(cohort.name, () => showCohort(cohort.id), "row-link"),
            `v${cohort.revision}`, `${cohort.starts_on} – ${cohort.ends_on}`, badge(cohort.registration_open ? "open" : "closed")]);
        return [back("路径详情", () => showLearningPath(path.id)), heading(step.title, `${path.title} · 第 ${step.position} 步`,
            manages() ? button("为此步骤开班", () => newCohort({path, step}), "primary") : null),
            paginated(["班期", "课程版本", "排期", "报名状态"], page, rows, "这一步尚未开设班期。", after, (cursor) => showPathCohorts(path, step, cursor))];
    });
}

async function editLearningPath(path = null) {
    const courses = (await allPages("/api/courses")).filter((course) => course.revision);
    if (courses.length < 2) {
        setMessage($("notice"), "至少发布两门课程后可配置学习路径。", true);
        return;
    }
    const choices = [["", "选择课程"], ...courses.map((course) => [course.id, course.title])];
    const steps = element("div", null, "form-stack");
    function renumber() {
        Array.from(steps.children).forEach((row, index) => {
            row.querySelector("legend").textContent = `第 ${index + 1} 步`;
        });
    }
    function addStep(course = "") {
        if (steps.childElementCount >= Math.min(courses.length, 30)) return;
        const row = block("fieldset", "form-stack", element("legend"), field("course_id", "课程", "select", course, {choices}));
        row.append(block("div", "toolbar", button("上移", () => {
            if (row.previousElementSibling) steps.insertBefore(row, row.previousElementSibling);
            renumber();
        }, "quiet"), button("下移", () => {
            if (row.nextElementSibling) steps.insertBefore(row.nextElementSibling, row);
            renumber();
        }, "quiet"), button("移除", () => {
            if (steps.childElementCount > 2) row.remove();
            renumber();
        }, "quiet danger")));
        steps.append(row);
        renumber();
    }
    for (const course of path ? path.steps.map((step) => step.course_id) : ["", ""]) addStep(course);
    openForm(path ? "编辑学习路径" : "新建学习路径", [
        ...(path ? [] : [field("code", "路径代码", "text", "", {maxlength: 64})]),
        field("title", "路径名称", "text", path?.title ?? "", {maxlength: 200}),
        field("description", "说明", "textarea", path?.description ?? "", {required: false, maxlength: 4000}),
        element("p", "按学习顺序排列课程。报名后续步骤的班期前，学员须完成前面的课程。", "meta"),
        steps, button("添加步骤", () => addStep()),
    ], async (form) => {
        const data = {title: form.get("title"), description: form.get("description"), course_ids: form.getAll("course_id").map(Number)};
        const saved = path ? await api(`/api/learning-paths/${path.id}`, "PUT", {...data, version: path.version})
            : await api("/api/learning-paths", "POST", {...data, code: form.get("code")});
        return () => showLearningPath(saved.id);
    });
}

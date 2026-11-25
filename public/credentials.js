"use strict";

const credentialsApi = "/api/credentials";
const credentialStates = {valid: "有效", expired: "已到期", revoked: "已撤销"};
const retrainingStates = {pending: "待安排", enrolled: "已关联报名", completed: "复训完成", cancelled: "已取消"};
const completionFailures = {enrollment: "当前报名状态无法签发", study: "必修学时未达标", coursework: "必修作业未全部及格",
    exam: "考试未及格或仍有待评分答卷"};

async function showCompletion(enrollment, cohort, roster) {
    await display(roster ? "cohorts" : "mine", async () => {
        const path = credentialsApi + "/enrollments/" + enrollment.id;
        const review = await api(path);
        const certificate = review.credential;
        const evidence = certificate?.evidence ?? review;
        const action = roster && manages() && !certificate && review.can_issue
            ? button("签发证书", () => issueCredential(enrollment, cohort, roster, path), "primary") : null;
        const view = [back("报名详情", () => showEnrollment(enrollment.id, roster)), heading(roster ? "结业审核" : "结业与证书", cohort.name, action)];
        if (certificate) view.push(credentialCard(certificate, enrollment, cohort, roster));
        else view.push(element("p", review.can_issue ? "学业条件已达标，可由教务签发证书。"
            : review.unmet.map((reason) => completionFailures[reason]).join("、") + "。", "message"));
        const {study, exam, required_assignments: assignments} = evidence;
        view.push(block("section", "surface",
            element("h2", certificate ? "签发时的审核记录" : "学业审核"),
            block("div", "metrics", metric("有效学时", studyDuration(study.studied_seconds)),
                metric("课程最低学时", studyDuration(study.minimum_study_seconds)),
                metric("有效考试成绩", exam ? exam.score + " / 100" : "待完成"),
                metric("考试及格线", exam?.pass_score ?? "—")),
            element("h3", "章节要求", "credential-subheading"),
            table(["章节", "已认定", "最低要求", "结果"], study.chapters.map((row) => [row.title, studyDuration(row.studied_seconds),
                studyDuration(row.required_seconds), row.study_requirement_met ? "达标" : "待完成"]), "没有章节要求。"),
            element("h3", "必修作业", "credential-subheading"),
            table(["作业", "答卷版本", "得分", "及格线", "结果"], assignments.map((row) => [row.title, row.revision ?? "—",
                row.score ?? "—", row.pass_score, row.passed ? "通过" : "待达标"]), "本班没有已发布的必修作业。")));
        return view;
    });
}

function issueCredential(enrollment, cohort, roster, path) {
    openForm("签发证书", [
        element("p", "签发时会重新审核学业条件，并把该报名标记为已完成。"),
        field("valid_days", "有效期（天）", "number", 365, {min: 1, max: 3650}),
    ], async (form) => {
        await api(path + "/issue", "POST", {valid_days: Number(form.get("valid_days"))});
        return () => showCompletion(enrollment, cohort, roster);
    }, "确认签发");
}

function credentialCard(certificate, enrollment, cohort, roster) {
    const link = element("a", "打开公开验真");
    link.href = "/verify?serial=" + encodeURIComponent(certificate.serial);
    link.target = "_blank";
    link.rel = "noopener noreferrer";
    const print = button("打印证书", () => {
        document.body.classList.add("printing-credential");
        try { window.print(); } finally { document.body.classList.remove("printing-credential"); }
    });
    const actions = block("div", "toolbar credential-actions", link, print);
    if (roster && manages() && certificate.status !== "revoked") {
        actions.append(button("撤销证书", () => {
            openForm("撤销证书", [element("p", "公开验真将显示已撤销。请记录内部原因。"),
                field("reason", "撤销原因", "textarea", "", {maxlength: 1000})], async (form) => {
                await api(credentialsApi + "/" + certificate.id + "/revoke", "POST", {reason: form.get("reason")});
                return () => showCompletion(enrollment, cohort, roster);
            }, "确认撤销");
        }, "danger"));
    }
    const entries = [
        ["证书编号", certificate.serial],
        ["签发机构", certificate.issuer_name],
        ["签发日期", courseworkTime(certificate.issued_at)],
        ["有效期限", courseworkTime(certificate.expires_at)],
    ];
    if (certificate.original_serial) entries.push(["原资格编号", certificate.original_serial]);
    const facts = block("dl", "credential-facts", ...entries.flatMap(([label, value]) => [element("dt", label), element("dd", value)]));
    const status = element("span", credentialStates[certificate.status], "state credential-" + certificate.status);
    const card = block("section", "surface credential-printable", block("div", "page-heading",
        block("div", "", element("h2", "学业资格证书"), element("p", certificate.course_title + " · 第 " + certificate.course_revision + " 版")), status),
    element("p", certificate.holder_name, "credential-holder"), facts);
    if (certificate.revocation_reason) card.append(element("p", "撤销原因 · " + certificate.revocation_reason, "credential-private work-text"));
    card.append(actions);
    return card;
}

async function showRetraining(state = "all", after = 0) {
    await display("retraining", async () => {
        const page = await api(credentialsApi + "/retraining?state=" + state + "&after=" + after);
        const filter = block("div", "toolbar", ...Object.entries({all: "全部", ...retrainingStates}).map(([name, label]) => {
            const control = button(label, () => showRetraining(name), state === name ? "primary" : "");
            control.setAttribute("aria-pressed", name === state ? "true" : "false");
            return control;
        }));
        const rows = page.items.map((task) => {
            const controls = block("div", "toolbar");
            if (task.target_enrollment_id) {
                controls.append(button("报名 #" + task.target_enrollment_id,
                    () => showEnrollment(task.target_enrollment_id, manages() ? task.target_cohort_id : null), "row-link"));
            }
            if (manages() && (task.state === "pending" || (task.state === "enrolled" && ["withdrawn", "transferred"].includes(task.target_state)))) {
                controls.append(button(task.state === "pending" ? "关联报名" : "更换报名", () => linkRetraining(task, state, after)));
            }
            const original = button(task.original_serial,
                () => showEnrollment(task.original_enrollment_id, manages() ? task.original_cohort_id : null), "row-link");
            return [task.course_title, ...(manages() ? [task.holder_name] : []), original, courseworkTime(task.due_at),
                retrainingStates[task.state], controls.childElementCount ? controls : "—", task.renewed_serial];
        });
        return [heading("复训任务", "", filter), paginated(["课程", ...(manages() ? ["学员"] : []), "原证书", "到期时间", "状态", "复训报名", "新证书"],
            page, rows, "当前没有符合筛选条件的复训任务。", after, (cursor) => showRetraining(state, cursor))];
    });
}

function linkRetraining(task, state, after) {
    openForm("关联复训报名", [
        element("p", "先为 " + task.holder_name + " 在同一课程的新班期办理报名，再填写该报名编号。"),
        field("enrollment_id", "复训报名编号", "number", "", {min: 1}),
    ], async (form) => {
        await api(credentialsApi + "/retraining/" + task.id + "/enrollment", "PUT", {enrollment_id: Number(form.get("enrollment_id"))});
        return () => showRetraining(state, after);
    });
}

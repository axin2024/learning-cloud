"use strict";

function reportUrl(endpoint, filter, after) {
    const query = new URLSearchParams({after});
    for (const [key, value] of Object.entries(filter)) {
        if (value !== null && value !== "") query.set(key, value);
    }
    return `${endpoint}?${query}`;
}

function reportPeriod(page) {
    return `${page.from} – ${page.to}（UTC）`;
}

function reportFilter(title, page, extra, apply) {
    openForm(title, [
        fields(field("from", "起始日（UTC）", "date", page.from), field("to", "截止日（UTC）", "date", page.to)),
        ...extra,
    ], (form) => () => apply(Object.fromEntries(form)), "应用筛选");
}

function completionPercent(value) {
    return value === null ? "—" : `${value}%`;
}

async function showCourseReports(filter = {}, after = 0) {
    await display("reports", async () => {
        const page = await api(reportUrl("/api/reports/courses", filter, after));
        const criteria = {from: page.from, to: page.to, course_id: page.course_id};
        const filters = button("筛选", async () => {
            const courses = (await allPages("/api/courses")).filter((course) => course.revision);
            reportFilter("筛选课程经营报表", page, [
                field("course_id", "课程", "select", page.course_id ?? "", {required: false,
                    choices: [["", "全部课程"], ...courses.map((course) => [course.id, course.title])]}),
            ], showCourseReports);
        });
        const rows = page.items.map((item) => [
            button(item.name, () => showCohort(item.id), "row-link"), `${item.title} · v${item.revision}`,
            item.capacity, item.enrollments_in_period, item.completions_in_period,
            item.effective_enrollments, item.effective_completions, completionPercent(item.completion_percent),
            item.withdrawals_in_period, item.transfers_in_period,
        ]);
        return [heading("课程经营", reportPeriod(page), filters),
            paginated(["班期", "课程版本", "当前容量", "期间入班", "期间结业", "期末有效", "期末结业", "完成率", "期间退班", "期间转出"],
                page, rows, "暂无匹配的班期。", after, (cursor) => showCourseReports(criteria, cursor))];
    });
}

async function showDepartmentReport(company, filter = {}, after = 0) {
    await display("companies", async () => {
        const endpoint = `${companyUrl(company.id)}/reports/departments`;
        const page = await api(reportUrl(endpoint, filter, after));
        const criteria = {from: page.from, to: page.to, cohort_id: page.cohort_id};
        const actions = block("div", "toolbar", button("筛选", async () => {
            const cohorts = await allPages("/api/cohorts");
            reportFilter("筛选部门报表", page, [
                field("cohort_id", "班期", "select", page.cohort_id ?? "", {required: false,
                    choices: [["", "全部班期"], ...cohorts.map((cohort) => [cohort.id, cohort.name])]}),
            ], (selected) => showDepartmentReport(company, selected));
        }), button("全部报名明细", () => showCompanyReportEnrollments(company, criteria)));
        const rows = page.items.map((item) => [
            button(item.name, () => showCompanyReportEnrollments(company, criteria, item), "row-link"),
            item.enrollments_in_period, item.completions_in_period, item.effective_enrollments, item.effective_learners,
            item.effective_completions, completionPercent(item.completion_percent), item.withdrawals_in_period, item.transfers_in_period,
        ]);
        const title = `${company.name} · ${reportPeriod(page)}`;
        return [back(company.name, () => showCompany(company.id)), heading("部门完成率", title, actions),
            block("div", "surface", block("div", "metrics", metric("期末有效报名", page.totals.effective_enrollments),
                metric("期末有效学员", page.totals.effective_learners))),
            paginated(["部门", "期间入班", "期间结业", "期末有效报名", "期末有效学员", "期末结业", "完成率", "期间退班", "期间转出"],
                page, rows, "暂无部门。", after, (cursor) => showDepartmentReport(company, criteria, cursor))];
    });
}

function reportEnrollmentCells(item) {
    return [item.id, item.learner_name, item.department_name, item.cohort_name, stateLabels[item.state],
        item.enrolled_at, item.closed_at ?? "—", item.exam_score ?? "—"];
}

const reportEnrollmentColumns = ["报名号", "学员", "部门", "班期", "期末状态", "入班时间（UTC）", "结束时间（UTC）", "考试采用分"];

async function showCompanyReportEnrollments(company, period, department = null, view = "all", after = 0) {
    await display("companies", async () => {
        const endpoint = `${companyUrl(company.id)}/reports/enrollments`;
        const filter = {...period, department_id: department?.id ?? null, view};
        const page = await api(reportUrl(endpoint, filter, after));
        const control = field("view", "报名范围", "select", view, {choices: [
            ["all", "全部记录"], ["effective", "期末有效"], ["history", "退班与换班历史"],
        ]});
        control.querySelector("select").addEventListener("change", (event) =>
            void showCompanyReportEnrollments(company, period, department, event.target.value));
        const actions = block("div", "toolbar", control, button("导出 CSV", () => exportReportEnrollments(endpoint, filter)));
        return [back("部门完成率", () => showDepartmentReport(company, period)),
            heading("企业报名明细", `${department?.name ?? company.name} · 截至 ${page.to}（UTC）`, actions),
            paginated(reportEnrollmentColumns, page, page.items.map(reportEnrollmentCells), "暂无匹配的报名。", after,
                (cursor) => showCompanyReportEnrollments(company, period, department, view, cursor))];
    });
}

async function exportReportEnrollments(endpoint, filter) {
    const records = [reportEnrollmentColumns];
    let after = 0;
    do {
        const page = await api(reportUrl(endpoint, filter, after));
        records.push(...page.items.map(reportEnrollmentCells));
        after = page.next_after;
    } while (after !== null);
    const quote = (value) => {
        const cell = String(value ?? "");
        const safe = /^\s*[=+@-]|^[\t\r\n]/.test(cell) ? "'" + cell : cell;
        return '"' + safe.replaceAll('"', '""') + '"';
    };
    const csv = records.map((row) => row.map(quote).join(",")).join("\r\n");
    const url = URL.createObjectURL(new Blob(["\uFEFF", csv], {type: "text/csv;charset=utf-8"}));
    const link = element("a");
    link.href = url;
    link.download = `enterprise-enrollments-${filter.to}.csv`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

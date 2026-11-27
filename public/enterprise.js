"use strict";

const companyUrl = (id) => `/api/companies/${id}`;

async function showCompanies(after = 0) {
    await display("companies", async () => {
        const page = await api(`/api/companies?after=${after}`);
        const rows = page.items.map((company) => [button(company.name, () => showCompany(company.id), "row-link"), company.code]);
        return [heading("企业购课", "", actor.roles.includes("admin") ? button("新建企业", newCompany, "primary") : null),
            paginated(["企业", "企业代码"], page, rows, "暂无企业。", after, showCompanies)];
    });
}

function newCompany() {
    openForm("新建企业", [field("name", "企业名称", "text", "", {maxlength: 160}), field("code", "企业代码", "text", "", {maxlength: 64})], async (form) => {
        const company = await api("/api/companies", "POST", Object.fromEntries(form));
        return () => showCompany(company.id);
    }, "创建企业");
}

async function showCompany(id, after = 0) {
    await display("companies", async () => {
        const url = companyUrl(id);
        const [company, page] = await Promise.all([api(url), api(`${url}/purchases?after=${after}`)]);
        const actions = block("div", "toolbar", button("部门与名单", () => showCompanyLearners(company)),
            button("部门完成率", () => showDepartmentReport(company)), button("岗位能力盘点", () => showCompetencyPlans(company)));
        if (actor.roles.includes("admin")) {
            actions.append(button("企业管理员", () => showCompanyAdmins(company)), button("登记采购", () => newPurchase(company), "primary"));
        }
        const rows = page.items.map((pool) => [button(pool.contract_code, () => showPool(company, pool.id), "row-link"), pool.course_id,
            `${pool.starts_on} – ${pool.ends_on}`, pool.total]);
        return [back("企业列表", showCompanies), heading(company.name, company.code, actions),
            element("h2", "采购合同", "section-heading"),
            paginated(["合同编号", "课程编号", "有效期（UTC）", "采购席位"], page, rows, "暂无采购合同。", after, (cursor) => showCompany(id, cursor))];
    });
}

async function showCompanyAdmins(company, after = 0) {
    await display("companies", async () => {
        const url = `${companyUrl(company.id)}/administrators`;
        const page = await api(`${url}?after=${after}`);
        const change = async (id, enabled) => {
            await api(`${url}/${id}`, "PUT", {enabled});
            await showCompanyAdmins(company, enabled ? 0 : after);
            success(enabled ? "企业管理权限已授予。" : "企业管理权限已收回。");
        };
        const grant = () => openForm("添加企业管理员", [field("account_id", "成员编号", "number", "", {min: 1})], async (form) => {
            const id = Number(form.get("account_id"));
            return () => change(id, true);
        }, "授予权限");
        const rows = page.items.map((admin) => [admin.id, admin.name, button("收回权限", () => {
            openForm("收回企业管理权限", [element("p", admin.name)], async () => () => change(admin.id, false), "确认收回");
        }, "danger")]);
        return [back(company.name, () => showCompany(company.id)), heading("企业管理员", company.name, button("添加企业管理员", grant, "primary")),
            paginated(["成员编号", "姓名", "操作"], page, rows, "暂无管理员。", after, (cursor) => showCompanyAdmins(company, cursor))];
    });
}

async function showCompanyLearners(company, after = 0) {
    await display("companies", async () => {
        const url = companyUrl(company.id);
        const [departments, page] = await Promise.all([allPages(`${url}/departments`), api(`${url}/learners?after=${after}`)]);
        const departmentNames = new Map(departments.map((department) => [department.id, department.name]));
        const actions = block("div", "toolbar", button("新建部门", () => newDepartment(company)));
        if (actor.roles.includes("admin") && departments.length) actions.append(button("导入学员名单", () => addCompanyLearners(company, departments), "primary"));
        const rows = page.items.map((item) => [item.learner_id, item.name, departmentNames.get(item.department_id), item.department_id]);
        return [back(company.name, () => showCompany(company.id)), heading("部门与名单", company.name, actions),
            element("h2", "部门", "section-heading"), block("div", "surface", table(["部门编号", "部门", "部门代码"],
                departments.map((department) => [department.id, department.name, department.code]), "暂无部门。")),
            element("h2", "学员", "section-heading"), paginated(["学员编号", "姓名", "部门", "部门编号"], page, rows, "暂无学员。", after,
                (cursor) => showCompanyLearners(company, cursor))];
    });
}

function newDepartment(company) {
    openForm("新建部门", [field("name", "部门名称", "text", "", {maxlength: 160}), field("code", "部门代码", "text", "", {maxlength: 64})], async (form) => {
        await api(`${companyUrl(company.id)}/departments`, "POST", Object.fromEntries(form));
        return () => showCompanyLearners(company);
    }, "创建部门");
}

function addCompanyLearners(company, departments, retry = null) {
    openForm(retry ? "修正所选名单" : "导入学员名单", [field("department_id", "归属部门", "select", retry?.department ?? departments[0].id,
        {choices: departments.map((item) => [item.id, item.name])}),
        field("ids", "学员编号，每行一个", "textarea", retry?.ids.join("\n") ?? "", {maxlength: 16000})], async (form) => {
        const ids = form.get("ids").trim().split(/\r?\n/).map((value) => parseMemberId(value));
        const department = Number(form.get("department_id"));
        const response = await api(`${companyUrl(company.id)}/learners`, "POST", {department_id: department, learner_ids: ids});
        return () => showCompanyLearnerImport(company, departments, {department, ids, items: response.items, selected: new Set(), previous: retry?.previous});
    }, "导入名单");
}

async function showCompanyLearnerImport(company, departments, result) {
    await display("companies", async () => {
        const actions = block("div", "toolbar");
        if (result.previous) actions.append(button("上一批导入结果", () => showCompanyLearnerImport(company, departments, result.previous)));
        const retry = button("修正所选行", () => addCompanyLearners(company, departments, {
            department: result.department,
            ids: result.items.filter((item) => result.selected.has(item.row)).map((item) => result.ids[item.row - 1]),
            previous: result,
        }), "primary");
        retry.disabled = !result.selected.size;
        const columns = ["行号", "学员编号", "结果", "原因"];
        const rejected = result.items.some((item) => item.outcome === "rejected");
        if (rejected) {
            actions.append(retry);
            columns.push("选择修正");
        }
        const rows = result.items.map((item) => {
            const cells = [item.row, item.learner_id ?? result.ids[item.row - 1],
                {added: "已加入", duplicate: "已在名单", rejected: "未加入"}[item.outcome], errorLabels[item.reason] ?? item.reason];
            if (rejected) cells.push(item.outcome === "rejected" ? selectBatchRow(item.row, result.selected, retry) : null);
            return cells;
        });
        return [back("部门与名单", () => showCompanyLearners(company)), heading("名单导入结果", company.name, actions.childElementCount ? actions : null),
            block("div", "surface", table(columns, rows, "暂无结果。"))];
    });
}

function selectBatchRow(row, selected, action) {
    const check = element("input");
    check.type = "checkbox";
    check.setAttribute("aria-label", `选择第 ${row} 行`);
    check.checked = selected.has(row);
    check.addEventListener("change", () => {
        if (check.checked) selected.add(row);
        else selected.delete(row);
        action.disabled = !selected.size;
    });
    return check;
}

async function newPurchase(company) {
    const courses = (await allPages("/api/courses")).filter((course) => course.revision);
    if (!courses.length) {
        setMessage($("notice"), "请先发布可采购的课程。", true);
        return;
    }
    openForm("登记采购", [field("contract_code", "合同编号", "text", "", {maxlength: 64}),
        field("course_id", "采购课程", "select", courses[0].id, {choices: courses.map((course) => [course.id, course.title])}),
        fields(field("starts_on", "合同生效日期", "date"), field("ends_on", "合同截止日期", "date")),
        field("total", "采购席位", "number", 100, {min: 1, max: 100000})], async (form) => {
        const body = {...Object.fromEntries(form), course_id: Number(form.get("course_id")), total: Number(form.get("total"))};
        const pool = await api(`${companyUrl(company.id)}/purchases`, "POST", body);
        return () => showPool(company, pool.id);
    }, "保存采购");
}

async function showPool(company, id, after = 0) {
    await display("companies", async () => {
        const url = `${companyUrl(company.id)}/purchases/${id}`;
        const [pool, page] = await Promise.all([api(url), api(`${url}/assignments?after=${after}`)]);
        const actions = block("div", "toolbar", button("分配回执", () => showSeatBatches(company, pool)));
        if (pool.active) actions.append(button("批量分配", () => assignSeats(company, pool), "primary"));
        const items = page.items.map((seat) => [seat.seat_number, seat.department_name, seat.learner_id, seat.cohort_name, badge(seat.state),
            seat.state === "assigned" ? button("回收", () => reclaimSeat(company, pool, seat, after), "row-link") : null]);
        return [back(company.name, () => showCompany(company.id)), heading(pool.contract_code, `${pool.starts_on} – ${pool.ends_on}（UTC）`, actions),
            block("div", "surface", block("div", "metrics", metric("采购席位", pool.total), metric("剩余席位", pool.available),
                metric("合同状态", pool.active ? "有效期内" : "有效期外"))), element("h2", "部门占用", "section-heading"),
            block("div", "surface", table(["部门", "待激活", "已激活"], pool.departments.map((dept) => [dept.department_name, dept.assigned, dept.activated]), "暂无部门。")),
            element("h2", "席位明细", "section-heading"), paginated(["席位号", "部门", "学员编号", "班期", "状态", "操作"], page, items, "暂无席位。", after,
                (cursor) => showPool(company, id, cursor))];
    });
}

function parseMemberId(value) {
    const literal = value.trim();
    return /^[1-9][0-9]*$/.test(literal) && Number.isSafeInteger(Number(literal)) ? Number(literal) : literal;
}

async function assignSeats(company, pool) {
    const cohorts = (await allPages("/api/cohorts")).filter((cohort) => cohort.course_id === pool.course_id);
    if (!cohorts.length) {
        setMessage($("notice"), "请先为合同课程开设班期。", true);
        return;
    }
    let pending = null;
    openForm("批量分配", [field("cohort_id", "分配班期", "select", cohorts[0].id, {choices: cohorts.map((cohort) => [cohort.id, cohort.name])}),
        field("rows", "每行填写部门编号,学员编号", "textarea", "", {maxlength: 32000})], async (form) => {
        const rows = form.get("rows").trim().split(/\r?\n/).map((line) => {
            const values = line.split(/[,，]/).map(parseMemberId);
            return values.length === 2 ? {department_id: values[0], learner_id: values[1]} : {};
        });
        const body = {cohort_id: Number(form.get("cohort_id")), rows};
        const signature = JSON.stringify(body);
        if (pending?.signature !== signature) pending = {signature, key: crypto.randomUUID()};
        const result = await api(`${companyUrl(company.id)}/purchases/${pool.id}/batches`, "POST", body, pending.key);
        return () => showSeatBatch(company, pool, result.id);
    }, "提交分配");
}

async function showSeatBatches(company, pool, after = 0) {
    await display("companies", async () => {
        const page = await api(`${companyUrl(company.id)}/purchases/${pool.id}/batches?after=${after}`);
        const rows = page.items.map((item) => [button(`#${item.id}`, () => showSeatBatch(company, pool, item.id), "row-link"),
            item.created_at, item.actor_id, item.row_count]);
        return [back(pool.contract_code, () => showPool(company, pool.id)), heading("分配回执", company.name),
            paginated(["回执编号", "提交时间（UTC）", "操作者编号", "行数"], page, rows, "暂无回执。", after, (cursor) => showSeatBatches(company, pool, cursor))];
    });
}

async function showSeatBatch(company, pool, id, selected = new Set()) {
    await display("companies", async () => {
        const url = `${companyUrl(company.id)}/purchases/${pool.id}/batches/${id}`;
        const receipt = await api(url);
        const actions = block("div", "toolbar", button("返回合同", () => showPool(company, pool.id)));
        if (receipt.retry_of) {
            const original = new Set(receipt.items.map((item) => item.source_row));
            actions.append(button(`来源回执 #${receipt.retry_of}`, () => showSeatBatch(company, pool, receipt.retry_of, original)));
        }
        const columns = ["行号", "部门编号", "学员编号", "结果", "席位号", "原因"];
        if (receipt.retry_of) columns.splice(1, 0, "来源行号");
        const rows = receipt.items.map((item) => {
            const cells = [item.row, item.department_id, item.learner_id,
                item.outcome === "assigned" ? "已分配" : stateLabels[item.outcome], item.seat_number, errorLabels[item.reason] ?? item.reason];
            if (receipt.retry_of) cells.splice(1, 0, item.source_row);
            return cells;
        });
        const canRetry = (item) => item.outcome === "rejected" && item.department_id != null && item.learner_id != null;
        if (pool.active && receipt.items.some(canRetry)) {
            let pending;
            const submit = button("重试所选行", async () => {
                const rows = [...selected].sort((left, right) => left - right);
                const signature = rows.join(",");
                if (pending?.signature !== signature) pending = {signature, key: crypto.randomUUID()};
                submit.disabled = true;
                checks.forEach((check) => { check.disabled = true; });
                try {
                    const result = await api(`${url}/retry`, "POST", {rows}, pending.key);
                    await showSeatBatch(company, pool, result.id);
                } finally {
                    submit.disabled = !selected.size;
                    checks.forEach((check) => { check.disabled = false; });
                }
            }, "primary");
            const checks = [];
            selected = new Set(receipt.items.filter((item) => canRetry(item) && selected.has(item.row)).map((item) => item.row));
            submit.disabled = !selected.size;
            columns.push("选择重试");
            rows.forEach((cells, index) => {
                const item = receipt.items[index];
                if (!canRetry(item)) { cells.push(null); return; }
                const check = selectBatchRow(item.row, selected, submit);
                checks.push(check);
                cells.push(check);
            });
            actions.append(submit);
        }
        return [back("分配回执", () => showSeatBatches(company, pool)), heading(`分配回执 #${id}`, `${pool.contract_code} · ${receipt.created_at} UTC`,
            actions), block("div", "surface", metric("提交后剩余席位", receipt.available_at_commit)),
            block("div", "surface", table(columns, rows, "暂无分配结果。"))];
    });
}

function reclaimSeat(company, pool, seat, after) {
    openForm("回收未用席位", [element("p", `席位 #${seat.seat_number} · 学员 #${seat.learner_id} · ${seat.cohort_name}`)], async () => {
        await api(`${companyUrl(company.id)}/purchases/${pool.id}/assignments/${seat.id}/reclaim`, "POST");
        return async () => { await showPool(company, pool.id, after); success("未用席位已回收。"); };
    }, "确认回收");
}

async function showMySeats(after = 0) {
    await display("my-seats", async () => {
        const page = await api(`/api/me/seat-assignments?after=${after}`);
        const rows = page.items.map((seat) => {
            let action = null;
            if (seat.state === "assigned") action = button("激活席位", () => activateSeat(seat), "row-link");
            if (seat.state === "activated") action = button("查看报名回执", () => showEnrollment(seat.enrollment_id), "row-link");
            return [seat.company_name, seat.department_name, seat.cohort_name, `${seat.starts_on} – ${seat.ends_on}`, badge(seat.state), action];
        });
        return [heading("企业席位"), paginated(["企业", "部门", "班期", "有效期（UTC）", "状态", "操作"], page, rows, "暂无企业席位。", after, showMySeats)];
    });
}

function activateSeat(seat) {
    openForm("激活企业席位", [element("p", `${seat.company_name} · ${seat.cohort_name}`)], async () => {
        const result = await api(`/api/seat-assignments/${seat.id}/activate`, "POST");
        return async () => { await showEnrollment(result.enrollment_id); success("企业席位已激活，报名已生成。"); };
    }, "确认激活");
}

"use strict";

function practicumForm(title, controls, path, method, payload, refresh) {
    const key = crypto.randomUUID();
    openForm(title, controls, async (data) => {
        const receipt = await api(path, method, await payload(data), key);
        return () => refresh(receipt);
    });
}

function showPracticums(cohort, teaching, after = 0) {
    const base = "/api/competency/cohorts/" + cohort.id;
    const refresh = () => showPracticums(cohort, teaching);
    return display("cohorts", async () => {
        const home = back(cohort.name, () => teaching && !manages() ? showTeachingCohorts() : showCohort(cohort.id));
        let rubric;
        try {
            rubric = await api(base + "/rubric");
        } catch (error) {
            if (error.result?.error !== "cohort_rubric_not_found") throw error;
            const controls = element("div", null, "toolbar");
            if (manages()) controls.append(button("发布岗位标准", () => editPracticumRubric(refresh)),
                button("选择并冻结评分标准", async () => {
                    const choices = (await allPages("/api/competency/rubrics")).map(r => [r.id, r.title + " · v" + r.revision]);
                    if (!choices.length) return setMessage($("notice"), "请先发布岗位标准。");
                    practicumForm("冻结本班评分标准", [field("id", "岗位标准版本", "select", choices[0][0], {choices})],
                        base + "/rubric", "PUT", f => ({rubric_id: Number(f.get("id"))}), refresh);
                }));
            return [home, heading("岗位实训", cohort.name, controls), element("p", "当前账号暂无可查看的班期评分标准。")];
        }
        const page = await api(base + "/practicums?after=" + after);
        const controls = element("div", null, "toolbar");
        if (manages()) controls.append(button("发布替代标准", async () => {
            const versions = (await allPages("/api/competency/rubrics")).filter(v => v.code === rubric.code);
            const latest = versions.reduce((a, b) => a.revision > b.revision ? a : b);
            editPracticumRubric(refresh, await api("/api/competency/rubrics/" + latest.id));
        }));
        if (teaching) controls.append(button("发布实训任务", () => practicumForm("发布任务", [
            field("title", "任务名称"), field("instructions", "任务书", "textarea"), field("sample", "参考样例", "textarea"),
            courseworkTimeField("due", "截止时刻（UTC）", "")], base + "/practicums", "POST",
            f => ({title: f.get("title"), instructions: f.get("instructions"), sample: f.get("sample"), due_at: courseworkUtc(f.get("due"))}),
            result => showPracticumTask(cohort, teaching, result.practicum_id)), "primary"));
        return [home, heading("岗位实训", rubric.title + " · " + rubric.level_name + " · v" + rubric.rubric_rev, controls),
            table(["能力维度", "权重", "门槛", "证据要求"], rubric.dimensions.map(d =>
                [d.name, d.weight_pct + "%", d.mandatory ? d.minimum_score : "选评", d.evidence_requirement]), ""),
            paginated(["任务", "截止时刻", "任务书版本"], page, page.items.map(t =>
                [button(t.title, () => showPracticumTask(cohort, teaching, t.id), "row-link"), courseworkTime(t.due_at), t.brief_rev]),
                "尚未发布实训任务。", after, cursor => showPracticums(cohort, teaching, cursor))];
    });
}

function editPracticumRubric(refresh, prior = null) {
    const dimensions = element("div", null, "form-stack");
    function append(d = {}) {
        const row = block("fieldset", "form-stack", element("legend", "能力维度"),
            fields(field("code", "维度代码", "text", d.code), field("parent", "上级维度代码", "text", d.parent_code || "", {required: false})),
            field("name", "维度名称", "text", d.name), fields(field("weight", "权重（%）", "number", d.weight_pct ?? 100, {min: 1, max: 100}),
                field("minimum", "达标分", "number", d.minimum_score ?? 60, {min: 0, max: 100})),
            field("mandatory", "必须达标", "select", d.mandatory === 0 ? "0" : "1", {choices: [["1", "是"], ["0", "否"]]}),
            fields(field("kind", "证据形式", "select", d.evidence_kind || "document", {choices: [["document","文档"],["image","图片"],["video","视频"]]}),
                field("scope", "归属", "select", d.evidence_scope || "personal", {choices: [["personal","个人"],["team","团队"]]})),
            field("requirement", "证据要求", "textarea", d.evidence_requirement));
        row.append(button("移除维度", () => { if (dimensions.childElementCount > 1) row.remove(); }));
        dimensions.append(row);
    }
    (prior?.dimensions ?? [{}]).forEach(append);
    practicumForm("发布岗位标准", [field("standard", "岗位代码", "text", prior?.code || "", prior ? {readonly: true} : {}),
        field("title", "标准名称", "text", prior?.title), field("level", "岗位等级", "text", prior?.level_name),
        fields(field("pass", "通过分数", "number", prior?.passing_score ?? 60, {min: 0, max: 100}),
            field("gap", "仲裁分差阈值", "number", prior?.arbitration_gap ?? 15, {min: 0, max: 100})),
        dimensions, button("添加维度", () => append())], "/api/competency/rubrics", "POST", f => {
            const col = key => f.getAll(key);
            return {code: f.get("standard"), title: f.get("title"), level: f.get("level"), previous_revision: prior?.rubric_rev ?? 0,
                passing_score: Number(f.get("pass")), arbitration_gap: Number(f.get("gap")),
                dimensions: col("code").map((code, i) => ({code, parent_code: col("parent")[i] || null, name: col("name")[i],
                    weight_pct: Number(col("weight")[i]), minimum_score: Number(col("minimum")[i]), mandatory: col("mandatory")[i] === "1",
                    evidence_kind: col("kind")[i], evidence_scope: col("scope")[i], evidence_requirement: col("requirement")[i]}))};
        }, refresh);
}

function showPracticumTask(cohort, teaching, id) {
    const path = "/api/practicums/" + id;
    const refresh = () => showPracticumTask(cohort, teaching, id);
    return display("cohorts", async () => {
        const task = await api(path);
        const own = task.assignments.find(a => a.learner_id === actor.account_id);
        const controls = element("div", null, "toolbar");
        if (task.can_assign) controls.append(button("分派任务和材料", () => assignPracticum(path, refresh)));
        const nodes = [back("岗位实训", () => showPracticums(cohort, teaching)), heading(task.title, "截止 " + courseworkTime(task.due_at), controls),
            block("div", "surface", element("h2", "任务书"), element("p", task.instructions), element("h3", "参考样例"), element("p", task.sample)),
            table(["小组", "成员编号", "职责", "独立材料", "最新贡献"], task.assignments.map(a =>
                [a.team_name, a.learner_id, a.responsibility, a.material ?? "—", a.contribution ?? (a.revision ? "已记录" : "待记录")]), "暂无分派。")];
        const latest = own ? Math.max(0, ...task.submissions.filter(s => s.team_id === own.team_id).map(s => s.attempt)) : 0;
        if (own) {
            const team = path + "/teams/" + own.team_id;
            controls.append(button("记录个人贡献", () => practicumForm("个人贡献", [field("body", own.responsibility, "textarea", own.contribution || "")],
                team + "/contribution", "PUT", f => ({body: f.get("body"), previous_revision: own.revision || 0}), refresh)),
                button("上传证据", async () => editPracticumArtifact(team, (await api("/api/competency/cohorts/" + cohort.id + "/rubric")).dimensions, refresh)),
                button("提交小组作品", () => selectPracticumArtifacts(path, team, own.team_id, task.rubric_rev, latest + 1, refresh), "primary"),
                button("申请补交", () => practicumForm("申请第 " + (latest + 1) + " 次补交", [field("reason", "补交原因", "textarea")],
                    team + "/extension", "POST", f => ({attempt: latest + 1, reason: f.get("reason")}), refresh)));
        }
        if (task.extensions.length) nodes.push(element("h2", "补交申请"), table(["小组编号", "提交次数", "原因", "审批"], task.extensions.map(r =>
            [r.team_id, r.attempt, r.reason, r.approved_by ? courseworkTime(r.until_at) : task.can_assign ? button("批准补交", () =>
                practicumForm("批准补交", [courseworkTimeField("until", "补交截止时刻（UTC）", "")],
                    path + "/teams/" + r.team_id + "/extension", "PUT", f => ({attempt: r.attempt, until_at: courseworkUtc(f.get("until"))}), refresh)) : "待审批"]), ""));
        if (task.submissions.length) nodes.push(element("h2", "已冻结的交付记录"), table(["小组编号", "提交次数", "交付时间", "回执"], task.submissions.map(s =>
            [s.team_id, s.attempt, courseworkTime(s.submitted_at) + (s.late ? " · 迟交" : ""), button("查看证据与差异", async () => {
                const r = await api(path + "/teams/" + s.team_id + "/submissions/" + s.attempt);
                await display("cohorts", async () => [back(task.title, refresh), heading("第 " + s.attempt + " 次交付", r.submitted_at),
                    element("p", "清单摘要 " + r.manifest_digest), element("p", "本次新增 " + r.changes.added.length + " 项，移除 " + r.changes.removed.length + " 项"),
                    table(["维度代码", "证据编号", "文件摘要", "作品"], r.evidence_manifest.artifacts.map(a =>
                        [a.dimension_code, a.artifact_id, a.digest, task.can_assign || a.scope === "team" || a.assignment_id === own?.assignment_id
                            ? button("下载作品", () => downloadPracticumArtifact(path, s.team_id, a)) : "个人材料"]), ""),
                    table(["分工编号", "待补证据"], r.missing_evidence.map(m => [m.assignment_id, m.name]), "必交证据齐全。"),
                    ...(manages() || own ? [button("评审与定分", () => showPracticumCases(r, refresh))] : [])]);
            })]), ""));
        return nodes;
    });
}

const practicumReviewStates = {reviewing: "待双评", arbitration: "待裁决", scored: "已定分"};
const practicumSeatLabels = {first: "第一评委", second: "第二评委", arbiter: "裁决评委"};

function showPracticumInbox(after = 0) {
    return display("panel", async () => {
        const page = await api("/api/practicum-reviews/inbox?after=" + after);
        return [heading("匿名评审"), paginated(["评审编号", "席位", "状态"], page, page.items.map(row =>
            [button("#" + row.review_id, () => showPracticumBallot(row.case_id)), practicumSeatLabels[row.seat],
                row.locked_at ? "已锁定" : "待评"]), "没有分派给你的评审。", after, showPracticumInbox)];
    });
}

function showPracticumBallot(id) {
    const path = "/api/practicum-reviews/cases/" + id + "/ballot";
    return display("panel", async () => {
        const ballot = await api(path);
        const names = new Map(ballot.dimensions.map(d => [d.code, d.name]));
        const rows = ballot.packet.filter(e => names.has(e.dimension_code)).map(e =>
            [names.get(e.dimension_code), Object.entries(e.anchor).map(([k,v]) => k + "=" + v).join(" "),
                e.preview ? block("div", "form-stack", element("p", e.text), reviewPreview(e.preview)) : e.text]);
        const vote = () => {
            const fields = ballot.dimensions.map(d => block("fieldset", "form-stack", element("legend", d.name),
                element("p", d.evidence_requirement), field("score", "得分", "number", "", {min:0,max:100}),
                field("reason", "评分理由", "textarea")));
            practicumForm("锁定独立评分", fields, path, "PUT", f => ({scores: ballot.dimensions.map((d,i) =>
                ({code:d.code, score:Number(f.getAll("score")[i]), reason:f.getAll("reason")[i]}))}), () => showPracticumBallot(id));
        };
        return [back("评审列表", showPracticumInbox), heading("匿名评审 #" + ballot.review_id, practicumSeatLabels[ballot.seat] + " · 量规 v" + ballot.rubric_rev,
            ballot.locked_at ? null : button("逐项评分", vote, "primary")), table(["维度", "证据位置", "匿名评审材料"], rows, "本次材料无对应证据。"),
            table(["维度", "达标门槛", "本人评分", "理由"], ballot.dimensions.map(d =>
                [d.name, d.minimum_score, ballot.scores?.[d.code]?.score ?? "待评", ballot.scores?.[d.code]?.reason ?? "—"]), "")];
    });
}

function showPracticumCases(receipt, backToTask) {
    const path = "/api/practicum-reviews/submissions/" + receipt.submission_id + "/cases";
    const refresh = () => showPracticumCases(receipt, backToTask);
    return display("cohorts", async () => {
        const list = (await api(path)).items;
        const people = receipt.evidence_manifest.members.filter(m => manages() || m.learner_id === actor.account_id);
        const rows = people.map(member => {
            const item = list.find(c => c.assignment_id === member.assignment_id);
            const prepare = () => {
                const files = receipt.evidence_manifest.artifacts.filter(a => a.scope === "team" || a.assignment_id === member.assignment_id);
                const controls = files.flatMap(a => {
                    const label = a.dimension_code + " · 作品 #" + a.artifact_id;
                    const fields = [field("excerpt", label + " · 匿名说明", "textarea")];
                    if (a.kind !== "document") fields.push(field("preview-" + a.artifact_id, label + " · 匿名选段", "file", "",
                        {accept:a.kind === "image" ? "image/*" : "video/*"}));
                    return fields;
                });
                practicumForm("制备匿名评审材料", [element("p", "核对原作品和标注位置。遮盖姓名、工号、面孔等身份线索，选择处理后的图片或最长 8 秒的视频。评委可以查看画面，声音和文件元数据会被移除。"), ...controls], path, "POST",
                    async f => {
                        const excerpts = [];
                        for (const [i, a] of files.entries()) {
                            const entry = {artifact_id:a.artifact_id, anonymous_text:f.getAll("excerpt")[i]};
                            if (a.kind !== "document") entry.preview = await encodeReviewPreview(f.get("preview-" + a.artifact_id), a.kind);
                            excerpts.push(entry);
                        }
                        return {assignment_id:member.assignment_id, excerpts};
                    }, refresh);
            };
            return [member.learner_id, item ? practicumReviewStates[item.status] : "待制备",
                item ? button("查看评审", () => showPracticumResult(item.id, refresh)) : manages() ? button("制备匿名件", prepare) : "等待制备"];
        });
        return [back("实训任务", backToTask), heading("评审与定分"), table(["成员编号", "状态", "评审操作"], rows, "")];
    });
}

function showPracticumResult(id, backToCases) {
    const path = "/api/practicum-reviews/cases/" + id;
    const refresh = () => showPracticumResult(id, backToCases);
    return display("cohorts", async () => {
        const result = await api(path + "/result");
        const nodes = [back("评审与定分", backToCases), heading("评审 #" + id, practicumReviewStates[result.status])];
        if (result.disputed.length) nodes.push(element("p", "裁决维度：" + result.disputed.join("、")));
        if (result.seats && result.status !== "scored") {
            const roles = result.status === "reviewing" ? ["first","second"] : ["arbiter"];
            nodes.push(table(["席位", "评委编号", "操作"], roles.map(role => {
                const current = result.seats.find(s => s.seat === role);
                const assign = () => practicumForm("分派" + practicumSeatLabels[role],
                    [field("reviewer", "独立评委的成员编号", "number", current?.reviewer_id || "", {min:1})], path + "/seats", "PUT",
                    f => ({seat:role, reviewer_id:Number(f.get("reviewer"))}), refresh);
                return [practicumSeatLabels[role], current?.reviewer_id ?? "待分派", current?.locked_at ? "已锁定" : button("分派或改派", assign)];
            }), ""));
        }
        if (result.final_scores) nodes.push(element("p", "加权分 " + result.weighted_score + " · " + (result.meets_standard ? "达标" : "待补训")),
            table(["维度", "原评得分"], Object.entries(result.final_scores), ""),
            button("凭证与申诉", () => showCertification(id, refresh)));
        return nodes;
    });
}

async function downloadPracticumArtifact(path, team, artifact) {
    const result = await api(path + "/teams/" + team + "/artifacts/" + artifact.artifact_id);
    const bytes = Uint8Array.from(atob(result.content_base64), character => character.charCodeAt(0));
    const link = document.createElement("a");
    link.href = URL.createObjectURL(new Blob([bytes], {type: "application/octet-stream"}));
    link.download = "practicum-" + artifact.artifact_id;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 10000);
}

async function assignPracticum(path, refresh) {
    const candidates = await allPages(path + "/candidates");
    if (!candidates.length) return setMessage($("notice"), "当前班期没有待分派的在读学员。");
    const choices = candidates.map(a => [a.id, a.name]);
    const members = element("div", null, "form-stack");
    function add() {
        if (members.childElementCount >= candidates.length) return;
        const row = block("fieldset", "form-stack", element("legend", "组员"), field("learner", "学员", "select", choices[members.childElementCount][0], {choices}),
            field("role", "职责"), field("material", "独立材料", "textarea"));
        row.append(button("移除", () => { if (members.childElementCount > 1) row.remove(); }));
        members.append(row);
    }
    add();
    practicumForm("分派任务", [field("name", "组名或个人任务名"), members, button("增加组员", add)], path + "/teams", "POST",
        f => ({name: f.get("name"), members: f.getAll("learner").map((id, i) => ({learner_id: Number(id),
            responsibility: f.getAll("role")[i], material: f.getAll("material")[i]}))}), refresh);
}

function editPracticumArtifact(team, dimensions, refresh) {
    const dimension = field("code", "证据维度", "select", dimensions[0].code, {choices: dimensions.map(d => [d.code, d.name])});
    const anchors = element("div", null, "form-stack");
    const select = dimension.querySelector("select");
    const locations = {document: [["page","页码",1,100000]], image: [["x_pct","左侧（%）",0,99],["y_pct","顶部（%）",0,99],
        ["width_pct","宽度（%）",1,100],["height_pct","高度（%）",1,100]], video: [["start_ms","起点（毫秒）",0,86400000],["end_ms","终点（毫秒）",1,86400001]]};
    const kind = () => dimensions.find(d => d.code === select.value).evidence_kind;
    function update() {
        anchors.replaceChildren(...locations[kind()].map(([name,label,min,max]) => field(name, label, "number", min, {min,max})));
    }
    select.addEventListener("change", update);
    update();
    practicumForm("上传并标注证据", [dimension, field("file", "作品文件（最大 2 MiB）", "file"), anchors], team + "/artifacts", "POST", async f => {
        const file = f.get("file");
        if (!file.size || file.size > 2097152) throw Object.assign(new Error(), {result: {error: "invalid_artifact"}});
        const data = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result.split(",")[1]);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
        return {dimension_code: f.get("code"), content_base64: data, anchor: Object.fromEntries(locations[kind()].map(([k]) => [k, Number(f.get(k))]))};
    }, refresh);
}

async function selectPracticumArtifacts(path, team, id, revision, attempt, refresh) {
    const {items} = await api(team + "/artifacts");
    if (!items.length) return setMessage($("notice"), "请先上传作品证据。");
    const grouped = new Map();
    for (const a of items) {
        if (!grouped.has(a.digest)) grouped.set(a.digest, []);
        grouped.get(a.digest).push(a.dimension_code);
    }
    const controls = Array.from(grouped, ([digest, codes]) => {
        const label = field("hash", codes.join("、") + " · " + digest.slice(7, 23), "checkbox", true, {required: false});
        label.querySelector("input").value = digest;
        return label;
    });
    practicumForm("冻结第 " + attempt + " 次交付", controls, path + "/submissions", "POST",
        f => ({team_id: id, rubric_rev: revision, attempt, artifact_hashes: f.getAll("hash")}), refresh);
}

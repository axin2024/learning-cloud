"use strict";

function credentialQR(serial) {
    const url = new URL("/verify", location.origin);
    url.searchParams.set("serial", serial);
    const qr = qrcode(0, "M");
    qr.addData(url.href);
    qr.make();
    const canvas = document.createElement("canvas");
    const scale = 4;
    const margin = 4;
    const modules = qr.getModuleCount();
    canvas.width = canvas.height = (modules + 2 * margin) * scale;
    canvas.setAttribute("aria-label", "公开查验二维码");
    const ctx = canvas.getContext("2d");
    ctx.fillStyle = "white";
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = "black";
    for (let row = 0; row < modules; row++) {
        for (let col = 0; col < modules; col++) {
            if (qr.isDark(row, col)) ctx.fillRect((col + margin) * scale, (row + margin) * scale, scale, scale);
        }
    }
    const link = element("a", "打开公开查验页");
    link.href = url.href;
    const save = button("下载查验二维码", () => {
        const file = document.createElement("a");
        file.href = canvas.toDataURL("image/png");
        file.download = serial + ".png";
        file.click();
    });
    return block("div", "surface", canvas, block("div", "toolbar", link, save));
}

function showCompetencyPlans(company, after = 0) {
    const path = companyUrl(company.id) + "/competencies";
    const refresh = () => showCompetencyPlans(company);
    return display("companies", async () => {
        const page = await api(path + "?after=" + after);
        const configure = async () => {
            const departments = await allPages(companyUrl(company.id) + "/departments");
            if (!departments.length) return setMessage($("notice"), "请先建立部门。");
            practicumForm("配置盘点范围", [
                field("dept","部门","select",departments[0].id,{choices:departments.map(d=>[d.id,d.name])}),
                field("cohort","已冻结量规的班期编号","number","",{min:1}),
                field("minimum","披露个人明细的最少人数","number",5,{min:2,max:1000})],path,"POST",
                f=>({department_id:Number(f.get("dept")),cohort_id:Number(f.get("cohort")),disclosure_minimum:Number(f.get("minimum"))}),refresh);
        };
        return [back(company.name,()=>showCompany(company.id)),heading("岗位能力盘点", company.name,
            actor.roles.includes("admin") ? button("配置盘点范围",configure) : null),
            paginated(["部门","班期","岗位","评分版本"],page,page.items.map(p=>[button(p.department,()=>showCompetencyPlan(company,p.id)),
                p.cohort,p.title,p.revision]),"暂无盘点范围。",after,cursor=>showCompetencyPlans(company,cursor))];
    });
}

function showCompetencyPlan(company, id) {
    const root = companyUrl(company.id) + "/competencies";
    const path = root + "/" + id;
    const refresh = () => showCompetencyPlan(company,id);
    return display("companies", async () => {
        const r = await api(path);
        const tools = element("div",null,"toolbar");
        if (actor.roles.includes("admin")) {
            const employees = (await allPages(companyUrl(company.id) + "/learners")).filter(e=>e.department_id===r.department_id);
            if (employees.length) {
                tools.append(button("调整应认证名单",()=>practicumForm("应认证名单",[
                    field("required","操作","select","yes",{choices:[["yes","纳入"],["no","移出"]]}),
                    ...employees.map(e=>{const f=field("member",e.name,"checkbox",false,{required:false});f.querySelector("input").value=e.learner_id;return f;})],
                    path+"/roster","PUT",f=>({required:f.get("required")==="yes",learner_ids:f.getAll("member").map(Number)}),refresh)));
                tools.append(button("变更在职状态",()=>{
                    openForm("在职状态",[field("employee","员工","select",employees[0].learner_id,{choices:employees.map(e=>[e.learner_id,e.name])}),
                        field("active","状态","select","yes",{choices:[["yes","在职"],["no","离职"]]})],async f=>{
                        await api(root+"/employment/"+Number(f.get("employee")),"PUT",{employed:f.get("active")==="yes"},crypto.randomUUID());
                        return refresh;
                    });
                }));
            }
        }
        tools.append(button("生成复训任务",()=>practicumForm("为待补训员工分派任务",[
            field("target","复训班期编号","number",r.cohort_id,{min:1}),field("instructions","复训安排","textarea")],path+"/training","POST",
            f=>({cohort_id:Number(f.get("target")),instructions:f.get("instructions")}),async receipt=>{
                await refresh(); success("已生成 "+receipt.created+" 项复训任务。");
            })));
        return [back("岗位能力盘点",()=>showCompetencyPlans(company)),heading(r.job_title,"班期 #"+r.cohort_id,tools),
            block("div","metrics",metric("在职应认证",r.required_count),metric("当前达标",r.qualified_count),
                metric("覆盖率",r.coverage_pct===null ? "—" : r.coverage_pct+"%"),metric("完成复训",r.training_completed)),
            table(["证据维度","缺少证据人数"],Object.values(r.gaps).map(d=>[d.name,d.missing_count]),"证据齐全。"),
            r.suppressed ? element("p","应认证人数少于 "+r.disclosure_minimum+"，隐藏个人明细。") :
                table(["员工","当前分数","结论","复训前","复训后","提升"],r.members.map(m=>[m.name,m.score,
                    m.appeal_pending ? "申诉中" : m.qualified ? "达标" : "待认证",m.training_baseline,m.training_final,m.score_change]),"暂无员工。")];
    });
}

function showCompetencyTraining(after=0) {
    return display("competency-training",async()=>{
        const [page,prior]=await Promise.all([api("/api/me/competency-training?after="+after),allPages("/api/me/competency-prerequisites")]);
        return [heading("我的岗位复训"),paginated(["复训班期","安排","待补证据","状态","复训后得分"],page,page.items.map(t=>[
            button(t.cohort,()=>showCohort(t.cohort_id)),t.instructions,Object.values(t.initial_missing).join("、"),
            t.completed_at ? "已完成" : "待完成",t.final_score]),"暂无岗位复训任务。",after,showCompetencyTraining),
            ...(prior.length ? [element("h2","已导入的先修记录"),table(["课程","班期","报名编号"],prior.map(p=>[p.title,p.cohort,p.enrollment_id]),"")] : [])];
    });
}

function showCertification(id, backToResult) {
    const path = "/api/practicum-reviews/cases/" + id;
    const refresh = () => showCertification(id, backToResult);
    return display("cohorts", async () => {
        const c = await api(path + "/certification");
        const tools = element("div", null, "toolbar");
        const nodes = [back("原始评审", backToResult), heading("凭证与申诉", "当前加权分 " + c.weighted_score + " · " +
            (c.meets_standard ? "达标" : "待补训"), tools), table(["维度", "当前得分"], Object.entries(c.scores), "")];
        if (Object.keys(c.missing_dimensions).length) nodes.push(element("p", "待补证据：" + Object.values(c.missing_dimensions).join("、")));
        if (manages() && c.meets_standard && !c.pending && c.credential?.case_id !== id) {
            tools.append(button("签发岗位凭证", () => practicumForm("签发岗位凭证", [element("p", "当前实训与评分满足要求。")],
                path + "/credential", "POST", () => ({}), refresh)));
        }
        if (c.credential) {
            const k = c.credential;
            nodes.push(element("h2", "当前凭证 v" + k.revision), element("p", k.serial + (k.revoked_at ? " · 已撤销" : "")), credentialQR(k.serial));
            if (manages() && k.case_id === id && !k.revoked_at) tools.append(button("撤销凭证", () => practicumForm("撤销凭证",
                [field("reason", "撤销理由", "textarea")], path + "/revoke", "POST", f => ({credential_id:k.id, reason:f.get("reason")}), refresh)));
        }
        if (c.holder) {
            tools.append(button("公开字段", () => practicumForm("公开查验字段", [
                field("title", "公开岗位名称", "checkbox", c.disclosure.show_title, {required:false}),
                field("score", "公开认证得分", "checkbox", c.disclosure.show_score, {required:false})], path + "/disclosure", "PUT",
                f => ({show_title:f.has("title"), show_score:f.has("score")}), refresh)));
            if (!c.pending) tools.append(button("提出单项申诉", () => practicumForm("单项申诉", [
                field("dimension", "争议维度", "select", Object.keys(c.scores)[0], {choices:Object.keys(c.scores).map(d => [d,d])}),
                field("evidence", "证据版本", "select", c.evidence_versions.at(-1).submission_id, {choices:c.evidence_versions.map(s => [s.submission_id,"第 " + s.attempt + " 次交付"])}),
                field("statement", "申诉理由", "textarea")], path + "/appeals", "POST", f => ({dimension_code:f.get("dimension"),
                    evidence_submission_id:Number(f.get("evidence")),statement:f.get("statement")}), refresh)));
        }
        const labels = {pending:"待复核", amended:"已改判", upheld:"维持原判"};
        nodes.push(table(["申诉编号", "维度", "状态", "复核"], c.appeals.map(a =>
            [a.id, a.dimension_code, labels[a.status], button("查看申诉", () => showCompetencyAppeal(path, a.id, refresh))]), "暂无申诉。"));
        return nodes;
    });
}

function showCompetencyAppeal(path, id, refresh) {
    const url = path + "/appeals/" + id;
    return display("cohorts", async () => {
        const a = await api(url);
        const tools = element("div", null, "toolbar");
        if (manages() && a.status === "pending") {
            for (const [decision,label] of [["upheld","维持原判"],["amended","改判"]]) {
                const amend = decision === "amended";
                tools.append(button(label, () => practicumForm(label,
                    [...(amend ? [field("score","复核得分","number",Math.round(a.previous_score),{min:0,max:100})] : []), field("reason","复核理由","textarea")],
                    url, "PUT", f => ({decision, reason:f.get("reason"), ...(amend ? {score:Number(f.get("score"))} : {})}), refresh)));
            }
        }
        return [back("凭证与申诉", refresh), heading("申诉 #" + id, a.dimension_code + " · " + a.statement, tools),
            table(["锁定记录", "得分", "评分理由"], a.votes.map(v => [v.review_id,v.score,v.reason]), ""),
            table(["证据编号", "位置", "作品"], a.artifacts.map(v => [v.artifact_id, Object.values(v.anchor).join(" / "),
                button("下载复核材料", () => downloadPracticumArtifact("/api/practicums/" + a.practicum_id,a.team_id,v))]), "该版本尚缺此项证据。"),
            element("p", a.reason || "等待复核")];
    });
}

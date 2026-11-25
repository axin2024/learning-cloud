"use strict";

function studyDuration(seconds) {
    const minutes = Math.floor(seconds / 60);
    const remainder = seconds % 60;
    return minutes ? `${minutes} 分 ${remainder} 秒` : `${remainder} 秒`;
}

async function showStudySummary(item, cohort, roster) {
    await display(roster ? "cohorts" : "mine", async () => {
        const progress = await api(`/api/study/enrollments/${item.id}`);
        const rows = progress.chapters.map((chapter) => [chapter.title, studyDuration(chapter.studied_seconds),
            studyDuration(chapter.required_seconds), chapter.study_requirement_met ? "已达到" : "未达到"]);
        return [back("报名详情", () => showEnrollment(item.id, roster)), heading("学习进度", cohort.name),
            block("div", "surface", block("div", "metrics", metric("累计有效学时", studyDuration(progress.studied_seconds)),
                metric("课程最低学时", studyDuration(progress.minimum_study_seconds)), metric("学时要求", progress.study_requirement_met ? "已达到" : "未达到"))),
            block("div", "surface", table(["章节", "已认定", "最低要求", "状态"], rows, "暂无章节。"))];
    });
}

function finishStudy() {
    if (!leaveStudy) return;
    const stop = leaveStudy;
    leaveStudy = null;
    stop();
}

function mediaPlayer(url) {
    const video = element("video");
    video.controls = true;
    video.preload = "none";
    video.src = url;
    return video;
}

async function showStudyChapter(enrollmentId, courseId, revisionId, chapter) {
    let mount;
    let panel;
    await display("mine", async () => {
        const session = await api(`/api/study/enrollments/${enrollmentId}/chapters/${chapter.id}/start`, "POST");
        panel = block("article", "surface material", element("p", chapter.content));
        if (chapter.kind === "live") {
            panel.append(element("h2", "直播学习会话号", "section-heading"), element("code", session.session_code, "display-code"),
                element("p", "使用班期直播平台时提交此会话号。平台回传后，可在学习进度查看认定结果。"));
        } else if (chapter.kind === "recorded" && !chapter.media_url) {
            panel.append(element("p", "本章尚未配置可播放的录播地址。"));
        } else {
            const status = element("p", "", "meta");
            status.setAttribute("role", "status");
            const outbox = studyOutbox(session.id, status);
            if (chapter.kind === "text") {
                mount = addReadingTimer(panel, session, outbox);
            } else {
                const video = mediaPlayer(chapter.media_url);
                video.preload = "metadata";
                panel.append(video);
                mount = () => trackPlayback(video, session, outbox);
            }
            panel.append(status);
        }
        return [back("课程材料", () => showRevision(courseId, revisionId, enrollmentId)), heading(chapter.title), panel];
    });
    if (mount && panel.isConnected) leaveStudy = mount();
}

function studyOutbox(session, status) {
    const prefix = `learning-cloud-study/${actor.tenant_id}/${actor.account_id}/${session}/`;
    const queue = new Map();
    try {
        for (let index = 0; index < sessionStorage.length; index++) {
            const name = sessionStorage.key(index);
            if (name.startsWith(prefix)) queue.set(name.slice(prefix.length), JSON.parse(sessionStorage.getItem(name)));
        }
    } catch {
        // In private storage modes the current page can still report intervals from memory.
    }
    let syncing = false;

    async function sync() {
        if (syncing) return;
        syncing = true;
        try {
            for (const [key, interval] of queue) {
                await api(`/api/study/sessions/${session}/intervals`, "POST", interval, key);
                queue.delete(key);
                try { sessionStorage.removeItem(prefix + key); } catch { /* Memory-only queue. */ }
                status.textContent = "学时已同步。";
            }
        } catch (error) {
            status.textContent = `${error.result ? errorMessage(error) : "连接恢复后会重试。"} 待同步 ${queue.size} 段。`;
        } finally {
            syncing = false;
        }
    }

    return {
        push(start, end) {
            if (end <= start) return;
            const key = `interval:${start}:${end}`;
            const interval = {start_second: start, end_second: end};
            queue.set(key, interval);
            try { sessionStorage.setItem(prefix + key, JSON.stringify(interval)); } catch { /* Memory-only queue. */ }
            void sync();
        },
        connect(collect) {
            window.addEventListener("online", sync);
            window.addEventListener("pagehide", collect);
            void sync();
            return () => {
                window.removeEventListener("online", sync);
                window.removeEventListener("pagehide", collect);
                collect();
            };
        },
    };
}

function addReadingTimer(panel, session, outbox) {
    let running = false;
    let cursor = session.resume_second;
    let committed = cursor;
    let sampledAt = null;
    let carry = 0;
    const time = element("strong", `${studyDuration(cursor)} / ${studyDuration(session.duration_seconds)}`);
    const emit = () => {
        outbox.push(committed, cursor);
        committed = cursor;
    };
    const toggle = button("开始阅读计时", () => {
        sample();
        if (cursor === session.duration_seconds) return;
        running = !running;
        sampledAt = running && !document.hidden ? performance.now() : null;
        toggle.textContent = running ? "暂停计时" : "继续阅读";
        if (!running) emit();
    }, "primary");
    toggle.disabled = cursor >= session.duration_seconds;
    if (toggle.disabled) toggle.textContent = "已记至结束";
    panel.append(block("div", "toolbar", toggle, time));

    function sample() {
        const now = performance.now();
        if (sampledAt !== null) carry += Math.min(now - sampledAt, 1500);
        sampledAt = running && !document.hidden ? now : null;
        const elapsed = Math.floor(carry / 1000);
        if (!elapsed) return;
        carry -= elapsed * 1000;
        cursor = Math.min(cursor + elapsed, session.duration_seconds);
        time.textContent = `${studyDuration(cursor)} / ${studyDuration(session.duration_seconds)}`;
        if (cursor - committed >= 10 || cursor === session.duration_seconds) emit();
        if (cursor === session.duration_seconds) {
            running = false;
            sampledAt = null;
            toggle.disabled = true;
            toggle.textContent = "已记至结束";
        }
    }

    return () => {
        const checkpoint = () => { sample(); emit(); };
        const disconnect = outbox.connect(checkpoint);
        const timer = window.setInterval(sample, 500);
        document.addEventListener("visibilitychange", checkpoint);
        return () => {
            window.clearInterval(timer);
            document.removeEventListener("visibilitychange", checkpoint);
            disconnect();
        };
    };
}

function trackPlayback(video, session, outbox) {
    const emitted = new Set();
    const collect = (includePartial) => {
        for (let i = 0; i < video.played.length; i++) {
            const playedUntil = video.played.end(i);
            const reachedEnd = video.ended && Math.abs(playedUntil - video.duration) < 0.05;
            const last = Math.min(reachedEnd ? Math.round(playedUntil) : Math.floor(playedUntil + 0.001), session.duration_seconds);
            for (let start = Math.max(0, Math.ceil(video.played.start(i) - 0.001)); start < last; start += 15) {
                const end = Math.min(start + 15, last);
                if (!includePartial && end - start < 15) break;
                const key = `${start}:${end}`;
                if (!emitted.has(key)) {
                    emitted.add(key);
                    outbox.push(start, end);
                }
            }
        }
    };
    const checkpoint = () => collect(true);
    video.addEventListener("loadedmetadata", () => {
        if (session.resume_second < Math.min(video.duration, session.duration_seconds)) video.currentTime = session.resume_second;
    }, {once: true});
    video.addEventListener("timeupdate", () => collect(false));
    video.addEventListener("pause", checkpoint);
    video.addEventListener("ended", checkpoint);
    video.addEventListener("seeking", checkpoint);
    const disconnect = outbox.connect(checkpoint);
    return () => { video.pause(); disconnect(); };
}

const attendanceLabels = {review: "待复核", accepted: "已确认", rejected: "已拒绝"};

async function showLiveHub(state = "review", after = 0) {
    await display("live", async () => {
        const [providers, page] = await Promise.all([allPages("/api/study/providers"), api(`/api/study/attendance?state=${state}&after=${after}`)]);
        const admin = actor.roles.includes("admin");
        const providerRows = providers.map((provider) => [provider.name, provider.code, provider.rotated_at || provider.created_at,
            admin ? button("轮换令牌", () => rotateProvider(provider), "row-link") : null]);
        const rows = page.items.map((event) => [event.external_id, event.provider_name,
            {learner: "学员", instructor: "讲师", host: "主持人"}[event.source_role], `${event.start_second}–${event.end_second} 秒`,
            event.session_id, errorLabels[event.reason] ?? event.reason, event.review_note,
            state === "review" ? button("复核", () => reviewAttendance(event, after), "row-link") : null]);
        const filters = block("div", "toolbar", ...Object.entries(attendanceLabels).map(([value, title]) =>
            button(title, () => showLiveHub(value), state === value ? "primary" : "")));
        return [heading("直播出勤", "", admin ? button("登记平台", newProvider, "primary") : null),
            element("h2", "出勤平台", "section-heading"), block("div", "surface", table(["平台", "平台代码", "令牌更新时间（UTC）", "操作"],
                providerRows, "暂无直播出勤平台。")), element("h2", "出勤回执", "section-heading"), filters,
            paginated(["外部回执号", "平台", "角色", "区间", "会话编号", "原因", "复核说明", "操作"], page, rows, "此状态下没有出勤回执。", after,
                (cursor) => showLiveHub(state, cursor))];
    });
}

function newProvider() {
    openForm("登记直播出勤平台", [field("name", "平台名称", "text", "", {maxlength: 160}), field("code", "平台代码", "text", "", {maxlength: 64})],
        async (form) => {
            const provider = await api("/api/study/providers", "POST", Object.fromEntries(form));
            return () => showProviderToken(provider.name, provider.token);
        }, "生成令牌");
}

function rotateProvider(provider) {
    openForm("轮换出勤令牌", [element("p", `${provider.name} 将立即使用新令牌，更新后请将新令牌配置到直播接入方。`)], async () => {
        const result = await api(`/api/study/providers/${provider.id}/rotate`, "POST");
        return () => showProviderToken(provider.name, result.token);
    }, "确认轮换");
}

function showProviderToken(name, token) {
    return display("live", async () => [back("直播出勤", showLiveHub), heading("接入令牌", name),
        block("div", "surface", element("p", "令牌只显示这一次。请复制到直播平台的安全配置。"), element("code", token, "display-code"))]);
}

function reviewAttendance(event, after) {
    const code = field("session_code", "学习会话号", "text", "", {maxlength: 48});
    const decision = field("decision", "决定", "select", "accept", {choices: [["accept", "确认出勤"], ["reject", "拒绝"]]});
    decision.addEventListener("change", () => {
        code.hidden = decision.querySelector("select").value === "reject";
        code.querySelector("input").required = !code.hidden;
    });
    openForm("复核出勤回执", [element("p", `${event.external_id} · ${event.provider_name}`), decision, code,
        field("note", "复核依据", "textarea", "", {maxlength: 1000})], async (form) => {
        await api(`/api/study/attendance/${event.id}/review`, "POST", Object.fromEntries(form));
        return async () => { await showLiveHub("review", after); success("出勤回执已复核。"); };
    }, "提交复核");
}

async function showLiveBindings(cohort) {
    await display("cohorts", async () => {
        const base = `/api/study/cohorts/${cohort.id}/live`;
        const [chapters, providers] = await Promise.all([api(base), allPages("/api/study/providers")]);
        const edit = (chapter) => openForm("配置直播出勤", [field("provider_id", "出勤平台", "select", chapter.provider_id ?? providers[0].id,
            {choices: providers.map((provider) => [provider.id, provider.name])})], async (form) => {
            await api(`${base}/${chapter.id}`, "PUT", {provider_id: Number(form.get("provider_id"))});
            return () => showLiveBindings(cohort);
        }, "保存绑定");
        const rows = chapters.items.map((chapter) => [chapter.title, chapter.provider_name ?? "待配置",
            providers.length ? button("选择平台", () => edit(chapter), "row-link") : null]);
        return [back(cohort.name, () => showCohort(cohort.id)), heading("配置直播出勤", cohort.name,
            providers.length ? null : button("查看出勤平台", () => showLiveHub())),
        block("div", "surface", table(["直播章节", "出勤平台", "操作"], rows, "课程中没有直播章节。"))];
    });
}

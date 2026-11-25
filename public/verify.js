"use strict";

const form = document.getElementById("verification-form");
const message = document.getElementById("verification-message");
const output = document.getElementById("verification-result");
const verifyStates = {valid: "证书有效", expired: "证书已到期", revoked: "证书已撤销"};

function verificationMessage(value) {
    message.hidden = !value;
    message.textContent = value;
    message.classList.toggle("error", Boolean(value));
}

async function verifyCredential(input) {
    const serial = input.trim().toUpperCase();
    output.hidden = true;
    output.replaceChildren();
    verificationMessage("");
    if (!/^LC-[A-F0-9]{32}$/.test(serial)) {
        verificationMessage("请填写完整的证书编号。");
        return;
    }
    const button = form.querySelector("button");
    button.disabled = true;
    try {
        const response = await fetch("/api/verify-credential/" + encodeURIComponent(serial), {cache: "no-store", credentials: "omit"});
        if (response.status === 404) {
            verificationMessage("未查询到该证书，请核对编号。");
            return;
        }
        if (!response.ok) throw new Error("Verification unavailable");
        const card = await response.json();
        const header = document.createElement("h2");
        header.textContent = verifyStates[card.status];
        header.className = "credential-" + card.status;
        const facts = document.createElement("dl");
        facts.className = "credential-facts";
        const utc = (value) => value.slice(0, 19) + " UTC";
        for (const [label, value] of [
            ["证书编号", card.serial],
            ["签发机构", card.issuer_name],
            ["课程与版本", card.course_title + " · 第 " + card.course_revision + " 版"],
            ["签发日期", utc(card.issued_at)],
            ["有效期限", utc(card.expires_at)],
        ]) {
            const key = document.createElement("dt");
            key.textContent = label;
            const detail = document.createElement("dd");
            detail.textContent = value;
            facts.append(key, detail);
        }
        output.append(header, facts);
        output.hidden = false;
    } catch {
        verificationMessage("暂时无法查询，请稍后重试。");
    } finally {
        button.disabled = false;
    }
}

form.addEventListener("submit", (event) => {
    event.preventDefault();
    if (!form.querySelector("button").disabled) void verifyCredential(form.elements.serial.value);
});
const selected = new URLSearchParams(window.location.search).get("serial");
if (selected) {
    form.elements.serial.value = selected.slice(0, 35);
    void verifyCredential(selected);
}

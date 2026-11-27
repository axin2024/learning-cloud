from pathlib import Path
import sys
import cv2
import numpy as np
from playwright.sync_api import sync_playwright


from practicum_http import run

origin = sys.argv[1]
def department_checkpoint(space, secret, phase):
    with sync_playwright() as driver:
        browser = driver.chromium.launch(channel="chrome",headless=True)
        page = browser.new_page()
        page.goto(origin)
        page.get_by_label("学习空间代码").fill(space)
        page.get_by_label("邮箱").fill("training-manager@example.test")
        page.get_by_label("密码").fill(secret)
        def click(name):
            page.get_by_role("button",name=name,exact=True).click()
        click("登录")
        click("企业购课")
        click("training-plant")
        click("岗位能力盘点")
        click("点检车间")
        page.get_by_role("heading",name="实训技术员").wait_for()
        row = page.get_by_role("row").filter(has_text="inspector")
        row.get_by_text({"awaiting_evidence":"待认证","under_appeal":"申诉中","qualified":"达标"}[phase],exact=True).wait_for()
        assert row.get_by_text("80.0" if phase=="qualified" else "72.5",exact=True).count() >= 1
        print("Department browser",phase,row.inner_text().replace("\n"," "),flush=True)
        browser.close()
tenant, password = run(origin, department_checkpoint, Path(sys.argv[2]) if len(sys.argv)>2 else None)
with sync_playwright() as playwright:
    browser = playwright.chromium.launch(channel="chrome", headless=True)
    errors = []
    def session(email):
        page = browser.new_page()
        page.set_default_timeout(7000)
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.goto(origin)
        page.get_by_label("学习空间代码").fill(tenant)
        page.get_by_label("邮箱").fill(email + "@example.test")
        page.get_by_label("密码").fill(password)
        page.get_by_role("button", name="登录", exact=True).click()
        page.get_by_role("button", name="退出", exact=True).wait_for()
        return page
    def click(page, name):
        page.get_by_role("button", name=name, exact=True).click()
    def member_task(page):
        click(page, "班期")
        click(page, "spring")
        click(page, "岗位实训")
        click(page, "产线设备点检")
    pupil = session("inspector")
    member_task(pupil)
    pupil.get_by_text("内部材料-核查", exact=True).wait_for()
    assert pupil.get_by_text("内部材料-组长", exact=True).count() == 0
    click(pupil, "记录个人贡献")
    pupil.get_by_role("dialog").get_by_label("核查").fill("追加复查传感器读数")
    click(pupil, "保存")
    pupil.get_by_text("追加复查传感器读数", exact=True).wait_for()
    click(pupil, "申请补交")
    pupil.get_by_label("补交原因").fill("追加独立复查")
    click(pupil, "保存")
    pupil.get_by_text("待审批", exact=True).wait_for()
    mentor = session("mentor")
    click(mentor, "授课班期")
    click(mentor, "岗位实训")
    click(mentor, "产线设备点检")
    mentor.get_by_text("内部材料-组长", exact=True).wait_for()
    click(mentor, "批准补交")
    mentor.get_by_label("补交截止时刻（UTC）").fill("2030-01-01T12:00")
    click(mentor, "保存")
    mentor.get_by_text("2030-01-01 12:00:00 UTC", exact=True).wait_for()
    click(pupil, "上传证据")
    pupil.get_by_label("证据维度").select_option("check")
    pupil.get_by_label("作品文件", exact=False).set_input_files({"name":"inspection.txt","mimeType":"text/plain","buffer":b"independent inspection"})
    click(pupil, "保存")
    pupil.get_by_role("dialog").wait_for(state="hidden")
    click(pupil, "提交小组作品")
    click(pupil, "保存")
    pupil.get_by_role("button", name="查看证据与差异").nth(3).click()
    pupil.get_by_role("heading", name="第 4 次交付").wait_for()
    pupil.get_by_text("必交证据齐全。", exact=True).wait_for()
    admin = session("admin")
    member_task(admin)
    admin.get_by_role("button", name="查看证据与差异").first.click()
    click(admin, "评审与定分")
    admin.get_by_role("button", name="查看评审", exact=True).first.click()
    admin.get_by_text("加权分 78.5 · 达标", exact=True).wait_for()
    click(admin, "凭证与申诉")
    qr_image = admin.locator("canvas").screenshot()
    decoded, _, _ = cv2.QRCodeDetector().detectAndDecode(cv2.imdecode(np.frombuffer(qr_image, np.uint8), cv2.IMREAD_GRAYSCALE))
    assert decoded == admin.get_by_role("link", name="打开公开查验页").get_attribute("href")
    anonymous = browser.new_page()
    anonymous.goto(decoded)
    anonymous.get_by_role("heading", name="证书已撤销").wait_for()
    assert anonymous.locator("#verification-result dt").all_text_contents() == ["失效日期"]
    judge = session("judge-three")
    click(judge, "匿名评审")
    judge.get_by_role("row").filter(has_text="裁决评委").get_by_role("button").click()
    judge.get_by_text("依据复测结果裁定", exact=True).wait_for()
    assert judge.get_by_text("内部材料-组长", exact=True).count() == 0
    admin.reload()
    member_task(admin)
    admin.get_by_role("button", name="查看证据与差异").nth(3).click()
    click(admin, "评审与定分")
    admin.get_by_role("button", name="制备匿名件").first.click()
    for control in admin.locator("textarea[name=excerpt]").all():
        control.fill("已核对匿名作品和原件")
    image_ok, png = cv2.imencode(".png", np.full((16,32,3), (30,100,180), np.uint8))
    assert image_ok
    admin.locator("input[accept='image/*']").set_input_files({"name":"sanitized.png","mimeType":"image/png","buffer":png.tobytes()})
    admin.locator("input[accept='video/*']").set_input_files(str(Path(__file__).resolve().parent / "fixtures/gauge-inspection.webm"))
    with admin.expect_response(lambda r:"/cases" in r.url and r.request.method=="POST") as created:
        click(admin, "保存")
    assert created.value.status==201, created.value.text()
    case_id=created.value.json()["case_id"]
    first_judge=session("judge-one")
    reviewer=first_judge.evaluate("async () => (await api('/api/session')).actor.account_id")
    admin.evaluate("async ([c,r]) => api('/api/practicum-reviews/cases/'+c+'/seats','PUT',{seat:'first',reviewer_id:r},crypto.randomUUID())",[case_id,reviewer])
    first_judge.evaluate("id => showPracticumBallot(id)",case_id)
    screen=first_judge.get_by_label("匿名证据图片")
    first_judge.wait_for_function("() => document.querySelector('canvas[aria-label=匿名证据图片]')?.getContext('2d').getImageData(0,0,1,1).data[0] === 180")
    assert screen.evaluate("c => c.width===32 && c.height===16")
    clip=first_judge.get_by_label("匿名证据视频画面")
    click(first_judge, "播放片段")
    first_judge.wait_for_function("() => Number(document.querySelector('input[aria-label=查看证据视频各帧]')?.value)>0")
    assert clip.evaluate("c => c.width===96 && c.height===64")
    print("Browser passed: staff selected anonymized PNG and WebM, independent account read measured image pixels and played the clip.")
    assert not errors, errors
    print("Browser passed: learner privacy, mentor approval, third submission, registrar result and independent arbiter ballot.")
    browser.close()

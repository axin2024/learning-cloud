"use strict";

function mediaEvent(target, name, start) {
    return new Promise((resolve, reject) => {
        const ac = new AbortController();
        const finish = fn => { clearTimeout(timer); ac.abort(); fn(); };
        const failed = () => finish(() => reject(new Error("无法解码所选媒体。")));
        const timer = setTimeout(failed, 10000);
        target.addEventListener(name, () => finish(resolve), {once:true, signal:ac.signal});
        target.addEventListener("error", failed, {once:true, signal:ac.signal});
        try { start(); } catch { failed(); }
    });
}

async function encodeReviewPreview(file, kind) {
    if (!(file instanceof File) || !file.size || file.size > 16 * 1024 * 1024) throw new Error("请选择 16 MiB 以内的匿名图像或短片。");
    const still = kind === "image";
    let bitmap, video, url;
    try {
        if (still) bitmap = await createImageBitmap(file);
        else {
            video = document.createElement("video");
            video.muted = true;
            video.preload = "auto";
            url = URL.createObjectURL(file);
            await mediaEvent(video, "loadeddata", () => { video.src = url; });
            if (!Number.isFinite(video.duration) || video.duration < 0.1 || video.duration > 8) {
                throw new Error("请选择 0.1 至 8 秒内、与标注对应的匿名片段。");
            }
        }
        const source = bitmap || video;
        const originalWidth = still ? bitmap.width : video.videoWidth;
        const originalHeight = still ? bitmap.height : video.videoHeight;
        const scale = Math.min(1, 320 / originalWidth, 240 / originalHeight);
        const canvas = document.createElement("canvas");
        const width = canvas.width = Math.max(1, Math.round(originalWidth * scale));
        const height = canvas.height = Math.max(1, Math.round(originalHeight * scale));
        const frames = still ? 1 : Math.max(2, Math.ceil(video.duration * 3));
        const frameMs = still ? 0 : Math.max(1, Math.round(video.duration * 1000 / frames));
        const ctx = canvas.getContext("2d", {willReadFrequently:true, alpha:false});
        const pixels = new Uint8Array(width * height * frames * 3);
        let dest = 0;
        for (let i = 0; i < frames; ++i) {
            if (i) await mediaEvent(video, "seeked", () => { video.currentTime = video.duration * i / frames; });
            ctx.drawImage(source, 0, 0, width, height);
            const rgba = ctx.getImageData(0, 0, width, height).data;
            for (let j = 0; j < rgba.length; j += 4) {
                pixels[dest++] = rgba[j];
                pixels[dest++] = rgba[j+1];
                pixels[dest++] = rgba[j+2];
            }
        }
        const compressed = new Uint8Array(await new Response(new Blob([pixels]).stream().pipeThrough(new CompressionStream("deflate"))).arrayBuffer());
        let binary = "";
        for (let i = 0; i < compressed.length; i += 8192) binary += String.fromCharCode(...compressed.subarray(i, i+8192));
        return {width, height, frames, frame_ms:frameMs, pixels_zlib:btoa(binary)};
    } finally {
        bitmap?.close();
        if (video) { video.removeAttribute("src"); video.load(); }
        if (url) URL.revokeObjectURL(url);
    }
}

function reviewPreview(preview) {
    const canvas = document.createElement("canvas");
    const {width, height, frames, frame_ms:frameMs} = preview;
    canvas.width = width;
    canvas.height = height;
    canvas.setAttribute("aria-label", frames === 1 ? "匿名证据图片" : "匿名证据视频画面");
    const container = block("div", "form-stack", canvas);
    const packed = Uint8Array.from(atob(preview.pixels_zlib), c => c.charCodeAt(0));
    const stream = new Blob([packed]).stream().pipeThrough(new DecompressionStream("deflate"));
    new Response(stream).arrayBuffer().then(buffer => {
        const rgb = new Uint8Array(buffer);
        const ctx = canvas.getContext("2d");
        const image = ctx.createImageData(width, height);
        const show = index => {
            let src = index * width * height * 3;
            for (let i = 0; i < image.data.length; i += 4) {
                image.data[i] = rgb[src++];
                image.data[i+1] = rgb[src++];
                image.data[i+2] = rgb[src++];
                image.data[i+3] = 255;
            }
            ctx.putImageData(image, 0, 0);
        };
        show(0);
        if (frames === 1) return;
        const slider = document.createElement("input");
        Object.assign(slider, {type:"range", min:"0", max:String(frames-1), value:"0"});
        slider.setAttribute("aria-label", "查看证据视频各帧");
        let timer;
        const stop = () => { clearInterval(timer); timer = null; play.textContent = "播放片段"; };
        const play = button("播放片段", () => {
            if (timer) return stop();
            slider.value = "0";
            show(0);
            play.textContent = "暂停";
            timer = setInterval(() => {
                if (!container.isConnected || Number(slider.value) >= frames-1) return stop();
                slider.value = String(Number(slider.value)+1);
                show(Number(slider.value));
            }, frameMs);
        });
        slider.addEventListener("input", () => { stop(); show(Number(slider.value)); });
        container.append(play, slider);
    }).catch(() => container.replaceChildren(document.createTextNode("匿名作品读取失败。")));
    return container;
}

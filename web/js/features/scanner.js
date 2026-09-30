// 카메라 스캐너. 브라우저 BarcodeDetector(QR·바코드)가 있으면 쓰고, 없으면 jsQR(QR)로 읽는다.
// 카메라를 못 쓰는 곳(https 가 아닌 NAS 주소 등)에서는 "사진 찍어서 읽기"로 대신한다.
import { $, esc, icon, loadScript, vibrate } from "../lib/util.js";
import { api } from "../lib/api.js";
import { toast } from "../lib/ui.js";
import * as sfx from "../lib/sfx.js";
import { app } from "../app.js";
import { can } from "../lib/store.js";

const FORMATS = ["qr_code", "ean_13", "ean_8", "code_128", "code_39", "upc_a", "upc_e", "itf", "data_matrix"];
let detectorPromise = null;

async function getDetector() {
  if (!detectorPromise) {
    detectorPromise = (async () => {
      if ("BarcodeDetector" in window) {
        try {
          const supported = await window.BarcodeDetector.getSupportedFormats();
          const formats = FORMATS.filter((f) => supported.includes(f));
          if (formats.length) {
            const d = new window.BarcodeDetector({ formats });
            return { kind: "native", detect: async (src) => { const r = await d.detect(src); return r.length ? r[0].rawValue : null; } };
          }
        } catch { /* 폴백 */ }
      }
      await loadScript("/vendor/jsqr/jsQR.js");
      const canvas = document.createElement("canvas");
      const ctx = canvas.getContext("2d", { willReadFrequently: true });
      return {
        kind: "jsqr",
        detect: async (src) => {
          const w0 = src.videoWidth || src.naturalWidth || src.width;
          const h0 = src.videoHeight || src.naturalHeight || src.height;
          if (!w0 || !h0) return null;
          const scale = Math.min(1, 720 / Math.max(w0, h0));
          canvas.width = Math.round(w0 * scale);
          canvas.height = Math.round(h0 * scale);
          ctx.drawImage(src, 0, 0, canvas.width, canvas.height);
          const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
          const r = window.jsQR(img.data, img.width, img.height, { inversionAttempts: "attemptBoth" });
          return r ? r.data : null;
        },
      };
    })();
  }
  return detectorPromise;
}

async function decodeFile(file) {
  const det = await getDetector();
  const bmp = await createImageBitmap(file).catch(() => null);
  if (!bmp) return null;
  if (det.kind === "native") {
    try { return await det.detect(bmp); } catch { return null; }
  }
  return det.detect(bmp);
}

/**
 * @param {object} o { title, hint, continuous, onCode(code) → Promise<{ok, text}> }
 * @returns {Promise<string|null>} 한 번 모드에서 읽은 값
 */
export function openScanner({ title = "스캔", hint = "라벨의 QR·바코드를 네모 안에 비춰 주세요", continuous = false, onCode = null } = {}) {
  return new Promise((resolve) => {
    const el = document.createElement("div");
    el.className = "scanner";
    el.setAttribute("role", "dialog");
    el.setAttribute("aria-label", title);
    el.innerHTML = `
      <video playsinline muted></video>
      <div class="veil2"></div>
      <div class="reticle"><i></i><i></i><i></i><i></i><div class="beam"></div></div>
      <div class="top">
        <div class="t"><small>SCANNER</small>${esc(title)}</div>
        <button class="icon-btn" type="button" data-b="torch" title="손전등" hidden>${icon("flash")}</button>
        <button class="icon-btn" type="button" data-b="switch" title="카메라 바꾸기" hidden>${icon("switch")}</button>
        <button class="icon-btn" type="button" data-b="close" title="닫기 (Esc)">${icon("x")}</button>
      </div>
      <div class="mid"></div>
      <div class="bottom">
        <div class="msg" aria-live="polite">${esc(hint)}</div>
        ${continuous ? '<div class="hits"></div>' : ""}
        <form class="manual"><input type="text" inputmode="text" autocomplete="off" placeholder="관리번호·코드 직접 입력" aria-label="코드 직접 입력"><button class="btn" type="submit">확인</button></form>
        <label class="btn ghost" style="justify-content:center">${icon("camera")}사진 찍어서 읽기<input type="file" accept="image/*" capture="environment" hidden></label>
      </div>`;
    document.body.appendChild(el);
    sfx.play("open");
    const video = el.querySelector("video");
    const msg = el.querySelector(".msg");
    const hits = el.querySelector(".hits");
    let stream = null;
    let timer = null;
    let closed = false;
    let lastCode = "";
    let lastAt = 0;
    let busy = false;
    let facing = "environment";

    const stop = () => {
      clearTimeout(timer);
      if (stream) for (const t of stream.getTracks()) t.stop();
      stream = null;
    };
    const close = (value = null) => {
      if (closed) return;
      closed = true;
      stop();
      document.removeEventListener("keydown", onKey, true);
      el.remove();
      sfx.play("close");
      resolve(value);
    };
    const onKey = (ev) => { if (ev.key === "Escape") { ev.stopPropagation(); close(null); } };
    document.addEventListener("keydown", onKey, true);
    el.querySelector('[data-b="close"]').onclick = () => close(null);

    async function got(code) {
      code = String(code || "").trim();
      if (!code) return;
      const now = Date.now();
      if (code === lastCode && now - lastAt < 2500) return;
      lastCode = code;
      lastAt = now;
      sfx.play("scan");
      vibrate(30);
      el.classList.add("hit");
      setTimeout(() => el.classList.remove("hit"), 400);
      if (!continuous) { close(code); return; }
      busy = true;
      try {
        const out = onCode ? await onCode(code) : { ok: true, text: code };
        msg.textContent = out && out.text ? out.text : code;
        if (hits && out) hits.insertAdjacentHTML("afterbegin", `<span class="tag ${out.ok ? "good" : "crit"}">${esc(out.short || code)}</span>`);
      } catch (e) {
        msg.textContent = e.message;
        sfx.play("error");
      } finally {
        busy = false;
      }
    }

    el.querySelector(".manual").onsubmit = (ev) => {
      ev.preventDefault();
      const input = el.querySelector(".manual input");
      const v = input.value.trim();
      input.value = "";
      if (v) { lastCode = ""; got(v); }
    };
    el.querySelector('input[type="file"]').onchange = async (ev) => {
      const f = ev.target.files[0];
      ev.target.value = "";
      if (!f) return;
      msg.textContent = "사진을 읽는 중…";
      const code = await decodeFile(f);
      if (code) { lastCode = ""; got(code); } else { msg.textContent = "사진에서 코드를 찾지 못했어요. 라벨을 가까이, 밝게 찍어 보세요."; sfx.play("warn"); }
    };

    async function startCam() {
      stop();
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.isSecureContext) {
        el.querySelector(".mid").innerHTML = `<div class="nocam">이 주소에서는 브라우저가 카메라를 막습니다(https 가 아님).<br>아래 <b>사진 찍어서 읽기</b>를 누르거나,<br>휴대폰 기본 카메라로 라벨 QR 을 찍어도 바로 열려요.</div>`;
        return;
      }
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: facing }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
      } catch (e) {
        el.querySelector(".mid").innerHTML = `<div class="nocam">카메라를 열지 못했어요(${esc(e.name)}).<br>주소창에서 카메라 권한을 허용하거나 <b>사진 찍어서 읽기</b>를 쓰세요.</div>`;
        return;
      }
      if (closed) { stop(); return; }
      video.srcObject = stream;
      await video.play().catch(() => {});
      const track = stream.getVideoTracks()[0];
      const caps = track.getCapabilities ? track.getCapabilities() : {};
      const torchBtn = el.querySelector('[data-b="torch"]');
      if (caps.torch) {
        torchBtn.hidden = false;
        let on = false;
        torchBtn.onclick = async () => { on = !on; try { await track.applyConstraints({ advanced: [{ torch: on }] }); torchBtn.setAttribute("aria-pressed", String(on)); } catch { /* 지원 안 함 */ } };
      }
      const devices = await navigator.mediaDevices.enumerateDevices().catch(() => []);
      if (devices.filter((d) => d.kind === "videoinput").length > 1) {
        const sw = el.querySelector('[data-b="switch"]');
        sw.hidden = false;
        sw.onclick = () => { facing = facing === "environment" ? "user" : "environment"; startCam(); };
      }
      const det = await getDetector();
      const loop = async () => {
        if (closed || !stream) return;
        if (!busy && video.readyState >= 2) {
          try {
            const code = await det.detect(video);
            if (code) await got(code);
          } catch { /* 한 장 실패는 넘어간다 */ }
        }
        timer = setTimeout(loop, det.kind === "native" ? 110 : 160);
      };
      loop();
    }
    startCam();
  });
}

// 스캔한 코드를 풀어 알맞은 화면을 연다
export async function handleCode(code, { replace = false } = {}) {
  try {
    const hit = await api.get(`/api/scan/${encodeURIComponent(String(code).trim())}`);
    sfx.play("ok");
    if (hit.type === "location") app.openLocation(hit.id);
    else if (hit.type === "asset") app.openItem(hit.item_id, hit.id);
    else app.openItem(hit.id);
    return hit;
  } catch (e) {
    if (e.status === 404) {
      toast(`등록되지 않은 코드: ${code}`, {
        tone: "warn",
        action: can("register") ? { label: "새로 등록", icon: "plus", run: () => app.addItem({ barcode: /^\d{8,14}$/.test(code) ? code : "", name: "" }) } : null,
      });
    } else toast(e.message, { tone: "crit" });
    if (replace) app.go("bridge");
    return null;
  }
}

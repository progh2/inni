// 사진 다듬기: 자르기·돌리기·뒤집기·밝기/대비/채도·자동 보정·배경 지우기(빠른/AI)·지우개/되살리기 붓.
// 결과는 캔버스로 돌려준다(올리기는 부른 쪽이).
import { $, esc, icon } from "../lib/util.js";
import { modal, toast } from "../lib/ui.js";
import { state } from "../lib/store.js";
import * as sfx from "../lib/sfx.js";
import { loadToCanvas } from "./images.js";


const clone = (c) => {
  const n = document.createElement("canvas");
  n.width = c.width;
  n.height = c.height;
  n.getContext("2d").drawImage(c, 0, 0);
  return n;
};

function rotate(c, dir) {
  const n = document.createElement("canvas");
  n.width = c.height;
  n.height = c.width;
  const ctx = n.getContext("2d");
  ctx.translate(n.width / 2, n.height / 2);
  ctx.rotate((dir * Math.PI) / 2);
  ctx.drawImage(c, -c.width / 2, -c.height / 2);
  return n;
}

function flip(c) {
  const n = document.createElement("canvas");
  n.width = c.width;
  n.height = c.height;
  const ctx = n.getContext("2d");
  ctx.translate(n.width, 0);
  ctx.scale(-1, 1);
  ctx.drawImage(c, 0, 0);
  return n;
}

function crop(c, r) {
  const n = document.createElement("canvas");
  n.width = Math.max(1, Math.round(r.w));
  n.height = Math.max(1, Math.round(r.h));
  n.getContext("2d").drawImage(c, r.x, r.y, r.w, r.h, 0, 0, n.width, n.height);
  return n;
}

// 밝기(-100~100)·대비(-100~100)·채도(-100~100)를 픽셀에 굽는다
export function applyAdjust(c, { b = 0, k = 0, s = 0 }) {
  if (!b && !k && !s) return c;
  const n = clone(c);
  const ctx = n.getContext("2d", { willReadFrequently: true });
  const img = ctx.getImageData(0, 0, n.width, n.height);
  const d = img.data;
  const bb = b * 2.55;
  const kf = (259 * (k * 2.55 + 255)) / (255 * (259 - k * 2.55));
  const sf = 1 + s / 100;
  for (let i = 0; i < d.length; i += 4) {
    let r = d[i] + bb;
    let g = d[i + 1] + bb;
    let bl = d[i + 2] + bb;
    r = kf * (r - 128) + 128;
    g = kf * (g - 128) + 128;
    bl = kf * (bl - 128) + 128;
    const l = 0.299 * r + 0.587 * g + 0.114 * bl;
    d[i] = l + (r - l) * sf;
    d[i + 1] = l + (g - l) * sf;
    d[i + 2] = l + (bl - l) * sf;
  }
  ctx.putImageData(img, 0, 0);
  return n;
}

// 자동 보정: 채널마다 1%~99% 를 늘린다
function autoLevels(c) {
  const ctx = c.getContext("2d", { willReadFrequently: true });
  const img = ctx.getImageData(0, 0, c.width, c.height);
  const d = img.data;
  const hist = [new Uint32Array(256), new Uint32Array(256), new Uint32Array(256)];
  let n = 0;
  for (let i = 0; i < d.length; i += 4) {
    if (d[i + 3] < 128) continue;
    hist[0][d[i]]++; hist[1][d[i + 1]]++; hist[2][d[i + 2]]++;
    n++;
  }
  if (!n) return;
  const lo = [0, 0, 0];
  const hi = [255, 255, 255];
  for (let ch = 0; ch < 3; ch++) {
    let acc = 0;
    for (let v = 0; v < 256; v++) { acc += hist[ch][v]; if (acc > n * 0.01) { lo[ch] = v; break; } }
    acc = 0;
    for (let v = 255; v >= 0; v--) { acc += hist[ch][v]; if (acc > n * 0.01) { hi[ch] = v; break; } }
  }
  for (let i = 0; i < d.length; i += 4) {
    for (let ch = 0; ch < 3; ch++) {
      const span = Math.max(1, hi[ch] - lo[ch]);
      d[i + ch] = Math.max(0, Math.min(255, ((d[i + ch] - lo[ch]) * 255) / span));
    }
  }
  ctx.putImageData(img, 0, 0);
}

// 빠른 배경 지우기: 가장자리에서 시작해 비슷한 색을 따라 지운다(흰 책상·단색 배경에 잘 맞음)
export function floodRemove(c, tolerance = 32) {
  const ctx = c.getContext("2d", { willReadFrequently: true });
  const W = c.width;
  const H = c.height;
  const img = ctx.getImageData(0, 0, W, H);
  const d = img.data;
  // 가장자리 평균색
  let sr = 0; let sg = 0; let sb = 0; let cnt = 0;
  const edge = (x, y) => { const i = (y * W + x) * 4; sr += d[i]; sg += d[i + 1]; sb += d[i + 2]; cnt++; };
  for (let x = 0; x < W; x += 2) { edge(x, 0); edge(x, H - 1); }
  for (let y = 0; y < H; y += 2) { edge(0, y); edge(W - 1, y); }
  const mr = sr / cnt; const mg = sg / cnt; const mb = sb / cnt;
  const tol2 = (tolerance * 1.9) ** 2;
  const step2 = (tolerance * 0.55) ** 2;
  const bg = new Uint8Array(W * H);
  const queue = new Int32Array(W * H);
  let qh = 0; let qt = 0;
  const dist2 = (i, r, g, b) => (d[i] - r) ** 2 + (d[i + 1] - g) ** 2 + (d[i + 2] - b) ** 2;
  const seed = (p) => {
    if (bg[p]) return;
    const i = p * 4;
    if (d[i + 3] < 20 || dist2(i, mr, mg, mb) < tol2) { bg[p] = 1; queue[qt++] = p; }
  };
  for (let x = 0; x < W; x++) { seed(x); seed((H - 1) * W + x); }
  for (let y = 0; y < H; y++) { seed(y * W); seed(y * W + W - 1); }
  while (qh < qt) {
    const p = queue[qh++];
    const x = p % W;
    const y = (p - x) / W;
    const i = p * 4;
    const neigh = [x > 0 ? p - 1 : -1, x < W - 1 ? p + 1 : -1, y > 0 ? p - W : -1, y < H - 1 ? p + W : -1];
    for (const q of neigh) {
      if (q < 0 || bg[q]) continue;
      const j = q * 4;
      const local = (d[j] - d[i]) ** 2 + (d[j + 1] - d[i + 1]) ** 2 + (d[j + 2] - d[i + 2]) ** 2;
      if (d[j + 3] < 20 || (local < step2 && dist2(j, mr, mg, mb) < tol2 * 1.6) || dist2(j, mr, mg, mb) < tol2 * 0.5) {
        bg[q] = 1;
        queue[qt++] = q;
      }
    }
  }
  // 가장자리를 부드럽게(알파 3x3 평균)
  const alpha = new Uint8ClampedArray(W * H);
  for (let p = 0; p < W * H; p++) alpha[p] = bg[p] ? 0 : d[p * 4 + 3];
  for (let y = 1; y < H - 1; y++) {
    for (let x = 1; x < W - 1; x++) {
      const p = y * W + x;
      if (bg[p]) continue;
      let s = 0;
      for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) s += bg[p + dy * W + dx] ? 0 : 255;
      d[p * 4 + 3] = Math.min(alpha[p], s / 9);
    }
  }
  for (let p = 0; p < W * H; p++) if (bg[p]) d[p * 4 + 3] = 0;
  ctx.putImageData(img, 0, 0);
  const removed = bg.reduce((a, v) => a + v, 0) / (W * H);
  return removed;
}

// 브라우저 AI 배경 지우기는 숨은 iframe(/bg-lab.html)에서 돈다(보안 정책을 앱 화면과 나누려고).
// 한 번 띄운 방은 계속 두어서 두 번째부터는 모델을 다시 받지 않는다.
let lab = null;
function bgLab() {
  if (lab) return lab;
  const frame = document.createElement("iframe");
  frame.src = "/bg-lab.html";
  frame.hidden = true;
  frame.tabIndex = -1;
  frame.setAttribute("aria-hidden", "true");
  const jobs = new Map();
  let ready;
  const readyP = new Promise((resolve) => { ready = resolve; });
  window.addEventListener("message", (ev) => {
    if (ev.source !== frame.contentWindow || ev.origin !== location.origin) return;
    const m = ev.data || {};
    if (m.type === "ready") { ready(); return; }
    const job = jobs.get(m.id);
    if (!job) return;
    job.seen = Date.now();
    if (m.type === "progress") job.progress(m.text);
    else {
      jobs.delete(m.id);
      if (m.type === "done") job.resolve(new Blob([m.buffer], { type: "image/png" }));
      else job.reject(new Error(m.error || "배경을 지우지 못했어요"));
    }
  });
  document.body.appendChild(frame);
  lab = { frame, jobs, ready: readyP };
  return lab;
}

async function removeInLab(blob, onProgress) {
  const L = bgLab();
  const id = `bg${Date.now()}${Math.random().toString(36).slice(2, 6)}`;
  const buffer = await blob.arrayBuffer();
  return new Promise((resolve, reject) => {
    const job = { seen: Date.now(), progress: (t) => onProgress && onProgress(t), resolve, reject };
    // 모델을 받는 동안은 진행률이 계속 온다. 한동안 아무 소식이 없으면 멈춘 것으로 본다
    const timer = setInterval(() => {
      if (Date.now() - job.seen > 60000) {
        L.jobs.delete(id);
        reject(new Error("AI 가 응답하지 않아요(인터넷 연결을 확인하세요)"));
      }
    }, 2000);
    const done = (fn) => (v) => { clearInterval(timer); fn(v); };
    job.resolve = done(resolve);
    job.reject = done(reject);
    L.jobs.set(id, job);
    L.ready.then(() => L.frame.contentWindow.postMessage({ id, buffer, type: blob.type }, location.origin, [buffer]));
  });
}

async function aiRemove(c, onProgress) {
  const mode = state.settings.images.bg_removal;
  const blob = await new Promise((r) => c.toBlob(r, "image/png"));
  let out;
  if (mode === "rembg") {
    const r = await fetch("/api/rembg", { method: "POST", credentials: "same-origin", headers: { "x-inni": "1", "Content-Type": "image/png" }, body: blob });
    if (!r.ok) {
      let m = `배경 제거 서버 오류 (HTTP ${r.status})`;
      try { m = (await r.json()).error || m; } catch { /* 글자 아님 */ }
      throw new Error(m);
    }
    out = await r.blob();
  } else {
    out = await removeInLab(blob, onProgress);
  }
  return loadToCanvas(out, Math.max(c.width, c.height));
}

/**
 * @param src Blob | 캔버스
 * @returns {Promise<HTMLCanvasElement|null>}
 */
export async function editImage(src, { title = "사진 다듬기", autoRemove = false } = {}) {
  let work = src instanceof HTMLCanvasElement ? clone(src) : await loadToCanvas(src);
  let orig = clone(work);
  const history = [];
  const push = () => { history.push([clone(work), clone(orig)]); if (history.length > 12) history.shift(); };
  const adj = { b: 0, k: 0, s: 0 };
  let mode = "none";
  let brush = 26;
  let whiteBg = false;
  let result = null;
  const bgMode = state.settings.images.bg_removal;

  const body = document.createElement("div");
  body.className = "editor";
  body.innerHTML = `
    <div class="canvas-wrap"><canvas></canvas><div class="crop" hidden><i class="nw"></i><i class="ne"></i><i class="sw"></i><i class="se"></i></div><div class="brush-cursor" hidden></div><div class="busy-cover" hidden><div><div class="spin"></div><span></span></div></div></div>
    <div class="side">
      <div class="grp"><b>TRANSFORM</b><div class="tool-row">
        <button class="btn" type="button" data-t="crop">${icon("crop")}자르기</button>
        <button class="btn" type="button" data-t="rotl">${icon("rotate")}돌리기</button>
        <button class="btn" type="button" data-t="flip">${icon("flip")}뒤집기</button></div>
        <div class="row" data-cropbar hidden><div class="seg" data-aspect><button type="button" data-v="0" aria-pressed="true">자유</button><button type="button" data-v="1">1:1</button><button type="button" data-v="1.333">4:3</button></div>
          <button class="btn sm primary" type="button" data-t="crop-ok">${icon("check")}자르기 적용</button></div></div>
      <div class="grp"><b>BACKGROUND · 배경</b><div class="tool-row">
        ${bgMode !== "off" ? `<button class="btn" type="button" data-t="ai">${icon("magic")}AI 지우기</button>` : ""}
        <button class="btn" type="button" data-t="quick">${icon("wand")}빠른 지우기</button>
        <button class="btn" type="button" data-t="erase">${icon("eraser")}지우개</button>
        <button class="btn" type="button" data-t="restore">${icon("brush")}되살리기</button>
        <button class="btn" type="button" data-t="white">${icon("image")}흰 배경</button>
        <button class="btn" type="button" data-t="undo">${icon("undo")}되돌리기</button></div>
        <label class="slider" data-tol hidden><span>허용 범위</span><input type="range" min="8" max="90" value="32"><span class="num">32</span></label>
        <label class="slider" data-brush hidden><span>붓 크기</span><input type="range" min="6" max="90" value="26"><span class="num">26</span></label></div>
      <div class="grp"><b>LIGHT · 빛</b>
        <label class="slider"><span>밝기</span><input type="range" data-a="b" min="-60" max="60" value="0"><span class="num">0</span></label>
        <label class="slider"><span>대비</span><input type="range" data-a="k" min="-60" max="60" value="0"><span class="num">0</span></label>
        <label class="slider"><span>채도</span><input type="range" data-a="s" min="-100" max="80" value="0"><span class="num">0</span></label>
        <button class="btn sm" type="button" data-t="auto">${icon("sun")}자동 보정</button></div>
    </div>`;
  const view = body.querySelector("canvas");
  const wrap = body.querySelector(".canvas-wrap");
  const cropEl = body.querySelector(".crop");
  const cursor = body.querySelector(".brush-cursor");
  const cover = body.querySelector(".busy-cover");
  const vctx = view.getContext("2d");

  function draw() {
    view.width = work.width;
    view.height = work.height;
    vctx.clearRect(0, 0, view.width, view.height);
    if (whiteBg) { vctx.fillStyle = "#fff"; vctx.fillRect(0, 0, view.width, view.height); }
    vctx.drawImage(work, 0, 0);
    view.style.filter = `brightness(${1 + adj.b / 100}) contrast(${1 + adj.k / 100}) saturate(${1 + adj.s / 100})`;
  }
  const busy = (text) => { cover.hidden = !text; if (text) cover.querySelector("span").textContent = text; };
  const scale = () => view.getBoundingClientRect().width / view.width;

  // ---- 자르기 상자
  let cr = null;
  let aspect = 0;
  function showCrop() {
    const s = scale();
    const vr = view.getBoundingClientRect();
    const wr = wrap.getBoundingClientRect();
    cropEl.style.left = `${vr.left - wr.left + cr.x * s}px`;
    cropEl.style.top = `${vr.top - wr.top + cr.y * s}px`;
    cropEl.style.width = `${cr.w * s}px`;
    cropEl.style.height = `${cr.h * s}px`;
  }
  function startCrop() {
    mode = "crop";
    const m = Math.round(Math.min(work.width, work.height) * 0.08);
    cr = { x: m, y: m, w: work.width - 2 * m, h: work.height - 2 * m };
    cropEl.hidden = false;
    body.querySelector("[data-cropbar]").hidden = false;
    showCrop();
  }
  function endCrop() {
    mode = "none";
    cropEl.hidden = true;
    body.querySelector("[data-cropbar]").hidden = true;
  }
  let drag = null;
  cropEl.addEventListener("pointerdown", (ev) => {
    ev.preventDefault();
    cropEl.setPointerCapture(ev.pointerId);
    drag = { h: ev.target.tagName === "I" ? ev.target.className : "move", x: ev.clientX, y: ev.clientY, r: { ...cr } };
  });
  cropEl.addEventListener("pointermove", (ev) => {
    if (!drag) return;
    const s = scale();
    const dx = (ev.clientX - drag.x) / s;
    const dy = (ev.clientY - drag.y) / s;
    const r = { ...drag.r };
    if (drag.h === "move") { r.x += dx; r.y += dy; }
    else {
      if (drag.h.includes("w")) { r.x += dx; r.w -= dx; }
      if (drag.h.includes("e")) r.w += dx;
      if (drag.h.includes("n")) { r.y += dy; r.h -= dy; }
      if (drag.h.includes("s")) r.h += dy;
      if (aspect) { r.h = r.w / aspect; }
    }
    r.w = Math.max(20, Math.min(work.width, r.w));
    r.h = Math.max(20, Math.min(work.height, r.h));
    r.x = Math.max(0, Math.min(work.width - r.w, r.x));
    r.y = Math.max(0, Math.min(work.height - r.h, r.y));
    cr = r;
    showCrop();
  });
  cropEl.addEventListener("pointerup", () => { drag = null; });

  // ---- 붓
  let painting = false;
  const toWork = (ev) => {
    const r = view.getBoundingClientRect();
    return { x: ((ev.clientX - r.left) / r.width) * work.width, y: ((ev.clientY - r.top) / r.height) * work.height };
  };
  function paint(ev) {
    const p = toWork(ev);
    const rad = brush / scale() / 2;
    const ctx = work.getContext("2d");
    ctx.save();
    ctx.beginPath();
    ctx.arc(p.x, p.y, rad, 0, Math.PI * 2);
    if (mode === "erase") {
      ctx.globalCompositeOperation = "destination-out";
      ctx.fill();
    } else {
      ctx.clip();
      ctx.clearRect(p.x - rad, p.y - rad, rad * 2, rad * 2);
      ctx.drawImage(orig, 0, 0);
    }
    ctx.restore();
    draw();
  }
  view.addEventListener("pointerdown", (ev) => {
    if (mode !== "erase" && mode !== "restore") return;
    ev.preventDefault();
    view.setPointerCapture(ev.pointerId);
    push();
    painting = true;
    paint(ev);
  });
  view.addEventListener("pointermove", (ev) => {
    if (mode === "erase" || mode === "restore") {
      const wr = wrap.getBoundingClientRect();
      cursor.hidden = false;
      cursor.style.left = `${ev.clientX - wr.left}px`;
      cursor.style.top = `${ev.clientY - wr.top}px`;
      cursor.style.width = cursor.style.height = `${brush}px`;
    }
    if (painting) paint(ev);
  });
  view.addEventListener("pointerup", () => { painting = false; });
  view.addEventListener("pointerleave", () => { cursor.hidden = true; });

  // ---- 도구
  const tolBox = body.querySelector("[data-tol]");
  const brushBox = body.querySelector("[data-brush]");
  let quickBase = null;
  function pressMode(t) {
    for (const b of body.querySelectorAll("[data-t]")) b.setAttribute("aria-pressed", String(b.dataset.t === t && (t === "erase" || t === "restore" || t === "white" && whiteBg)));
  }
  async function runAi() {
    push();
    busy("AI 가 물건만 남기는 중… (처음엔 모델을 받느라 30초쯤 걸려요)");
    try {
      const out = await aiRemove(work, (t) => busy(t));
      work = out.width === work.width ? out : (() => { const n = document.createElement("canvas"); n.width = work.width; n.height = work.height; n.getContext("2d").drawImage(out, 0, 0, n.width, n.height); return n; })();
      draw();
      sfx.play("ok");
    } catch (e) {
      history.pop();
      toast(`AI 배경 지우기 실패: ${e.message}. 빠른 지우기를 써 보세요.`, { tone: "warn" });
    } finally { busy(""); }
  }
  body.addEventListener("click", async (ev) => {
    const b = ev.target.closest("[data-t]");
    if (!b) return;
    const t = b.dataset.t;
    sfx.play("click");
    if (t !== "crop" && t !== "crop-ok" && mode === "crop") endCrop();
    if (t === "crop") startCrop();
    else if (t === "crop-ok") { push(); work = crop(work, cr); orig = crop(orig, cr); endCrop(); draw(); }
    else if (t === "rotl") { push(); work = rotate(work, 1); orig = rotate(orig, 1); draw(); }
    else if (t === "flip") { push(); work = flip(work); orig = flip(orig); draw(); }
    else if (t === "auto") { push(); autoLevels(work); autoLevels(orig); draw(); }
    else if (t === "ai") await runAi();
    else if (t === "quick") {
      push();
      quickBase = clone(work);
      const removed = floodRemove(work, Number(tolBox.querySelector("input").value));
      tolBox.hidden = false;
      draw();
      if (removed < 0.03) toast("가장자리와 비슷한 배경을 거의 찾지 못했어요. 허용 범위를 늘리거나 AI 지우기를 써 보세요.", { tone: "warn" });
    } else if (t === "erase" || t === "restore") {
      mode = mode === t ? "none" : t;
      brushBox.hidden = mode === "none";
      pressMode(mode);
    } else if (t === "white") { whiteBg = !whiteBg; draw(); b.setAttribute("aria-pressed", String(whiteBg)); }
    else if (t === "undo") {
      const last = history.pop();
      if (last) { [work, orig] = last; draw(); } else toast("되돌릴 것이 없어요", { timeout: 1500 });
    }
  });
  tolBox.querySelector("input").addEventListener("change", (ev) => {
    tolBox.querySelector(".num").textContent = ev.target.value;
    if (!quickBase) return;
    work = clone(quickBase);
    floodRemove(work, Number(ev.target.value));
    draw();
  });
  brushBox.querySelector("input").addEventListener("input", (ev) => { brush = Number(ev.target.value); brushBox.querySelector(".num").textContent = ev.target.value; });
  body.querySelector("[data-aspect]").addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-v]");
    if (!b) return;
    aspect = Number(b.dataset.v);
    for (const x of body.querySelectorAll("[data-aspect] [data-v]")) x.setAttribute("aria-pressed", String(x === b));
    if (aspect && cr) { cr.h = Math.min(work.height - cr.y, cr.w / aspect); cr.w = cr.h * aspect; showCrop(); }
  });
  for (const r of body.querySelectorAll("[data-a]")) {
    r.addEventListener("input", () => { adj[r.dataset.a] = Number(r.value); r.parentElement.querySelector(".num").textContent = r.value; draw(); });
  }

  return new Promise((resolve) => {
    modal({
      title, code: "IMAGE LAB", size: "xwide", body, sheet: true,
      actions: [{ label: "취소", tone: "ghost", value: null }, {
        label: "다 됐어요", tone: "primary", icon: "check",
        onClick: () => {
          let out = applyAdjust(work, adj);
          if (whiteBg) {
            const n = document.createElement("canvas");
            n.width = out.width;
            n.height = out.height;
            const ctx = n.getContext("2d");
            ctx.fillStyle = "#fff";
            ctx.fillRect(0, 0, n.width, n.height);
            ctx.drawImage(out, 0, 0);
            out = n;
          }
          result = out;
        },
      }],
      onClose: () => resolve(result),
      onOpen: () => {
        draw();
        if (autoRemove && bgMode !== "off") setTimeout(runAi, 200);
        window.addEventListener("resize", () => { if (mode === "crop") showCrop(); });
      },
    });
  });
}

export { $ , esc };

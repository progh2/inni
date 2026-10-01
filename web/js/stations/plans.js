// 04 도면: 층마다 도면 그림을 올리고, 그 위에 실을 그려 장소와 잇는다.
// 여기서 그린 대로 3D 선내 지도가 층을 쌓고 실을 세우며, 물건을 찾으면 그 층·그 자리를 비춘다.
import { esc, icon, isMobile } from "../lib/util.js";
import { api, uploadImage } from "../lib/api.js";
import { modal, toast, toastError, confirmDialog, promptDialog, isOverlayOpen } from "../lib/ui.js";
import { state, can } from "../lib/store.js";
import { app } from "../app.js";
import * as sfx from "../lib/sfx.js";

const SHAPE_KINDS = new Set(["room", "zone", "storage", "bin"]);
const KIND = { building: "건물", floor: "층", room: "실", zone: "구역", storage: "보관함", bin: "칸" };
const MARKS = { entrance: "현관", stairs: "계단", elevator: "승강기" };
const MARK_COLOR = { entrance: "#1fbf8f", stairs: "#e0a21c", elevator: "#6f86e8" };
const MAX_SIDE = 4096; // 도면 그림은 이 크기까지(3D 지도 텍스처·저장 용량)

let root;
let hostId = null;
let ed = null; // 지금 편집기
let saveTimer = null;
let saving = false;
let again = false;
let statusText = "";

// ---------------------------------------------------------------- 데이터 도우미
const loc = (id) => state.locMap.get(id);
const planOf = (id) => state.plans.find((p) => p.location_id === id) || null;
function guessLevel(name) {
  const s = String(name || "");
  let m = /지하\s*(\d+)/.exec(s) || /\bB\s*(\d+)/i.exec(s);
  if (m) return -Number(m[1]);
  m = /(\d+)\s*(층|F\b)/i.exec(s);
  return m ? Number(m[1]) : 1;
}
const levelOf = (l) => { const p = planOf(l.id); return p ? p.level : guessLevel(l.name); };
const levelText = (n) => (n < 0 ? `지하 ${-n}층` : `${n}층`);

// 건물마다 도면을 붙일 자리: 층이 있으면 층들, 없으면 건물 자신
function hostGroups() {
  return state.locations.filter((l) => l.kind === "building").map((b) => {
    const fl = state.locations.filter((l) => l.kind === "floor" && l.parent_id === b.id).sort((a, c) => levelOf(a) - levelOf(c));
    return { building: b, hosts: fl.length ? fl : [b] };
  });
}

// 이 도면에 그릴 수 있는 장소: 그 층(건물) 아래 실·구역·보관함. 층 도면이면 층 없이 건물 바로 아래 있는 실도.
function candidates(host) {
  const out = new Map();
  for (const l of state.locations) {
    if (!SHAPE_KINDS.has(l.kind) || !l.path_ids) continue;
    if (l.path_ids.includes(host.id)) out.set(l.id, l);
    else if (host.kind === "floor" && l.path_ids.includes(host.parent_id) && !l.path_ids.some((id) => (loc(id) || {}).kind === "floor")) out.set(l.id, l);
  }
  return [...out.values()];
}
function drawnElsewhere(planId) {
  const s = new Set();
  for (const p of state.plans) if (p.id !== planId) for (const sh of p.shapes) s.add(sh.location_id);
  return s;
}

// ---------------------------------------------------------------- 그림 준비·올리기
async function prepareImage(file) {
  const url = URL.createObjectURL(file);
  try {
    const img = await new Promise((resolve, reject) => {
      const im = new Image();
      im.onload = () => resolve(im);
      im.onerror = () => reject(new Error("그림을 열 수 없어요(PNG·JPG 로 저장해 주세요)"));
      im.src = url;
    });
    const s = Math.min(1, MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
    const w = Math.max(1, Math.round(img.naturalWidth * s));
    const h = Math.max(1, Math.round(img.naturalHeight * s));
    const c = document.createElement("canvas");
    c.width = w;
    c.height = h;
    const g = c.getContext("2d");
    g.fillStyle = "#fff"; // 투명 바탕은 흰색으로(선이 잘 보이게)
    g.fillRect(0, 0, w, h);
    g.drawImage(img, 0, 0, w, h);
    let blob = await new Promise((r) => c.toBlob(r, "image/png"));
    if (!blob || blob.size > 11.5 * 1048576) blob = await new Promise((r) => c.toBlob(r, "image/webp", 0.9));
    if (!blob || blob.size > 11.5 * 1048576) blob = await new Promise((r) => c.toBlob(r, "image/jpeg", 0.85));
    return { blob, width: w, height: h };
  } finally {
    URL.revokeObjectURL(url);
  }
}

function pickFile() {
  return new Promise((resolve) => {
    const input = document.createElement("input");
    input.type = "file";
    input.accept = "image/*";
    input.onchange = () => resolve(input.files && input.files[0] ? input.files[0] : null);
    input.click();
  });
}

async function createPlanFor(host, file) {
  try {
    statusText = "그림을 준비하는 중…";
    render();
    const { blob, width, height } = await prepareImage(file);
    statusText = "올리는 중…";
    render();
    const up = await uploadImage(blob);
    const r = await api.post("/api/plans", { location_id: host.id, image: up.path, width, height });
    state.plans = [...state.plans.filter((p) => p.id !== r.plan.id), r.plan];
    statusText = "";
    sfx.play("ok");
    toast(`${host.name} 도면을 올렸어요. 이제 실을 그려 이어 주세요.`, { tone: "good" });
    render();
  } catch (e) {
    statusText = "";
    render();
    toastError(e);
  }
}

// ---------------------------------------------------------------- 저장(그리는 대로 자동)
function scheduleSave() {
  statusText = "저장 전…";
  paintStatus();
  clearTimeout(saveTimer);
  saveTimer = setTimeout(save, 650);
}
async function save() {
  if (!ed) return;
  if (saving) { again = true; return; }
  saving = true;
  statusText = "저장 중…";
  paintStatus();
  const planId = ed.plan.id;
  try {
    const r = await api.patch(`/api/plans/${planId}`, { shapes: ed.shapes, marks: ed.marks });
    state.plans = state.plans.map((p) => (p.id === planId ? r.plan : p));
    statusText = "저장됨";
  } catch (e) {
    statusText = "저장 못 함";
    toastError(e);
  } finally {
    saving = false;
    paintStatus();
    paintSide();
    if (again) { again = false; save(); }
  }
}
function paintStatus() {
  const el = root && root.querySelector("[data-status]");
  if (el) {
    el.textContent = statusText;
    el.className = `pe-status ${statusText === "저장됨" ? "good" : statusText === "저장 못 함" ? "bad" : ""}`;
  }
}
const flush = () => { if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; save(); } };

// ---------------------------------------------------------------- 실 고르기(그린 뒤)
function pickLocation(host, planId, current = []) {
  return new Promise((resolve) => {
    const taken = drawnElsewhere(planId);
    const here = new Set(current);
    const list = candidates(host).filter((l) => !taken.has(l.id)).sort((a, b) => Number(here.has(a.id)) - Number(here.has(b.id)) || a.name.localeCompare(b.name, "ko"));
    let picked = null;
    const body = document.createElement("div");
    body.innerHTML = `
      <div class="search" style="margin-bottom:10px">${icon("search")}<input type="search" placeholder="실 이름·번호" data-q autofocus></div>
      <div class="pick-grid" data-list style="max-height:46vh;overflow:auto"></div>
      <hr class="sep"><div class="pick-sec">NEW · 목록에 없으면</div>
      <form class="row nw" data-new><input type="text" placeholder="새 실 이름(예: 전자실습실)" maxlength="60"><input type="text" placeholder="번호(선택)" maxlength="20" style="max-width:120px"><button class="btn" type="submit">${icon("plus")}만들기</button></form>`;
    const paint = (q = "") => {
      const k = q.trim().toLowerCase();
      const rows = list.filter((l) => !k || l.name.toLowerCase().includes(k) || String(l.code || "").toLowerCase().includes(k));
      body.querySelector("[data-list]").innerHTML = rows.length
        ? rows.map((l) => `<button class="pick" type="button" data-id="${l.id}"><span class="n">${esc(l.name)}${l.code ? ` <small class="muted mono">${esc(l.code)}</small>` : ""}</span><span class="p">${esc(KIND[l.kind] || "")}${here.has(l.id) ? " · 이미 그림(다시 그리면 바뀜)" : ""}</span></button>`).join("")
        : `<div class="empty">그릴 수 있는 실이 없어요. 아래에서 새로 만드세요.</div>`;
    };
    paint();
    const h = modal({ title: "어느 실인가요?", code: "ASSIGN", body, actions: [{ label: "취소", tone: "ghost" }], onClose: () => resolve(picked) });
    body.querySelector("[data-q]").addEventListener("input", (ev) => paint(ev.target.value));
    body.querySelector("[data-list]").addEventListener("click", (ev) => {
      const b = ev.target.closest("[data-id]");
      if (!b) return;
      picked = b.dataset.id;
      h.close();
    });
    body.querySelector("[data-new]").addEventListener("submit", async (ev) => {
      ev.preventDefault();
      const [n, c] = ev.currentTarget.querySelectorAll("input");
      const name = n.value.trim();
      if (!name) { n.focus(); return; }
      try {
        const r = await api.post("/api/locations", { name, code: c.value.trim() || undefined, kind: "room", parent_id: host.id });
        await app.refreshCore();
        picked = r.location.id;
        toast(`${name} 만들었어요`, { tone: "good" });
        h.close();
      } catch (e) { toastError(e); }
    });
  });
}

// ---------------------------------------------------------------- 캔버스 편집기
function centroid(pts) {
  let a = 0;
  let cx = 0;
  let cy = 0;
  for (let i = 0; i < pts.length; i++) {
    const [x0, y0] = pts[i];
    const [x1, y1] = pts[(i + 1) % pts.length];
    const f = x0 * y1 - x1 * y0;
    a += f;
    cx += (x0 + x1) * f;
    cy += (y0 + y1) * f;
  }
  if (Math.abs(a) < 1e-6) return [pts.reduce((s, p) => s + p[0], 0) / pts.length, pts.reduce((s, p) => s + p[1], 0) / pts.length];
  return [cx / (3 * a), cy / (3 * a)];
}
function inside([x, y], pts) {
  let hit = false;
  for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
    const [xi, yi] = pts[i];
    const [xj, yj] = pts[j];
    if ((yi > y) !== (yj > y) && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) hit = !hit;
  }
  return hit;
}

function createEditor(wrap, { host, plan, img }) {
  let image = img;
  const canvas = wrap.querySelector("canvas");
  const g = canvas.getContext("2d");
  const self = {
    plan,
    shapes: plan.shapes.map((s) => ({ location_id: s.location_id, pts: s.pts.map((p) => [...p]) })),
    marks: plan.marks.map((m) => ({ ...m })),
    tool: "select",
    markType: "entrance",
    armed: null,
  };
  let sel = null; // { type: "shape"|"mark", i }
  let view = { s: 1, x: 0, y: 0 };
  let drag = null;
  let draft = null;
  let hover = null;
  let W = 0;
  let H = 0;
  let fitted = false;
  const dpr = () => window.devicePixelRatio || 1;
  const toImg = (sx, sy) => [(sx - view.x) / view.s, (sy - view.y) / view.s];
  const toScr = (ix, iy) => [ix * view.s + view.x, iy * view.s + view.y];
  const clampI = ([x, y]) => [Math.min(plan.width, Math.max(0, x)), Math.min(plan.height, Math.max(0, y))];
  const hint = (t) => { const el = wrap.querySelector("[data-hint]"); if (el) { el.textContent = t || ""; el.hidden = !t; } };

  function resize() {
    const r = wrap.getBoundingClientRect();
    W = Math.max(10, r.width);
    H = Math.max(10, r.height);
    canvas.width = Math.round(W * dpr());
    canvas.height = Math.round(H * dpr());
    canvas.style.width = `${W}px`;
    canvas.style.height = `${H}px`;
    if (!fitted) { fit(); fitted = true; } else draw();
  }
  function fit() {
    const s = Math.min(W / plan.width, H / plan.height) * 0.94;
    view = { s, x: (W - plan.width * s) / 2, y: (H - plan.height * s) / 2 };
    draw();
  }
  function zoomAt(f, sx = W / 2, sy = H / 2) {
    const s = Math.min(40, Math.max(0.02, view.s * f));
    const [ix, iy] = toImg(sx, sy);
    view = { s, x: sx - ix * s, y: sy - iy * s };
    draw();
  }
  function path(pts) {
    g.beginPath();
    pts.forEach(([x, y], k) => { const [a, b] = toScr(x, y); if (k) g.lineTo(a, b); else g.moveTo(a, b); });
    g.closePath();
  }
  function pill(text, x, y, { bg = "rgba(4,16,30,.86)", fg = "#e8f7ff", bold = false } = {}) {
    g.font = `${bold ? 700 : 600} 12px Pretendard, "Malgun Gothic", sans-serif`;
    const w = g.measureText(text).width + 12;
    g.fillStyle = bg;
    g.beginPath();
    g.roundRect(x - w / 2, y - 10, w, 20, 6);
    g.fill();
    g.fillStyle = fg;
    g.textAlign = "center";
    g.textBaseline = "middle";
    g.fillText(text, x, y + 0.5);
  }
  function draw() {
    const d = dpr();
    g.setTransform(d, 0, 0, d, 0, 0);
    g.clearRect(0, 0, W, H);
    // 도면
    g.save();
    g.translate(view.x, view.y);
    g.scale(view.s, view.s);
    g.fillStyle = "#fff";
    g.fillRect(0, 0, plan.width, plan.height);
    if (image) g.drawImage(image, 0, 0, plan.width, plan.height);
    g.restore();
    g.strokeStyle = "rgba(95,220,255,.6)";
    g.lineWidth = 1;
    g.strokeRect(view.x - 0.5, view.y - 0.5, plan.width * view.s + 1, plan.height * view.s + 1);
    // 실
    self.shapes.forEach((s, i) => {
      const l = loc(s.location_id);
      const on = sel && sel.type === "shape" && sel.i === i;
      const small = l && (l.kind === "storage" || l.kind === "bin");
      path(s.pts);
      g.fillStyle = on ? "rgba(255,200,87,.32)" : small ? "rgba(31,191,143,.22)" : "rgba(43,184,236,.24)";
      g.fill();
      g.lineWidth = on ? 2.6 : 1.8;
      g.strokeStyle = on ? "#f0a500" : small ? "#14a37a" : "#1592c4";
      g.stroke();
      const [cx, cy] = toScr(...centroid(s.pts));
      pill(l ? `${l.name}${l.code ? ` ${l.code}` : ""}` : "(지워진 장소)", cx, cy, on ? { bg: "#f0a500", fg: "#1a1200", bold: true } : {});
      if (on) {
        for (const [x, y] of s.pts) {
          const [a, b] = toScr(x, y);
          g.fillStyle = "#fff";
          g.strokeStyle = "#f0a500";
          g.lineWidth = 2;
          g.beginPath();
          g.arc(a, b, 5.5, 0, Math.PI * 2);
          g.fill();
          g.stroke();
        }
      }
    });
    // 표시
    self.marks.forEach((m, i) => {
      const [a, b] = toScr(m.x, m.y);
      const on = sel && sel.type === "mark" && sel.i === i;
      g.fillStyle = MARK_COLOR[m.type];
      g.strokeStyle = on ? "#f0a500" : "#fff";
      g.lineWidth = on ? 3 : 2;
      g.beginPath();
      g.arc(a, b, 9, 0, Math.PI * 2);
      g.fill();
      g.stroke();
      g.fillStyle = "#fff";
      g.font = "700 10px Pretendard, sans-serif";
      g.textAlign = "center";
      g.textBaseline = "middle";
      g.fillText(MARKS[m.type][0], a, b + 0.5);
      pill(m.label || MARKS[m.type], a, b - 20, { bg: "rgba(4,16,30,.78)" });
    });
    // 그리는 중
    if (draft && draft.type === "rect" && draft.b) {
      const [x0, y0] = toScr(...draft.a);
      const [x1, y1] = toScr(...draft.b);
      g.fillStyle = "rgba(255,200,87,.18)";
      g.strokeStyle = "#f0a500";
      g.lineWidth = 2;
      g.setLineDash([6, 4]);
      g.fillRect(Math.min(x0, x1), Math.min(y0, y1), Math.abs(x1 - x0), Math.abs(y1 - y0));
      g.strokeRect(Math.min(x0, x1), Math.min(y0, y1), Math.abs(x1 - x0), Math.abs(y1 - y0));
      g.setLineDash([]);
    }
    if (draft && (draft.type === "poly" || draft.type === "scale") && draft.pts.length) {
      g.strokeStyle = draft.type === "scale" ? "#e0336a" : "#f0a500";
      g.lineWidth = 2;
      g.setLineDash([6, 4]);
      g.beginPath();
      draft.pts.forEach(([x, y], k) => { const [a, b] = toScr(x, y); if (k) g.lineTo(a, b); else g.moveTo(a, b); });
      if (hover) { const [a, b] = toScr(...hover); g.lineTo(a, b); }
      g.stroke();
      g.setLineDash([]);
      for (const [x, y] of draft.pts) {
        const [a, b] = toScr(x, y);
        g.fillStyle = "#f0a500";
        g.beginPath();
        g.arc(a, b, 4.5, 0, Math.PI * 2);
        g.fill();
      }
    }
  }

  // ---- 맞히기
  function hitVertex(sx, sy) {
    if (!sel || sel.type !== "shape") return -1;
    const s = self.shapes[sel.i];
    return s.pts.findIndex(([x, y]) => { const [a, b] = toScr(x, y); return Math.hypot(a - sx, b - sy) < 9; });
  }
  function hitMark(sx, sy) {
    for (let i = self.marks.length - 1; i >= 0; i--) {
      const [a, b] = toScr(self.marks[i].x, self.marks[i].y);
      if (Math.hypot(a - sx, b - sy) < 11) return i;
    }
    return -1;
  }
  function hitShape(sx, sy) {
    const p = toImg(sx, sy);
    for (let i = self.shapes.length - 1; i >= 0; i--) if (inside(p, self.shapes[i].pts)) return i;
    return -1;
  }
  const changed = () => { draw(); scheduleSave(); };
  function select(s) {
    sel = s;
    draw();
    paintSide();
  }

  async function finishShape(pts) {
    draft = null;
    draw();
    const id = self.armed || (await pickLocation(host, plan.id, self.shapes.map((s) => s.location_id)));
    self.armed = null;
    paintSide();
    if (!id) { hint(""); return; }
    const old = self.shapes.findIndex((s) => s.location_id === id);
    if (old >= 0) self.shapes.splice(old, 1);
    self.shapes.push({ location_id: id, pts: pts.map(clampI) });
    sel = { type: "shape", i: self.shapes.length - 1 };
    sfx.play("ok");
    hint("");
    changed();
    paintSide();
  }
  function finishPoly() {
    if (!draft || draft.type !== "poly") return;
    if (draft.pts.length >= 3) finishShape(draft.pts);
    else { draft = null; draw(); }
  }
  async function finishScale(pts) {
    draft = null;
    draw();
    const px = Math.hypot(pts[1][0] - pts[0][0], pts[1][1] - pts[0][1]);
    if (px < 4) return;
    const v = await promptDialog({ title: "축척 맞추기", label: "두 점 사이의 실제 거리(m)", placeholder: "예: 8.4", confirmLabel: "맞추기" });
    const m = Number(String(v || "").replace(",", "."));
    if (!m || m <= 0) return;
    try {
      const r = await api.patch(`/api/plans/${plan.id}`, { meters_per_px: m / px });
      state.plans = state.plans.map((p) => (p.id === plan.id ? r.plan : p));
      self.plan = r.plan;
      toast(`축척을 맞췄어요(그림 100px ≈ ${((m / px) * 100).toFixed(2)}m)`, { tone: "good" });
      paintFoot();
    } catch (e) { toastError(e); }
  }

  function setTool(t) {
    self.tool = t;
    draft = null;
    hint({
      select: "실을 눌러 고르고, 점을 끌어 모양을 고칩니다. 빈 곳을 끌면 화면이 움직여요.",
      rect: "끌어서 사각형으로 실을 그립니다.",
      poly: "점을 차례로 누르고, 첫 점을 다시 누르거나 Enter 로 닫습니다.",
      mark: "누른 곳에 표시를 둡니다(현관·계단·승강기).",
      scale: "길이를 아는 두 점(예: 복도 끝과 끝)을 차례로 누르세요.",
    }[t]);
    for (const b of root.querySelectorAll("[data-tool]")) b.setAttribute("aria-pressed", String(b.dataset.tool === t));
    const mt = root.querySelector("[data-marktype]");
    if (mt) mt.hidden = t !== "mark";
    canvas.style.cursor = t === "select" ? "default" : "crosshair";
    draw();
  }

  // ---- 포인터
  canvas.addEventListener("pointerdown", (ev) => {
    canvas.setPointerCapture(ev.pointerId);
    const r = canvas.getBoundingClientRect();
    const sx = ev.clientX - r.left;
    const sy = ev.clientY - r.top;
    const p = clampI(toImg(sx, sy));
    if (ev.button === 1 || ev.button === 2 || ev.shiftKey) { drag = { kind: "pan", sx, sy, vx: view.x, vy: view.y }; return; }
    const t = self.tool;
    if (t === "select") {
      const v = hitVertex(sx, sy);
      if (v >= 0) { drag = { kind: "vertex", i: sel.i, v }; return; }
      const m = hitMark(sx, sy);
      if (m >= 0) { select({ type: "mark", i: m }); drag = { kind: "mark", i: m, moved: false }; return; }
      const s = hitShape(sx, sy);
      if (s >= 0) { select({ type: "shape", i: s }); drag = { kind: "shape", i: s, from: p, orig: self.shapes[s].pts.map((q) => [...q]), moved: false }; return; }
      select(null);
      drag = { kind: "pan", sx, sy, vx: view.x, vy: view.y };
    } else if (t === "rect") {
      draft = { type: "rect", a: p, b: null };
    } else if (t === "poly") {
      if (!draft) draft = { type: "poly", pts: [] };
      const first = draft.pts[0];
      if (first && draft.pts.length >= 3) {
        const [a, b] = toScr(...first);
        if (Math.hypot(a - sx, b - sy) < 11) { finishPoly(); return; }
      }
      draft.pts.push(p);
      draw();
    } else if (t === "mark") {
      self.marks.push({ type: self.markType, x: Math.round(p[0]), y: Math.round(p[1]), label: "" });
      select({ type: "mark", i: self.marks.length - 1 });
      changed();
    } else if (t === "scale") {
      if (!draft) draft = { type: "scale", pts: [] };
      draft.pts.push(p);
      if (draft.pts.length === 2) finishScale(draft.pts);
      else draw();
    }
  });
  canvas.addEventListener("pointermove", (ev) => {
    const r = canvas.getBoundingClientRect();
    const sx = ev.clientX - r.left;
    const sy = ev.clientY - r.top;
    const p = clampI(toImg(sx, sy));
    if (drag) {
      if (drag.kind === "pan") { view.x = drag.vx + (sx - drag.sx); view.y = drag.vy + (sy - drag.sy); draw(); }
      else if (drag.kind === "vertex") { self.shapes[drag.i].pts[drag.v] = p; drag.moved = true; draw(); }
      else if (drag.kind === "shape") {
        const dx = p[0] - drag.from[0];
        const dy = p[1] - drag.from[1];
        self.shapes[drag.i].pts = drag.orig.map(([x, y]) => clampI([x + dx, y + dy]));
        drag.moved = true;
        draw();
      } else if (drag.kind === "mark") { self.marks[drag.i].x = Math.round(p[0]); self.marks[drag.i].y = Math.round(p[1]); drag.moved = true; draw(); }
      return;
    }
    if (draft && draft.type === "rect" && ev.buttons) { draft.b = p; draw(); return; }
    if (draft && (draft.type === "poly" || draft.type === "scale")) { hover = p; draw(); }
  });
  canvas.addEventListener("pointerup", () => {
    if (drag) {
      const moved = drag.kind !== "pan" && (drag.kind === "vertex" || drag.moved);
      drag = null;
      if (moved) changed();
      return;
    }
    if (draft && draft.type === "rect") {
      const [x0, y0] = draft.a;
      const b = draft.b;
      if (!b || Math.abs(b[0] - x0) * view.s < 6 || Math.abs(b[1] - y0) * view.s < 6) { draft = null; draw(); return; }
      const [x1, y1] = b;
      finishShape([[Math.min(x0, x1), Math.min(y0, y1)], [Math.max(x0, x1), Math.min(y0, y1)], [Math.max(x0, x1), Math.max(y0, y1)], [Math.min(x0, x1), Math.max(y0, y1)]]);
    }
  });
  canvas.addEventListener("dblclick", () => { if (self.tool === "poly") finishPoly(); });
  canvas.addEventListener("contextmenu", (ev) => ev.preventDefault());
  canvas.addEventListener("wheel", (ev) => {
    ev.preventDefault();
    const r = canvas.getBoundingClientRect();
    zoomAt(ev.deltaY > 0 ? 0.88 : 1.14, ev.clientX - r.left, ev.clientY - r.top);
  }, { passive: false });

  // ---- 자판(이 화면이 열려 있을 때만, 다른 단축키보다 먼저)
  const onKey = (ev) => {
    if (app.current !== "plans" || isOverlayOpen()) return;
    const t = ev.target;
    if (t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA" || t.tagName === "SELECT" || t.isContentEditable)) return;
    let handled = true;
    if ((ev.key === "Delete" || ev.key === "Backspace") && sel) self.removeSelected();
    else if (ev.key === "Escape" && (draft || sel || self.armed)) { draft = null; self.armed = null; sel = null; hint(""); draw(); paintSide(); }
    else if (ev.key === "Enter" && draft && draft.type === "poly") finishPoly();
    else if (!ev.ctrlKey && !ev.metaKey && !ev.altKey && ev.code === "KeyV") setTool("select");
    else if (!ev.ctrlKey && !ev.metaKey && !ev.altKey && ev.code === "KeyR") setTool("rect");
    else if (!ev.ctrlKey && !ev.metaKey && !ev.altKey && ev.code === "KeyP") setTool("poly");
    else handled = false;
    if (handled) { ev.preventDefault(); ev.stopPropagation(); }
  };
  window.addEventListener("keydown", onKey, true);
  const ro = new ResizeObserver(() => resize());
  ro.observe(wrap);

  Object.assign(self, {
    setImage(im) { image = im; draw(); },
    setTool,
    fit,
    zoomAt,
    draw,
    selected: () => sel,
    selectLocation(id) {
      const i = self.shapes.findIndex((s) => s.location_id === id);
      if (i < 0) return;
      select({ type: "shape", i });
      // 그 실이 가운데 오게
      const [cx, cy] = centroid(self.shapes[i].pts);
      view.x = W / 2 - cx * view.s;
      view.y = H / 2 - cy * view.s;
      draw();
    },
    arm(id) {
      self.armed = id;
      if (self.tool !== "rect" && self.tool !== "poly") setTool("rect");
      const l = loc(id);
      hint(`${l ? l.name : "실"}: 도면 위에 ${self.tool === "poly" ? "점을 찍어" : "끌어서"} 그리세요. (Esc 취소)`);
      paintSide();
    },
    removeSelected() {
      if (!sel) return;
      if (sel.type === "shape") self.shapes.splice(sel.i, 1);
      else self.marks.splice(sel.i, 1);
      sel = null;
      changed();
      paintSide();
    },
    setMarkLabel(text) {
      if (!sel || sel.type !== "mark") return;
      self.marks[sel.i].label = text;
      changed();
    },
    destroy() {
      window.removeEventListener("keydown", onKey, true);
      ro.disconnect();
    },
  });
  setTool("select");
  return self;
}

// ---------------------------------------------------------------- 화면
function hostsHtml() {
  const groups = hostGroups();
  if (!groups.length) return `<div class="panel-h"><span class="code">DECKS</span><h3>건물·층</h3></div><div class="empty">먼저 장소(03)에서 건물을 만드세요.</div>`;
  return `<div class="panel-h"><span class="code">DECKS</span><h3>건물·층</h3></div>
    <div class="pe-hosts">${groups.map(({ building, hosts }) => `
      <div class="pe-bld"><div class="pe-bh">${icon("decks")}<b>${esc(building.name)}</b>
        <button class="btn xs ghost" type="button" data-addfloor="${building.id}" title="층 추가">${icon("plus")}층</button></div>
        ${hosts.map((hst) => {
          const p = planOf(hst.id);
          const n = p ? p.shapes.length : 0;
          const total = candidates(hst).length;
          return `<button class="pe-host" type="button" data-host="${hst.id}" ${hst.id === hostId ? "aria-current=\"true\"" : ""}>
            <span class="lv">${hst.kind !== "floor" ? "단층" : guessLevel(hst.name) === levelOf(hst) && /\d/.test(hst.name) ? "" : esc(levelText(levelOf(hst)))}</span>
            <span class="nm">${esc(hst.kind === "floor" ? hst.name : "건물 전체")}</span>
            <span class="st ${p ? "on" : ""}">${p ? `실 ${n}/${total}` : "도면 없음"}</span></button>`;
        }).join("")}</div>`).join("")}</div>`;
}

function introHtml() {
  return `<div class="pe-intro">
    <div class="code">BLUEPRINT · 도면으로 3D 지도 만들기</div>
    <h2>층마다 도면을 올리고, 실을 그려 이어 주세요</h2>
    <ol class="guide-steps">
      <li>왼쪽에서 <b>건물·층</b>을 고릅니다(층이 없으면 <b>+층</b>으로 만들기).</li>
      <li><b>도면 그림</b>을 올립니다. 비상대피도 사진·스캔·건축 도면 모두 됩니다(PDF 는 그림으로 저장해서).</li>
      <li><b>사각형·다각형</b>으로 실을 그리고 어느 실인지 고릅니다. 오른쪽 목록의 [그리기]를 누르고 그리면 바로 이어져요.</li>
      <li>현관·계단 <b>표시</b>를 두고, 아는 길이로 <b>축척</b>을 맞추면 층마다 크기가 맞습니다.</li>
      <li>장소(03)·물건 화면의 3D 지도가 도면 모양대로 바뀌고, 찾는 물건이 있는 <b>층·실</b>이 빛납니다.</li>
    </ol></div>`;
}

function uploadHtml(host) {
  return `<div class="pe-intro">
    <div class="code">${esc(KIND[host.kind])} · ${esc(host.path || host.name)}</div>
    <h2>${esc(host.name)} 도면 올리기</h2>
    <div class="pe-drop" data-drop tabindex="0">${icon("plan")}<b>도면 그림을 끌어다 놓거나 눌러서 고르세요</b>
      <span>PNG·JPG·WebP · 큰 그림은 ${MAX_SIDE}px 로 줄여서 저장해요</span></div>
    ${statusText ? `<p class="help" style="margin-top:10px">${esc(statusText)}</p>` : ""}
    <p class="help" style="margin-top:12px">층 번호는 이름에서 짐작해요(${esc(levelText(levelOf(host)))}). 올린 뒤 바꿀 수 있어요.</p></div>`;
}

function editorHtml(host, plan) {
  return `<div class="pe-bar">
      <div class="seg" role="toolbar" aria-label="그리기 도구">
        <button type="button" data-tool="select" title="선택·고치기 (V)">${icon("hand")}선택</button>
        <button type="button" data-tool="rect" title="사각형으로 실 그리기 (R)">${icon("plan")}사각형</button>
        <button type="button" data-tool="poly" title="다각형으로 실 그리기 (P)">${icon("edit")}다각형</button>
        <button type="button" data-tool="mark" title="현관·계단·승강기 표시">${icon("pin")}표시</button>
        <button type="button" data-tool="scale" title="두 점으로 축척 맞추기">${icon("grid")}축척</button>
      </div>
      <select data-marktype hidden aria-label="표시 종류">${Object.entries(MARKS).map(([k, l]) => `<option value="${k}">${l}</option>`).join("")}</select>
      <span class="grow"></span>
      <label class="pe-level">층 <input type="number" data-level value="${plan.level}" min="-10" max="200"></label>
      <button class="icon-btn" type="button" data-b="zout" title="작게">${icon("minus")}</button>
      <button class="icon-btn" type="button" data-b="zin" title="크게">${icon("plus")}</button>
      <button class="btn sm" type="button" data-b="fit">맞춤</button>
      <span class="pe-status" data-status></span>
    </div>
    <div class="pe-canvas" data-canvas><canvas></canvas><div class="pe-hint" data-hint hidden></div></div>
    <div class="pe-foot" data-foot>${footHtml(plan)}</div>`;
}
function footHtml(plan) {
  return `<span class="help">${plan.meters_per_px ? `축척: 그림 100px ≈ ${(plan.meters_per_px * 100).toFixed(2)}m` : "축척 없음(그림 크기로 맞춤)"} · 그림 ${plan.width}×${plan.height}</span>
    <span class="grow"></span>
    <button class="btn sm ghost" type="button" data-b="replace">${icon("upload")}그림 바꾸기</button>
    <button class="btn sm ghost danger" type="button" data-b="delplan">${icon("trash")}도면 지우기</button>`;
}
function paintFoot() {
  const el = root && root.querySelector("[data-foot]");
  if (el && ed) el.innerHTML = footHtml(ed.plan);
}

function sideHtml(host) {
  const list = candidates(host).sort((a, b) => a.name.localeCompare(b.name, "ko"));
  const here = new Set(ed ? ed.shapes.map((s) => s.location_id) : []);
  const elsewhere = drawnElsewhere(ed ? ed.plan.id : null);
  const sel = ed && ed.selected();
  const selId = sel && sel.type === "shape" ? ed.shapes[sel.i] && ed.shapes[sel.i].location_id : null;
  const mark = sel && sel.type === "mark" ? ed.marks[sel.i] : null;
  return `<div class="panel-h"><span class="code">ROOMS</span><h3>이 층의 장소</h3><span class="sub">그림 ${list.filter((l) => here.has(l.id)).length}/${list.length}</span></div>
    ${mark ? `<div class="pe-markedit"><b>${esc(MARKS[mark.type])} 표시</b>
      <input type="text" data-marklabel value="${esc(mark.label || "")}" placeholder="이름(예: 정문, 중앙 계단)" maxlength="30">
      <button class="btn xs ghost danger" type="button" data-b="delsel">${icon("trash")}지우기</button></div>` : ""}
    <div class="pe-rooms">${list.length ? list.map((l) => {
      const on = here.has(l.id);
      const other = !on && elsewhere.has(l.id);
      return `<div class="pe-room ${on ? "on" : ""} ${selId === l.id ? "sel" : ""} ${ed && ed.armed === l.id ? "armed" : ""}" data-room="${l.id}">
        <span class="tx"><span class="nm">${esc(l.name)}${l.code ? ` <small class="mono">${esc(l.code)}</small>` : ""}</span><span class="sub">${esc(KIND[l.kind])}${l.items ? ` · ${l.items}종` : ""}${other ? " · 다른 층 도면에 있음" : ""}</span></span>
        ${on ? `<span class="tag good">그림</span>` : other ? "" : `<button class="btn xs" type="button" data-draw="${l.id}">${icon("plan")}그리기</button>`}</div>`;
    }).join("") : `<div class="empty">이 ${esc(KIND[host.kind])} 아래에 실이 없어요.</div>`}</div>
    <div class="row" style="margin-top:10px"><button class="btn sm" type="button" data-b="newroom">${icon("plus")}새 실</button>
      ${selId ? `<button class="btn sm ghost danger" type="button" data-b="delsel">${icon("trash")}고른 실 지우기</button>` : ""}</div>
    <p class="help" style="margin-top:10px">[그리기]를 누르고 도면 위를 끌면 그 실로 바로 이어져요. 고른 모양은 점을 끌어 고치고 Delete 로 지웁니다. 빈 곳을 끌면 화면 이동, 휠로 확대.</p>`;
}
function paintSide() {
  const el = root && root.querySelector("[data-side]");
  const host = hostId && loc(hostId);
  if (el && host) el.innerHTML = sideHtml(host);
  const hb = root && root.querySelector("[data-hosts]");
  if (hb) hb.innerHTML = hostsHtml();
}

function render() {
  const prev = ed;
  if (ed) { ed.destroy(); ed = null; }
  if (!can("settings")) {
    root.innerHTML = `<div class="st-inner"><div class="empty">도면은 담당교사·관리자가 만들어요.</div></div>`;
    return;
  }
  const host = hostId ? loc(hostId) : null;
  if (hostId && !host) hostId = null;
  const plan = host ? planOf(host.id) : null;
  const mobile = isMobile();
  root.innerHTML = `
    <div class="st-inner wide">
      <div class="st-head"><div class="ttl"><span class="code">STATION 04 · BLUEPRINT</span><h1>도면</h1>
        <p>층마다 도면 그림을 올리고 실을 그려 이으면, 3D 지도가 실제 모양대로 바뀌고 물건이 있는 층·실을 그 자리에서 비춰 줘요.</p></div>
        <div class="tools">${plan && app.scene ? `<button class="btn" type="button" data-b="3d">${icon("decks")}3D로 보기</button>` : ""}</div></div>
      ${mobile ? `<div class="callout" style="margin-bottom:12px">도면 그리기는 화면이 넓은 PC·태블릿에서 해 주세요. 휴대폰에서는 보기만 권해요.</div>` : ""}
      <div class="plans-grid ${plan ? "has-side" : ""}">
        <section class="panel" data-hosts>${hostsHtml()}</section>
        <section class="panel pe-main">${!host ? introHtml() : !plan ? uploadHtml(host) : editorHtml(host, plan)}</section>
        ${plan ? `<section class="panel" data-side></section>` : ""}
      </div>
    </div>`;
  if (host && plan) {
    // 편집기는 실 모양부터 바로 띄우고, 도면 그림이 오면 끼워 넣는다
    // 아직 저장 안 된 내 그림이 있으면 이어받는다
    const local = prev && prev.plan.id === plan.id ? { ...plan, shapes: prev.shapes, marks: prev.marks } : plan;
    ed = createEditor(root.querySelector("[data-canvas]"), { host, plan: local, img: null });
    if (plan.image_url) {
      const img = new Image();
      img.onload = () => { if (ed && ed.plan.id === plan.id) ed.setImage(img); };
      img.src = plan.image_url;
    }
    paintSide();
    paintStatus();
  }
}

async function addFloor(buildingId) {
  const b = loc(buildingId);
  const n = state.locations.filter((l) => l.kind === "floor" && l.parent_id === buildingId).length;
  const name = await promptDialog({ title: `${b ? b.name : ""} 층 추가`, label: "층 이름", value: `${n + 1}층`, placeholder: "예: 2층, 지하1층", confirmLabel: "추가" });
  if (!name || !name.trim()) return;
  try {
    const r = await api.post("/api/locations", { name: name.trim(), kind: "floor", parent_id: buildingId });
    await app.refreshCore();
    hostId = r.location.id;
    history.replaceState(null, "", `#plans?host=${hostId}`);
    render();
  } catch (e) { toastError(e); }
}

async function replaceImage() {
  if (!ed) return;
  const file = await pickFile();
  if (!file) return;
  const plan = ed.plan;
  try {
    statusText = "그림을 바꾸는 중…";
    paintStatus();
    const { blob, width, height } = await prepareImage(file);
    const up = await uploadImage(blob);
    // 실 모양은 새 그림 크기에 맞춰 늘리거나 줄인다
    const sx = width / plan.width;
    const sy = height / plan.height;
    const shapes = ed.shapes.map((s) => ({ ...s, pts: s.pts.map(([x, y]) => [x * sx, y * sy]) }));
    const marks = ed.marks.map((m) => ({ ...m, x: m.x * sx, y: m.y * sy }));
    const r = await api.patch(`/api/plans/${plan.id}`, { image: up.path, width, height, shapes, marks });
    state.plans = state.plans.map((p) => (p.id === plan.id ? r.plan : p));
    statusText = "저장됨";
    toast("도면 그림을 바꿨어요(실 모양은 새 크기에 맞춤)", { tone: "good" });
    render();
  } catch (e) { statusText = "저장 못 함"; paintStatus(); toastError(e); }
}

export default {
  mount(el) {
    root = el;
    el.addEventListener("click", async (ev) => {
      const h = ev.target.closest("[data-host]");
      if (h) { flush(); hostId = h.dataset.host; history.replaceState(null, "", `#plans?host=${hostId}`); statusText = ""; render(); return; }
      const af = ev.target.closest("[data-addfloor]");
      if (af) { addFloor(af.dataset.addfloor); return; }
      const tool = ev.target.closest("[data-tool]");
      if (tool && ed) { ed.setTool(tool.dataset.tool); return; }
      const draw = ev.target.closest("[data-draw]");
      if (draw && ed) { ed.arm(draw.dataset.draw); return; }
      const room = ev.target.closest("[data-room]");
      if (room && ed && room.classList.contains("on")) { ed.selectLocation(room.dataset.room); return; }
      const drop = ev.target.closest("[data-drop]");
      if (drop && hostId) { const f = await pickFile(); if (f) createPlanFor(loc(hostId), f); return; }
      const b = ev.target.closest("[data-b]");
      if (!b) return;
      const k = b.dataset.b;
      if (k === "fit" && ed) ed.fit();
      else if (k === "zin" && ed) ed.zoomAt(1.25);
      else if (k === "zout" && ed) ed.zoomAt(0.8);
      else if (k === "delsel" && ed) ed.removeSelected();
      else if (k === "replace") replaceImage();
      else if (k === "newroom" && ed) {
        const host = loc(hostId);
        const name = await promptDialog({ title: `${host.name}에 새 실`, label: "실 이름", placeholder: "예: 전자실습실", confirmLabel: "만들고 그리기" });
        if (!name || !name.trim()) return;
        try {
          const r = await api.post("/api/locations", { name: name.trim(), kind: "room", parent_id: host.id });
          await app.refreshCore();
          paintSide();
          ed.arm(r.location.id);
        } catch (e) { toastError(e); }
      } else if (k === "delplan" && ed) {
        const host = loc(hostId);
        const ok = await confirmDialog({ title: "도면 지우기", message: `${host.name} 도면과 그 위에 그린 실 모양을 지울까요? 장소와 물건은 그대로예요.`, confirmLabel: "지우기", tone: "danger" });
        if (!ok) return;
        try {
          await api.del(`/api/plans/${ed.plan.id}`);
          state.plans = state.plans.filter((p) => p.id !== ed.plan.id);
          toast("도면을 지웠어요", { tone: "good" });
          render();
        } catch (e) { toastError(e); }
      } else if (k === "3d") {
        flush();
        const planId = ed ? ed.plan.id : (planOf(hostId) || {}).id;
        app.go("decks");
        setTimeout(() => { if (app.scene && app.scene.showFloor) app.scene.showFloor(planId); }, 450);
      }
    });
    el.addEventListener("change", async (ev) => {
      if (ev.target.matches("[data-marktype]") && ed) ed.markType = ev.target.value;
      if (ev.target.matches("[data-level]") && ed) {
        const v = Number(ev.target.value);
        if (!Number.isInteger(v)) return;
        try {
          const r = await api.patch(`/api/plans/${ed.plan.id}`, { level: v });
          state.plans = state.plans.map((p) => (p.id === r.plan.id ? r.plan : p));
          ed.plan = r.plan;
          paintSide();
          toast(`${levelText(v)}으로 바꿨어요`, { tone: "good" });
        } catch (e) { toastError(e); }
      }
      if (ev.target.matches("[data-marklabel]") && ed) ed.setMarkLabel(ev.target.value.trim());
    });
    // 도면 그림 끌어다 놓기
    el.addEventListener("dragover", (ev) => { if (ev.target.closest("[data-drop]")) ev.preventDefault(); });
    el.addEventListener("drop", (ev) => {
      const d = ev.target.closest("[data-drop]");
      if (!d || !hostId) return;
      ev.preventDefault();
      const f = ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files[0];
      if (f) createPlanFor(loc(hostId), f);
    });
  },
  enter(params = {}) {
    flush();
    if (params.host) hostId = params.host;
    statusText = "";
    render();
    return null;
  },
  async refresh(change) {
    // 그리는 중에는 편집기를 다시 만들지 않는다(내가 저장한 것이 돌아온 알림일 수 있다)
    if (ed && (!change || change.kind === "plans" || change.kind === "data")) { paintSide(); return; }
    if (ed && hostId && planOf(hostId)) { paintSide(); return; }
    render();
  },
};

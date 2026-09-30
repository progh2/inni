// 토스트(되돌리기 버튼 포함)·창·확인창·아래에서 올라오는 시트·서랍·명령 팔레트.
// aiapi-manager 관제 함교의 ui.js 를 바탕으로 휴대폰용 시트를 더했다.
import { $, esc, icon, isMobile } from "./util.js";
import * as sfx from "./sfx.js";

const root = () => $("#overlay-root");
const stack = [];

// ---------------------------------------------------------------- 토스트
/**
 * toast("옮겼어요", { tone: "good", action: { label: "되돌리기", run }, timeout })
 */
export function toast(message, { tone = "info", title = "", timeout, action = null, sound = true } = {}) {
  const box = $("#toasts");
  const el = document.createElement("div");
  el.className = `toast ${tone}`;
  el.setAttribute("role", tone === "crit" ? "alert" : "status");
  const led = tone === "crit" ? "crit" : tone === "warn" ? "warn" : tone === "good" ? "good" : "info";
  el.innerHTML = `<span class="led ${led}"></span><div class="tb">${title ? `<div class="ttl">${esc(title)}</div>` : ""}<div class="msg">${esc(message)}</div></div>
    ${action ? `<button class="btn xs act" type="button">${action.icon ? icon(action.icon) : ""}${esc(action.label)}</button>` : ""}
    <button class="x" type="button" aria-label="닫기">×</button><i class="bar"></i>`;
  let closed = false;
  const close = () => {
    if (closed) return;
    closed = true;
    el.classList.add("out");
    setTimeout(() => el.remove(), 300);
  };
  el.querySelector(".x").onclick = close;
  if (action) {
    el.querySelector(".act").onclick = async () => {
      const b = el.querySelector(".act");
      b.disabled = true;
      try { await action.run(); } finally { close(); }
    };
  }
  box.appendChild(el);
  while (box.children.length > 4) box.firstElementChild.remove();
  const ms = timeout ?? (action ? 9000 : tone === "crit" ? 0 : tone === "warn" ? 7000 : 3800);
  if (ms) {
    el.style.setProperty("--ttl", `${ms}ms`);
    el.classList.add("timed");
    let left = ms;
    let started = Date.now();
    let timer = setTimeout(close, left);
    el.addEventListener("pointerenter", () => { clearTimeout(timer); left -= Date.now() - started; el.classList.add("hold"); });
    el.addEventListener("pointerleave", () => { started = Date.now(); timer = setTimeout(close, Math.max(1200, left)); el.classList.remove("hold"); });
  }
  if (sound) {
    if (tone === "crit") sfx.play("error");
    else if (tone === "warn") sfx.play("warn");
    else if (tone === "good") sfx.play("ok");
  }
  window.dispatchEvent(new CustomEvent("inni:toast", { detail: { tone } }));
  return close;
}

export const toastError = (e, title = "") => toast(e && e.message ? e.message : String(e), { tone: "crit", title });

// ---------------------------------------------------------------- 공통 틀
function focusables(el) {
  return [...el.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')].filter((n) => !n.disabled && n.offsetParent !== null);
}

function trap(el, ev) {
  if (ev.key !== "Tab") return;
  const list = focusables(el);
  if (!list.length) return;
  const first = list[0];
  const last = list[list.length - 1];
  if (ev.shiftKey && document.activeElement === first) { last.focus(); ev.preventDefault(); }
  else if (!ev.shiftKey && document.activeElement === last) { first.focus(); ev.preventDefault(); }
}

export const isOverlayOpen = () => stack.length > 0;
export function closeTop() {
  const top = stack[stack.length - 1];
  if (top) top.close();
}
export function closeAll() {
  while (stack.length) stack[stack.length - 1].close();
}

function mount(node, { scrim = true, onClose, dismissable = true, sound = true } = {}) {
  const prev = document.activeElement;
  let scrimEl = null;
  if (scrim) {
    scrimEl = document.createElement("div");
    scrimEl.className = "scrim";
    if (dismissable) scrimEl.onclick = () => handle.close();
    root().appendChild(scrimEl);
  }
  root().appendChild(node);
  const onKey = (ev) => {
    if (stack[stack.length - 1] !== handle) return;
    if (ev.key === "Escape" && dismissable) { ev.stopPropagation(); handle.close(); }
    else trap(node, ev);
  };
  document.addEventListener("keydown", onKey, true);
  let closed = false;
  const handle = {
    el: node,
    closed: () => closed,
    close(result) {
      if (closed) return;
      closed = true;
      if (sound) sfx.play("close");
      document.removeEventListener("keydown", onKey, true);
      node.classList.add("closing");
      if (scrimEl) scrimEl.classList.add("closing");
      const done = () => { node.remove(); if (scrimEl) scrimEl.remove(); };
      if (document.body.classList.contains("reduce-motion")) done();
      else setTimeout(done, 180);
      const i = stack.indexOf(handle);
      if (i >= 0) stack.splice(i, 1);
      if (prev && prev.focus && document.contains(prev)) { try { prev.focus({ preventScroll: true }); } catch { /* 사라짐 */ } }
      if (onClose) onClose(result);
    },
  };
  stack.push(handle);
  if (sound) sfx.play("open");
  requestAnimationFrame(() => {
    const auto = node.querySelector("[autofocus]");
    if (auto && !(isMobile() && auto.matches("input, textarea") && !auto.hasAttribute("data-mobile-focus"))) auto.focus();
    else if (!isMobile()) { const f = focusables(node)[0]; if (f) f.focus(); }
  });
  return handle;
}

/**
 * 창. 휴대폰에서는 아래에서 올라오는 시트로 뜬다(sheet:true 기본).
 * actions: [{ label, tone, value, onClick(handle) → false 면 닫지 않음 }]
 */
export function modal({ title, code = "", body = "", actions = [], size = "", tone = "", onOpen, onClose, dismissable = true, sheet = true, className = "" } = {}) {
  const el = document.createElement("div");
  const asSheet = sheet && isMobile();
  el.className = `${asSheet ? "sheet" : "modal"} ${size} ${tone} ${className}`;
  el.setAttribute("role", "dialog");
  el.setAttribute("aria-modal", "true");
  el.setAttribute("aria-label", title);
  el.innerHTML = `
    ${asSheet ? '<div class="grab" aria-hidden="true"><i></i></div>' : ""}
    <div class="modal-h">${code ? `<span class="code">${esc(code)}</span>` : ""}<h3>${esc(title)}</h3>
      ${dismissable ? `<button class="icon-btn x" type="button" aria-label="닫기">${icon("x")}</button>` : ""}</div>
    <div class="modal-b"></div>
    ${actions.length ? `<div class="modal-f"></div>` : ""}`;
  const b = el.querySelector(".modal-b");
  if (typeof body === "string") b.innerHTML = body;
  else if (body instanceof Node) b.appendChild(body);
  const handle = mount(el, { onClose, dismissable });
  handle.body = b;
  if (dismissable) el.querySelector(".modal-h .x").onclick = () => handle.close(null);
  if (asSheet && dismissable) swipeToClose(el, handle);
  const foot = el.querySelector(".modal-f");
  for (const a of actions) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = `btn ${a.tone || ""}`;
    btn.innerHTML = `${a.icon ? icon(a.icon) : ""}${esc(a.label)}`;
    if (a.id) btn.id = a.id;
    btn.onclick = async () => {
      if (a.onClick) {
        btn.classList.add("busy");
        btn.disabled = true;
        let keep;
        try { keep = await a.onClick(handle); } finally { btn.classList.remove("busy"); btn.disabled = false; }
        if (keep === false) return;
      }
      handle.close(a.value !== undefined ? a.value : a.label);
    };
    foot.appendChild(btn);
  }
  if (onOpen) onOpen(handle, b);
  return handle;
}

export const sheet = (o) => modal({ ...o, sheet: true });

function swipeToClose(el, handle) {
  const grab = el.querySelector(".grab");
  const head = el.querySelector(".modal-h");
  let y0 = null;
  let dy = 0;
  const start = (ev) => { y0 = ev.touches ? ev.touches[0].clientY : ev.clientY; dy = 0; el.style.transition = "none"; };
  const move = (ev) => {
    if (y0 === null) return;
    dy = Math.max(0, (ev.touches ? ev.touches[0].clientY : ev.clientY) - y0);
    el.style.transform = `translateY(${dy}px)`;
  };
  const end = () => {
    if (y0 === null) return;
    el.style.transition = "";
    el.style.transform = "";
    if (dy > 110) handle.close();
    y0 = null;
  };
  for (const h of [grab, head]) {
    if (!h) continue;
    h.addEventListener("touchstart", start, { passive: true });
    h.addEventListener("touchmove", move, { passive: true });
    h.addEventListener("touchend", end);
  }
}

/** 확인창. typed 가 있으면 그 낱말을 입력해야 실행된다(되돌릴 수 없는 작업). */
export function confirmDialog({ title, message, detail = "", confirmLabel = "실행", tone = "", typed = "", code = "CONFIRM" }) {
  return new Promise((resolve) => {
    const body = `
      <p class="lead">${esc(message)}</p>
      ${detail ? `<div class="callout ${tone === "danger" ? "crit" : "warn"}" style="white-space:pre-wrap">${esc(detail)}</div>` : ""}
      ${typed ? `<label class="field"><span>확인을 위해 <b>${esc(typed)}</b> 를 입력하세요</span><input type="text" id="cf-typed" autocomplete="off" autofocus data-mobile-focus></label>` : ""}`;
    let done = false;
    const h = modal({
      title, code, body, tone: tone === "danger" ? "crit" : "",
      actions: [
        { label: "취소", tone: "ghost", value: false },
        { label: confirmLabel, tone: tone === "danger" ? "danger" : tone === "warn" ? "warn" : "primary", value: true },
      ],
      onClose: (v) => { if (!done) { done = true; resolve(v === true); } },
    });
    if (typed) {
      const go = h.el.querySelector(".modal-f .btn:last-child");
      const input = h.el.querySelector("#cf-typed");
      go.disabled = true;
      input.addEventListener("input", () => { go.disabled = input.value.trim() !== typed; });
      input.addEventListener("keydown", (ev) => { if (ev.key === "Enter" && !go.disabled) go.click(); });
    }
  });
}

// 짧은 글 입력 받기
export function promptDialog({ title, label = "", value = "", placeholder = "", confirmLabel = "확인", multiline = false }) {
  return new Promise((resolve) => {
    let out = null;
    const h = modal({
      title,
      body: `<label class="field"><span>${esc(label)}</span>${multiline ? `<textarea id="pd-v" rows="5" placeholder="${esc(placeholder)}" autofocus>${esc(value)}</textarea>` : `<input id="pd-v" type="text" value="${esc(value)}" placeholder="${esc(placeholder)}" autofocus data-mobile-focus>`}</label>`,
      actions: [{ label: "취소", tone: "ghost", value: null }, { label: confirmLabel, tone: "primary", onClick: (hh) => { out = hh.el.querySelector("#pd-v").value; } }],
      onClose: () => resolve(out),
    });
    const input = h.el.querySelector("#pd-v");
    if (!multiline) input.addEventListener("keydown", (ev) => { if (ev.key === "Enter" && !ev.isComposing) { out = input.value; h.close(); } });
  });
}

// ---------------------------------------------------------------- 서랍(오른쪽)
export function drawer({ title, code = "", render, onClose, wide = false }) {
  if (isMobile()) return modal({ title, code, sheet: true, size: "tall", onClose, onOpen: (h, b) => render && render(b, h) });
  const el = document.createElement("aside");
  el.className = `drawer${wide ? " wide" : ""}`;
  el.setAttribute("role", "dialog");
  el.setAttribute("aria-modal", "true");
  el.setAttribute("aria-label", title);
  el.innerHTML = `<div class="modal-h">${code ? `<span class="code">${esc(code)}</span>` : ""}<h3>${esc(title)}</h3><button class="icon-btn x" type="button" aria-label="닫기">${icon("x")}</button></div><div class="modal-b"></div>`;
  const handle = mount(el, { onClose });
  handle.body = el.querySelector(".modal-b");
  el.querySelector(".x").onclick = () => handle.close();
  if (render) render(handle.body, handle);
  return handle;
}

// ---------------------------------------------------------------- 명령 팔레트
export function commandPalette(getItems, { placeholder = "명령·화면·물건 이름…" } = {}) {
  const el = document.createElement("div");
  el.className = "palette";
  el.setAttribute("role", "dialog");
  el.setAttribute("aria-modal", "true");
  el.setAttribute("aria-label", "명령 팔레트");
  el.innerHTML = `<input type="text" placeholder="${esc(placeholder)}" aria-label="명령 검색" autocomplete="off" autofocus><ul role="listbox"></ul>`;
  const input = el.querySelector("input");
  const list = el.querySelector("ul");
  const handle = mount(el);
  let items = [];
  let sel = 0;
  const render = () => {
    const q = input.value.trim().toLowerCase();
    const all = getItems(q);
    items = (q ? all.filter((it) => `${it.label} ${it.sub || ""} ${it.keywords || ""}`.toLowerCase().includes(q)) : all.filter((it) => !it.searchOnly)).slice(0, 40);
    sel = Math.min(sel, Math.max(0, items.length - 1));
    list.innerHTML = items.map((it, i) => `<li role="option" data-i="${i}" aria-selected="${i === sel}"><span class="grp">${esc(it.group)}</span><span>${esc(it.label)}</span>${it.sub ? `<span class="sub">${esc(it.sub)}</span>` : ""}</li>`).join("")
      || '<li class="muted" aria-disabled="true">맞는 항목이 없습니다</li>';
    const cur = list.querySelector('[aria-selected="true"]');
    if (cur) cur.scrollIntoView({ block: "nearest" });
  };
  const run = (i) => {
    const it = items[i];
    if (!it) return;
    handle.close();
    setTimeout(() => it.run(), 0);
  };
  input.addEventListener("input", () => { sel = 0; render(); });
  input.addEventListener("keydown", (ev) => {
    if (ev.key === "ArrowDown") { sel = Math.min(items.length - 1, sel + 1); render(); ev.preventDefault(); }
    else if (ev.key === "ArrowUp") { sel = Math.max(0, sel - 1); render(); ev.preventDefault(); }
    else if (ev.key === "Enter" && !ev.isComposing) { run(sel); ev.preventDefault(); }
  });
  list.addEventListener("click", (ev) => {
    const li = ev.target.closest("li[data-i]");
    if (li) run(Number(li.dataset.i));
  });
  render();
  return handle;
}

export async function busy(btn, fn) {
  if (!btn) return fn();
  btn.disabled = true;
  btn.classList.add("busy");
  try { return await fn(); } finally { btn.disabled = false; btn.classList.remove("busy"); }
}

// 탭(세그먼트) 도우미: <div class="seg">의 버튼 중 하나를 고른다
export function segmented(container, onChange) {
  container.addEventListener("click", (ev) => {
    const b = ev.target.closest("button[data-v]");
    if (!b || b.disabled) return;
    for (const x of container.querySelectorAll("button[data-v]")) x.setAttribute("aria-pressed", String(x === b));
    sfx.play("click");
    onChange(b.dataset.v, b);
  });
}

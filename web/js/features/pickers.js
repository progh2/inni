// 고르기 부품: 장소·빌리는 사람·수량·반납 예정. 최근 고른 것을 앞에 둬서 누르기만 하면 되게 한다.
import { $, esc, icon, qty as fmtQty, debounce, dueAt, local, remember, fmtDateTime } from "../lib/util.js";
import { state, recentLocations, locationTree } from "../lib/store.js";
import { api } from "../lib/api.js";
import { normalize, choseong, isChoseong } from "../shared/hangul.js";
import { openScanner } from "./scanner.js";
import * as sfx from "../lib/sfx.js";

// ---------------------------------------------------------------- 장소
/**
 * container 안에 장소 고르기를 그린다. 누르면 onPick(locationId)
 * exclude: 지금 있는 곳(흐리게)
 */
export function locationPicker(container, { onPick, exclude = [], title = "", kinds = null, allowScan = true, compact = false } = {}) {
  const ex = new Set(exclude.filter(Boolean));
  container.innerHTML = `
    ${title ? `<div class="pick-sec">${esc(title)}</div>` : ""}
    <div class="row nw" style="margin-bottom:8px">
      <div class="search" style="flex:1">${icon("search")}<input type="search" placeholder="장소 이름·번호로 찾기" aria-label="장소 찾기"></div>
      ${allowScan ? `<button class="btn" type="button" data-b="scan" title="장소 라벨 스캔">${icon("scan")}<span class="hide-mobile">라벨 스캔</span></button>` : ""}
    </div>
    <div class="pk-body"></div>`;
  const body = container.querySelector(".pk-body");
  const input = container.querySelector("input");
  const tree = locationTree().filter((l) => !kinds || kinds.includes(l.kind));
  const card = (l, cls = "") => `<button class="pick ${cls}${ex.has(l.id) ? " current" : ""}" type="button" data-id="${l.id}" ${ex.has(l.id) ? 'disabled title="지금 있는 곳"' : ""}>
      <span class="n">${esc(l.name)}${l.code ? ` <small class="muted mono">${esc(l.code)}</small>` : ""}</span><span class="p">${esc(l.path)}</span></button>`;
  function render(q = "") {
    const n = normalize(q);
    let html = "";
    if (!n) {
      const recent = recentLocations(6).map((id) => state.locMap.get(id)).filter(Boolean);
      if (recent.length) html += `<div class="pick-sec">RECENT · 최근</div><div class="pick-grid">${recent.map((l) => card(l, "recent")).join("")}</div>`;
      const groups = new Map();
      for (const l of tree) {
        if (l.kind === "building" || l.kind === "floor") continue;
        const root = state.locMap.get(l.path_ids[0]);
        const key = root && root.id !== l.id ? root.name : "기타";
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(l);
      }
      for (const [g, list] of groups) {
        html += `<div class="pick-sec">${esc(g)}</div><div class="pick-grid">${list.slice(0, compact ? 24 : 200).map((l) => card(l)).join("")}</div>`;
      }
      if (!tree.length) html = `<div class="empty">아직 장소가 없어요. <b>장소</b> 화면에서 실을 먼저 만드세요.</div>`;
    } else {
      const hits = tree.filter((l) => normalize(l.path).includes(n) || (l.code && normalize(l.code).includes(n)) || (isChoseong(n) && choseong(l.name).includes(n)));
      html = hits.length ? `<div class="pick-grid">${hits.slice(0, 60).map((l) => card(l)).join("")}</div>` : `<div class="empty">"${esc(q)}" 장소가 없어요.</div>`;
    }
    body.innerHTML = html;
  }
  render();
  input.addEventListener("input", () => render(input.value.trim()));
  body.addEventListener("click", (ev) => {
    const b = ev.target.closest(".pick[data-id]");
    if (!b || b.disabled) return;
    sfx.play("click");
    onPick(b.dataset.id);
  });
  const sb = container.querySelector('[data-b="scan"]');
  if (sb) {
    sb.onclick = async () => {
      const code = await openScanner({ title: "장소 라벨 스캔", hint: "옮길 곳(실·선반)의 라벨을 비춰 주세요" });
      if (!code) return;
      try {
        const hit = await api.get(`/api/scan/${encodeURIComponent(code)}`);
        if (hit.type === "location") onPick(hit.id);
        else sfx.play("warn");
      } catch { sfx.play("error"); }
    };
  }
  return { refresh: () => render(input.value.trim()) };
}

// ---------------------------------------------------------------- 수량
export function stepperHtml({ value = 1, unit = "개", min = 0, max = null, id = "qty" } = {}) {
  return `<div class="row nw" style="gap:10px;flex-wrap:wrap">
    <div class="stepper" data-step="${id}"><button type="button" data-d="-1" aria-label="하나 빼기">${icon("minus")}</button>
      <input type="number" inputmode="decimal" id="${id}" value="${value}" min="${min}" ${max !== null ? `max="${max}"` : ""} step="any" aria-label="수량">
      <span class="u">${esc(unit)}</span><button type="button" data-d="1" aria-label="하나 더하기">${icon("plus")}</button></div>
    <div class="chips" data-quick="${id}">${[1, 2, 5, 10].filter((n) => max === null || n <= max).map((n) => `<button class="chip" type="button" data-v="${n}">${n}</button>`).join("")}
      ${max !== null ? `<button class="chip amber" type="button" data-v="${max}">전부 ${fmtQty(max)}</button>` : ""}</div></div>`;
}

export function bindStepper(root, id = "qty", { onChange } = {}) {
  const input = root.querySelector(`#${id}`);
  const step = root.querySelector(`[data-step="${id}"]`);
  const clampV = (v) => {
    const min = Number(input.min || 0);
    const max = input.max !== "" ? Number(input.max) : Infinity;
    return Math.max(min, Math.min(max, Math.round(v * 100) / 100));
  };
  step.addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-d]");
    if (!b) return;
    input.value = String(clampV((Number(input.value) || 0) + Number(b.dataset.d)));
    sfx.play("tick");
    if (onChange) onChange(Number(input.value));
  });
  const quick = root.querySelector(`[data-quick="${id}"]`);
  if (quick) {
    quick.addEventListener("click", (ev) => {
      const b = ev.target.closest("[data-v]");
      if (!b) return;
      input.value = b.dataset.v;
      sfx.play("tick");
      if (onChange) onChange(Number(input.value));
    });
  }
  input.addEventListener("input", () => onChange && onChange(Number(input.value)));
  return () => clampV(Number(input.value) || 0);
}

// ---------------------------------------------------------------- 반납 예정
const DUE = [["today", "오늘 수업 끝"], ["tomorrow", "내일"], ["3d", "3일"], ["week", "1주"], ["2w", "2주"], ["none", "기한 없음"]];

export function dueHtml(selected) {
  const def = selected || (state.settings && state.settings.loan.default_due) || "today";
  return `<div class="chips" data-due>${DUE.map(([v, l]) => `<button class="chip" type="button" data-v="${v}" aria-pressed="${v === def}">${esc(l)}</button>`).join("")}
    <button class="chip" type="button" data-v="pick">${icon("calendar")}날짜</button></div>
    <input type="datetime-local" data-due-at hidden style="margin-top:8px;max-width:260px">
    <div class="help" data-due-label style="margin:6px 0 0"></div>`;
}

export function bindDue(root, { initial = null } = {}) {
  const box = root.querySelector("[data-due]");
  const at = root.querySelector("[data-due-at]");
  const label = root.querySelector("[data-due-label]");
  const dayEnd = (state.settings && state.settings.loan.day_end) || "17:00";
  let value = initial;
  const paint = () => { label.textContent = value ? `반납 예정: ${fmtDateTime(value)}` : "반납 기한 없이 빌려줍니다"; };
  const pick = (v) => {
    for (const b of box.querySelectorAll("[data-v]")) b.setAttribute("aria-pressed", String(b.dataset.v === v));
    at.hidden = v !== "pick";
    if (v === "pick") {
      at.focus();
      return;
    }
    value = dueAt(v, dayEnd);
    paint();
  };
  box.addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-v]");
    if (b) { sfx.play("tick"); pick(b.dataset.v); }
  });
  at.addEventListener("change", () => { value = at.value ? new Date(at.value).toISOString() : null; paint(); });
  if (initial) {
    for (const b of box.querySelectorAll("[data-v]")) b.setAttribute("aria-pressed", "false");
    paint();
  } else {
    const def = box.querySelector('[aria-pressed="true"]');
    pick(def ? def.dataset.v : "today");
  }
  return () => value;
}

// ---------------------------------------------------------------- 빌리는 사람
export function borrowerHtml({ name = "" } = {}) {
  const me = state.me;
  const recent = local.get("recentBorrowers", []).filter((b) => b.name !== me.name).slice(0, 6);
  return `<div class="chips" data-bw>
      <button class="chip" type="button" data-self aria-pressed="${!name}">${icon("user")}나 (${esc(me.name)})</button>
      ${recent.map((b, i) => `<button class="chip" type="button" data-r="${i}" aria-pressed="${name === b.name}">${esc(b.name)}${b.note ? ` <small>${esc(b.note)}</small>` : ""}</button>`).join("")}
    </div>
    <div class="grid g2" style="margin-top:8px;gap:8px">
      <label class="field"><span>다른 사람</span><input type="text" data-bw-name placeholder="이름(학생·선생님)" value="${esc(name)}" autocomplete="off" list="bw-list"></label>
      <label class="field"><span>반·번호·연락처 <span class="hint">선택</span></span><input type="text" data-bw-note placeholder="예: 3-2 15번" autocomplete="off"></label>
    </div>
    <datalist id="bw-list"></datalist>`;
}

export function bindBorrower(root) {
  const me = state.me;
  const recent = local.get("recentBorrowers", []).filter((b) => b.name !== me.name).slice(0, 6);
  const nameIn = root.querySelector("[data-bw-name]");
  const noteIn = root.querySelector("[data-bw-note]");
  const chips = root.querySelector("[data-bw]");
  const list = root.querySelector("#bw-list");
  let chosen = nameIn.value ? { name: nameIn.value } : { self: true };
  let users = [];
  const press = (el) => { for (const b of chips.querySelectorAll("button")) b.setAttribute("aria-pressed", String(b === el)); };
  chips.addEventListener("click", (ev) => {
    const b = ev.target.closest("button");
    if (!b) return;
    sfx.play("tick");
    press(b);
    if (b.hasAttribute("data-self")) { chosen = { self: true }; nameIn.value = ""; noteIn.value = ""; }
    else {
      const r = recent[Number(b.dataset.r)];
      chosen = { name: r.name, note: r.note, user_id: r.user_id || null };
      nameIn.value = r.name;
      noteIn.value = r.note || "";
    }
  });
  const suggest = debounce(async (q) => {
    try {
      const d = await api.get(`/api/borrowers?q=${encodeURIComponent(q)}`);
      users = d.users;
      list.innerHTML = [...d.users.map((u) => `<option value="${esc(u.name)}">${esc(u.role_label)} · ${esc(u.email)}</option>`), ...d.recent.map((r) => `<option value="${esc(r.name)}">${esc(r.note || "예전에 빌린 사람")}</option>`)].join("");
    } catch { /* 무시 */ }
  }, 200);
  nameIn.addEventListener("input", () => {
    const v = nameIn.value.trim();
    press(null);
    chosen = v ? { name: v } : { self: true };
    if (!v) press(chips.querySelector("[data-self]"));
    if (v) suggest(v);
  });
  suggest("");
  return () => {
    if (chosen.self) return { borrower_user_id: me.id, borrower_name: me.name };
    const name = nameIn.value.trim();
    const u = users.find((x) => x.name === name);
    const note = noteIn.value.trim();
    remember("recentBorrowers", { name, note, user_id: u ? u.id : chosen.user_id || null }, 10);
    return { borrower_name: name, borrower_note: note || null, borrower_user_id: u ? u.id : chosen.user_id || null };
  };
}

export const PURPOSES = ["수업", "실습", "방과후", "동아리", "프로젝트", "행사"];
export function chipsHtml(list, attr = "data-purpose") {
  return `<div class="chips" ${attr}>${list.map((p) => `<button class="chip" type="button" data-v="${esc(p)}" aria-pressed="false">${esc(p)}</button>`).join("")}</div>`;
}
export function bindChips(root, attr = "data-purpose", { multi = false } = {}) {
  const box = root.querySelector(`[${attr}]`);
  let value = "";
  box.addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-v]");
    if (!b) return;
    sfx.play("tick");
    const on = b.getAttribute("aria-pressed") !== "true";
    if (!multi) for (const x of box.querySelectorAll("[data-v]")) x.setAttribute("aria-pressed", "false");
    b.setAttribute("aria-pressed", String(on));
    value = on ? b.dataset.v : "";
  });
  return () => value;
}

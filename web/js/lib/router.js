// 스테이션 전환·해시 주소·상황판 모드(자동 순환). aiapi-manager 라우터를 바탕으로 숨은 화면(물건)을 더했다.
import { $, esc, icon } from "./util.js";
import * as sfx from "./sfx.js";

export const STATIONS = [
  { id: "bridge", no: "01", code: "BRIDGE", label: "함교", icon: "bridge", display: true, veil: 0.05, tab: true },
  { id: "search", no: "02", code: "SCANNER", label: "찾기", icon: "search", veil: 0.66, tab: true },
  { id: "decks", no: "03", code: "DECK MAP", label: "장소", icon: "decks", display: true, veil: 0.3 },
  { id: "plans", no: "04", code: "BLUEPRINT", label: "도면", icon: "plan", veil: 0.86, cap: "settings" },
  { id: "dock", no: "05", code: "DOCKING BAY", label: "입출항", icon: "dock", display: true, veil: 0.62, tab: true },
  { id: "audit", no: "06", code: "INSPECTION", label: "실사", icon: "audit", veil: 0.62 },
  { id: "repair", no: "07", code: "REPAIR BAY", label: "정비", icon: "wrench", display: true, veil: 0.62 },
  { id: "log", no: "08", code: "SHIP LOG", label: "기록", icon: "log", veil: 0.64 },
  { id: "crew", no: "09", code: "CREW", label: "승무원", icon: "crew", veil: 0.64, cap: "users" },
  { id: "systems", no: "10", code: "SYSTEMS", label: "시스템", icon: "gear", veil: 0.66, cap: "settings" },
  { id: "item", no: "", code: "CARGO", label: "물건", icon: "box", hidden: true, veil: 0.72 },
];

const ENTER_SEL = ".st-head, .tabs, .panel, .card, .tile, .qa-grid";
function stagger(station) {
  if (document.body.classList.contains("reduce-motion")) return;
  const items = [...station.querySelectorAll(ENTER_SEL)].filter((el) => {
    const outer = el.parentElement && el.parentElement.closest(ENTER_SEL);
    return !outer || !station.contains(outer);
  }).slice(0, 16);
  for (const el of station.querySelectorAll(".enter-item")) el.classList.remove("enter-item");
  void station.offsetWidth;
  items.forEach((el, i) => {
    el.style.setProperty("--i", String(i));
    el.classList.add("enter-item");
  });
  clearTimeout(station._enterTimer);
  station._enterTimer = setTimeout(() => items.forEach((el) => el.classList.remove("enter-item")), 1500);
  const stage = $("#stage");
  if (stage) {
    stage.classList.remove("sweep");
    void stage.offsetWidth;
    stage.classList.add("sweep");
  }
  const name = $("#hud-name");
  if (name) {
    name.classList.remove("glitch");
    void name.offsetWidth;
    name.classList.add("glitch");
  }
}

const byId = Object.fromEntries(STATIONS.map((s, i) => [s.id, { ...s, index: i }]));

export function parseHash() {
  const raw = decodeURIComponent((location.hash || "").replace(/^#/, ""));
  const [id, query = ""] = raw.split("?");
  const params = Object.fromEntries(new URLSearchParams(query));
  return { id: id || null, params };
}

export function hashFor(id, params) {
  const q = new URLSearchParams(Object.entries(params || {}).filter(([, v]) => v != null && v !== "")).toString();
  return `#${id}${q ? `?${q}` : ""}`;
}

export function createRouter({ onEnter, getSettings, onAutoChange, canSee, special = {} }) {
  let current = null;
  let currentParams = {};
  const rail = $("#rail");
  const tabbar = $("#tabbar");

  function paintNav() {
    const visible = STATIONS.filter((s) => !s.hidden && (!s.cap || canSee(s.cap)));
    rail.innerHTML = visible.map((s) => `
      <button class="rail-btn" type="button" data-go="${s.id}" title="${esc(s.label)} (${Number(s.no)})">
        ${icon(s.icon)}<span class="no">${s.no}</span><span class="lb">${esc(s.label)}</span><i class="badge-dot" hidden></i>
      </button>`).join("")
      + `<div class="rail-sep"></div>
        <div class="auto-ring"><button type="button" id="rail-auto" aria-pressed="false" title="상황판 모드: 화면을 자동으로 돌며 보여 줍니다(교실 TV용)">
          <svg viewBox="0 0 60 60"><circle cx="30" cy="30" r="28"/></svg>상황판</button></div>`;
    tabbar.innerHTML = `
      <button class="tab-btn" type="button" data-go="bridge">${icon("bridge")}<span>함교</span></button>
      <button class="tab-btn" type="button" data-go="search">${icon("search")}<span>찾기</span></button>
      <button class="tab-btn scan" type="button" data-act="scan" aria-label="스캔">${icon("scan")}<span>스캔</span></button>
      <button class="tab-btn" type="button" data-go="dock">${icon("dock")}<span>입출항</span></button>
      <button class="tab-btn" type="button" data-act="menu">${icon("menu")}<span>메뉴</span><i class="badge-dot" hidden></i></button>`;
    $("#rail-auto").addEventListener("click", () => setAuto(!autoOn));
    mark(current);
  }

  function mark(id) {
    for (const b of document.querySelectorAll("[data-go]")) {
      if (b.closest("#rail, #tabbar")) b.toggleAttribute("aria-current", b.dataset.go === id);
    }
  }

  for (const nav of [rail, tabbar]) {
    nav.addEventListener("click", (ev) => {
      const b = ev.target.closest("[data-go]");
      if (b) go(b.dataset.go, {}, { user: true });
      const a = ev.target.closest("[data-act]");
      if (a && special[a.dataset.act]) special[a.dataset.act]();
    });
  }

  function el(id) { return document.getElementById(`st-${id}`); }

  function go(id, params = {}, { user = false, replace = false } = {}) {
    if (special.intercept) {
      const handled = special.intercept(id, params);
      if (handled) return;
    }
    if (!byId[id] || (byId[id].cap && !canSee(byId[id].cap))) id = "bridge";
    if (user) pauseAuto();
    if (user && id !== current) sfx.play("nav");
    const prevId = current;
    currentParams = params || {};
    const url = hashFor(id, currentParams);
    if (location.hash !== url) {
      if (replace || (!user && prevId === id)) history.replaceState(null, "", url);
      else history.pushState(null, "", url);
    }
    if (prevId === id) {
      onEnter(id, currentParams, { same: true, prev: prevId });
      return;
    }
    const forward = !prevId || byId[id].index > byId[prevId].index;
    const next = el(id);
    const prev = prevId ? el(prevId) : null;
    next.style.transition = "none";
    next.classList.remove("active");
    next.classList.toggle("leave-left", !forward);
    void next.offsetWidth;
    next.style.transition = "";
    next.classList.remove("leave-left");
    next.classList.add("active");
    if (prev) {
      prev.classList.remove("active");
      prev.classList.toggle("leave-left", forward);
    }
    current = id;
    mark(id);
    onEnter(id, currentParams, { same: false, prev: prevId, forward });
    next.scrollTop = 0;
    stagger(next);
  }

  window.addEventListener("popstate", () => {
    const h = parseHash();
    if (h.id) go(h.id, h.params, { replace: true });
  });

  // ---------------------------------------------------------------- 상황판 모드
  let autoOn = false;
  let autoStart = 0;
  let pausedUntil = 0;
  let timer = null;
  function autoList() {
    const s = getSettings();
    const list = (s.autoStations && s.autoStations.length ? s.autoStations : STATIONS.filter((x) => x.display).map((x) => x.id)).filter((id) => byId[id]);
    return list.length ? list : ["bridge"];
  }
  function paint(progress) {
    const c = $("#rail-auto circle");
    if (c) c.style.strokeDashoffset = String(176 - 176 * progress);
    const label = $("#auto-state");
    if (!label) return;
    if (!autoOn) { label.textContent = ""; return; }
    const left = Math.max(0, Math.ceil((pausedUntil - Date.now()) / 1000));
    label.textContent = left > 0 ? `상황판 · 조작 감지, ${left}초 뒤 재개` : `상황판 · ${Math.max(0, Math.ceil(getSettings().autoInterval * (1 - progress)))}초 뒤 전환`;
  }
  function tick() {
    if (!autoOn) return;
    const now = Date.now();
    if (now < pausedUntil) { paint(0); autoStart = now; return; }
    const span = Math.max(5, Number(getSettings().autoInterval) || 20) * 1000;
    const p = (now - autoStart) / span;
    if (p >= 1) {
      const list = autoList();
      const i = list.indexOf(current);
      go(list[(i + 1) % list.length], {}, { replace: true });
      autoStart = now;
      paint(0);
    } else paint(p);
  }
  function setAuto(on) {
    autoOn = Boolean(on);
    autoStart = Date.now();
    pausedUntil = 0;
    clearInterval(timer);
    if (autoOn) timer = setInterval(tick, 250);
    for (const b of [$("#rail-auto"), $("#btn-auto")]) if (b) b.setAttribute("aria-pressed", String(autoOn));
    document.body.classList.toggle("auto-mode", autoOn);
    paint(0);
    if (onAutoChange) onAutoChange(autoOn);
  }
  function pauseAuto(ms = 30000) {
    if (autoOn) pausedUntil = Date.now() + ms;
  }
  for (const t of ["pointerdown", "keydown", "wheel"]) {
    window.addEventListener(t, (ev) => {
      if (!autoOn) return;
      if (ev.target && ev.target.closest && ev.target.closest("#rail-auto, #btn-auto")) return;
      pauseAuto();
    }, { passive: true, capture: true });
  }

  return {
    go,
    paintNav,
    start() {
      const h = parseHash();
      go(h.id || "bridge", h.params, { replace: true });
    },
    get current() { return current; },
    get params() { return currentParams; },
    station: (id) => byId[id],
    setAuto,
    toggleAuto: () => setAuto(!autoOn),
    get auto() { return autoOn; },
    back() {
      if (history.length > 1) history.back();
      else go("bridge");
    },
  };
}

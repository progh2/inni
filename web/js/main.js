// inni 보급 함교 진입점: 부팅 → 로그인 → 함교(스테이션) · 이니 · 선내 지도 · 실시간 알림
import { $, esc, icon, seoulParts, debounce, fmtTime, prefersReducedMotion, isMobile, relTime } from "./lib/util.js";
import { api, setUnauthorizedHandler } from "./lib/api.js";
import * as store from "./lib/store.js";
import { toast, toastError, modal, commandPalette, isOverlayOpen, promptDialog } from "./lib/ui.js";
import { createRouter, STATIONS } from "./lib/router.js";
import * as sfx from "./lib/sfx.js";
import { showLogin, logout } from "./lib/auth.js";
import { app, prefs, savePrefs } from "./app.js";
import { createOmnibox } from "./features/omnibox.js";
import { openScanner, handleCode } from "./features/scanner.js";
import * as actions from "./features/actions.js";
import { openAddItem } from "./features/add-item.js";
import { createInni } from "./ai/chat.js";
import { openSetup } from "./features/setup.js";
import bridge from "./stations/bridge.js";
import search from "./stations/search.js";
import decks from "./stations/decks.js";
import dock from "./stations/dock.js";
import audit from "./stations/audit.js";
import repair from "./stations/repair.js";
import log from "./stations/log.js";
import crew from "./stations/crew.js";
import systems from "./stations/systems.js";
import item from "./stations/item.js";

const MODULES = { bridge, search, decks, dock, audit, repair, log, crew, systems, item };
const { state } = store;

// ---------------------------------------------------------------- 화면 설정 적용
function applyPrefs() {
  const reduce = prefs.reduceMotion || prefersReducedMotion();
  document.body.classList.toggle("reduce-motion", reduce);
  document.body.classList.toggle("hc", Boolean(prefs.contrast));
  document.body.classList.toggle("big-text", Boolean(prefs.bigText));
  sfx.configure({ enabled: prefs.sound !== false, vol: prefs.volume ?? 0.5 });
  const b = $("#btn-sound");
  if (b) {
    b.setAttribute("aria-pressed", String(prefs.sound !== false));
    b.innerHTML = `<svg><use href="#i-${prefs.sound !== false ? "volume" : "mute"}"/></svg>`;
  }
}
app.applyPrefs = applyPrefs;

// ---------------------------------------------------------------- 부팅 화면
const bootLines = $("#boot-lines");
const bootBar = $("#boot-bar");
function bootLine(text, cls = "") {
  const d = document.createElement("div");
  d.textContent = text;
  if (cls) d.className = cls;
  bootLines.appendChild(d);
}
const bootProgress = (p) => { bootBar.style.width = `${Math.round(p * 100)}%`; };
function bootDone() {
  bootProgress(1);
  setTimeout(() => $("#boot").classList.add("done"), prefersReducedMotion() ? 0 : 350);
  setTimeout(() => $("#boot").remove(), 1200);
}

// ---------------------------------------------------------------- 시작
async function start() {
  applyPrefs();
  bootLine("INNI 보급 함교 기동");
  bootProgress(0.1);
  let authCfg;
  try {
    authCfg = await api.get("/api/auth/config");
    state.authConfig = authCfg;
    bootLine(`통신 연결 · ${authCfg.school}`, "ok");
  } catch (e) {
    bootLine(`서버에 연결하지 못했습니다: ${e.message}`, "bad");
    bootProgress(1);
    return;
  }
  bootProgress(0.3);
  let me = null;
  try {
    me = (await api.get("/api/me")).me;
  } catch { me = null; }
  if (!me || me.status !== "active") {
    bootDone();
    me = await showLogin(authCfg, { pendingUser: me && me.status === "pending" ? me : null });
  } else {
    bootLine(`승무원 확인 · ${me.name}`, "ok");
  }
  // 메일 로그인 링크의 일회용 코드가 주소창에 남지 않게
  if (/[?&](oobCode|login)=/.test(location.search)) history.replaceState(null, "", `/${location.hash}`);
  bootProgress(0.55);
  try {
    await store.loadBootstrap();
    bootLine(`화물 목록 동기화 · 품목 ${state.counts.items} · 장소 ${state.locations.length}`, "ok");
  } catch (e) {
    bootLine(`불러오지 못했습니다: ${e.message}`, "bad");
    toastError(e);
    return;
  }
  bootProgress(0.75);
  await store.loadAiConfig();
  bootLine(state.ai.available ? `${state.ai.name} 코어 연결됨` : `${state.ai.name} 코어 대기(AI 미연결 — 기본 명령은 됨)`, state.ai.available ? "ok" : "");
  bootProgress(0.9);
  setupShell();
  bootLine("준비 완료", "ok");
  if (document.getElementById("boot")) bootDone();
  sfx.play("boot");
}

// ---------------------------------------------------------------- 셸
let router;
let inni;
let scene = null;

function setupShell() {
  $("#app").hidden = false;
  setUnauthorizedHandler(() => {
    toast("로그인이 끝났습니다. 다시 로그인하세요.", { tone: "warn" });
    setTimeout(() => location.reload(), 1500);
  });

  // 스테이션 붙이기
  for (const [id, mod] of Object.entries(MODULES)) {
    const el = document.getElementById(`st-${id}`);
    if (el && mod.mount) mod.mount(el);
  }

  router = createRouter({
    canSee: (cap) => store.can(cap),
    getSettings: () => prefs,
    onAutoChange: (on) => {
      toast(on ? "상황판 모드: 화면을 자동으로 돌며 보여 줍니다" : "상황판 모드를 껐습니다", { timeout: 2000, sound: false });
    },
    onEnter: (id, params, info) => {
      const st = router.station(id);
      $("#hud-code").textContent = st.no ? `STATION ${st.no} · ${st.code}` : st.code;
      $("#hud-name").textContent = st.label;
      document.documentElement.style.setProperty("--veil", String(st.veil ?? 0.6));
      document.body.dataset.station = id;
      const mod = MODULES[id];
      if (mod && mod.enter) {
        Promise.resolve(mod.enter(params, info)).catch((e) => toastError(e));
      }
      if (inni) inni.setStation(id);
      if (scene) scene.setStation(id, params);
    },
    special: {
      scan: () => app.scanAndOpen(),
      menu: () => openMenu(),
      // #scan?code=… (라벨 QR 주소) 는 코드를 풀어서 해당 화면으로
      intercept: (id, params) => {
        if (id === "scan") {
          if (params.code) handleCode(params.code, { replace: true });
          else setTimeout(() => app.scanAndOpen(), 50);
          return true;
        }
        if (id === "add") {
          setTimeout(() => app.addItem({}), 50);
          if (!router.current) router.go("bridge", {}, { replace: true });
          return true;
        }
        return false;
      },
    },
  });
  router.paintNav();

  // 앱 손잡이 채우기
  app.go = (station, params = {}) => router.go(station, params, { user: true });
  app.back = () => router.back();
  app.openItem = (id, unitId) => router.go("item", unitId ? { id, unit: unitId } : { id }, { user: true });
  app.openLocation = (id) => router.go("decks", { loc: id }, { user: true });
  app.scan = (opts) => openScanner(opts);
  app.scanAndOpen = async () => {
    const code = await openScanner({ title: "스캔해서 열기", hint: "물건·장소 라벨의 QR 이나 바코드를 비춰 주세요" });
    if (code) handleCode(code);
  };
  app.addItem = (draft = {}) => openAddItem(draft);
  app.actions = actions;
  app.refresh = refreshAll;
  Object.defineProperty(app, "current", { get: () => router.current, configurable: true });

  // HUD
  const omni = createOmnibox({ big: false });
  $("#omni-slot").appendChild(omni.el);
  app.omnibox = omni;
  $("#brand").onclick = () => app.go("bridge");
  $("#btn-add").onclick = () => app.addItem({});
  $("#btn-scan").onclick = () => app.scanAndOpen();
  $("#btn-search-m").onclick = () => app.go("search", { focus: "1" });
  $("#btn-sound").onclick = () => { prefs.sound = prefs.sound === false; savePrefs(); applyPrefs(); if (prefs.sound) sfx.play("ok", { force: true }); };
  $("#btn-full").onclick = toggleFullscreen;
  $("#btn-help").onclick = openHelp;
  $("#btn-auto").onclick = () => router.toggleAuto();
  $("#btn-3d").onclick = () => toggleScene();
  $("#hud-condition").onclick = openAlerts;
  paintWho();
  paintCondition();
  setInterval(paintClock, 1000);
  paintClock();

  // 이니
  inni = createInni({ getStation: () => router.current });
  app.inni = inni;

  // 3D 선내 지도
  initScene();

  // 단축키·실시간
  bindKeys();
  connectLive();
  store.on("core", () => { paintCondition(); router.paintNav(); paintBadges(); });
  paintBadges();

  router.start();

  // 처음 설정(빈 inni 의 관리자)
  if (store.can("system") && !state.counts.items && !state.locations.length) setTimeout(() => openSetup(), 700);
  else setTimeout(() => inni.greet(), 1600);
}

async function refreshAll() {
  await store.refreshCore();
  const mod = MODULES[router.current];
  if (mod && mod.refresh) await mod.refresh({ kind: "manual" });
}
app.refreshCore = () => store.refreshCore();

// ---------------------------------------------------------------- HUD 조각
function paintClock() {
  const p = seoulParts();
  $("#hud-clock").textContent = `${String(p.hh).padStart(2, "0")}:${String(p.mm).padStart(2, "0")}:${String(p.ss).padStart(2, "0")}`;
  $("#hud-date").textContent = `${p.m}월 ${p.d}일 (${p.weekday}) · 서울`;
}

function paintWho() {
  const me = state.me;
  const b = $("#hud-who");
  b.innerHTML = me.photo_url ? `<img src="${esc(me.photo_url)}" alt="" referrerpolicy="no-referrer">` : esc((me.name || "?").slice(0, 1));
  b.title = `${me.name} · ${me.email}`;
  b.onclick = openAccount;
}

const LEVEL = { green: ["CONDITION GREEN", "good", "이상 없음"], yellow: ["CONDITION YELLOW", "warn", "주의"], red: ["CONDITION RED", "crit", "경보"] };
let lastLevel = null;
function paintCondition() {
  const a = state.alerts || { level: "green", alerts: [] };
  const [code, led, text] = LEVEL[a.level] || LEVEL.green;
  const btn = $("#hud-condition");
  btn.dataset.level = a.level;
  $("#hud-condition-code").textContent = code;
  $("#hud-condition-led").className = `led ${led}${a.level === "red" ? " pulse" : ""}`;
  const n = a.alerts.filter((x) => x.level !== "info").length;
  $("#hud-condition-text").textContent = n ? `${text} ${n}` : text;
  if (lastLevel && a.level === "red" && lastLevel !== "red") sfx.play("alarm");
  lastLevel = a.level;
  const lights = [
    ["서버", "good"],
    ["실시간", liveOn ? "good" : "warn"],
    [state.ai.name || "이니", state.ai.available ? "good" : "off"],
  ];
  $("#hud-lights").innerHTML = lights.map(([k, c]) => `<span class="light"><i class="led ${c}"></i><span class="lb">${esc(k)}</span></span>`).join("");
}

function paintBadges() {
  const a = state.alerts.alerts;
  const dot = (id, on) => {
    const b = document.querySelector(`#rail [data-go="${id}"] .badge-dot`);
    if (b) b.hidden = !on;
  };
  dot("dock", a.some((x) => x.kind === "overdue" || x.kind === "mine"));
  dot("repair", a.some((x) => x.kind === "repair"));
  dot("crew", a.some((x) => x.kind === "users"));
  const m = document.querySelector('#tabbar [data-act="menu"] .badge-dot');
  if (m) m.hidden = !a.some((x) => x.kind === "repair" || x.kind === "users");
}

function openAlerts() {
  const a = state.alerts.alerts;
  modal({
    title: "경보", code: "ALERTS", size: "",
    body: a.length ? `<div class="alert-list">${a.map((x, i) => `
      <div class="alert ${x.level}"><span class="ico">${x.level === "crit" ? "!" : x.level === "warn" ? "!" : "i"}</span>
        <span class="t">${esc(x.title)}</span>${x.go ? `<button class="btn xs" type="button" data-i="${i}">보기</button>` : "<span></span>"}
        ${x.detail ? `<span class="d">${esc(x.detail)}</span>` : ""}</div>`).join("")}</div>`
      : `<div class="empty">지금은 챙길 일이 없어요. 모든 화물이 제자리에 있습니다.</div>`,
    actions: [{ label: "닫기", tone: "ghost" }],
    onOpen: (h) => {
      h.el.querySelectorAll("[data-i]").forEach((b) => {
        b.onclick = () => { const x = a[Number(b.dataset.i)]; h.close(); app.go(x.go.station, x.go.params || {}); };
      });
    },
  });
}

function openAccount() {
  const me = state.me;
  const ROLE = { owner: "관리자", manager: "담당교사", teacher: "교사", student: "학생" };
  modal({
    title: me.name, code: "CREW ID", size: "narrow",
    body: `<dl class="kv"><dt>이름</dt><dd>${esc(me.name)} <button class="btn xs ghost" type="button" data-a="rename">${icon("edit")}바꾸기</button></dd>
      <dt>이메일</dt><dd>${esc(me.email)}</dd><dt>역할</dt><dd>${esc(ROLE[me.role] || me.role)}</dd></dl>
      <hr class="sep"><div class="stack" style="gap:8px">
        <button class="btn block" type="button" data-a="mine">${icon("hand")}내가 빌린 것</button>
        <button class="btn block" type="button" data-a="prefs">${icon("gear")}이 기기 화면 설정</button>
        <button class="btn block" type="button" data-a="help">${icon("help")}도움말·단축키</button>
        <button class="btn danger block" type="button" data-a="out">${icon("exit")}로그아웃</button></div>`,
    onOpen: (h) => {
      h.el.querySelector('[data-a="rename"]').onclick = async () => {
        const name = await promptDialog({ title: "내 이름", label: "대여·기록에 보이는 이름", value: me.name, confirmLabel: "바꾸기" });
        if (!name || !name.trim() || name.trim() === me.name) return;
        try {
          const r = await api.patch("/api/me", { name: name.trim() });
          state.me = { ...state.me, ...r.me };
          paintWho();
          h.close();
          toast("이름을 바꿨어요", { tone: "good" });
        } catch (e) { toastError(e); }
      };
      h.el.querySelector('[data-a="mine"]').onclick = () => { h.close(); app.go("dock", { tab: "mine" }); };
      h.el.querySelector('[data-a="prefs"]').onclick = () => { h.close(); app.go(store.can("settings") ? "systems" : "bridge", store.can("settings") ? { tab: "device" } : {}); if (!store.can("settings")) openDevicePrefs(); };
      h.el.querySelector('[data-a="help"]').onclick = () => { h.close(); openHelp(); };
      h.el.querySelector('[data-a="out"]').onclick = () => logout();
    },
  });
}

// 휴대폰 메뉴(모든 스테이션)
function openMenu() {
  const visible = STATIONS.filter((s) => !s.hidden && (!s.cap || store.can(s.cap)));
  modal({
    title: "메뉴", code: "STATIONS",
    body: `<div class="qa-grid" style="grid-template-columns:repeat(3,1fr)">${visible.map((s) => `<button class="qa" type="button" data-go="${s.id}">${icon(s.icon)}${esc(s.label)}</button>`).join("")}
      ${store.can("register") ? `<button class="qa amber" type="button" data-x="add">${icon("plus")}등록</button>` : ""}
      <button class="qa" type="button" data-x="inni">${icon("magic")}${esc(state.ai.name)}</button>
      <button class="qa" type="button" data-x="me">${icon("user")}내 계정</button></div>`,
    onOpen: (h) => {
      h.el.querySelectorAll("[data-go]").forEach((b) => { b.onclick = () => { h.close(); app.go(b.dataset.go); }; });
      h.el.querySelectorAll("[data-x]").forEach((b) => {
        b.onclick = () => {
          h.close();
          if (b.dataset.x === "add") app.addItem({});
          else if (b.dataset.x === "inni") inni.open();
          else openAccount();
        };
      });
    },
  });
}

export function openDevicePrefs() {
  import("./stations/systems.js").then((m) => m.openDevicePrefs && m.openDevicePrefs());
}

function openHelp() {
  modal({
    title: "도움말·단축키", code: "MANUAL", size: "wide",
    body: `
      <div class="grid g2">
        <div><h4 style="margin:0 0 8px">자주 하는 일</h4><ol class="guide-steps">
          <li><b>찾기</b> — 위의 검색창에 이름·초성(ㅁㅌㅁㅌ)·관리번호·장소를 치거나, 마이크로 "멀티미터 어디 있어?"라고 말하세요.</li>
          <li><b>스캔</b> — 라벨 QR 을 찍으면 그 물건·장소 화면이 바로 열립니다. 휴대폰 기본 카메라로 찍어도 됩니다.</li>
          <li><b>등록</b> — 사진 → 이름 → 장소만 넣고 저장. 사진을 찍으면 ${esc(state.ai.name)}가 이름을 맞혀 줍니다(AI 연결 시).</li>
          <li><b>이동·대여·반납·사용</b> — 물건 화면의 큰 버튼 한 번. 실수하면 알림의 <b>되돌리기</b>.</li>
          <li><b>${esc(state.ai.name)}에게 말하기</b> — "드릴 박학생한테 내일까지 빌려줘"처럼 말하면 확인 카드를 띄워 줍니다.</li></ol></div>
        <div><h4 style="margin:0 0 8px">단축키 (PC)</h4><div class="keys">
          <span class="kbd">/</span><span>찾기</span><span class="kbd">Ctrl K</span><span>명령 팔레트</span>
          <span class="kbd">N</span><span>새 물건 등록</span><span class="kbd">S</span><span>스캔</span>
          <span class="kbd">E</span><span>${esc(state.ai.name)}에게 말하기</span><span class="kbd">1~9</span><span>스테이션 이동</span>
          <span class="kbd">M</span><span>효과음</span><span class="kbd">T</span><span>3D 지도</span>
          <span class="kbd">A</span><span>상황판 모드</span><span class="kbd">F</span><span>전체 화면</span>
          <span class="kbd">Esc</span><span>창 닫기</span></div>
          <p class="help" style="margin-top:12px">바코드 스캐너(USB)를 꽂아 두면 아무 화면에서나 찍기만 해도 물건이 열립니다.</p></div>
      </div>`,
    actions: [{ label: "닫기", tone: "primary" }],
  });
}

function toggleFullscreen() {
  if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
  else document.documentElement.requestFullscreen().catch(() => toast("전체 화면을 켤 수 없습니다", { tone: "warn" }));
}

// ---------------------------------------------------------------- 3D 선내 지도
function sceneWanted() {
  if (prefs.scene === "off") return false;
  if (prefs.scene === "on") return true;
  return !isMobile() && !navigator.userAgent.match(/Mobi|Android/i);
}

async function initScene() {
  const btn = $("#btn-3d");
  if (!sceneWanted()) {
    document.body.classList.add("scene-off");
    btn.setAttribute("aria-pressed", "false");
    return;
  }
  try {
    const mod = await import("./scene/deckmap.js");
    scene = mod.createDeckMap({
      canvas: $("#scene"), labels: $("#scene-labels"), quality: prefs.quality, showLabels: prefs.labels,
      onPick: (loc) => app.openLocation(loc.id),
    });
    if (!scene) throw new Error("WebGL 없음");
    scene.setData(state.locations);
    store.on("core", () => scene.setData(state.locations));
    app.scene = scene;
    btn.setAttribute("aria-pressed", "true");
  } catch (e) {
    console.warn("3D 지도를 켤 수 없습니다:", e.message);
    document.body.classList.add("no-webgl");
    btn.setAttribute("aria-pressed", "false");
  }
}

function toggleScene() {
  const on = document.body.classList.contains("scene-off") || document.body.classList.contains("no-webgl");
  prefs.scene = on ? "on" : "off";
  savePrefs();
  if (on) {
    document.body.classList.remove("scene-off", "no-webgl");
    if (!scene) initScene();
    else { scene.resume(); $("#btn-3d").setAttribute("aria-pressed", "true"); }
  } else {
    document.body.classList.add("scene-off");
    if (scene) scene.pause();
    $("#btn-3d").setAttribute("aria-pressed", "false");
  }
}
app.toggleScene = toggleScene;

// ---------------------------------------------------------------- 단축키
function bindKeys() {
  // 바코드 스캐너(키보드처럼 빠르게 치고 Enter) 알아보기
  let buf = "";
  let lastT = 0;
  document.addEventListener("keydown", (ev) => {
    const tag = (ev.target && ev.target.tagName) || "";
    const typing = /INPUT|TEXTAREA|SELECT/.test(tag) || (ev.target && ev.target.isContentEditable);
    const now = performance.now();
    if (!typing && !ev.ctrlKey && !ev.metaKey && !ev.altKey) {
      if (ev.key.length === 1) {
        buf = now - lastT > 60 ? ev.key : buf + ev.key;
        lastT = now;
      } else if (ev.key === "Enter" && buf.length >= 4 && now - lastT < 80) {
        const code = buf;
        buf = "";
        ev.preventDefault();
        handleCode(code);
        return;
      }
    }
    if (ev.key === "Escape" && !isOverlayOpen()) {
      if (inni && inni.isOpen) { inni.close(); return; }
    }
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === "k") {
      ev.preventDefault();
      openPalette();
      return;
    }
    if (typing || isOverlayOpen() || ev.ctrlKey || ev.metaKey || ev.altKey) return;
    // 한글 자판이어도 키 위치로 판단
    const k = ev.code;
    if (/^Digit[1-9]$/.test(k)) {
      const visible = STATIONS.filter((s) => !s.hidden && (!s.cap || store.can(s.cap)));
      const s = visible[Number(k.slice(5)) - 1];
      if (s) { setTimeout(() => app.go(s.id), 0); }
    } else if (k === "Slash") { ev.preventDefault(); app.omnibox.focus(); }
    else if (k === "KeyN" && store.can("register")) { ev.preventDefault(); app.addItem({}); }
    else if (k === "KeyS") { ev.preventDefault(); app.scanAndOpen(); }
    else if (k === "KeyE") { ev.preventDefault(); inni.toggle(); }
    else if (k === "KeyM") $("#btn-sound").click();
    else if (k === "KeyF") toggleFullscreen();
    else if (k === "KeyT") toggleScene();
    else if (k === "KeyA") router.toggleAuto();
    else if (ev.key === "?") openHelp();
  });
}

function openPalette() {
  const visible = STATIONS.filter((s) => !s.hidden && (!s.cap || store.can(s.cap)));
  let remote = [];
  const fetchRemote = debounce(async (q) => {
    if (!q) { remote = []; return; }
    try {
      const r = await api.get(`/api/search?q=${encodeURIComponent(q)}&limit=8`);
      remote = [
        ...r.items.map((x) => ({ group: "물건", label: x.name, sub: x.where[0] ? x.where[0].path : x.status_label, keywords: q, run: () => app.openItem(x.id, x.match_unit_id) })),
        ...r.locations.map((l) => ({ group: "장소", label: l.name, sub: l.path, keywords: q, run: () => app.openLocation(l.id) })),
      ];
      const input = document.querySelector(".palette input");
      if (input) input.dispatchEvent(new Event("input"));
    } catch { /* 무시 */ }
  }, 150);
  commandPalette((q) => {
    fetchRemote(q);
    const cmds = [
      ...visible.map((s) => ({ group: "화면", label: s.label, sub: `${Number(s.no)}`, keywords: s.code, run: () => app.go(s.id) })),
      store.can("register") && { group: "명령", label: "새 물건 등록", sub: "N", run: () => app.addItem({}) },
      { group: "명령", label: "스캔", sub: "S", run: () => app.scanAndOpen() },
      { group: "명령", label: `${state.ai.name}에게 말하기`, sub: "E", run: () => inni.open() },
      { group: "명령", label: "재고 부족 보기", run: () => app.go("search", { status: "low" }) },
      { group: "명령", label: "연체 보기", run: () => app.go("dock", { tab: "loans", filter: "overdue" }) },
      { group: "명령", label: "내가 빌린 것", run: () => app.go("dock", { tab: "mine" }) },
      { group: "명령", label: "라벨 인쇄", run: () => app.go("search", { select: "1" }) },
      { group: "명령", label: "상황판 모드", sub: "A", run: () => router.toggleAuto() },
      { group: "명령", label: "로그아웃", run: () => logout() },
    ].filter(Boolean);
    return q ? [...remote, ...cmds] : cmds;
  }, { placeholder: "화면·명령·물건 이름을 입력하세요…" });
}

// ---------------------------------------------------------------- 실시간(SSE)
let liveOn = false;
let es = null;
function connectLive() {
  const refresh = debounce(async (change) => {
    try {
      await store.refreshCore();
      const mod = MODULES[router.current];
      if (mod && mod.refresh) await mod.refresh(change);
    } catch (e) { console.warn(e); }
  }, 350);
  const open = () => {
    es = new EventSource("/api/stream");
    es.onopen = () => { liveOn = true; $("#live-state").classList.add("on"); paintCondition(); };
    es.onerror = () => { liveOn = false; $("#live-state").classList.remove("on"); };
    es.addEventListener("change", (ev) => {
      let data;
      try { data = JSON.parse(ev.data); } catch { return; }
      if (data.kind === "restore") {
        toast("백업이 복원되었습니다. 화면을 새로 불러옵니다.", { tone: "warn" });
        setTimeout(() => location.reload(), 1800);
        return;
      }
      for (const e of data.events || []) {
        pushTicker(e);
        if (scene) scene.pulse(e);
      }
      refresh(data);
    });
  };
  open();
  loadTicker();
}

async function loadTicker() {
  try {
    const d = await api.get("/api/events?limit=14");
    const track = $("#ticker-track");
    track.innerHTML = d.events.length ? "" : '<span class="tk muted">아직 기록이 없습니다</span>';
    for (const e of d.events.reverse()) pushTicker(e, { quiet: true });
  } catch { /* 학생 등 */ }
}

function pushTicker(e, { quiet = false } = {}) {
  const track = $("#ticker-track");
  if (!track) return;
  if (track.querySelector(".muted")) track.innerHTML = "";
  const span = document.createElement("span");
  span.className = "tk";
  span.innerHTML = `<i class="led ${e.action === "loan" || e.action === "use" ? "warn" : e.action === "return" || e.action === "restock" ? "good" : "info"}"></i><span class="num">${esc(fmtTime(e.at))}</span><b>${esc(e.actor_name)}</b>${esc(e.summary)}`;
  track.prepend(span);
  while (track.children.length > 16) track.lastElementChild.remove();
  if (!quiet && e.actor_id !== (state.me && state.me.id) && prefs.proactive) {
    // 다른 사람의 작업은 조용히 알려 준다
    if (inni) inni.peek(`${e.actor_name}: ${e.summary}`);
  }
}

// 페이지를 떠날 때 SSE 닫기
window.addEventListener("beforeunload", () => { if (es) es.close(); });

start().catch((e) => {
  console.error(e);
  bootLine(`오류: ${e.message}`, "bad");
});

export { relTime };

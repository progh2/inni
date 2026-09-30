// 01 함교: 인사와 브리핑 · 빠른 작업 · 함선 상태 · 경보 · 내가 빌린 것 · 최근 기록. 가운데는 3D 선내 지도.
import { $, esc, icon, num, qty as fmtQty, dueLabel, thumb, fmtTime, relTime, isMobile } from "../lib/util.js";
import { api } from "../lib/api.js";
import { toast, toastError } from "../lib/ui.js";
import { state, can } from "../lib/store.js";
import { app } from "../app.js";
import { characterHtml } from "../ai/character.js";
import { createOmnibox } from "../features/omnibox.js";
import { pickItem } from "../features/pick-item.js";
import * as actions from "../features/actions.js";
import { openScanner } from "../features/scanner.js";

let root;
let data = null;
let typing = null;

function greetingText() {
  const h = new Date().getHours();
  const hi = h < 11 ? "좋은 아침이에요" : h < 17 ? "안녕하세요" : "오늘도 수고 많으셨어요";
  const a = state.alerts.alerts;
  const name = state.me.name;
  if (!a.length) return `${hi}, ${name} 선생님! 모든 화물이 제자리에 있어요. 찾을 물건은 위 검색창에 말하듯 적어 보세요.`;
  const crit = a.filter((x) => x.level === "crit");
  const main = crit[0] || a[0];
  return `${hi}, ${name} 선생님! ${main.title}${a.length > 1 ? ` 외 ${a.length - 1}건` : ""}이 있어요. 오른쪽 경보에서 바로 처리할 수 있어요.`;
}

function typeInto(el, text) {
  clearInterval(typing);
  if (document.body.classList.contains("reduce-motion")) { el.textContent = text; return; }
  let i = 0;
  el.innerHTML = '<span class="txt"></span><span class="caret"></span>';
  const out = el.querySelector(".txt");
  typing = setInterval(() => {
    i += 2;
    out.textContent = text.slice(0, i);
    if (i >= text.length) { clearInterval(typing); setTimeout(() => { const c = el.querySelector(".caret"); if (c) c.remove(); }, 1200); }
  }, 24);
}

function quickActions() {
  const list = [
    ["scan", "scan", "스캔", "라벨 찍기", "hero"],
    ["search", "search", "찾기", "어디 있지?", ""],
    can("register") && ["add", "plus", "등록", "새 물건", "amber"],
    can("move") && ["move", "move", "옮기기", "위치 이동", ""],
    can("loan") && ["loan", "loan", "빌려주기", "대여", ""],
    (can("loan") || state.me.role === "student") && ["return", "return", "반납", "돌려받기", ""],
    can("stock") && ["use", "use", "사용", "소모품 출고", ""],
    can("stock") && ["restock", "restock", "입고", "채워 넣기", ""],
  ].filter(Boolean);
  return `<div class="qa-grid">${list.map(([k, ic, l, s, cls]) => `<button class="qa ${cls}" type="button" data-qa="${k}">${icon(ic)}<span>${l}</span><small>${s}</small></button>`).join("")}</div>`;
}

async function runQuick(k) {
  if (k === "scan") return app.scanAndOpen();
  if (k === "search") return app.go("search", { focus: "1" });
  if (k === "add") return app.addItem({});
  if (k === "return") {
    const code = await openScanner({ title: "반납할 물건 스캔", hint: "돌려받을 장비의 라벨을 비춰 주세요. 바로 반납돼요." });
    if (!code) return;
    try {
      const hit = await api.get(`/api/scan/${encodeURIComponent(code)}`);
      if (hit.type === "asset") {
        const loans = await api.get("/api/loans?filter=active");
        const l = loans.loans.find((x) => x.asset_id === hit.id);
        if (l) return actions.quickReturn(l);
        toast("대여 중인 장비가 아니에요", { tone: "info" });
        return app.openItem(hit.item_id, hit.id);
      }
      if (hit.type === "item") return actions.returnFind({ itemId: hit.id });
    } catch (e) { toastError(e); }
    return;
  }
  const kinds = { move: null, loan: null, use: (x) => x.kind !== "equipment", restock: (x) => x.kind !== "equipment" };
  const titles = { move: "무엇을 옮길까요?", loan: "무엇을 빌려줄까요?", use: "무엇을 썼나요?", restock: "무엇이 들어왔나요?" };
  const picked = await pickItem({ title: titles[k], filter: kinds[k] });
  if (!picked) return;
  if (k === "move") actions.move({ itemId: picked.id, unitId: picked.unit_id });
  else if (k === "loan") actions.loan({ itemId: picked.id, unitId: picked.unit_id });
  else if (k === "use") actions.use({ itemId: picked.id });
  else if (k === "restock") actions.restock({ itemId: picked.id });
}

function render() {
  const c = state.counts;
  const d = data || { my_loans: [], events: [], favorites: [], active_audits: [] };
  const a = state.alerts.alerts;
  root.innerHTML = `
    <div class="st-inner">
      <div class="bridge-grid">
        <div class="left stack">
          <section class="panel">
            <div class="greet">${characterHtml({ size: "md", mood: "idle" })}<div class="say"><div class="brief"><span class="who">AI 보급관 · ${esc(state.ai.name)}</span><span data-brief></span></div></div></div>
            <div class="only-mobile-block" data-omni style="margin-top:12px"></div>
          </section>
          <section class="panel">
            <div class="panel-h"><span class="code">SHIP STATUS</span><h2>함선 상태</h2><span class="sub">${esc(state.settings.school.name)}</span></div>
            <div class="row" style="align-items:flex-end;gap:14px"><div class="big-num">${num(c.items)}<small>품목</small></div><div class="muted" style="padding-bottom:6px">장비 ${num(c.units)}대 · 장소 ${num(c.locations)}곳</div></div>
            <div class="vital-rows">
              <button class="vital-row" type="button" data-go="dock" data-p='{"tab":"loans"}'><i class="led ${c.overdue ? "crit pulse" : "info"}"></i><span class="k">대여 중</span><span class="v">${num(c.loans)}${c.overdue ? ` <small>연체 ${c.overdue}</small>` : ""}</span></button>
              <button class="vital-row" type="button" data-go="search" data-p='{"status":"low"}'><i class="led ${c.low ? "warn" : "good"}"></i><span class="k">재고 부족</span><span class="v">${num(c.low)}<small> 품목</small></span></button>
              <button class="vital-row" type="button" data-go="repair" data-p='{}'><i class="led ${c.repairs ? "serious" : "good"}"></i><span class="k">고장·수리</span><span class="v">${num(c.repairs)}<small> 건</small></span></button>
              <button class="vital-row" type="button" data-go="decks" data-p='{}'><i class="led info"></i><span class="k">실</span><span class="v">${num(c.rooms)}<small> 곳</small></span></button>
            </div>
          </section>
          ${d.my_loans.length ? `<section class="panel"><div class="panel-h"><span class="code">MY CARGO</span><h3>내가 빌린 것</h3><span class="end"><button class="btn xs ghost" type="button" data-go="dock" data-p='{"tab":"mine"}'>전체</button></span></div>
            <div class="list">${d.my_loans.slice(0, 5).map((l, i) => `<div class="irow loan-row ${l.overdue ? "overdue" : l.due_today ? "today" : ""}" data-item="${l.item_id}">${thumb({ thumb: l.thumb, kind: l.asset_id ? "equipment" : "consumable" }, "sm")}<span class="tx"><span class="nm">${esc(l.item_name)}${l.unit ? ` · ${esc(l.unit)}` : ""}</span><span class="sub">${esc(dueLabel(l.due_at))}</span></span><span class="end"><button class="btn xs good" type="button" data-ret="${i}">${icon("return")}반납</button></span></div>`).join("")}</div></section>` : ""}
        </div>
        <div class="void"><div class="void-legend"><span><i class="led info"></i>실(높이=물건 수)</span><span><i class="led warn"></i>경보 있는 실</span><span style="color:var(--amber)">▮ 찾은 곳 빛기둥</span><span class="muted">끌어서 회전 · 누르면 열기</span></div></div>
        <section class="panel qa-panel"><div class="panel-h"><span class="code">QUICK OPS</span><h3>빠른 작업</h3><span class="sub">누르고 → 고르고 → 끝</span></div>${quickActions()}</section>
        <div class="right stack">
          <section class="panel"><div class="panel-h"><span class="code">ALERTS</span><h2>경보</h2><span class="end"><span class="tag ${state.alerts.level === "red" ? "crit" : state.alerts.level === "yellow" ? "warn" : "good"}">${a.length ? `${a.length}건` : "이상 없음"}</span></span></div>
            <div class="alert-list">${a.length ? a.slice(0, 6).map((x, i) => `<div class="alert ${x.level}"><span class="ico">${x.level === "info" ? "i" : "!"}</span><span class="t">${esc(x.title)}</span>${x.go ? `<button class="btn xs" type="button" data-alert="${i}">보기</button>` : "<span></span>"}${x.detail ? `<span class="d">${esc(x.detail)}</span>` : ""}</div>`).join("")
              : `<div class="empty">모든 화물이 제자리에 있어요.<br><span class="muted">연체·재고 부족·고장 신고가 생기면 여기에 떠요.</span></div>`}</div></section>
          <section class="panel"><div class="panel-h"><span class="code">COMMS</span><h3>최근 기록</h3><span class="end"><button class="btn xs ghost" type="button" data-go="log" data-p='{}'>전체</button></span></div>
            <div class="ev-list">${(d.events || []).slice(0, 9).map((e) => `<div class="ev ${e.undone_at ? "undone" : ""}"><span class="t">${esc(fmtTime(e.at))}</span><span class="s"><span class="lab ${esc(e.action)}">${esc(e.label)}</span>${esc(e.summary)}</span>${e.undoable && e.actor_id === state.me.id && Date.now() - Date.parse(e.at) < 1800000 ? `<button class="btn xs ghost" type="button" data-undo="${e.id}" title="되돌리기">${icon("undo")}</button>` : `<span class="a">${esc(e.actor_name)}</span>`}</div>`).join("") || '<div class="empty">아직 기록이 없어요</div>'}</div></section>
          ${d.active_audits && d.active_audits.length ? `<section class="panel amber"><div class="panel-h"><span class="code">INSPECTION</span><h3>진행 중인 실사</h3></div><div class="list">${d.active_audits.map((x) => `<button class="irow" type="button" data-audit="${x.id}"><span class="thumb sm blank">${icon("audit")}</span><span class="tx"><span class="nm">${esc(x.title)}</span><span class="sub">${x.checked}/${x.total} 확인</span></span></button>`).join("")}</div></section>` : ""}
        </div>
      </div>
    </div>`;
  typeInto(root.querySelector("[data-brief]"), greetingText());
  if (isMobile()) {
    const omni = createOmnibox({ big: true });
    root.querySelector("[data-omni]").appendChild(omni.el);
  }
  root.querySelector(".greet .inni").dataset.live = "1";
}

export default {
  mount(el) {
    root = el;
    el.addEventListener("click", (ev) => {
      const q = ev.target.closest("[data-qa]");
      if (q) { runQuick(q.dataset.qa); return; }
      const g = ev.target.closest("[data-go]");
      if (g) { app.go(g.dataset.go, JSON.parse(g.dataset.p || "{}")); return; }
      const al = ev.target.closest("[data-alert]");
      if (al) { const x = state.alerts.alerts[Number(al.dataset.alert)]; if (x && x.go) app.go(x.go.station, x.go.params || {}); return; }
      const r = ev.target.closest("[data-ret]");
      if (r) { ev.stopPropagation(); actions.quickReturn(data.my_loans[Number(r.dataset.ret)]); return; }
      const u = ev.target.closest("[data-undo]");
      if (u) {
        api.post("/api/undo", { event_ids: [u.dataset.undo] }).then((res) => toast(res.events[0].summary, { tone: "info" })).catch(toastError);
        return;
      }
      const au = ev.target.closest("[data-audit]");
      if (au) { app.go("audit", { id: au.dataset.audit }); return; }
      const it = ev.target.closest("[data-item]");
      if (it) app.openItem(it.dataset.item);
    });
  },
  async enter() {
    render();
    try {
      data = await api.get("/api/dashboard");
      render();
    } catch (e) { toastError(e); }
  },
  async refresh() {
    try { data = await api.get("/api/dashboard"); } catch { /* 다음에 */ }
    render();
  },
};

export { relTime };

// 07 기록: 누가·언제·무엇을. 되돌리기, CSV. 보고서(사업예산·노후 장비·발주 목록)도 여기서.
import { $, esc, icon, fmtDateTime, relTime, qty as fmtQty, won, KIND_LABEL } from "../lib/util.js";
import { api } from "../lib/api.js";
import { toast, toastError } from "../lib/ui.js";
import { state, can } from "../lib/store.js";
import { app } from "../app.js";

let root;
let tab = "events";
let events = [];
let f = { q: "", actions: "", mine: false, item_id: "" };
let report = null;

const GROUPS = [["", "모두"], ["loan,return", "대여·반납"], ["move", "이동"], ["use,restock,adjust", "입출고"], ["create,update,unit_add,archive,unarchive", "등록·수정"], ["repair_open,repair_update,retire,status", "고장·폐기"], ["audit_start,audit_finish,audit_resolve", "실사"], ["settings,user,backup,restore,import,location", "관리"]];

function eventsHtml() {
  return `
    <div class="filterbar"><div class="scroll">${GROUPS.map(([v, l]) => `<button class="chip" type="button" data-g="${v}" aria-pressed="${f.actions === v}">${l}</button>`).join("")}</div>
      <label class="check"><input type="checkbox" data-mine ${f.mine ? "checked" : ""}> 내 것만</label>
      <div class="search" style="min-width:180px">${icon("search")}<input type="search" data-q placeholder="내용 찾기" value="${esc(f.q)}"></div></div>
    ${f.item_id ? `<p class="count-line">한 물건의 기록만 보는 중 · <button class="btn xs ghost" type="button" data-clear-item>모두 보기</button></p>` : ""}
    <section class="panel"><div class="ev-list">${events.length ? events.map((e) => `<div class="ev ${e.undone_at ? "undone" : ""}"><span class="t">${esc(fmtDateTime(e.at))}</span>
      <span class="s"><span class="lab ${esc(e.action)}">${esc(e.label)}</span>${esc(e.summary)}${e.via && e.via !== "app" ? ` <span class="tag muted">${esc({ ai: "이니", scan: "스캔", desk: "데스크", import: "가져오기", audit: "실사" }[e.via] || e.via)}</span>` : ""}
        ${e.item_id ? ` <button class="btn xs ghost" type="button" data-item="${e.item_id}">열기</button>` : ""}</span>
      ${e.undoable && ((e.actor_id === state.me.id && Date.now() - Date.parse(e.at) < 1800000) || can("edit")) ? `<button class="btn xs" type="button" data-undo="${e.id}">${icon("undo")}되돌리기</button>` : `<span class="a">${esc(e.actor_name)}</span>`}</div>`).join("") : '<div class="empty">기록이 없어요</div>'}</div>
      ${events.length >= 100 ? `<div style="text-align:center;margin-top:10px"><button class="btn" type="button" data-more>더 이전 기록</button></div>` : ""}</section>`;
}

function reportsHtml() {
  if (!report) return '<div class="empty">불러오는 중…</div>';
  const { budget, aging, low } = report;
  return `<div class="grid g2" style="align-items:start">
    <section class="panel"><div class="panel-h"><span class="code">BUDGET</span><h3>사업예산별 현황</h3></div>
      ${budget.length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>사업명</th><th>연도</th><th class="r">품목</th><th class="r">장비</th><th class="r">금액(추정)</th></tr></thead><tbody>
        ${budget.map((b) => `<tr><td>${esc(b.program || "(없음)")}</td><td>${esc(b.year || "")}</td><td class="r">${b.items}</td><td class="r">${b.units}</td><td class="r">${b.value ? won(b.value) : "—"}</td></tr>`).join("")}</tbody></table></div>` : '<div class="empty">사업명이 적힌 품목이 없어요</div>'}</section>
    <section class="panel"><div class="panel-h"><span class="code">LOW STOCK</span><h3>채워야 할 것(발주 목록)</h3><span class="end"><button class="btn xs" type="button" data-csv="low">${icon("download")}CSV</button></span></div>
      ${low.length ? `<div class="list">${low.map((x) => `<button class="irow" type="button" data-item="${x.id}"><span class="tx"><span class="nm">${esc(x.name)}</span><span class="sub">지금 ${fmtQty(x.qty)} / 최소 ${fmtQty(x.min_stock)}${esc(x.unit)} · ${esc(x.where.map((w) => w.name).join(", "))}</span></span><span class="tag warn">${fmtQty(Math.max(0, x.min_stock * 2 - x.qty))}${esc(x.unit)} 더</span></button>`).join("")}</div>` : '<div class="empty">부족한 재고가 없어요</div>'}</section>
    <section class="panel span2"><div class="panel-h"><span class="code">AGING</span><h3>내용연수 지난 장비</h3><span class="end"><button class="btn xs" type="button" data-csv="aging">${icon("download")}CSV</button></span></div>
      ${aging.length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>물건</th><th>관리번호</th><th>도입일</th><th>연수</th><th>장소</th><th>상태</th></tr></thead><tbody>
        ${aging.map((u) => `<tr class="warn"><td class="link" data-item="${u.item_id}">${esc(u.name)}</td><td class="num">${esc(u.management_number || "")}</td><td>${esc(u.purchase_date || "")}</td><td>${esc(u.years || "")}년 (~${esc(u.life_end || "")})</td><td>${esc(u.location_path || "")}</td><td>${esc(u.status_label)}</td></tr>`).join("")}</tbody></table></div>` : '<div class="empty">내용연수가 지난 장비가 없어요(도입일·내용연수를 적은 장비만 계산해요)</div>'}</section></div>`;
}

function render() {
  root.innerHTML = `
    <div class="st-inner">
      <div class="st-head"><div class="ttl"><span class="code">STATION 07 · SHIP LOG</span><h1>기록</h1><p>모든 작업이 남아요. 실수는 30분 안에 되돌릴 수 있어요(담당교사는 7일).</p></div>
        <div class="tools">${can("edit") ? `<a class="btn" href="/api/export/events.csv">${icon("download")}기록 CSV</a>` : ""}<a class="btn" href="/api/export/items.csv">${icon("download")}물품 CSV</a></div></div>
      <div class="tabs"><button class="tab" type="button" data-tab="events" aria-selected="${tab === "events"}"><span class="code">LOG</span>작업 기록</button>
        ${can("edit") ? `<button class="tab" type="button" data-tab="reports" aria-selected="${tab === "reports"}"><span class="code">REPORT</span>보고서</button>` : ""}</div>
      ${tab === "events" ? eventsHtml() : reportsHtml()}
    </div>`;
}

async function loadEvents({ more = false } = {}) {
  const qs = new URLSearchParams({ limit: "100" });
  if (f.q) qs.set("q", f.q);
  if (f.actions) qs.set("actions", f.actions);
  if (f.mine) qs.set("mine", "1");
  if (f.item_id) qs.set("item_id", f.item_id);
  if (more && events.length) qs.set("before", events[events.length - 1].at);
  const r = await api.get(`/api/events?${qs}`);
  events = more ? events.concat(r.events) : r.events;
}

async function loadReports() {
  const all = (await api.get("/api/items?limit=1000")).items;
  const budget = new Map();
  for (const x of all) {
    const k = `${x.budget_program || ""}|${x.budget_year || ""}`;
    if (!x.budget_program && !x.budget_year) continue;
    if (!budget.has(k)) budget.set(k, { program: x.budget_program, year: x.budget_year, items: 0, units: 0, value: 0 });
    const b = budget.get(k);
    b.items += 1;
    b.units += x.units;
  }
  const low = (await api.get("/api/items?status=low&limit=500")).items;
  const agingItems = (await api.get("/api/items?status=aging&limit=500")).items;
  const aging = [];
  for (const x of agingItems.slice(0, 60)) {
    const d = await api.get(`/api/items/${x.id}`);
    for (const u of d.units.filter((uu) => uu.aging)) aging.push({ ...u, name: d.item.name, years: u.useful_life_years ?? d.item.useful_life_years });
  }
  report = { budget: [...budget.values()].sort((a, b) => String(b.year).localeCompare(String(a.year))), low, aging };
}

function downloadCsv(name, rows) {
  const csv = "﻿" + rows.map((r) => r.map((c) => (/[",\n]/.test(String(c ?? "")) ? `"${String(c).replace(/"/g, '""')}"` : c ?? "")).join(",")).join("\r\n");
  const a = document.createElement("a");
  a.href = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
  a.download = name;
  a.click();
}

export default {
  mount(el) {
    root = el;
    el.addEventListener("input", (ev) => {
      if (ev.target.matches("[data-q]")) { f.q = ev.target.value.trim(); clearTimeout(el._t); el._t = setTimeout(async () => { await loadEvents(); render(); }, 300); }
    });
    el.addEventListener("change", async (ev) => {
      if (ev.target.matches("[data-mine]")) { f.mine = ev.target.checked; await loadEvents(); render(); }
    });
    el.addEventListener("click", async (ev) => {
      const t = ev.target.closest("[data-tab]");
      if (t) {
        tab = t.dataset.tab;
        render();
        if (tab === "reports") { try { await loadReports(); } catch (e) { toastError(e); } render(); }
        return;
      }
      const g = ev.target.closest("[data-g]");
      if (g) { f.actions = g.dataset.g; await loadEvents(); render(); return; }
      const u = ev.target.closest("[data-undo]");
      if (u) { try { const r = await api.post("/api/undo", { event_ids: [u.dataset.undo] }); toast(r.events[0].summary, { tone: "info" }); } catch (e) { toastError(e); } return; }
      const it = ev.target.closest("[data-item]");
      if (it) { app.openItem(it.dataset.item); return; }
      if (ev.target.closest("[data-more]")) { await loadEvents({ more: true }); render(); return; }
      if (ev.target.closest("[data-clear-item]")) { f.item_id = ""; await loadEvents(); render(); return; }
      const c = ev.target.closest("[data-csv]");
      if (c && report) {
        if (c.dataset.csv === "low") downloadCsv("inni-발주목록.csv", [["품명", "지금", "최소", "단위", "권장 주문량", "장소"], ...report.low.map((x) => [x.name, x.qty, x.min_stock, x.unit, Math.max(0, x.min_stock * 2 - x.qty), x.where.map((w) => w.path).join(" / ")])]);
        else downloadCsv("inni-노후장비.csv", [["품명", "관리번호", "도입일", "내용연수", "만료", "장소", "상태"], ...report.aging.map((u) => [u.name, u.management_number, u.purchase_date, u.years, u.life_end, u.location_path, u.status_label])]);
      }
    });
  },
  async enter(params = {}) {
    tab = params.tab || "events";
    f.item_id = params.item_id || "";
    try { await loadEvents(); } catch (e) { toastError(e); }
    render();
  },
  async refresh() {
    if (tab === "events") { try { await loadEvents(); } catch { /* 다음에 */ } render(); }
  },
};

export { relTime, KIND_LABEL };

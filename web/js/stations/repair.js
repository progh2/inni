// 06 정비: 고장 신고 접수 → 처리 중 → 완료/반려. 수리비를 적으면 월·연 합계가 나온다.
import { $, esc, icon, won, fmtDate, fmtDateTime, relTime, thumb } from "../lib/util.js";
import { api } from "../lib/api.js";
import { modal, toast, toastError, confirmDialog } from "../lib/ui.js";
import { state, can } from "../lib/store.js";
import { app } from "../app.js";
import * as actions from "../features/actions.js";
import { pickItem } from "../features/pick-item.js";

let root;
let tab = "open";
let list = [];
let costs = null;
let year = new Date().getFullYear();

function card(r, i) {
  const t = r.target;
  return `<div class="card ${r.urgency === "urgent" && r.status !== "done" ? "focus" : ""}" data-i="${i}">
    <div class="card-h">${t.thumb ? thumb({ thumb: t.thumb }, "") : `<span class="thumb blank">${icon(r.target_type === "location" ? "decks" : "wrench")}</span>`}
      <div class="ttl">${esc(r.title)}<small>${esc(t.name)}${t.unit ? ` · ${esc(t.unit)}` : ""}${t.where ? ` · ${esc(t.where)}` : ""}</small></div>
      <div class="end">${r.urgency === "urgent" ? '<span class="tag crit">급함</span>' : ""}<span class="tag ${r.status === "open" ? "warn" : r.status === "in_progress" ? "info" : r.status === "done" ? "good" : "muted"}">${esc(r.status_label)}</span></div></div>
    ${r.body ? `<p class="help" style="margin:0;white-space:pre-wrap">${esc(r.body)}</p>` : ""}
    <div class="help" style="margin:0">${esc(r.reporter_name)} · ${esc(relTime(r.created_at))}${r.cost_amount ? ` · 수리비 ${won(r.cost_amount)}` : ""}${r.resolution ? ` · ${esc(r.resolution)}` : ""}</div>
    <div class="card-actions">
      ${can("repair_manage") && r.status === "open" ? `<button class="btn sm" type="button" data-s="in_progress">${icon("wrench")}처리 시작</button>` : ""}
      ${can("repair_manage") && (r.status === "open" || r.status === "in_progress") ? `<button class="btn sm good" type="button" data-s="done">${icon("check")}완료</button><button class="btn sm ghost" type="button" data-s="rejected">반려</button>` : ""}
      ${can("repair_manage") ? `<button class="btn sm ghost" type="button" data-cost>${icon("edit")}비용·메모</button>` : ""}
      ${t.item_id ? `<button class="btn sm ghost" type="button" data-open>${icon("box")}물건 보기</button>` : ""}
    </div></div>`;
}

function render() {
  root.innerHTML = `
    <div class="st-inner">
      <div class="st-head"><div class="ttl"><span class="code">STATION 06 · REPAIR BAY</span><h1>정비</h1><p>고장 신고는 누구나, 처리는 담당교사가. 완료하면 장비가 다시 "사용 가능"이 돼요.</p></div>
        <div class="tools">${can("repair") ? `<button class="btn warn" type="button" data-b="new">${icon("wrench")}고장 신고</button>` : ""}</div></div>
      <div class="tabs">${[["open", "OPEN", "처리할 것"], ["closed", "CLOSED", "끝난 것"], can("repair_manage") && ["costs", "COST", "수리비"]].filter(Boolean).map(([k, c, l]) => `<button class="tab" type="button" data-tab="${k}" aria-selected="${tab === k}"><span class="code">${c}</span>${l}</button>`).join("")}</div>
      ${tab === "costs" ? costsHtml() : list.length ? `<div class="cards">${list.map(card).join("")}</div>` : `<div class="empty">${tab === "open" ? "처리할 고장 신고가 없어요. 모든 장비 이상 없음!" : "기록이 없어요."}</div>`}
    </div>`;
}

function costsHtml() {
  if (!costs) return '<div class="empty">불러오는 중…</div>';
  const max = Math.max(1, ...costs.months.map((m) => m.total || 0));
  return `<section class="panel"><div class="panel-h"><span class="code">COST</span><h3>${costs.year}년 수리비</h3>
      <span class="end"><select data-year style="width:auto">${[0, 1, 2, 3].map((d) => new Date().getFullYear() - d).map((y) => `<option ${y === costs.year ? "selected" : ""}>${y}</option>`).join("")}</select>
      <a class="btn sm" href="/api/export/repair-costs.csv?year=${costs.year}">${icon("download")}CSV</a></span></div>
    <div class="big-num">${won(costs.total)}</div>
    <div class="stack" style="gap:6px;margin-top:12px">${costs.months.map((m) => `<div class="row nw"><span class="num" style="width:70px">${esc(m.ym)}</span><div class="meter" style="flex:1"><i style="width:${(m.total / max) * 100}%"></i></div><span class="num" style="width:120px;text-align:right">${won(m.total)} · ${m.n}건</span></div>`).join("") || '<div class="empty">비용 기록이 없어요</div>'}</div></section>`;
}

async function load() {
  try {
    if (tab === "costs") costs = await api.get(`/api/repairs-costs?year=${year}`);
    else list = (await api.get(`/api/repairs?status=${tab}`)).repairs;
  } catch (e) { toastError(e); }
  render();
}

function costDialog(r) {
  modal({
    title: "수리 기록", code: "REPAIR LOG",
    body: `<div class="form-grid"><label class="field"><span>수리비(원)</span><input type="number" data-k="cost_amount" value="${r.cost_amount ?? ""}" inputmode="numeric"></label>
      <label class="field"><span>업체</span><input type="text" data-k="cost_vendor" value="${esc(r.cost_vendor || "")}"></label>
      <label class="field"><span>예산 과목</span><input type="text" data-k="cost_budget" value="${esc(r.cost_budget || "")}"></label>
      <label class="field"><span>비용 날짜</span><input type="date" data-k="cost_at" value="${esc(r.cost_at || "")}"></label>
      <label class="field wide"><span>처리 메모</span><input type="text" data-k="resolution" value="${esc(r.resolution || "")}" placeholder="예: 롤러 교체"></label></div>`,
    actions: [{ label: "취소", tone: "ghost" }, { label: "저장", tone: "primary", onClick: async (h) => {
      const body = {};
      for (const el of h.el.querySelectorAll("[data-k]")) body[el.dataset.k] = el.value.trim() || null;
      try { await api.patch(`/api/repairs/${r.id}`, body); toast("저장했어요", { tone: "good" }); return true; } catch (e) { toastError(e); return false; }
    } }],
  });
}

export default {
  mount(el) {
    root = el;
    el.addEventListener("change", (ev) => { if (ev.target.matches("[data-year]")) { year = Number(ev.target.value); load(); } });
    el.addEventListener("click", async (ev) => {
      const t = ev.target.closest("[data-tab]");
      if (t) { tab = t.dataset.tab; load(); return; }
      const b = ev.target.closest("[data-b]");
      if (b && b.dataset.b === "new") {
        const p = await pickItem({ title: "어떤 물건이 고장났나요?" });
        if (!p) return;
        const d = await api.get(`/api/items/${p.id}`);
        const unit = p.unit_id ? d.units.find((u) => u.id === p.unit_id) : d.units.length === 1 ? d.units[0] : null;
        actions.reportRepair(unit ? { targetType: "asset", targetId: unit.id, name: `${d.item.name} ${unit.management_number || ""}` } : { targetType: "item", targetId: d.item.id, name: d.item.name });
        return;
      }
      const c = ev.target.closest("[data-i]");
      if (!c) return;
      const r = list[Number(c.dataset.i)];
      const s = ev.target.closest("[data-s]");
      if (s) {
        if (s.dataset.s === "rejected" && !(await confirmDialog({ title: "반려", message: "이 신고를 반려할까요?", confirmLabel: "반려", tone: "warn" }))) return;
        try { await api.patch(`/api/repairs/${r.id}`, { status: s.dataset.s, mark_repair: s.dataset.s === "in_progress" }); toast("상태를 바꿨어요", { tone: "good" }); } catch (e) { toastError(e); }
        return;
      }
      if (ev.target.closest("[data-cost]")) { costDialog(r); return; }
      if (ev.target.closest("[data-open]")) app.openItem(r.target.item_id, r.target.asset_id);
    });
  },
  enter(params = {}) {
    tab = params.status === "closed" ? "closed" : params.tab || "open";
    return load();
  },
  refresh() { return load(); },
};

export { fmtDate, fmtDateTime, state };

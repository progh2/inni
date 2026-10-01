// 05 입출항: 대여 데스크(빌리는 사람을 고르고 스캔만 → 대여·반납이 번갈아) · 대여 중 · 내 대여 · 기록
import { $, esc, icon, qty as fmtQty, dueLabel, thumb, fmtDateTime, fmtTime, relTime } from "../lib/util.js";
import { api } from "../lib/api.js";
import { modal, toast, toastError } from "../lib/ui.js";
import { state, can } from "../lib/store.js";
import { app } from "../app.js";
import * as sfx from "../lib/sfx.js";
import * as actions from "../features/actions.js";
import { borrowerHtml, bindBorrower, dueHtml, bindDue } from "../features/pickers.js";
import { openScanner } from "../features/scanner.js";
import { mood } from "../ai/character.js";

let root;
let tab = "loans";
let filter = "";
let q = "";
let loans = [];
let events = [];
const deskLog = [];
let getBorrower = null;
let getDue = null;

function tabs() {
  const list = [
    can("loan") && ["desk", "DESK", "대여 데스크"],
    ["loans", "ACTIVE", "대여 중", state.counts.loans],
    ["mine", "MINE", "내가 빌린 것"],
    ["returned", "RETURNED", "반납 기록"],
    ["moves", "MOVES", "입출고·이동 기록"],
  ].filter(Boolean);
  return `<div class="tabs" role="tablist">${list.map(([k, code, l, n]) => `<button class="tab" type="button" role="tab" data-tab="${k}" aria-selected="${tab === k}"><span class="code">${code}</span>${l}${n ? ` <span class="n">${n}</span>` : ""}</button>`).join("")}</div>`;
}

function loanRow(l, i) {
  return `<div class="irow loan-row ${l.overdue ? "overdue" : l.due_today ? "today" : ""}" data-i="${i}">
    ${thumb({ thumb: l.thumb, kind: l.asset_id ? "equipment" : "consumable" }, "sm")}
    <span class="tx"><span class="nm">${esc(l.item_name)}${l.unit ? ` · ${esc(l.unit)}` : ""}${!l.asset_id ? ` · ${fmtQty(l.quantity)}${esc(l.unit_label || "")}` : ""}</span>
      <span class="sub">${icon("user")} ${esc(l.borrower_name)}${l.borrower_note ? ` (${esc(l.borrower_note)})` : ""} · ${l.status === "returned" ? `반납 ${esc(fmtDateTime(l.returned_at))}${l.return_condition === "issue" ? " · 이상 있음" : ""}` : `<b style="color:${l.overdue ? "#ff9a9a" : l.due_today ? "#ffdd8f" : "inherit"}">${esc(dueLabel(l.due_at))}</b>`}${l.purpose ? ` · ${esc(l.purpose)}` : ""}</span></span>
    <span class="end">${l.status !== "returned" && (can("loan") || tab === "mine") ? `<button class="btn sm good" type="button" data-ret="${i}">${icon("return")}반납</button><button class="btn sm ghost" type="button" data-retx="${i}" title="상태·장소 골라 반납">${icon("more")}</button>` : ""}</span></div>`;
}

function listHtml() {
  if (tab === "moves") {
    return events.length ? `<div class="ev-list">${events.map((e) => `<div class="ev ${e.undone_at ? "undone" : ""}"><span class="t">${esc(fmtTime(e.at))}</span><span class="s"><span class="lab ${esc(e.action)}">${esc(e.label)}</span>${esc(e.summary)} <span class="muted">· ${esc(relTime(e.at))}</span></span><span class="a">${esc(e.actor_name)}</span></div>`).join("")}</div>` : '<div class="empty">기록이 없어요</div>';
  }
  const rows = loans.map(loanRow).join("");
  return `${tab === "loans" ? `<div class="filterbar"><div class="scroll">${[["", "전체"], ["overdue", "연체"], ["today", "오늘까지"]].map(([v, l]) => `<button class="chip ${v === "overdue" ? "warn" : ""}" type="button" data-f="${v}" aria-pressed="${filter === v}">${l}</button>`).join("")}</div>
      <div class="search" style="min-width:180px">${icon("search")}<input type="search" data-q placeholder="물건·사람 이름" value="${esc(q)}"></div></div>` : ""}
    ${loans.length ? `<div class="list">${rows}</div>${tab !== "returned" && loans.length > 1 && can("loan") ? `<div style="margin-top:12px"><button class="btn" type="button" data-b="retall">${icon("return")}보이는 ${loans.length}건 한꺼번에 반납</button></div>` : ""}`
      : `<div class="empty">${tab === "mine" ? "빌린 것이 없어요." : tab === "returned" ? "반납 기록이 없어요." : filter === "overdue" ? "연체된 대여가 없어요. 👍" : "대여 중인 것이 없어요."}</div>`}`;
}

function deskHtml() {
  return `<div class="desk">
    <section class="panel">
      <div class="panel-h"><span class="code">STEP 1</span><h3>누가 빌리나요</h3><span class="sub">반납만 할 때는 신경 쓰지 않아도 돼요</span></div>
      ${borrowerHtml()}
      <div class="pick-sec">반납 예정</div>${dueHtml()}
      <div class="panel-h" style="margin-top:18px"><span class="code">STEP 2</span><h3>스캔하세요</h3><span class="sub">빌려 간 것이면 반납, 있는 것이면 대여</span></div>
      <form class="desk-input" data-desk><input type="text" data-code placeholder="바코드 스캐너로 찍거나 관리번호 입력" autocomplete="off" autofocus><button class="btn primary" type="submit">확인</button></form>
      <div class="row" style="margin-top:10px"><button class="btn lg" type="button" data-b="cam">${icon("camera")}카메라로 계속 스캔</button></div>
      <p class="help" style="margin-top:10px">USB 바코드 스캐너를 꽂으면 이 칸에 저절로 찍혀요. 휴대폰은 카메라로 연속 스캔하세요.</p>
    </section>
    <section class="panel"><div class="panel-h"><span class="code">DESK LOG</span><h3>이번에 처리한 것</h3><span class="sub">${deskLog.length}건</span></div>
      <div class="desk-log">${deskLog.length ? deskLog.map((h, i) => `<div class="desk-hit ${h.kind}"><span class="big">${h.kind === "loan" ? "OUT" : h.kind === "return" ? "IN" : "ERR"}</span><span style="flex:1;min-width:0"><b>${esc(h.title)}</b><div class="help" style="margin:0">${esc(h.sub)}</div></span>${h.events ? `<button class="btn xs ghost" type="button" data-dundo="${i}" title="되돌리기">${icon("undo")}</button>` : ""}</div>`).join("") : '<div class="empty">아직 없어요. 스캔하면 여기에 쌓여요.</div>'}</div></section>
  </div>`;
}

function render() {
  root.innerHTML = `
    <div class="st-inner">
      <div class="st-head"><div class="ttl"><span class="code">STATION 05 · DOCKING BAY</span><h1>입출항</h1><p>빌려주고 돌려받는 곳. 수업 시작·끝에는 대여 데스크에서 스캔만 하세요.</p></div></div>
      ${tabs()}
      <div data-body>${tab === "desk" ? deskHtml() : listHtml()}</div>
    </div>`;
  if (tab === "desk") {
    getBorrower = bindBorrower(root);
    getDue = bindDue(root);
    setTimeout(() => { const i = root.querySelector("[data-code]"); if (i) i.focus(); }, 300);
  }
}

async function load() {
  if (tab === "desk") { render(); return; }
  try {
    if (tab === "moves") events = (await api.get("/api/events?actions=use,restock,move,loan,return,adjust&limit=120")).events;
    else {
      const f = tab === "mine" ? "mine" : tab === "returned" ? "returned" : filter || "active";
      loans = (await api.get(`/api/loans?filter=${f}${q ? `&q=${encodeURIComponent(q)}` : ""}`)).loans;
    }
  } catch (e) { toastError(e); }
  render();
}

// 데스크: 코드 하나 처리
async function deskCode(code) {
  code = String(code || "").trim();
  if (!code) return null;
  let hit;
  try {
    hit = await api.get(`/api/scan/${encodeURIComponent(code)}`);
  } catch {
    deskLog.unshift({ kind: "err", title: code, sub: "등록되지 않은 코드" });
    sfx.play("error");
    render();
    return { ok: false, text: `모르는 코드: ${code}`, short: code };
  }
  if (hit.type !== "asset") {
    deskLog.unshift({ kind: "err", title: code, sub: hit.type === "location" ? "장소 라벨이에요" : "수량 품목은 물건 화면에서 빌려주세요" });
    sfx.play("warn");
    render();
    return { ok: false, text: "장비 라벨을 찍어 주세요", short: code };
  }
  try {
    const d = await api.get(`/api/items/${hit.item_id}`);
    const u = d.units.find((x) => x.id === hit.id);
    const tag = `${d.item.name} ${u ? u.management_number || u.label || "" : ""}`.trim();
    if (u && u.status === "on_loan") {
      const r = await api.post("/api/actions/return", { asset_ids: [u.id], condition: "ok" }, { via: "desk" });
      deskLog.unshift({ kind: "return", title: tag, sub: `반납 · ${u.loan ? u.loan.borrower_name : ""}`, events: r.events });
      sfx.play("in");
      mood("happy", 1000);
      render();
      return { ok: true, text: `반납: ${tag}`, short: `IN ${u.management_number || ""}` };
    }
    if (u && u.status === "available") {
      const bw = getBorrower ? getBorrower() : { borrower_name: state.me.name, borrower_user_id: state.me.id };
      const r = await api.post("/api/actions/loan", { asset_ids: [u.id], ...bw, due_at: getDue ? getDue() : null }, { via: "desk" });
      deskLog.unshift({ kind: "loan", title: tag, sub: `대여 → ${bw.borrower_name}`, events: r.events });
      sfx.play("out");
      render();
      return { ok: true, text: `대여: ${tag} → ${bw.borrower_name}`, short: `OUT ${u.management_number || ""}` };
    }
    deskLog.unshift({ kind: "err", title: tag, sub: `지금 ${u ? u.status_label : "?"}이라 처리할 수 없어요` });
    sfx.play("warn");
    render();
    return { ok: false, text: `${tag}: ${u ? u.status_label : ""}`, short: u ? u.management_number : code };
  } catch (e) {
    deskLog.unshift({ kind: "err", title: code, sub: e.message });
    sfx.play("error");
    render();
    return { ok: false, text: e.message, short: code };
  }
}

export default {
  mount(el) {
    root = el;
    el.addEventListener("submit", async (ev) => {
      if (!ev.target.matches("[data-desk]")) return;
      ev.preventDefault();
      const input = ev.target.querySelector("[data-code]");
      const v = input.value;
      input.value = "";
      await deskCode(v);
      const i = root.querySelector("[data-code]");
      if (i) i.focus();
    });
    el.addEventListener("input", (ev) => {
      if (ev.target.matches("[data-q]")) { q = ev.target.value.trim(); clearTimeout(el._qt); el._qt = setTimeout(load, 250); }
    });
    el.addEventListener("click", async (ev) => {
      const t = ev.target.closest("[data-tab]");
      if (t) { tab = t.dataset.tab; history.replaceState(null, "", `#dock?tab=${tab}`); load(); return; }
      const f = ev.target.closest("[data-f]");
      if (f) { filter = f.dataset.f; load(); return; }
      const r = ev.target.closest("[data-ret]");
      if (r) { ev.stopPropagation(); actions.quickReturn(loans[Number(r.dataset.ret)]); return; }
      const rx = ev.target.closest("[data-retx]");
      if (rx) { ev.stopPropagation(); actions.returnLoans([loans[Number(rx.dataset.retx)]]); return; }
      const du = ev.target.closest("[data-dundo]");
      if (du) {
        const h = deskLog[Number(du.dataset.dundo)];
        try {
          await api.post("/api/undo", { event_ids: h.events.map((e) => e.id) });
          h.kind = "err";
          h.sub = `되돌림 (${h.sub})`;
          h.events = null;
          sfx.play("undo");
          render();
        } catch (e) { toastError(e); }
        return;
      }
      const b = ev.target.closest("[data-b]");
      if (b && b.dataset.b === "cam") {
        await openScanner({ title: "대여 데스크 연속 스캔", hint: "라벨을 차례로 비추세요. 빌려 간 것은 반납, 있는 것은 대여돼요.", continuous: true, onCode: deskCode });
        render();
        return;
      }
      if (b && b.dataset.b === "retall") { actions.returnLoans(loans); return; }
      const row = ev.target.closest("[data-i]");
      if (row) app.openItem(loans[Number(row.dataset.i)].item_id, loans[Number(row.dataset.i)].asset_id || undefined);
    });
  },
  enter(params = {}) {
    tab = params.tab || (tab === "desk" && can("loan") ? "desk" : "loans");
    if (tab === "desk" && !can("loan")) tab = "loans";
    filter = params.filter || "";
    q = "";
    return load();
  },
  refresh() { if (tab !== "desk") return load(); return null; },
};

// 물건 화면: 어디 있는지 크게, 할 일은 큰 버튼 한 번. 장비는 한 대씩, 수량 품목은 장소마다.
import { $, esc, icon, qty as fmtQty, won, fmtDate, fmtDateTime, relTime, dueLabel, statusChip, UNIT_TONE, thumb, KIND_ICON } from "../lib/util.js";
import { api } from "../lib/api.js";
import { modal, toast, toastError, confirmDialog } from "../lib/ui.js";
import { state, can, locPath } from "../lib/store.js";
import { app } from "../app.js";
import * as actions from "../features/actions.js";
import { openEditItem } from "../features/add-item.js";
import { printLabels } from "../features/labels.js";
import { locationPicker, stepperHtml, bindStepper } from "../features/pickers.js";
import { editImage } from "../features/image-editor.js";
import { uploadCanvas } from "../features/images.js";

let root;
let d = null;
let params = {};

const unitTone = (u) => ({ available: "available", on_loan: "on_loan", repair: "repair", lost: "lost", retired: "archived" }[u.status] || "info");

function whereSummary() {
  const c = d.card;
  if (!c.where.length) return { p: c.on_loan ? "모두 대여 중" : "위치가 정해지지 않았어요", s: "" };
  const top = c.where[0];
  const more = c.where.length > 1 || c.where_more ? `외 ${c.where.length - 1 + c.where_more}곳` : "";
  const amount = d.item.kind === "equipment" ? `${top.units}대` : `${fmtQty(top.qty)}${d.item.unit}`;
  return { p: top.path, s: `${amount} ${more}`.trim() };
}

function unitRow(u) {
  const focus = params.unit === u.id;
  const loan = u.loan;
  return `<div class="unit ${focus ? "focus" : ""}" data-unit="${u.id}">
    <span class="no">${esc(u.management_number || u.label || "번호 없음")}${u.label && u.management_number ? `<small>${esc(u.label)}</small>` : ""}${u.serial_number ? `<small>S/N ${esc(u.serial_number)}</small>` : ""}</span>
    <span class="info">${statusChip(unitTone(u), u.status_label)}
      ${loan ? `<div class="where-line">${icon("user")}<span>${esc(loan.borrower_name)}${loan.borrower_note ? ` (${esc(loan.borrower_note)})` : ""} · <b style="color:${loan.overdue ? "#ff9a9a" : "inherit"}">${esc(dueLabel(loan.due_at))}</b></span></div>` : ""}
      <div class="where-line">${icon("pin")}<span>${esc(u.location_path || "위치 없음")}</span></div>
      ${u.aging ? `<span class="tag warn">내용연수 지남(${esc(u.life_end)})</span>` : u.life_end ? `<span class="tag muted">내용연수 ~${esc(u.life_end)}</span>` : ""}</span>
    <span class="acts">
      ${u.status === "on_loan" ? (can("loan") ? `<button class="btn sm good" type="button" data-u="return">${icon("return")}반납</button>` : "")
        : u.status === "available" && can("loan") ? `<button class="btn sm amber" type="button" data-u="loan">${icon("loan")}대여</button>` : ""}
      ${u.status !== "on_loan" && can("move") ? `<button class="btn sm" type="button" data-u="move">${icon("move")}옮기기</button>` : ""}
      <button class="btn sm ghost" type="button" data-u="more" aria-label="더보기">${icon("more")}</button></span></div>`;
}

function stockRow(s) {
  const low = d.item.min_stock !== null && d.card.low;
  return `<div class="stock ${low ? "low" : ""}" data-stock="${s.id}">
    <div class="where-line" style="font-size:14px">${icon("pin")}<span>${esc(s.location_path)}${s.lot_code ? ` · 로트 ${esc(s.lot_code)}` : ""}${s.expires_at ? ` · <span style="color:${s.expired ? "#ff9a9a" : "inherit"}">~${esc(s.expires_at)}</span>` : ""}</span></div>
    <div class="q">${fmtQty(s.quantity)}<small>${esc(d.item.unit)}</small></div>
    ${can("stock") ? `<div class="qbtn"><button class="btn" type="button" data-s="minus" title="1${esc(d.item.unit)} 사용">${icon("minus")}</button><button class="btn" type="button" data-s="plus" title="1${esc(d.item.unit)} 입고">${icon("plus")}</button>
      <button class="btn ghost" type="button" data-s="more" aria-label="더보기">${icon("more")}</button></div>` : ""}
  </div>`;
}

function render() {
  const it = d.item;
  const c = d.card;
  const w = whereSummary();
  const eq = it.kind === "equipment";
  const img = it.image;
  const loansQty = d.loans.filter((l) => !l.asset_id);
  const canEdit = can("edit") || (can("register") && it.created_by === state.me.id && Date.now() - Date.parse(it.created_at) < 3600000);
  const field = (k, v) => (v === null || v === undefined || v === "" ? "" : `<div class="f"><div class="k">${esc(k)}</div><div class="v">${v}</div></div>`);
  root.innerHTML = `
    <div class="st-inner">
      <div class="st-head" style="margin-bottom:10px"><button class="btn ghost" type="button" data-b="back">${icon("left")}뒤로</button>
        <div class="tools">
          <button class="icon-btn" type="button" data-b="fav" aria-pressed="${it.favorite}" title="즐겨찾기">${icon(it.favorite ? "star-fill" : "star")}</button>
          <button class="btn" type="button" data-b="labels">${icon("print")}라벨</button>
          ${canEdit ? `<button class="btn" type="button" data-b="edit">${icon("edit")}고치기</button>` : ""}
          <button class="btn ghost" type="button" data-b="more">${icon("more")}</button>
        </div></div>
      <div class="item-hero">
        <div class="item-photo" data-b="photo" title="${canEdit ? "사진 바꾸기·다듬기" : ""}">${img ? `<img src="/uploads/${esc(img)}" alt="${esc(it.name)}">` : `<div class="noimg">${icon(KIND_ICON[it.kind] || "box")}</div>`}<i class="ring"></i>
          ${canEdit ? `<label class="btn sm cam" onclick="event.stopPropagation()">${icon("camera")}${img ? "사진" : "사진 넣기"}<input type="file" accept="image/*" capture="environment" hidden data-photo></label>` : ""}</div>
        <div>
          <div class="item-title"><div style="min-width:0"><span class="code" style="font:600 10px/1 var(--font-display);letter-spacing:.26em;color:var(--hud-dim)">CARGO · ${esc(it.qr)}</span>
            <h1>${esc(it.name)}</h1>
            <div class="meta">${esc(it.kind_label)}${d.card.category ? ` · ${esc(d.card.category.name)}` : ""}${it.manufacturer || it.model ? ` · ${esc([it.manufacturer, it.model].filter(Boolean).join(" "))}` : ""}${it.archived ? ' · <span class="tag muted">보관함</span>' : ""}</div></div></div>
          <div class="where-big">${icon("pin")}<div style="flex:1;min-width:0"><div class="p">${esc(w.p)}<small>${esc(w.s)}</small></div></div>
            ${app.scene && c.where.length ? `<button class="btn sm" type="button" data-b="map">${icon("decks")}지도</button>` : ""}</div>
          <div class="row" style="margin-bottom:14px">${statusChip(c.status, c.status_label)}
            ${eq ? `<span class="tag">${c.units}대 · 사용 가능 ${c.available}${c.on_loan ? ` · 대여 ${c.on_loan}` : ""}${c.repair ? ` · 수리 ${c.repair}` : ""}</span>` : `<span class="tag">전체 ${fmtQty(c.qty)}${esc(it.unit)}${it.min_stock !== null ? ` · 최소 ${fmtQty(it.min_stock)}` : ""}${c.loaned_qty ? ` · 대여 ${fmtQty(c.loaned_qty)}` : ""}</span>`}
            ${c.overdue ? '<span class="tag crit">연체</span>' : ""}${c.low ? '<span class="tag warn">재고 부족</span>' : ""}${c.expired ? '<span class="tag crit">유통기한 지남</span>' : c.expiring ? '<span class="tag warn">유통기한 임박</span>' : ""}${c.aging ? '<span class="tag warn">노후</span>' : ""}</div>
          <div class="actbar">
            ${eq ? `${can("loan") && c.available ? `<button class="btn amber" type="button" data-a="loan">${icon("loan")}빌려주기</button>` : ""}
              ${can("loan") && c.on_loan ? `<button class="btn good" type="button" data-a="return">${icon("return")}반납 받기</button>` : ""}
              ${can("move") ? `<button class="btn" type="button" data-a="move">${icon("move")}옮기기</button>` : ""}`
            : `${can("stock") ? `<button class="btn amber" type="button" data-a="use">${icon("use")}사용</button><button class="btn good" type="button" data-a="restock">${icon("restock")}입고</button>` : ""}
              ${can("move") ? `<button class="btn" type="button" data-a="move">${icon("move")}옮기기</button>` : ""}
              ${can("loan") && c.qty > 0 ? `<button class="btn" type="button" data-a="loan">${icon("loan")}빌려주기</button>` : ""}`}
            ${can("repair") ? `<button class="btn" type="button" data-a="repair">${icon("wrench")}고장 신고</button>` : ""}
          </div>
        </div>
      </div>
      <div class="grid g2" style="margin-top:16px;align-items:start">
        <div class="stack">
          ${eq ? `<section class="panel"><div class="panel-h"><span class="code">UNITS</span><h3>장비 ${d.units.length}대</h3><span class="end">${can("register") ? `<button class="btn xs" type="button" data-b="addunits">${icon("plus")}대수 추가</button>` : ""}</span></div>
            <div class="unit-list">${d.units.length ? d.units.map(unitRow).join("") : '<div class="empty">등록된 장비가 없어요. [대수 추가]로 넣으세요.</div>'}</div>
            ${d.retired_units.length ? `<details class="more" style="margin-top:8px"><summary>폐기된 장비 ${d.retired_units.length}대</summary><div class="list">${d.retired_units.map((u) => `<div class="irow"><span class="tx"><span class="nm">${esc(u.management_number || u.label || "")}</span><span class="sub">${esc(u.retired_at || "")} · ${esc(u.retire_reason || "")}</span></span></div>`).join("")}</div></details>` : ""}</section>`
          : `<section class="panel"><div class="panel-h"><span class="code">STOCK</span><h3>어디에 얼마나</h3><span class="sub">[−][+] 한 번 = 1${esc(it.unit)}, 바로 반영(되돌리기 있음)</span></div>
            <div class="stock-list">${d.stocks.length ? d.stocks.map(stockRow).join("") : '<div class="empty">재고가 없어요. [입고]로 넣으세요.</div>'}</div></section>`}
          ${loansQty.length ? `<section class="panel"><div class="panel-h"><span class="code">ON LOAN</span><h3>빌려 간 것</h3></div><div class="list">${loansQty.map((l) => `<div class="irow loan-row ${l.overdue ? "overdue" : ""}"><span class="tx"><span class="nm">${esc(l.borrower_name)} · ${fmtQty(l.quantity)}${esc(it.unit)}</span><span class="sub">${esc(dueLabel(l.due_at))}${l.purpose ? ` · ${esc(l.purpose)}` : ""}</span></span>${can("loan") ? `<button class="btn xs good" type="button" data-ret="${l.id}">${icon("return")}반납</button>` : ""}</div>`).join("")}</div></section>` : ""}
          ${d.repairs.length ? `<section class="panel"><div class="panel-h"><span class="code">REPAIR</span><h3>고장·수리</h3></div><div class="list">${d.repairs.map((r) => `<button class="irow" type="button" data-go-repair="${r.id}"><span class="thumb sm blank">${icon("wrench")}</span><span class="tx"><span class="nm">${esc(r.title)}</span><span class="sub">${esc(relTime(r.created_at))}</span></span>${r.urgency === "urgent" ? '<span class="tag crit">급함</span>' : ""}<span class="tag">${r.status === "open" ? "접수" : "처리 중"}</span></button>`).join("")}</div></section>` : ""}
        </div>
        <div class="stack">
          <section class="panel"><div class="panel-h"><span class="code">SPEC</span><h3>정보</h3></div>
            <div class="fields-view">
              ${field("제조사", esc(it.manufacturer))}${field("모델", esc(it.model))}${field("규격", esc(it.spec))}${field("분류", d.card.category ? esc(d.card.category.name) : "")}
              ${field("다른 이름", esc(it.aliases))}${field("가격", it.price ? won(it.price) : "")}${field("구입처", esc(it.vendor))}
              ${field("제품 링크", it.product_url ? `<a href="${esc(it.product_url)}" target="_blank" rel="noopener">${icon("link")} 열기</a>` : "")}
              ${field("바코드", esc(it.barcode))}${field("에듀파인", esc(it.edufine_number))}${field("물품분류번호", esc(it.class_number))}
              ${field("사업명", esc(it.budget_program))}${field("예산 연도", it.budget_year || "")}${field("내용연수", it.useful_life_years ? `${it.useful_life_years}년` : "")}
              ${field("최소 재고", !eq && it.min_stock !== null ? `${fmtQty(it.min_stock)}${esc(it.unit)}` : "")}${field("등록", fmtDate(it.created_at))}
            </div>
            ${it.description || it.notes ? `<p class="help" style="margin-top:10px;white-space:pre-wrap">${esc([it.description, it.notes].filter(Boolean).join("\n"))}</p>` : ""}</section>
          <section class="panel"><div class="panel-h"><span class="code">HISTORY</span><h3>기록</h3></div>
            <div class="ev-list">${d.events.length ? d.events.map((e) => `<div class="ev ${e.undone_at ? "undone" : ""}"><span class="t">${esc(fmtDateTime(e.at).split(" ").slice(0, 1).join(""))}</span><span class="s"><span class="lab ${esc(e.action)}">${esc(e.label)}</span>${esc(e.summary)}</span>${e.undoable && ((e.actor_id === state.me.id && Date.now() - Date.parse(e.at) < 1800000) || can("edit")) ? `<button class="btn xs ghost" type="button" data-undo="${e.id}" title="되돌리기">${icon("undo")}</button>` : `<span class="a">${esc(e.actor_name)}</span>`}</div>`).join("") : '<div class="empty">기록이 없어요</div>'}</div></section>
        </div>
      </div>
    </div>`;
  const f = root.querySelector(".unit.focus");
  if (f) setTimeout(() => f.scrollIntoView({ block: "center", behavior: "smooth" }), 400);
}

async function load() {
  d = await api.get(`/api/items/${params.id}`);
  app.currentItem = d.item;
  document.getElementById("hud-name").textContent = d.item.name;
  render();
}

function unitMenu(u) {
  const list = [
    can("edit") && u.status !== "available" && u.status !== "on_loan" && ["available", "check", "사용 가능으로"],
    can("edit") && u.status === "available" && ["repair", "wrench", "수리 중으로 표시"],
    can("edit") && u.status !== "lost" && u.status !== "on_loan" && ["lost", "warn", "분실로 표시"],
    can("repair") && ["report", "wrench", "고장 신고"],
    ["label", "print", "이 장비 라벨"],
    can("edit") && ["edit", "edit", "번호·도입일 고치기"],
    can("delete") && u.status !== "on_loan" && ["retire", "trash", "폐기"],
  ].filter(Boolean);
  const h = modal({
    title: `${d.item.name} ${u.management_number || u.label || ""}`, code: "UNIT", size: "narrow",
    body: `<div class="stack" style="gap:8px">${list.map(([k, ic, l]) => `<button class="btn block ${k === "retire" ? "danger" : ""}" type="button" data-m="${k}">${icon(ic)}${l}</button>`).join("")}</div>`,
  });
  h.el.querySelectorAll("[data-m]").forEach((b) => {
    b.onclick = () => {
      h.close();
      const k = b.dataset.m;
      if (k === "available" || k === "repair" || k === "lost") actions.setStatus(u, k);
      else if (k === "report") actions.reportRepair({ targetType: "asset", targetId: u.id, name: `${d.item.name} ${u.management_number || ""}` });
      else if (k === "label") printLabels([{ kind: "unit", item: d.item, unit: u }]);
      else if (k === "edit") editUnit(u);
      else if (k === "retire") actions.retire(u, d.item);
    };
  });
}

function editUnit(u) {
  const F = [["label", "이름표(예: #1, 1호기)", "text"], ["management_number", "관리번호", "text"], ["serial_number", "시리얼", "text"], ["edufine_number", "에듀파인 번호", "text"],
    ["purchase_date", "도입일", "date"], ["purchase_price", "구입 가격(원)", "number"], ["useful_life_years", "내용연수(년)", "number"], ["budget_program", "사업명", "text"], ["budget_year", "예산 연도", "number"], ["notes", "메모", "text"]];
  modal({
    title: "장비 정보 고치기", code: "UNIT EDIT", size: "wide",
    body: `<div class="form-grid">${F.map(([k, l, t]) => `<label class="field"><span>${l}</span><input type="${t}" data-k="${k}" value="${esc(u[k] ?? "")}"></label>`).join("")}</div>`,
    actions: [{ label: "취소", tone: "ghost" }, {
      label: "저장", tone: "primary",
      onClick: async (h) => {
        const body = {};
        for (const el of h.el.querySelectorAll("[data-k]")) body[el.dataset.k] = el.value.trim() || null;
        try { await api.patch(`/api/units/${u.id}`, body); toast("고쳤어요", { tone: "good" }); return true; } catch (e) { toastError(e); return false; }
      },
    }],
  });
}

function stockMenu(s) {
  const h = modal({
    title: `${d.item.name} · ${s.location_path.split(" › ").pop()}`, code: "STOCK", size: "narrow",
    body: `<div class="stack" style="gap:8px">
      <button class="btn block amber" type="button" data-m="use">${icon("use")}여러 개 사용</button>
      <button class="btn block good" type="button" data-m="restock">${icon("restock")}여러 개 입고</button>
      ${can("move") ? `<button class="btn block" type="button" data-m="move">${icon("move")}이 재고 옮기기</button>` : ""}
      ${can("edit") ? `<button class="btn block" type="button" data-m="adjust">${icon("edit")}수량 바로잡기(세어 보니 다름)</button>` : ""}</div>`,
  });
  h.el.querySelectorAll("[data-m]").forEach((b) => {
    b.onclick = () => {
      h.close();
      const k = b.dataset.m;
      if (k === "use") actions.use({ detail: d, stockId: s.id });
      else if (k === "restock") actions.restock({ detail: d, locationId: s.location_id });
      else if (k === "move") actions.move({ detail: d, stockId: s.id });
      else if (k === "adjust") actions.adjust({ stock: s, item: d.item });
    };
  });
}

function addUnits() {
  const body = document.createElement("div");
  body.innerHTML = `<div class="pick-sec" style="margin-top:0">몇 대</div>${stepperHtml({ value: 1, unit: "대", min: 1, id: "au-n" })}
    <label class="field" style="margin-top:10px"><span>관리번호 앞머리 <span class="hint">비우면 올해-순번</span></span><input type="text" data-prefix value="${esc((d.units[0] && d.units[0].management_number || "").replace(/\d+$/, ""))}"></label>
    <div class="pick-sec">둘 곳 — 누르면 바로 추가</div><div data-picker></div>`;
  const getN = bindStepper(body, "au-n");
  const h = modal({ title: `${d.item.name} 대수 추가`, code: "ADD UNITS", size: "wide", body });
  locationPicker(body.querySelector("[data-picker]"), {
    onPick: async (loc) => {
      try {
        const r = await api.post(`/api/items/${d.item.id}/units`, { location_id: loc, unit_count: getN(), number_prefix: body.querySelector("[data-prefix]").value });
        h.close();
        toast(`${r.units.length}대 추가: ${r.units.map((u) => u.management_number).filter(Boolean).slice(0, 3).join(", ")}${r.units.length > 3 ? "…" : ""}`, { tone: "good", action: { label: "라벨 인쇄", icon: "print", run: () => printLabels(r.units.map((u) => ({ kind: "unit", item: d.item, unit: { ...u, location_path: locPath(u.location_id) } }))) } });
      } catch (e) { toastError(e); }
    },
  });
}

async function changePhoto(file) {
  const canvas = await editImage(file, { title: "사진 다듬기" });
  if (!canvas) return;
  try {
    const paths = await uploadCanvas(canvas);
    await api.patch(`/api/items/${d.item.id}`, paths);
    toast("사진을 바꿨어요", { tone: "good" });
  } catch (e) { toastError(e); }
}

export default {
  mount(el) {
    root = el;
    el.addEventListener("change", (ev) => {
      if (ev.target.matches("[data-photo]")) {
        const f = ev.target.files[0];
        ev.target.value = "";
        if (f) changePhoto(f);
      }
    });
    el.addEventListener("click", async (ev) => {
      if (!d) return;
      const a = ev.target.closest("[data-a]");
      if (a) {
        const k = a.dataset.a;
        if (k === "loan") actions.loan({ detail: d, unitId: params.unit && d.units.find((u) => u.id === params.unit && u.status === "available") ? params.unit : undefined });
        else if (k === "return") {
          const loans = (await api.get("/api/loans?filter=active")).loans.filter((l) => l.item_id === d.item.id);
          if (params.unit) {
            const one = loans.find((l) => l.asset_id === params.unit);
            if (one) { actions.returnLoans([one]); return; }
          }
          actions.returnLoans(loans);
        } else if (k === "move") actions.move({ detail: d, unitId: params.unit });
        else if (k === "use") actions.use({ detail: d });
        else if (k === "restock") actions.restock({ detail: d });
        else if (k === "repair") {
          const unit = params.unit ? d.units.find((u) => u.id === params.unit) : d.units.length === 1 ? d.units[0] : null;
          actions.reportRepair(unit ? { targetType: "asset", targetId: unit.id, name: `${d.item.name} ${unit.management_number || ""}` } : { targetType: "item", targetId: d.item.id, name: d.item.name });
        }
        return;
      }
      const u = ev.target.closest("[data-u]");
      if (u) {
        const unit = d.units.find((x) => x.id === u.closest("[data-unit]").dataset.unit);
        const k = u.dataset.u;
        if (k === "loan") actions.loan({ detail: d, unitIds: [unit.id] });
        else if (k === "return") {
          const loans = (await api.get("/api/loans?filter=active")).loans;
          const l = loans.find((x) => x.asset_id === unit.id);
          if (l) actions.returnLoans([l]);
        } else if (k === "move") actions.move({ detail: d, unitIds: [unit.id] });
        else if (k === "more") unitMenu(unit);
        return;
      }
      const s = ev.target.closest("[data-s]");
      if (s) {
        const stock = d.stocks.find((x) => x.id === s.closest("[data-stock]").dataset.stock);
        if (s.dataset.s === "minus") actions.quickUse(stock, d.item, 1);
        else if (s.dataset.s === "plus") actions.quickRestock(stock, d.item, 1);
        else stockMenu(stock);
        return;
      }
      const r = ev.target.closest("[data-ret]");
      if (r) { actions.quickReturn(r.dataset.ret); return; }
      const un = ev.target.closest("[data-undo]");
      if (un) { api.post("/api/undo", { event_ids: [un.dataset.undo] }).then((x) => toast(x.events[0].summary, { tone: "info" })).catch(toastError); return; }
      if (ev.target.closest("[data-go-repair]")) { app.go("repair", {}); return; }
      const b = ev.target.closest("[data-b]");
      if (!b) return;
      const k = b.dataset.b;
      if (k === "back") app.back();
      else if (k === "fav") {
        try { await api.patch(`/api/items/${d.item.id}`, { favorite: !d.item.favorite }); } catch (e) { toastError(e); }
      } else if (k === "labels") {
        printLabels(d.units.length ? d.units.map((x) => ({ kind: "unit", item: d.item, unit: x })) : [{ kind: "item", item: d.item, where: d.stocks[0] ? d.stocks[0].location_path : "" }]);
      } else if (k === "edit") openEditItem(d);
      else if (k === "map" && app.scene) app.scene.highlight(d.card.where.map((w) => w.id));
      else if (k === "addunits") addUnits();
      else if (k === "photo" && d.item.image) {
        // 사진 크게 보기
        modal({ title: d.item.name, code: "PHOTO", size: "wide", body: `<img src="/uploads/${esc(d.item.image)}" alt="" style="width:100%;max-height:70vh;object-fit:contain;background:radial-gradient(circle,#123,#02060d);border-radius:8px">` });
      } else if (k === "more") {
        const list = [
          can("register") && ["copy", "copy", "비슷한 것 새로 등록"],
          can("delete") && !d.item.archived && ["archive", "trash", "보관함으로(목록에서 숨기기)"],
          can("delete") && d.item.archived && ["unarchive", "undo", "보관함에서 꺼내기"],
          ["history", "history", "모든 기록 보기"],
        ].filter(Boolean);
        const h = modal({ title: "더보기", size: "narrow", body: `<div class="stack" style="gap:8px">${list.map(([kk, ic, l]) => `<button class="btn block" type="button" data-m="${kk}">${icon(ic)}${l}</button>`).join("")}</div>` });
        h.el.querySelectorAll("[data-m]").forEach((x) => {
          x.onclick = async () => {
            h.close();
            const m = x.dataset.m;
            if (m === "copy") app.addItem({ name: `${d.item.name}`, kind: d.item.kind, manufacturer: d.item.manufacturer, model: d.item.model, spec: d.item.spec, category_id: d.item.category_id });
            else if (m === "archive") { if (await actions.confirmArchive(d.item)) app.back(); }
            else if (m === "unarchive") { await api.post(`/api/items/${d.item.id}/archive`, { archived: false }).catch(toastError); }
            else if (m === "history") app.go("log", { item_id: d.item.id });
          };
        });
      }
    });
  },
  async enter(p = {}) {
    params = p;
    if (!p.id) { app.go("search"); return; }
    root.innerHTML = `<div class="st-inner"><div class="empty"><div class="dots"><i></i><i></i><i></i></div></div></div>`;
    try { await load(); } catch (e) { toastError(e); root.innerHTML = `<div class="st-inner"><div class="empty">${esc(e.message)}<br><button class="btn" type="button" onclick="history.back()">뒤로</button></div></div>`; }
  },
  async refresh(change) {
    if (!params.id) return;
    try { await load(); } catch { /* 지워졌을 수 있다 */ }
  },
};

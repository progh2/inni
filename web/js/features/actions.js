// 현장 작업 시트: 옮기기·빌려주기·반납·사용·입고·보정·상태·폐기·고장 신고.
// 확인창 대신 끝나면 "되돌리기"를 준다. 목적지를 누르는 순간 옮겨진다(한 번 누르기).
import { esc, icon, qty as fmtQty, dueLabel, statusChip, UNIT_TONE, thumb } from "../lib/util.js";
import { api } from "../lib/api.js";
import { modal, toast, toastError, confirmDialog } from "../lib/ui.js";
import { state, rememberLocation, locPath, can } from "../lib/store.js";
import { app } from "../app.js";
import * as sfx from "../lib/sfx.js";
import { mood } from "../ai/character.js";
import { locationPicker, stepperHtml, bindStepper, dueHtml, bindDue, borrowerHtml, bindBorrower, chipsHtml, bindChips, PURPOSES } from "./pickers.js";

const detailOf = (itemId) => api.get(`/api/items/${itemId}`);

// 끝난 작업을 알리고 되돌리기를 준다
export function undoToast(message, events, { sound = "ok", title = "" } = {}) {
  const ids = (events || []).filter((e) => e && e.undoable).map((e) => e.id);
  if (sound) sfx.play(sound);
  mood("happy", 1800);
  toast(message, {
    tone: "good", title, sound: false,
    action: ids.length ? {
      label: "되돌리기", icon: "undo",
      run: async () => {
        try {
          const r = await api.post("/api/undo", { event_ids: ids });
          sfx.play("undo");
          toast(r.events.length === 1 ? r.events[0].summary.replace(/^되돌림: /, "되돌렸어요: ") : `${r.events.length}건을 되돌렸어요`, { tone: "info", sound: false });
        } catch (e) { toastError(e); }
      },
    } : null,
  });
}

// ---------------------------------------------------------------- 옮기기
/**
 * { itemId, unitId, unitIds, count, stockId, qty, toLocationId, detail }
 */
export async function move(o = {}) {
  const d = o.detail || (o.itemId ? await detailOf(o.itemId) : null);
  if (!d) return moveMany(o);
  const it = d.item;
  const isEq = it.kind === "equipment";
  const units = d.units.filter((u) => u.status !== "on_loan");
  let chosenUnits = new Set(o.unitIds || (o.unitId ? [o.unitId] : []));
  if (isEq && !chosenUnits.size) {
    if (units.length === 1) chosenUnits.add(units[0].id);
    else if (o.count) for (const u of units.slice(0, o.count)) chosenUnits.add(u.id);
  }
  const stocks = d.stocks.filter((s) => s.quantity > 0);
  let stockId = o.stockId || (stocks.length === 1 ? stocks[0].id : stocks[0] && stocks[0].id);
  const stockOf = () => stocks.find((s) => s.id === stockId);
  const body = document.createElement("div");
  body.innerHTML = `
    <div class="row nw" style="margin-bottom:12px">${thumb(d.card, "lg")}<div><b style="font-size:16px">${esc(it.name)}</b><div class="help" style="margin:2px 0 0">${esc(d.card.status_label)}</div></div></div>
    ${isEq ? (units.length > 1 ? `<div class="pick-sec">옮길 장비 · ${units.length}대 중 고르기</div>
      <div class="chips" data-units style="margin-bottom:6px">${units.map((u) => `<button class="chip" type="button" data-u="${u.id}" aria-pressed="${chosenUnits.has(u.id)}">${esc(u.management_number || u.label || "번호 없음")} <small>${esc(u.location_path.split(" › ").pop())}</small></button>`).join("")}
      <button class="chip amber" type="button" data-all>모두</button></div>` : units.length ? "" : `<div class="callout warn">옮길 수 있는 장비가 없어요(모두 대여 중).</div>`)
    : stocks.length ? `${stocks.length > 1 ? `<div class="pick-sec">어디 있는 것을</div><div class="chips" data-stocks style="margin-bottom:8px">${stocks.map((s) => `<button class="chip" type="button" data-s="${s.id}" aria-pressed="${s.id === stockId}">${esc(s.location_path.split(" › ").slice(-2).join(" › "))} <small>${fmtQty(s.quantity)}</small></button>`).join("")}</div>` : ""}
      <div class="pick-sec">몇 ${esc(it.unit)} 옮길까요</div><div data-qty-wrap>${stepperHtml({ value: o.qty || (stockOf() ? stockOf().quantity : 1), unit: it.unit, min: 0, max: stockOf() ? stockOf().quantity : null })}</div>`
      : `<div class="callout warn">옮길 재고가 없어요.</div>`}
    <div class="pick-sec">어디로 옮길까요 — 누르면 바로 옮겨집니다</div>
    <div data-picker></div>`;
  let getQty = () => 0;
  const bindQty = () => {
    const w = body.querySelector("[data-qty-wrap]");
    if (!w) return;
    const s = stockOf();
    w.innerHTML = stepperHtml({ value: s ? s.quantity : 1, unit: it.unit, min: 0, max: s ? s.quantity : null });
    getQty = bindStepper(body, "qty");
  };
  bindQty();
  const uBox = body.querySelector("[data-units]");
  if (uBox) {
    uBox.addEventListener("click", (ev) => {
      const b = ev.target.closest("[data-u]");
      if (b) {
        const on = b.getAttribute("aria-pressed") !== "true";
        b.setAttribute("aria-pressed", String(on));
        if (on) chosenUnits.add(b.dataset.u); else chosenUnits.delete(b.dataset.u);
        sfx.play("tick");
      } else if (ev.target.closest("[data-all]")) {
        for (const x of uBox.querySelectorAll("[data-u]")) { x.setAttribute("aria-pressed", "true"); chosenUnits.add(x.dataset.u); }
        sfx.play("tick");
      }
    });
  }
  const sBox = body.querySelector("[data-stocks]");
  if (sBox) {
    sBox.addEventListener("click", (ev) => {
      const b = ev.target.closest("[data-s]");
      if (!b) return;
      stockId = b.dataset.s;
      for (const x of sBox.querySelectorAll("[data-s]")) x.setAttribute("aria-pressed", String(x === b));
      bindQty();
    });
  }
  const exclude = isEq ? [] : [stockOf() && stockOf().location_id];
  if (isEq && chosenUnits.size === 1) exclude.push(units.find((u) => chosenUnits.has(u.id)) && units.find((u) => chosenUnits.has(u.id)).location_id);
  const run = async (to) => {
    let targets;
    if (isEq) {
      if (!chosenUnits.size) { toast("옮길 장비를 먼저 고르세요", { tone: "warn" }); return; }
      targets = [...chosenUnits].map((id) => ({ asset_id: id }));
    } else {
      const q = getQty();
      if (!stockOf() || !(q > 0)) { toast("옮길 수량을 넣으세요", { tone: "warn" }); return; }
      targets = [{ stock_id: stockId, qty: q }];
    }
    try {
      const r = await api.post("/api/actions/move", { targets, to_location_id: to });
      h.close(true);
      rememberLocation(to);
      if (!r.moved) { toast("이미 그곳에 있어요", { tone: "info" }); return; }
      undoToast(r.moved === 1 ? r.events[0].summary : `${r.moved}건을 ${state.locMap.get(to).name}(으)로 옮겼어요`, r.events, { sound: "lock" });
    } catch (e) { toastError(e); }
  };
  const h = modal({ title: "옮기기", code: "TRANSFER", size: "wide", body });
  locationPicker(body.querySelector("[data-picker]"), { onPick: run, exclude });
  if (o.toLocationId && (isEq ? chosenUnits.size : stockOf())) {
    // 이니·말 명령으로 목적지까지 정해졌으면 목적지를 강조만 한다(누르면 진행)
    const b = body.querySelector(`.pick[data-id="${o.toLocationId}"]`);
    if (b) { b.classList.add("recent"); b.scrollIntoView({ block: "center" }); b.focus(); }
  }
  return h;
}

// 여러 물건 한꺼번에(찾기 화면 선택·장소 화면)
export function moveMany({ targets = [], label = "" } = {}) {
  if (!targets.length) { toast("옮길 것을 고르세요", { tone: "warn" }); return null; }
  const body = document.createElement("div");
  body.innerHTML = `<p class="lead">${esc(label || `${targets.length}건`)}을(를) 어디로 옮길까요? 누르면 바로 옮겨집니다.</p><div data-picker></div>`;
  const h = modal({ title: "한꺼번에 옮기기", code: "TRANSFER", size: "wide", body });
  locationPicker(body.querySelector("[data-picker]"), {
    onPick: async (to) => {
      try {
        const r = await api.post("/api/actions/move", { targets, to_location_id: to });
        h.close(true);
        rememberLocation(to);
        undoToast(`${r.moved}건을 ${state.locMap.get(to).name}(으)로 옮겼어요`, r.events, { sound: "lock" });
      } catch (e) { toastError(e); }
    },
  });
  return h;
}

// ---------------------------------------------------------------- 빌려주기
export async function loan(o = {}) {
  const d = o.detail || await detailOf(o.itemId);
  const it = d.item;
  const isEq = it.kind === "equipment";
  const avail = d.units.filter((u) => u.status === "available");
  const chosen = new Set(o.unitIds || (o.unitId ? [o.unitId] : []));
  if (isEq && !chosen.size && avail.length) for (const u of avail.slice(0, o.count || 1)) chosen.add(u.id);
  const stocks = d.stocks.filter((s) => s.quantity > 0);
  let stockId = o.stockId || (stocks[0] && stocks[0].id);
  if (isEq && !avail.length) {
    toast(`${it.name}: 지금 빌려줄 수 있는 장비가 없어요`, { tone: "warn" });
    return null;
  }
  const body = document.createElement("div");
  body.innerHTML = `
    <div class="row nw" style="margin-bottom:12px">${thumb(d.card, "lg")}<div><b style="font-size:16px">${esc(it.name)}</b><div class="help" style="margin:2px 0 0">${esc(d.card.status_label)}</div></div></div>
    ${isEq ? `<div class="pick-sec">빌려줄 장비</div><div class="chips" data-units>${avail.map((u) => `<button class="chip" type="button" data-u="${u.id}" aria-pressed="${chosen.has(u.id)}">${esc(u.management_number || u.label || "번호 없음")} <small>${esc(u.location_path.split(" › ").pop())}</small></button>`).join("")}</div>`
    : `${stocks.length > 1 ? `<div class="pick-sec">어디 있는 것을</div><div class="chips" data-stocks>${stocks.map((s) => `<button class="chip" type="button" data-s="${s.id}" aria-pressed="${s.id === stockId}">${esc(s.location_path.split(" › ").pop())} <small>${fmtQty(s.quantity)}</small></button>`).join("")}</div>` : ""}
      <div class="pick-sec">몇 ${esc(it.unit)}</div>${stepperHtml({ value: o.qty || 1, unit: it.unit, min: 1, max: (stocks.find((s) => s.id === stockId) || {}).quantity ?? null })}`}
    <div class="pick-sec">누가 빌리나요</div>${borrowerHtml({ name: o.borrowerName || "" })}
    <div class="pick-sec">언제까지</div>${dueHtml()}
    <div class="pick-sec">용도 <span class="muted">(선택)</span></div>${chipsHtml(PURPOSES)}`;
  const getQty = isEq ? () => 1 : bindStepper(body, "qty");
  const getBorrower = bindBorrower(body);
  const getDue = bindDue(body, { initial: o.dueAt || null });
  const getPurpose = bindChips(body);
  const uBox = body.querySelector("[data-units]");
  if (uBox) uBox.addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-u]");
    if (!b) return;
    const on = b.getAttribute("aria-pressed") !== "true";
    b.setAttribute("aria-pressed", String(on));
    if (on) chosen.add(b.dataset.u); else chosen.delete(b.dataset.u);
    sfx.play("tick");
  });
  const sBox = body.querySelector("[data-stocks]");
  if (sBox) sBox.addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-s]");
    if (!b) return;
    stockId = b.dataset.s;
    for (const x of sBox.querySelectorAll("[data-s]")) x.setAttribute("aria-pressed", String(x === b));
    const s = stocks.find((x) => x.id === stockId);
    body.querySelector("#qty").max = s.quantity;
  });
  return modal({
    title: "빌려주기", code: "LOAN", size: "wide", body,
    actions: [{ label: "취소", tone: "ghost" }, {
      label: "빌려주기", tone: "amber", icon: "loan",
      onClick: async () => {
        const bw = getBorrower();
        if (!bw.borrower_name) { toast("빌리는 사람 이름을 넣으세요", { tone: "warn" }); return false; }
        const payload = { ...bw, due_at: getDue(), purpose: getPurpose() || null };
        if (isEq) {
          if (!chosen.size) { toast("빌려줄 장비를 고르세요", { tone: "warn" }); return false; }
          payload.asset_ids = [...chosen];
        } else {
          payload.stock_id = stockId;
          payload.qty = getQty();
        }
        try {
          const r = await api.post("/api/actions/loan", payload);
          undoToast(r.events.length === 1 ? r.events[0].summary : `${r.events.length}건을 ${bw.borrower_name}에게 빌려줬어요`, r.events, { sound: "out" });
          return true;
        } catch (e) { toastError(e); return false; }
      },
    }],
  });
}

// ---------------------------------------------------------------- 반납
export async function quickReturn(loanOrId) {
  const id = typeof loanOrId === "string" ? loanOrId : loanOrId.id;
  try {
    const r = await api.post("/api/actions/return", { loan_ids: [id], condition: "ok" });
    undoToast(r.events[0].summary, r.events, { sound: "in" });
    return r;
  } catch (e) { toastError(e); return null; }
}

/** 반납 시트: 상태(정상/이상)와 둘 곳을 고른다 */
export function returnLoans(loans = []) {
  if (!loans.length) { toast("반납할 대여가 없어요", { tone: "info" }); return null; }
  const body = document.createElement("div");
  body.innerHTML = `
    <div class="list" style="margin-bottom:10px">${loans.map((l) => `<div class="irow">${thumb({ thumb: l.thumb, kind: l.asset_id ? "equipment" : "consumable" }, "sm")}
      <div class="tx"><span class="nm">${esc(l.item_name || "")}${l.unit ? ` · ${esc(l.unit)}` : ""}${!l.asset_id && l.quantity ? ` · ${fmtQty(l.quantity)}${esc(l.unit_label || "")}` : ""}</span>
      <span class="sub">${esc(l.borrower_name)} · ${esc(dueLabel(l.due_at))}${l.from_path ? ` · 원래 자리 ${esc(l.from_path)}` : ""}</span></div>${l.overdue ? '<span class="tag crit">연체</span>' : ""}</div>`).join("")}</div>
    <div class="pick-sec">상태</div>
    <div class="seg block" data-cond><button type="button" data-v="ok" aria-pressed="true">${icon("check")}이상 없음</button><button type="button" data-v="issue" aria-pressed="false">${icon("warn")}문제 있음</button></div>
    <div data-issue hidden style="margin-top:8px"><label class="field"><span>어떤 문제인가요</span><input type="text" data-note placeholder="예: 화면 깨짐, 부속 없음"></label>
      <label class="check" style="margin-top:8px"><input type="checkbox" data-repair checked> 수리 필요로 표시(고장 신고 자동 접수)</label></div>
    <div class="pick-sec">어디에 둘까요</div>
    <div class="chips" data-where><button class="chip" type="button" data-v="orig" aria-pressed="true">${icon("undo")}원래 자리${loans.length === 1 && loans[0].from_path ? ` (${esc(loans[0].from_path.split(" › ").pop())})` : ""}</button><button class="chip" type="button" data-v="other">다른 곳…</button></div>
    <div data-picker hidden style="margin-top:8px"></div>`;
  let cond = "ok";
  let to = null;
  body.querySelector("[data-cond]").addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-v]");
    if (!b) return;
    cond = b.dataset.v;
    for (const x of body.querySelectorAll("[data-cond] [data-v]")) x.setAttribute("aria-pressed", String(x === b));
    body.querySelector("[data-issue]").hidden = cond !== "issue";
  });
  const pickBox = body.querySelector("[data-picker]");
  body.querySelector("[data-where]").addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-v]");
    if (!b) return;
    for (const x of body.querySelectorAll("[data-where] [data-v]")) x.setAttribute("aria-pressed", String(x === b));
    if (b.dataset.v === "orig") { to = null; pickBox.hidden = true; }
    else {
      pickBox.hidden = false;
      locationPicker(pickBox, {
        compact: true,
        onPick: (id) => {
          to = id;
          b.innerHTML = `${icon("pin")}${esc(state.locMap.get(id).name)}`;
          pickBox.hidden = true;
        },
      });
    }
  });
  return modal({
    title: loans.length > 1 ? `${loans.length}건 반납` : "반납", code: "RETURN", body, size: "wide",
    actions: [{ label: "취소", tone: "ghost" }, {
      label: "반납 받기", tone: "good", icon: "return",
      onClick: async () => {
        try {
          const r = await api.post("/api/actions/return", {
            loan_ids: loans.map((l) => l.id), condition: cond, note: cond === "issue" ? body.querySelector("[data-note]").value : null,
            mark_repair: cond === "issue" ? body.querySelector("[data-repair]").checked : false, to_location_id: to,
          });
          if (to) rememberLocation(to);
          undoToast(r.events.length === 1 ? r.events[0].summary : `${r.events.length}건 반납 받았어요`, r.events, { sound: "in" });
          return true;
        } catch (e) { toastError(e); return false; }
      },
    }],
  });
}

export async function returnFind({ item = "", borrower = "", itemId = "" } = {}) {
  const d = await api.get(`/api/loans?filter=active${borrower ? `&q=${encodeURIComponent(borrower)}` : ""}`);
  let list = d.loans;
  if (itemId) list = list.filter((l) => l.item_id === itemId);
  else if (item) {
    const r = await api.get(`/api/search?q=${encodeURIComponent(item)}&limit=5`);
    const ids = new Set(r.items.map((x) => x.id));
    list = list.filter((l) => ids.has(l.item_id));
  }
  if (!list.length) { toast("맞는 대여를 찾지 못했어요", { tone: "info" }); return null; }
  return returnLoans(list.slice(0, 20));
}

// ---------------------------------------------------------------- 사용·입고
export async function use(o = {}) {
  const d = o.detail || await detailOf(o.itemId);
  const it = d.item;
  const stocks = d.stocks.filter((s) => s.quantity > 0);
  if (!stocks.length) { toast(`${it.name} 재고가 없어요. 입고부터 하세요.`, { tone: "warn" }); return null; }
  let stockId = o.stockId || stocks[0].id;
  const s0 = stocks.find((s) => s.id === stockId);
  const body = document.createElement("div");
  body.innerHTML = `
    <div class="row nw" style="margin-bottom:12px">${thumb(d.card, "lg")}<div><b style="font-size:16px">${esc(it.name)}</b><div class="help" style="margin:2px 0 0">전체 ${fmtQty(d.card.qty)}${esc(it.unit)}${it.min_stock ? ` · 최소 ${fmtQty(it.min_stock)}` : ""}</div></div></div>
    ${stocks.length > 1 ? `<div class="pick-sec">어디 것을</div><div class="chips" data-stocks>${stocks.map((s) => `<button class="chip" type="button" data-s="${s.id}" aria-pressed="${s.id === stockId}">${esc(s.location_path.split(" › ").slice(-2).join(" › "))} <small>${fmtQty(s.quantity)}</small></button>`).join("")}</div>` : `<p class="help">${icon("pin")} ${esc(s0.location_path)} · 남은 ${fmtQty(s0.quantity)}${esc(it.unit)}</p>`}
    <div class="pick-sec">얼마나 썼나요</div><div data-q>${stepperHtml({ value: o.qty || 1, unit: it.unit, min: 0, max: s0.quantity })}</div>
    <div class="pick-sec">어디에 <span class="muted">(선택)</span></div>${chipsHtml(PURPOSES)}`;
  let getQty = bindStepper(body, "qty");
  const getPurpose = bindChips(body);
  const sBox = body.querySelector("[data-stocks]");
  if (sBox) sBox.addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-s]");
    if (!b) return;
    stockId = b.dataset.s;
    for (const x of sBox.querySelectorAll("[data-s]")) x.setAttribute("aria-pressed", String(x === b));
    const s = stocks.find((x) => x.id === stockId);
    body.querySelector("[data-q]").innerHTML = stepperHtml({ value: 1, unit: it.unit, min: 0, max: s.quantity });
    getQty = bindStepper(body, "qty");
  });
  return modal({
    title: "사용(출고)", code: "ISSUE", body,
    actions: [{ label: "취소", tone: "ghost" }, {
      label: "사용 처리", tone: "amber", icon: "use",
      onClick: async () => {
        const q = getQty();
        if (!(q > 0)) { toast("수량을 넣으세요", { tone: "warn" }); return false; }
        try {
          const r = await api.post("/api/actions/use", { stock_id: stockId, qty: q, purpose: getPurpose() || null });
          undoToast(r.event.summary, [r.event], { sound: "out" });
          return true;
        } catch (e) { toastError(e); return false; }
      },
    }],
  });
}

// 물건 화면의 [−1] 버튼: 확인 없이 바로(되돌리기 있음)
export async function quickUse(stock, item, amount = 1) {
  try {
    const r = await api.post("/api/actions/use", { stock_id: stock.id, qty: amount });
    undoToast(r.event.summary, [r.event], { sound: "tick" });
    return r;
  } catch (e) { toastError(e); return null; }
}

export async function quickRestock(stock, item, amount = 1) {
  try {
    const r = await api.post("/api/actions/restock", { item_id: item.id, location_id: stock.location_id, qty: amount, lot_code: stock.lot_code || "" });
    undoToast(r.event.summary, [r.event], { sound: "tick" });
    return r;
  } catch (e) { toastError(e); return null; }
}

export async function restock(o = {}) {
  const d = o.detail || await detailOf(o.itemId);
  const it = d.item;
  const stocks = d.stocks;
  let loc = o.locationId || (stocks[0] && stocks[0].location_id) || null;
  const body = document.createElement("div");
  body.innerHTML = `
    <div class="row nw" style="margin-bottom:12px">${thumb(d.card, "lg")}<div><b style="font-size:16px">${esc(it.name)}</b><div class="help" style="margin:2px 0 0">지금 ${fmtQty(d.card.qty)}${esc(it.unit)}${it.min_stock ? ` · 최소 ${fmtQty(it.min_stock)}` : ""}</div></div></div>
    <div class="pick-sec">얼마나 들어왔나요</div>${stepperHtml({ value: o.qty || 1, unit: it.unit, min: 0 })}
    <div class="pick-sec">어디에 넣을까요</div>
    <div class="chips" data-locs>${stocks.map((s) => `<button class="chip" type="button" data-l="${s.location_id}" aria-pressed="${s.location_id === loc}">${esc(s.location_path.split(" › ").slice(-2).join(" › "))} <small>${fmtQty(s.quantity)}</small></button>`).join("")}
      <button class="chip" type="button" data-other>${icon("plus")}다른 곳</button></div>
    <div data-picker hidden style="margin-top:8px"></div>
    <details class="more" style="margin-top:10px"><summary>로트·유통기한</summary><div class="grid g2">
      <label class="field"><span>로트 번호</span><input type="text" data-lot placeholder="선택"></label>
      <label class="field"><span>유통기한</span><input type="date" data-exp></label></div></details>`;
  const getQty = bindStepper(body, "qty");
  const lbox = body.querySelector("[data-locs]");
  const pick = body.querySelector("[data-picker]");
  if (!loc) {
    pick.hidden = false;
    locationPicker(pick, { compact: true, onPick: (id) => { loc = id; pick.hidden = true; lbox.insertAdjacentHTML("afterbegin", `<button class="chip" type="button" data-l="${id}" aria-pressed="true">${esc(state.locMap.get(id).name)}</button>`); } });
  }
  lbox.addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-l]");
    if (b) {
      loc = b.dataset.l;
      for (const x of lbox.querySelectorAll("[data-l]")) x.setAttribute("aria-pressed", String(x === b));
      pick.hidden = true;
      return;
    }
    if (ev.target.closest("[data-other]")) {
      pick.hidden = false;
      locationPicker(pick, { compact: true, onPick: (id) => { loc = id; pick.hidden = true; for (const x of lbox.querySelectorAll("[data-l]")) x.setAttribute("aria-pressed", "false"); lbox.insertAdjacentHTML("afterbegin", `<button class="chip" type="button" data-l="${id}" aria-pressed="true">${esc(state.locMap.get(id).name)}</button>`); } });
    }
  });
  return modal({
    title: "입고", code: "RESTOCK", body,
    actions: [{ label: "취소", tone: "ghost" }, {
      label: "입고", tone: "good", icon: "restock",
      onClick: async () => {
        const q = getQty();
        if (!(q > 0)) { toast("수량을 넣으세요", { tone: "warn" }); return false; }
        if (!loc) { toast("넣을 곳을 고르세요", { tone: "warn" }); return false; }
        try {
          const r = await api.post("/api/actions/restock", { item_id: it.id, location_id: loc, qty: q, lot_code: body.querySelector("[data-lot]").value, expires_at: body.querySelector("[data-exp]").value || null });
          rememberLocation(loc);
          undoToast(r.event.summary, [r.event], { sound: "in" });
          return true;
        } catch (e) { toastError(e); return false; }
      },
    }],
  });
}

export function adjust({ stock, item }) {
  return modal({
    title: "수량 바로잡기", code: "ADJUST", size: "narrow",
    body: `<p class="help">${esc(stock.location_path)}에 실제로 몇 ${esc(item.unit)} 있나요? (지금 장부 ${fmtQty(stock.quantity)})</p>
      ${stepperHtml({ value: stock.quantity, unit: item.unit, min: 0 })}
      <label class="field" style="margin-top:10px"><span>이유 <span class="hint">선택</span></span><input type="text" data-reason placeholder="예: 세어 보니 다름"></label>`,
    onOpen: (h) => { h.getQty = bindStepper(h.el, "qty"); },
    actions: [{ label: "취소", tone: "ghost" }, {
      label: "고치기", tone: "primary",
      onClick: async (h) => {
        try {
          const r = await api.post("/api/actions/adjust", { stock_id: stock.id, quantity: h.getQty(), reason: h.el.querySelector("[data-reason]").value });
          if (r.event) undoToast(r.event.summary, [r.event]);
          return true;
        } catch (e) { toastError(e); return false; }
      },
    }],
  });
}

// ---------------------------------------------------------------- 상태·폐기·고장
export async function setStatus(unit, status) {
  const LABEL = { available: "사용 가능", repair: "수리 중", lost: "분실" };
  try {
    const r = await api.post("/api/actions/status", { asset_id: unit.id, status });
    if (r.event) undoToast(r.event.summary, [r.event]);
    else toast(`이미 ${LABEL[status]} 상태예요`);
  } catch (e) { toastError(e); }
}

export function retire(unit, item) {
  return modal({
    title: "폐기", code: "RETIRE", tone: "crit",
    body: `<p class="lead"><b>${esc(item.name)} ${esc(unit.management_number || unit.label || "")}</b>을(를) 폐기 처리합니다. 목록에서 빠지고 기록은 남습니다.</p>
      <div class="chips" data-kind>${[["unrepairable", "수리 불가"], ["life_exceeded", "내용연수 지남"], ["lost", "분실"], ["other", "기타"]].map(([v, l], i) => `<button class="chip" type="button" data-v="${v}" aria-pressed="${i === 0}">${l}</button>`).join("")}</div>
      <label class="field" style="margin-top:10px"><span>사유 <span class="req">*</span></span><input type="text" data-reason placeholder="예: 메인보드 고장, 수리비가 새것보다 비쌈"></label>
      <div class="grid g2" style="margin-top:8px"><label class="field"><span>폐기일</span><input type="date" data-date></label>
      <label class="field"><span>근거(문서번호 등)</span><input type="text" data-ev placeholder="선택"></label></div>`,
    onOpen: (h) => { h.getKind = bindChips(h.el, "data-kind"); h.el.querySelector('[data-kind] [data-v="unrepairable"]').click(); },
    actions: [{ label: "취소", tone: "ghost" }, {
      label: "폐기", tone: "danger", icon: "trash",
      onClick: async (h) => {
        const reason = h.el.querySelector("[data-reason]").value.trim();
        if (!reason) { toast("폐기 사유를 넣으세요", { tone: "warn" }); return false; }
        try {
          const r = await api.post("/api/actions/retire", { asset_id: unit.id, kind: h.getKind() || "other", reason, date: h.el.querySelector("[data-date]").value || null, evidence: h.el.querySelector("[data-ev]").value });
          undoToast(r.event.summary, [r.event], { sound: "warn" });
          return true;
        } catch (e) { toastError(e); return false; }
      },
    }],
  });
}

const SYMPTOMS = ["전원이 안 켜짐", "작동이 이상함", "부품 파손", "부속품 없음", "소음·발열", "소모품 교체 필요"];
export function reportRepair({ targetType = "asset", targetId, name = "" }) {
  const staff = can("repair_manage");
  return modal({
    title: "고장 신고", code: "REPAIR REQUEST",
    body: `<p class="help">${esc(name)}</p>
      <div class="chips" data-sym>${SYMPTOMS.map((s) => `<button class="chip" type="button" data-v="${esc(s)}" aria-pressed="false">${esc(s)}</button>`).join("")}</div>
      <label class="field" style="margin-top:10px"><span>증상 <span class="req">*</span></span><input type="text" data-title placeholder="짧게: 예) 화면이 안 나옴"></label>
      <label class="field" style="margin-top:8px"><span>자세히 <span class="hint">선택</span></span><textarea data-body rows="3" placeholder="언제부터, 어떻게"></textarea></label>
      <label class="check" style="margin-top:8px"><input type="checkbox" data-urgent> 급해요(수업에 바로 필요)</label>
      ${staff && targetType === "asset" ? '<label class="check" style="margin-top:6px"><input type="checkbox" data-mark checked> 장비를 수리 중으로 표시(대여 막기)</label>' : ""}`,
    onOpen: (h) => {
      h.el.querySelector("[data-sym]").addEventListener("click", (ev) => {
        const b = ev.target.closest("[data-v]");
        if (b) { h.el.querySelector("[data-title]").value = b.dataset.v; sfx.play("tick"); }
      });
    },
    actions: [{ label: "취소", tone: "ghost" }, {
      label: "신고하기", tone: "warn", icon: "wrench",
      onClick: async (h) => {
        const title = h.el.querySelector("[data-title]").value.trim();
        if (!title) { toast("증상을 적어 주세요", { tone: "warn" }); return false; }
        try {
          await api.post("/api/repairs", { target_type: targetType, target_id: targetId, title, body: h.el.querySelector("[data-body]").value, urgency: h.el.querySelector("[data-urgent]").checked ? "urgent" : "normal", mark_repair: h.el.querySelector("[data-mark]") ? h.el.querySelector("[data-mark]").checked : false });
          toast("고장 신고를 접수했어요. 담당 선생님께 알림이 갑니다.", { tone: "good" });
          return true;
        } catch (e) { toastError(e); return false; }
      },
    }],
  });
}

// 장비 한 대의 상태 표시
export function unitChip(u) {
  return statusChip(UNIT_TONE[u.status] === "good" ? "available" : u.status === "on_loan" ? "on_loan" : u.status === "repair" ? "repair" : u.status === "lost" ? "lost" : "archived", u.status_label);
}

export async function confirmArchive(item) {
  const ok = await confirmDialog({ title: "보관함으로", message: `${item.name}을(를) 목록에서 숨깁니다(보관함). 기록은 남고 언제든 되살릴 수 있어요.`, confirmLabel: "보관함으로", tone: "warn" });
  if (!ok) return false;
  try {
    await api.post(`/api/items/${item.id}/archive`, { archived: true });
    toast("보관함으로 옮겼어요", { tone: "good" });
    return true;
  } catch (e) { toastError(e); return false; }
}

export { locPath };

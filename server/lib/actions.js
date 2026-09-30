// 현장 작업: 이동·대여·반납·사용·입고·보정·상태·폐기, 그리고 되돌리기.
// 확인창 대신 "되돌리기"를 준다(입력 최소화). 되돌리려면 그 뒤로 상태가 바뀌지 않았어야 한다.
import { newId, nowIso, str, optStr, reqStr, qty as readQty, optDate, optDateTime, oneOf, badRequest, notFound, conflict, forbidden, fmtQty } from "./util.js";
import { tx } from "./db.js";
import { logEvent, eventView } from "./events.js";
import { ASSET_STATUS_LABEL } from "./inventory.js";

const UNDO_WINDOW_OWN_MS = 30 * 60000; // 본인 작업은 30분 안에
const UNDO_WINDOW_EDIT_MS = 7 * 86400000; // 담당교사는 7일 안에

function unitName(db, asset) {
  const it = db.prepare("SELECT name FROM items WHERE id = ?").get(asset.item_id);
  const tag = asset.management_number || asset.label || "";
  return `${it ? it.name : "장비"}${tag ? ` ${tag}` : ""}`;
}

function locName(db, id) {
  if (!id) return "?";
  const l = db.prepare("SELECT name FROM locations WHERE id = ?").get(id);
  return l ? l.name : "?";
}

function requireLoc(db, id, what = "옮길 곳") {
  if (!id) throw badRequest(`${what}을 고르세요`);
  const l = db.prepare("SELECT * FROM locations WHERE id = ?").get(id);
  if (!l || l.archived_at) throw badRequest(`${what}이 없습니다`);
  return l;
}

function getAsset(db, id) {
  const a = db.prepare("SELECT * FROM assets WHERE id = ?").get(id);
  if (!a) throw notFound("장비가 없습니다");
  return a;
}

function getStock(db, id) {
  const s = db.prepare("SELECT * FROM stocks WHERE id = ?").get(id);
  if (!s) throw notFound("재고 기록이 없습니다");
  return s;
}

function itemOf(db, id) {
  const it = db.prepare("SELECT * FROM items WHERE id = ?").get(id);
  if (!it) throw notFound("품목이 없습니다");
  return it;
}

// 같은 품목·위치·로트면 합친다. 새로 만들었는지 함께 돌려준다.
function addToStock(db, { item_id, location_id, lot_code = "", expires_at = null, amount, t }) {
  const hit = db.prepare("SELECT * FROM stocks WHERE item_id = ? AND location_id = ? AND lot_code = ?").get(item_id, location_id, lot_code || "");
  if (hit) {
    db.prepare("UPDATE stocks SET quantity = round(quantity + ?, 2), expires_at = COALESCE(?, expires_at), updated_at = ? WHERE id = ?").run(amount, expires_at, t, hit.id);
    return { id: hit.id, created: false };
  }
  const id = newId();
  db.prepare("INSERT INTO stocks(id, item_id, location_id, quantity, lot_code, expires_at, updated_at) VALUES(?, ?, ?, round(?, 2), ?, ?, ?)")
    .run(id, item_id, location_id, amount, lot_code || "", expires_at, t);
  return { id, created: true };
}

function takeFromStock(db, stock, amount, t, { dropEmpty = false } = {}) {
  const r = db.prepare("UPDATE stocks SET quantity = round(quantity - ?, 2), updated_at = ? WHERE id = ? AND quantity >= ?").run(amount, t, stock.id, amount - 1e-9);
  if (r.changes !== 1) throw conflict(`남은 수량(${fmtQty(stock.quantity)})보다 많습니다`);
  const left = db.prepare("SELECT quantity FROM stocks WHERE id = ?").get(stock.id).quantity;
  if (dropEmpty && left <= 1e-9) {
    db.prepare("DELETE FROM stocks WHERE id = ?").run(stock.id);
    return { left: 0, deleted: true };
  }
  return { left, deleted: false };
}

// ---------------------------------------------------------------- 이동
/**
 * targets: [{ asset_id } | { stock_id, qty? }], to_location_id
 */
export function move(ctx, actor, input) {
  const db = ctx.db;
  const targets = Array.isArray(input.targets) ? input.targets.slice(0, 500) : [];
  if (!targets.length) throw badRequest("옮길 물건을 고르세요");
  const batch = targets.length > 1 ? newId() : null;
  const events = [];
  tx(db, () => {
    const to = requireLoc(db, input.to_location_id);
    const t = nowIso();
    for (const tg of targets) {
      if (tg.asset_id) {
        const a = getAsset(db, tg.asset_id);
        if (a.status === "retired") throw conflict(`${unitName(db, a)}은(는) 폐기된 장비입니다`);
        if (a.status === "on_loan") throw conflict(`${unitName(db, a)}은(는) 대여 중입니다. 반납할 때 둘 곳을 고르세요.`);
        if (a.location_id === to.id) continue;
        db.prepare("UPDATE assets SET location_id = ?, updated_at = ? WHERE id = ?").run(to.id, t, a.id);
        events.push(logEvent(db, actor, {
          action: "move", item_id: a.item_id, asset_id: a.id, location_id: a.location_id, to_location_id: to.id,
          summary: `${unitName(db, a)} · ${locName(db, a.location_id)} → ${to.name}`,
          data: { batch, undo: { type: "move_asset", asset_id: a.id, from: a.location_id, to: to.id } },
        }));
      } else if (tg.stock_id) {
        const s = getStock(db, tg.stock_id);
        if (s.location_id === to.id) continue;
        const amount = tg.qty === undefined || tg.qty === null || tg.qty === "" ? s.quantity : readQty(tg.qty, "옮길 수량");
        if (amount <= 0) continue;
        if (amount > s.quantity + 1e-9) throw conflict(`남은 수량(${fmtQty(s.quantity)})보다 많이 옮길 수 없습니다`);
        const item = itemOf(db, s.item_id);
        const took = takeFromStock(db, s, amount, t, { dropEmpty: true });
        const put = addToStock(db, { item_id: s.item_id, location_id: to.id, lot_code: s.lot_code, expires_at: s.expires_at, amount, t });
        events.push(logEvent(db, actor, {
          action: "move", item_id: s.item_id, location_id: s.location_id, to_location_id: to.id, qty: amount,
          summary: `${item.name} ${fmtQty(amount)}${item.unit} · ${locName(db, s.location_id)} → ${to.name}`,
          data: { batch, undo: { type: "move_stock", source: s, source_deleted: took.deleted, target_id: put.id, target_created: put.created, qty: amount } },
        }));
      }
    }
  });
  if (events.length) ctx.changed({ kind: "event", events });
  return { moved: events.length, events: events.map(eventView) };
}

// ---------------------------------------------------------------- 대여
function borrowerFrom(db, actor, input) {
  let userId = optStr(input.borrower_user_id, 40);
  let name = str(input.borrower_name, 60);
  if (userId) {
    const u = db.prepare("SELECT id, name FROM users WHERE id = ?").get(userId);
    if (!u) throw badRequest("빌리는 사람을 찾지 못했습니다");
    if (!name) name = u.name;
  }
  if (!name && actor) {
    userId = actor.id;
    name = actor.name;
  }
  if (!name) throw badRequest("빌리는 사람을 입력하세요");
  return { userId, name };
}

/**
 * { asset_ids: [...] } 또는 { stock_id, qty }, borrower_name/borrower_user_id, borrower_note, due_at, purpose
 */
export function loan(ctx, actor, input) {
  const db = ctx.db;
  const events = [];
  const loans = [];
  const assetIds = Array.isArray(input.asset_ids) ? input.asset_ids.slice(0, 200) : input.asset_id ? [input.asset_id] : [];
  if (!assetIds.length && !input.stock_id) throw badRequest("빌려줄 물건을 고르세요");
  const batch = assetIds.length > 1 ? newId() : null;
  tx(db, () => {
    const { userId, name } = borrowerFrom(db, actor, input);
    const due = optDateTime(input.due_at, "반납 예정");
    const purpose = optStr(input.purpose, 100);
    const note = optStr(input.borrower_note, 100);
    const t = nowIso();
    const dueText = due ? ` · ${new Date(due).toLocaleDateString("ko-KR", { timeZone: "Asia/Seoul", month: "numeric", day: "numeric" })}까지` : "";
    for (const id of assetIds) {
      const a = getAsset(db, id);
      const r = db.prepare("UPDATE assets SET status = 'on_loan', updated_at = ? WHERE id = ? AND status = 'available'").run(t, id);
      if (r.changes !== 1) throw conflict(`${unitName(db, a)}은(는) 지금 ${ASSET_STATUS_LABEL[a.status] || a.status}이라 빌려줄 수 없습니다`);
      const row = {
        id: newId(), item_id: a.item_id, asset_id: a.id, stock_id: null, quantity: 1, from_location_id: a.location_id,
        borrower_user_id: userId, borrower_name: name, borrower_note: note, purpose, due_at: due, created_by: actor ? actor.id : null, created_at: t,
      };
      db.prepare(`INSERT INTO loans(id, item_id, asset_id, stock_id, quantity, from_location_id, borrower_user_id, borrower_name, borrower_note, purpose, due_at, status, created_by, created_at)
        VALUES(@id, @item_id, @asset_id, @stock_id, @quantity, @from_location_id, @borrower_user_id, @borrower_name, @borrower_note, @purpose, @due_at, 'active', @created_by, @created_at)`).run(row);
      loans.push(row);
      events.push(logEvent(db, actor, {
        action: "loan", item_id: a.item_id, asset_id: a.id, location_id: a.location_id, loan_id: row.id,
        summary: `${unitName(db, a)} → ${name}${dueText}`,
        data: { batch, undo: { type: "loan", loan_id: row.id, asset_id: a.id } },
      }));
    }
    if (input.stock_id) {
      const s = getStock(db, input.stock_id);
      const item = itemOf(db, s.item_id);
      const amount = readQty(input.qty ?? 1, "빌려줄 수량");
      const took = takeFromStock(db, s, amount, t);
      const row = {
        id: newId(), item_id: s.item_id, asset_id: null, stock_id: s.id, quantity: amount, from_location_id: s.location_id,
        borrower_user_id: userId, borrower_name: name, borrower_note: note, purpose, due_at: due, created_by: actor ? actor.id : null, created_at: t,
      };
      db.prepare(`INSERT INTO loans(id, item_id, asset_id, stock_id, quantity, from_location_id, borrower_user_id, borrower_name, borrower_note, purpose, due_at, status, created_by, created_at)
        VALUES(@id, @item_id, @asset_id, @stock_id, @quantity, @from_location_id, @borrower_user_id, @borrower_name, @borrower_note, @purpose, @due_at, 'active', @created_by, @created_at)`).run(row);
      loans.push(row);
      events.push(logEvent(db, actor, {
        action: "loan", item_id: s.item_id, location_id: s.location_id, loan_id: row.id, qty: amount,
        summary: `${item.name} ${fmtQty(amount)}${item.unit} → ${name}${dueText}`,
        data: { undo: { type: "loan_stock", loan_id: row.id, stock_id: s.id, qty: amount, left: took.left } },
      }));
    }
  });
  ctx.changed({ kind: "event", events });
  return { loans, events: events.map(eventView) };
}

/**
 * { loan_ids: [...] } 또는 { asset_ids: [...] }, condition: ok|issue, note, to_location_id(없으면 빌려 간 곳), mark_repair
 */
export function returnLoans(ctx, actor, input) {
  const db = ctx.db;
  const events = [];
  let ids = Array.isArray(input.loan_ids) ? input.loan_ids.slice(0, 200) : input.loan_id ? [input.loan_id] : [];
  tx(db, () => {
    if (!ids.length && Array.isArray(input.asset_ids)) {
      ids = input.asset_ids.map((aid) => {
        const l = db.prepare("SELECT id FROM loans WHERE asset_id = ? AND status = 'active'").get(aid);
        if (!l) throw conflict("대여 중이 아닌 장비가 있습니다");
        return l.id;
      });
    }
    if (!ids.length) throw badRequest("반납할 대여를 고르세요");
    const condition = input.condition ? oneOf(input.condition, ["ok", "issue"], "상태") : "ok";
    const note = optStr(input.note, 300);
    const toId = optStr(input.to_location_id, 40);
    if (toId) requireLoc(db, toId, "둘 곳");
    const markRepair = condition === "issue" && input.mark_repair !== false;
    const batch = ids.length > 1 ? newId() : null;
    const t = nowIso();
    for (const id of ids) {
      const l = db.prepare("SELECT * FROM loans WHERE id = ?").get(id);
      if (!l) throw notFound("대여 기록이 없습니다");
      if (l.status !== "active") throw conflict("이미 반납된 대여입니다");
      if (actor && actor.role === "student" && l.borrower_user_id !== actor.id) throw forbidden("본인이 빌린 것만 반납할 수 있습니다");
      const r = db.prepare("UPDATE loans SET status = 'returned', returned_at = ?, return_condition = ?, return_note = ?, return_location_id = ?, returned_by = ? WHERE id = ? AND status = 'active'")
        .run(t, condition, note, null, actor ? actor.id : null, id);
      if (r.changes !== 1) throw conflict("이미 반납된 대여입니다");
      const item = itemOf(db, l.item_id);
      let undo;
      let summary;
      let dest;
      if (l.asset_id) {
        const a = getAsset(db, l.asset_id);
        dest = toId || l.from_location_id || a.location_id;
        const status = markRepair ? "repair" : "available";
        db.prepare("UPDATE assets SET status = ?, location_id = COALESCE(?, location_id), updated_at = ? WHERE id = ?").run(status, dest, t, a.id);
        undo = { type: "return", loan_id: l.id, asset_id: a.id, prev_location: a.location_id, prev_status: a.status, new_location: dest, new_status: status };
        summary = `${unitName(db, a)} 반납 · ${l.borrower_name} → ${locName(db, dest)}${condition === "issue" ? " (이상 있음)" : ""}`;
        if (markRepair) {
          const rid = newId();
          db.prepare(`INSERT INTO repairs(id, target_type, target_id, title, body, status, urgency, reporter_id, reporter_name, created_at, updated_at)
            VALUES(?, 'asset', ?, ?, ?, 'open', 'normal', ?, ?, ?, ?)`).run(rid, a.id, `반납 때 이상: ${(note || "상태 확인 필요").slice(0, 60)}`, note || "", actor ? actor.id : null, actor ? actor.name : "시스템", t, t);
          undo.repair_id = rid;
        }
      } else {
        dest = toId || l.from_location_id;
        if (!dest) throw badRequest("둘 곳을 고르세요");
        const src = l.stock_id && db.prepare("SELECT * FROM stocks WHERE id = ?").get(l.stock_id);
        const put = addToStock(db, { item_id: l.item_id, location_id: dest, lot_code: src && src.location_id === dest ? src.lot_code : "", amount: l.quantity, t });
        undo = { type: "return_stock", loan_id: l.id, stock_id: put.id, created: put.created, qty: l.quantity };
        summary = `${item.name} ${fmtQty(l.quantity)}${item.unit} 반납 · ${l.borrower_name} → ${locName(db, dest)}`;
      }
      db.prepare("UPDATE loans SET return_location_id = ? WHERE id = ?").run(dest, l.id);
      events.push(logEvent(db, actor, {
        action: "return", item_id: l.item_id, asset_id: l.asset_id, location_id: l.from_location_id, to_location_id: dest, loan_id: l.id, qty: l.quantity,
        summary, data: { batch, undo },
      }));
    }
  });
  ctx.changed({ kind: "event", events });
  return { returned: events.length, events: events.map(eventView) };
}

// ---------------------------------------------------------------- 사용·입고·보정
export function useStock(ctx, actor, input) {
  const db = ctx.db;
  let ev;
  tx(db, () => {
    const s = getStock(db, reqStr(input.stock_id, "재고", 40));
    const item = itemOf(db, s.item_id);
    const amount = readQty(input.qty, "사용 수량");
    const t = nowIso();
    takeFromStock(db, s, amount, t);
    const purpose = optStr(input.purpose, 100);
    ev = logEvent(db, actor, {
      action: "use", item_id: s.item_id, location_id: s.location_id, qty: amount,
      summary: `${item.name} ${fmtQty(amount)}${item.unit} 사용 · ${locName(db, s.location_id)}${purpose ? ` · ${purpose}` : ""}`,
      data: { purpose, note: optStr(input.note, 300), undo: { type: "use", stock_id: s.id, qty: amount } },
    });
  });
  ctx.changed({ kind: "event", events: [ev] });
  return { event: eventView(ev) };
}

export function restock(ctx, actor, input) {
  const db = ctx.db;
  let ev;
  let stockId;
  tx(db, () => {
    const item = itemOf(db, reqStr(input.item_id, "품목", 40));
    if (item.kind === "equipment") throw badRequest("장비는 '대수 추가'로 늘립니다");
    const loc = requireLoc(db, input.location_id, "넣을 곳");
    const amount = readQty(input.qty, "입고 수량");
    const t = nowIso();
    const put = addToStock(db, { item_id: item.id, location_id: loc.id, lot_code: str(input.lot_code, 40), expires_at: optDate(input.expires_at, "유통기한"), amount, t });
    stockId = put.id;
    ev = logEvent(db, actor, {
      action: "restock", item_id: item.id, location_id: loc.id, qty: amount,
      summary: `${item.name} ${fmtQty(amount)}${item.unit} 입고 · ${loc.name}`,
      data: { note: optStr(input.note, 300), undo: { type: "restock", stock_id: put.id, created: put.created, qty: amount } },
    });
  });
  ctx.changed({ kind: "event", events: [ev] });
  return { event: eventView(ev), stock_id: stockId };
}

export function adjustStock(ctx, actor, input) {
  const db = ctx.db;
  let ev;
  tx(db, () => {
    const s = getStock(db, reqStr(input.stock_id, "재고", 40));
    const item = itemOf(db, s.item_id);
    const next = readQty(input.quantity, "실제 수량", { allowZero: true });
    const reason = optStr(input.reason, 200) || "수량 보정";
    if (Math.abs(next - s.quantity) < 1e-9) return;
    db.prepare("UPDATE stocks SET quantity = ?, updated_at = ? WHERE id = ?").run(next, nowIso(), s.id);
    ev = logEvent(db, actor, {
      action: "adjust", item_id: s.item_id, location_id: s.location_id, qty: next - s.quantity,
      summary: `${item.name} · ${locName(db, s.location_id)} ${fmtQty(s.quantity)} → ${fmtQty(next)}${item.unit} (${reason})`,
      data: { reason, undo: { type: "adjust", stock_id: s.id, before: s.quantity, after: next } },
    });
  });
  if (ev) ctx.changed({ kind: "event", events: [ev] });
  return { event: ev ? eventView(ev) : null };
}

// ---------------------------------------------------------------- 상태·폐기
export function setUnitStatus(ctx, actor, input) {
  const db = ctx.db;
  let ev;
  tx(db, () => {
    const a = getAsset(db, reqStr(input.asset_id, "장비", 40));
    const status = oneOf(input.status, ["available", "repair", "lost"], "상태");
    if (a.status === "on_loan") throw conflict("대여 중인 장비입니다. 먼저 반납하세요.");
    if (a.status === "retired") throw conflict("폐기된 장비입니다");
    if (a.status === status) return;
    db.prepare("UPDATE assets SET status = ?, updated_at = ? WHERE id = ?").run(status, nowIso(), a.id);
    const note = optStr(input.note, 300);
    ev = logEvent(db, actor, {
      action: "status", item_id: a.item_id, asset_id: a.id, location_id: a.location_id,
      summary: `${unitName(db, a)} ${ASSET_STATUS_LABEL[a.status]} → ${ASSET_STATUS_LABEL[status]}${note ? ` · ${note}` : ""}`,
      data: { note, undo: { type: "status", asset_id: a.id, before: a.status, after: status } },
    });
  });
  if (ev) ctx.changed({ kind: "event", events: [ev] });
  return { event: ev ? eventView(ev) : null };
}

export const RETIRE_KINDS = { unrepairable: "수리 불가", life_exceeded: "내용연수 지남", lost: "분실", other: "기타" };

export function retireUnit(ctx, actor, input) {
  const db = ctx.db;
  let ev;
  tx(db, () => {
    const a = getAsset(db, reqStr(input.asset_id, "장비", 40));
    if (a.status === "on_loan") throw conflict("대여 중인 장비는 반납 후 폐기하세요");
    if (a.status === "retired") throw conflict("이미 폐기된 장비입니다");
    const kind = oneOf(str(input.kind) || "other", Object.keys(RETIRE_KINDS), "폐기 사유 종류");
    const reason = reqStr(input.reason, "폐기 사유", 300);
    const date = optDate(input.date, "폐기일") || nowIso().slice(0, 10);
    const evidence = optStr(input.evidence, 300);
    db.prepare("UPDATE assets SET status = 'retired', retired_at = ?, retire_kind = ?, retire_reason = ?, retire_evidence = ?, updated_at = ? WHERE id = ?")
      .run(date, kind, reason, evidence, nowIso(), a.id);
    ev = logEvent(db, actor, {
      action: "retire", item_id: a.item_id, asset_id: a.id, location_id: a.location_id,
      summary: `${unitName(db, a)} 폐기 · ${RETIRE_KINDS[kind]} · ${reason}`,
      data: { undo: { type: "retire", asset_id: a.id, before: a.status } },
    });
  });
  ctx.changed({ kind: "event", events: [ev] });
  return { event: eventView(ev) };
}

// ---------------------------------------------------------------- 되돌리기
function canUndo(actor, ev, caps) {
  const age = Date.now() - Date.parse(ev.at);
  if (caps && caps.has("edit") && age <= UNDO_WINDOW_EDIT_MS) return true;
  return Boolean(actor && ev.actor_id === actor.id && age <= UNDO_WINDOW_OWN_MS);
}

function changedSince() {
  return conflict("그 뒤로 상태가 바뀌어서 자동으로 되돌릴 수 없습니다. 직접 고쳐 주세요.");
}

function applyUndo(db, u, t) {
  switch (u.type) {
    case "move_asset": {
      const a = getAsset(db, u.asset_id);
      if (a.location_id !== u.to || a.status === "on_loan" || a.status === "retired") throw changedSince();
      db.prepare("UPDATE assets SET location_id = ?, updated_at = ? WHERE id = ?").run(u.from, t, a.id);
      return { item_id: a.item_id, asset_id: a.id, summary: `${unitName(db, a)} 제자리로 (${locName(db, u.to)} → ${locName(db, u.from)})` };
    }
    case "move_stock": {
      const target = db.prepare("SELECT * FROM stocks WHERE id = ?").get(u.target_id);
      if (!target || target.quantity + 1e-9 < u.qty) throw changedSince();
      takeFromStock(db, target, u.qty, t, { dropEmpty: u.target_created });
      const src = db.prepare("SELECT * FROM stocks WHERE id = ?").get(u.source.id);
      if (src) db.prepare("UPDATE stocks SET quantity = round(quantity + ?, 2), updated_at = ? WHERE id = ?").run(u.qty, t, src.id);
      else addToStock(db, { item_id: u.source.item_id, location_id: u.source.location_id, lot_code: u.source.lot_code, expires_at: u.source.expires_at, amount: u.qty, t });
      const it = itemOf(db, u.source.item_id);
      return { item_id: it.id, summary: `${it.name} ${fmtQty(u.qty)}${it.unit} 제자리로 (${locName(db, target.location_id)} → ${locName(db, u.source.location_id)})` };
    }
    case "loan": {
      const l = db.prepare("SELECT * FROM loans WHERE id = ?").get(u.loan_id);
      if (!l || l.status !== "active") throw changedSince();
      db.prepare("DELETE FROM loans WHERE id = ?").run(l.id);
      db.prepare("UPDATE assets SET status = 'available', updated_at = ? WHERE id = ? AND status = 'on_loan'").run(t, u.asset_id);
      const a = getAsset(db, u.asset_id);
      return { item_id: a.item_id, asset_id: a.id, summary: `${unitName(db, a)} 대여 취소 (${l.borrower_name})` };
    }
    case "loan_stock": {
      const l = db.prepare("SELECT * FROM loans WHERE id = ?").get(u.loan_id);
      if (!l || l.status !== "active") throw changedSince();
      db.prepare("DELETE FROM loans WHERE id = ?").run(l.id);
      const s = db.prepare("SELECT * FROM stocks WHERE id = ?").get(u.stock_id);
      if (s) db.prepare("UPDATE stocks SET quantity = round(quantity + ?, 2), updated_at = ? WHERE id = ?").run(u.qty, t, s.id);
      else addToStock(db, { item_id: l.item_id, location_id: l.from_location_id, amount: u.qty, t });
      const it = itemOf(db, l.item_id);
      return { item_id: it.id, summary: `${it.name} ${fmtQty(u.qty)}${it.unit} 대여 취소 (${l.borrower_name})` };
    }
    case "return": {
      const l = db.prepare("SELECT * FROM loans WHERE id = ?").get(u.loan_id);
      const a = getAsset(db, u.asset_id);
      if (!l || l.status !== "returned" || a.status !== u.new_status || a.location_id !== u.new_location) throw changedSince();
      if (db.prepare("SELECT 1 FROM loans WHERE asset_id = ? AND status = 'active'").get(a.id)) throw changedSince();
      db.prepare("UPDATE loans SET status = 'active', returned_at = NULL, return_condition = NULL, return_note = NULL, return_location_id = NULL, returned_by = NULL WHERE id = ?").run(l.id);
      db.prepare("UPDATE assets SET status = 'on_loan', location_id = ?, updated_at = ? WHERE id = ?").run(u.prev_location, t, a.id);
      if (u.repair_id) db.prepare("DELETE FROM repairs WHERE id = ? AND status = 'open'").run(u.repair_id);
      return { item_id: a.item_id, asset_id: a.id, summary: `${unitName(db, a)} 반납 취소 (다시 ${l.borrower_name} 대여 중)` };
    }
    case "return_stock": {
      const l = db.prepare("SELECT * FROM loans WHERE id = ?").get(u.loan_id);
      const s = db.prepare("SELECT * FROM stocks WHERE id = ?").get(u.stock_id);
      if (!l || l.status !== "returned" || !s || s.quantity + 1e-9 < u.qty) throw changedSince();
      takeFromStock(db, s, u.qty, t, { dropEmpty: u.created });
      db.prepare("UPDATE loans SET status = 'active', returned_at = NULL, return_condition = NULL, return_note = NULL, return_location_id = NULL, returned_by = NULL WHERE id = ?").run(l.id);
      const it = itemOf(db, l.item_id);
      return { item_id: it.id, summary: `${it.name} 반납 취소 (다시 ${l.borrower_name} 대여 중)` };
    }
    case "use": {
      const s = db.prepare("SELECT * FROM stocks WHERE id = ?").get(u.stock_id);
      if (!s) throw changedSince();
      db.prepare("UPDATE stocks SET quantity = round(quantity + ?, 2), updated_at = ? WHERE id = ?").run(u.qty, t, s.id);
      const it = itemOf(db, s.item_id);
      return { item_id: it.id, summary: `${it.name} ${fmtQty(u.qty)}${it.unit} 사용 취소` };
    }
    case "restock": {
      const s = db.prepare("SELECT * FROM stocks WHERE id = ?").get(u.stock_id);
      if (!s || s.quantity + 1e-9 < u.qty) throw changedSince();
      takeFromStock(db, s, u.qty, t, { dropEmpty: u.created });
      const it = itemOf(db, s.item_id);
      return { item_id: it.id, summary: `${it.name} ${fmtQty(u.qty)}${it.unit} 입고 취소` };
    }
    case "adjust": {
      const s = db.prepare("SELECT * FROM stocks WHERE id = ?").get(u.stock_id);
      if (!s || Math.abs(s.quantity - u.after) > 1e-9) throw changedSince();
      db.prepare("UPDATE stocks SET quantity = ?, updated_at = ? WHERE id = ?").run(u.before, t, s.id);
      const it = itemOf(db, s.item_id);
      return { item_id: it.id, summary: `${it.name} 수량 보정 취소 (${fmtQty(u.after)} → ${fmtQty(u.before)})` };
    }
    case "status": {
      const a = getAsset(db, u.asset_id);
      if (a.status !== u.after) throw changedSince();
      db.prepare("UPDATE assets SET status = ?, updated_at = ? WHERE id = ?").run(u.before, t, a.id);
      return { item_id: a.item_id, asset_id: a.id, summary: `${unitName(db, a)} 상태 되돌림 (${ASSET_STATUS_LABEL[u.after]} → ${ASSET_STATUS_LABEL[u.before]})` };
    }
    case "retire": {
      const a = getAsset(db, u.asset_id);
      if (a.status !== "retired") throw changedSince();
      db.prepare("UPDATE assets SET status = ?, retired_at = NULL, retire_kind = NULL, retire_reason = NULL, retire_evidence = NULL, updated_at = ? WHERE id = ?").run(u.before, t, a.id);
      return { item_id: a.item_id, asset_id: a.id, summary: `${unitName(db, a)} 폐기 취소` };
    }
    default:
      throw badRequest("되돌릴 수 없는 작업입니다");
  }
}

export function undo(ctx, actor, caps, eventIds) {
  const db = ctx.db;
  const ids = (Array.isArray(eventIds) ? eventIds : [eventIds]).filter(Boolean).slice(0, 200);
  if (!ids.length) throw badRequest("되돌릴 작업을 고르세요");
  const events = [];
  tx(db, () => {
    const t = nowIso();
    // 나중 작업부터 되돌린다
    const rows = ids.map((id) => {
      const ev = db.prepare("SELECT * FROM events WHERE id = ?").get(id);
      if (!ev) throw notFound("작업 기록이 없습니다");
      return ev;
    }).sort((a, b) => String(b.at).localeCompare(String(a.at)));
    for (const ev of rows) {
      if (ev.undone_at) throw conflict("이미 되돌린 작업입니다");
      const data = ev.data ? JSON.parse(ev.data) : null;
      if (!data || !data.undo) throw badRequest("이 작업은 되돌리기를 지원하지 않습니다");
      if (!canUndo(actor, ev, caps)) throw forbidden("본인 작업은 30분 안에, 담당교사는 7일 안에 되돌릴 수 있습니다");
      const r = applyUndo(db, data.undo, t);
      db.prepare("UPDATE events SET undone_at = ?, undone_by = ? WHERE id = ?").run(t, actor ? actor.id : null, ev.id);
      events.push(logEvent(db, actor, { action: "undo", item_id: r.item_id || ev.item_id, asset_id: r.asset_id || ev.asset_id, location_id: ev.location_id, undo_of: ev.id, summary: `되돌림: ${r.summary}` }));
    }
  });
  ctx.changed({ kind: "event", events });
  return { undone: events.length, events: events.map(eventView) };
}

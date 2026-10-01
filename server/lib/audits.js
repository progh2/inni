// 실사(재물 조사). 장소를 고르면 장부 목록이 만들어지고, 스캔하거나 눌러서 확인한다.
// 끝나면 없는 것·수량이 다른 것을 장부에 반영(분실·보정·이곳으로 이동)할 수 있다.
import { newId, nowIso, optStr, reqStr, qty as readQty, badRequest, notFound, conflict } from "./util.js";
import { tx } from "./db.js";
import { logEvent } from "./events.js";
import { subtree } from "./snapshot.js";
import { resolveCode } from "./search.js";
import { move, adjustStock, setUnitStatus } from "./actions.js";

export function startAudit(ctx, actor, input) {
  const db = ctx.db;
  const snap = ctx.snapshot();
  const loc = snap.locations.get(reqStr(input.location_id, "장소", 40));
  if (!loc) throw badRequest("장소가 없습니다");
  if (db.prepare("SELECT 1 FROM audits WHERE location_id = ? AND status = 'active'").get(loc.id)) {
    throw conflict(`${loc.name}은(는) 이미 실사 중입니다. 이어서 하세요.`);
  }
  const deep = input.deep !== false;
  const scope = deep ? subtree(snap, loc.id) : new Set([loc.id]);
  const t = nowIso();
  const audit = {
    id: newId(), location_id: loc.id, title: optStr(input.title, 80) || `${loc.name} 실사`, started_by: actor ? actor.id : null,
    started_by_name: actor ? actor.name : "?", started_at: t, note: optStr(input.note, 500),
  };
  let n = 0;
  tx(db, () => {
    db.prepare("INSERT INTO audits(id, location_id, title, status, started_by, started_by_name, started_at, note) VALUES(@id, @location_id, @title, 'active', @started_by, @started_by_name, @started_at, @note)").run(audit);
    const ins = db.prepare(`INSERT INTO audit_lines(id, audit_id, kind, item_id, asset_id, stock_id, location_id, name, code, expected_qty)
      VALUES(@id, @audit_id, @kind, @item_id, @asset_id, @stock_id, @location_id, @name, @code, @expected_qty)`);
    for (const a of snap.assets.values()) {
      if (a.status === "retired" || !scope.has(a.location_id)) continue;
      const it = snap.items.get(a.item_id);
      if (!it || it.archived_at) continue;
      ins.run({ id: newId(), audit_id: audit.id, kind: "asset", item_id: a.item_id, asset_id: a.id, stock_id: null, location_id: a.location_id,
        name: `${it.name}${a.label ? ` ${a.label}` : ""}`, code: a.management_number || a.qr, expected_qty: 1 });
      n += 1;
    }
    for (const s of snap.stocks.values()) {
      if (!scope.has(s.location_id) || s.quantity <= 0) continue;
      const it = snap.items.get(s.item_id);
      if (!it || it.archived_at) continue;
      ins.run({ id: newId(), audit_id: audit.id, kind: "stock", item_id: s.item_id, asset_id: null, stock_id: s.id, location_id: s.location_id,
        name: `${it.name}${s.lot_code ? ` (${s.lot_code})` : ""}`, code: it.qr, expected_qty: s.quantity });
      n += 1;
    }
    logEvent(db, actor, { action: "audit_start", location_id: loc.id, summary: `실사 시작: ${loc.path} · 확인할 것 ${n}건` });
  });
  ctx.changed({ kind: "audit", id: audit.id });
  return auditView(ctx, audit.id);
}

export function auditView(ctx, id) {
  const db = ctx.db;
  const a = db.prepare("SELECT * FROM audits WHERE id = ?").get(id);
  if (!a) throw notFound("실사가 없습니다");
  const snap = ctx.snapshot();
  const lines = db.prepare("SELECT * FROM audit_lines WHERE audit_id = ? ORDER BY checked_at IS NOT NULL, location_id, name").all(id).map((l) => {
    const loc = l.location_id && snap.locations.get(l.location_id);
    const it = snap.items.get(l.item_id);
    const unit = l.asset_id && snap.assets.get(l.asset_id);
    return {
      ...l, location_path: loc ? loc.path : "", thumb: it ? it.thumb : null, unit_status: unit ? unit.status : null,
      mismatch: l.kind === "stock" && l.counted_qty !== null && Math.abs(l.counted_qty - l.expected_qty) > 1e-9,
      missing: a.status === "done" && !l.checked_at && !l.extra,
    };
  });
  const expected = lines.filter((l) => !l.extra);
  const done = expected.filter((l) => l.checked_at).length;
  const loc = snap.locations.get(a.location_id);
  return {
    ...a, location_path: loc ? loc.path : "", lines,
    progress: { total: expected.length, checked: done, extra: lines.filter((l) => l.extra).length, mismatch: lines.filter((l) => l.mismatch).length },
  };
}

export function listAudits(ctx) {
  const snap = ctx.snapshot();
  return ctx.db.prepare(`SELECT a.*, (SELECT COUNT(*) FROM audit_lines l WHERE l.audit_id = a.id AND l.extra = 0) total,
      (SELECT COUNT(*) FROM audit_lines l WHERE l.audit_id = a.id AND l.extra = 0 AND l.checked_at IS NOT NULL) checked
    FROM audits a ORDER BY a.status = 'active' DESC, a.started_at DESC LIMIT 100`).all()
    .map((a) => ({ ...a, location_path: (snap.locations.get(a.location_id) || {}).path || "" }));
}

function requireActive(db, id) {
  const a = db.prepare("SELECT * FROM audits WHERE id = ?").get(id);
  if (!a) throw notFound("실사가 없습니다");
  if (a.status !== "active") throw conflict("끝난 실사입니다");
  return a;
}

// 스캔한 코드로 확인. 장부에 없던 장비는 "여기서 발견"으로 더한다.
export function checkCode(ctx, actor, auditId, code) {
  const db = ctx.db;
  const audit = requireActive(db, auditId);
  const snap = ctx.snapshot();
  const hit = resolveCode(snap, code);
  if (!hit) return { result: "unknown", message: "등록되지 않은 코드입니다" };
  if (hit.type === "location") return { result: "location", message: "장소 라벨입니다. 물건 라벨을 찍어 주세요." };
  const t = nowIso();
  if (hit.type === "asset") {
    const line = db.prepare("SELECT * FROM audit_lines WHERE audit_id = ? AND asset_id = ?").get(auditId, hit.id);
    if (line) {
      if (line.checked_at) return { result: "again", line_id: line.id, message: `${line.name} — 이미 확인했어요` };
      db.prepare("UPDATE audit_lines SET checked_at = ?, checked_by_name = ?, counted_qty = 1 WHERE id = ?").run(t, actor ? actor.name : "", line.id);
      ctx.changed({ kind: "audit", id: auditId });
      return { result: "ok", line_id: line.id, message: `${line.name} 확인` };
    }
    const a = snap.assets.get(hit.id);
    const it = snap.items.get(a.item_id);
    const from = a.location_id && snap.locations.get(a.location_id);
    const id = newId();
    db.prepare(`INSERT INTO audit_lines(id, audit_id, kind, item_id, asset_id, location_id, name, code, expected_qty, counted_qty, checked_at, checked_by_name, extra)
      VALUES(?, ?, 'asset', ?, ?, ?, ?, ?, 0, 1, ?, ?, 1)`).run(id, auditId, a.item_id, a.id, audit.location_id, `${it.name}${a.label ? ` ${a.label}` : ""}`, a.management_number || a.qr, t, actor ? actor.name : "");
    ctx.changed({ kind: "audit", id: auditId });
    return { result: "extra", line_id: id, message: `장부에는 ${from ? from.path : "다른 곳"}에 있는 ${it.name} — 여기서 발견으로 적었어요` };
  }
  // 수량 품목: 해당 줄들을 알려 주고 화면에서 수량을 넣게 한다
  const lines = db.prepare("SELECT id, name, expected_qty, counted_qty FROM audit_lines WHERE audit_id = ? AND item_id = ?").all(auditId, hit.id);
  if (!lines.length) return { result: "not_here", message: "이 장소 장부에 없는 품목입니다" };
  return { result: "count", lines, message: "수량을 세어 넣어 주세요" };
}

export function setLine(ctx, actor, auditId, lineId, input) {
  const db = ctx.db;
  requireActive(db, auditId);
  const line = db.prepare("SELECT * FROM audit_lines WHERE id = ? AND audit_id = ?").get(lineId, auditId);
  if (!line) throw notFound("항목이 없습니다");
  const t = nowIso();
  if (input.counted_qty !== undefined && input.counted_qty !== null && input.counted_qty !== "") {
    const q = readQty(input.counted_qty, "센 수량", { allowZero: true });
    db.prepare("UPDATE audit_lines SET counted_qty = ?, checked_at = ?, checked_by_name = ? WHERE id = ?").run(q, t, actor ? actor.name : "", lineId);
  } else if (input.checked === false) {
    db.prepare("UPDATE audit_lines SET checked_at = NULL, checked_by_name = NULL, counted_qty = NULL WHERE id = ?").run(lineId);
  } else {
    db.prepare("UPDATE audit_lines SET checked_at = ?, checked_by_name = ?, counted_qty = COALESCE(counted_qty, expected_qty) WHERE id = ?").run(t, actor ? actor.name : "", lineId);
  }
  ctx.changed({ kind: "audit", id: auditId });
  return db.prepare("SELECT * FROM audit_lines WHERE id = ?").get(lineId);
}

export function finishAudit(ctx, actor, auditId, { cancel = false } = {}) {
  const db = ctx.db;
  const a = requireActive(db, auditId);
  const t = nowIso();
  const stat = db.prepare("SELECT SUM(extra = 0) total, SUM(extra = 0 AND checked_at IS NOT NULL) checked, SUM(extra) extra FROM audit_lines WHERE audit_id = ?").get(auditId);
  tx(db, () => {
    db.prepare("UPDATE audits SET status = ?, finished_at = ?, finished_by_name = ? WHERE id = ?").run(cancel ? "cancelled" : "done", t, actor ? actor.name : "", auditId);
    logEvent(db, actor, {
      action: "audit_finish", location_id: a.location_id,
      summary: `${a.title} ${cancel ? "취소" : "종료"} · 확인 ${stat.checked || 0}/${stat.total || 0}${stat.extra ? ` · 새로 발견 ${stat.extra}` : ""}`,
    });
  });
  ctx.changed({ kind: "audit", id: auditId });
  return auditView(ctx, auditId);
}

// 끝난 실사의 차이를 장부에 반영
export function resolveLine(ctx, actor, auditId, lineId, input) {
  const db = ctx.db;
  const a = db.prepare("SELECT * FROM audits WHERE id = ?").get(auditId);
  if (!a) throw notFound("실사가 없습니다");
  const line = db.prepare("SELECT * FROM audit_lines WHERE id = ? AND audit_id = ?").get(lineId, auditId);
  if (!line) throw notFound("항목이 없습니다");
  if (line.resolution) throw conflict("이미 반영한 항목입니다");
  const action = String(input.action || "");
  const who = { ...actor, via: "audit" };
  if (action === "lost" && line.kind === "asset" && !line.checked_at) {
    setUnitStatus(ctx, who, { asset_id: line.asset_id, status: "lost", note: `${a.title}에서 확인되지 않음` });
  } else if (action === "move_here" && line.kind === "asset" && line.extra) {
    move(ctx, who, { targets: [{ asset_id: line.asset_id }], to_location_id: a.location_id });
  } else if (action === "adjust" && line.kind === "stock" && line.counted_qty !== null) {
    adjustStock(ctx, who, { stock_id: line.stock_id, quantity: line.counted_qty, reason: `${a.title} 실사 보정` });
  } else if (action === "adjust_zero" && line.kind === "stock" && !line.checked_at) {
    adjustStock(ctx, who, { stock_id: line.stock_id, quantity: 0, reason: `${a.title}에서 확인되지 않음` });
  } else if (action !== "ignore") {
    throw badRequest("이 항목에 할 수 없는 처리입니다");
  }
  db.prepare("UPDATE audit_lines SET resolution = ?, resolved_at = ?, resolved_by_name = ? WHERE id = ?").run(action, nowIso(), actor ? actor.name : "", lineId);
  ctx.changed({ kind: "audit", id: auditId });
  return { ok: true };
}

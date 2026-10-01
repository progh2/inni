// 고장 신고·수리. 누구나 신고하고, 담당교사가 처리한다.
import { newId, nowIso, str, optStr, reqStr, optNum, optDate, oneOf, badRequest, notFound, conflict } from "./util.js";
import { tx } from "./db.js";
import { logEvent } from "./events.js";

export const REPAIR_STATUS = ["open", "in_progress", "done", "rejected"];
export const REPAIR_STATUS_LABEL = { open: "접수", in_progress: "처리 중", done: "완료", rejected: "반려" };

function targetInfo(db, type, id) {
  if (type === "asset") {
    const a = db.prepare("SELECT a.*, i.name item_name FROM assets a JOIN items i ON i.id = a.item_id WHERE a.id = ?").get(id);
    if (!a) throw badRequest("장비가 없습니다");
    return { name: `${a.item_name}${a.management_number ? ` ${a.management_number}` : ""}`, item_id: a.item_id, asset: a, location_id: a.location_id };
  }
  if (type === "item") {
    const it = db.prepare("SELECT * FROM items WHERE id = ?").get(id);
    if (!it) throw badRequest("품목이 없습니다");
    return { name: it.name, item_id: it.id };
  }
  const l = db.prepare("SELECT * FROM locations WHERE id = ?").get(id);
  if (!l) throw badRequest("장소가 없습니다");
  return { name: l.name, location_id: l.id };
}

export function createRepair(ctx, actor, input) {
  const db = ctx.db;
  const type = oneOf(str(input.target_type) || "asset", ["asset", "item", "location"], "대상 종류");
  const targetId = reqStr(input.target_id, "대상", 40);
  const title = reqStr(input.title, "제목", 100);
  const t = nowIso();
  const row = {
    id: newId(), target_type: type, target_id: targetId, title, body: str(input.body, 2000), image: optStr(input.image, 200),
    urgency: input.urgency === "urgent" ? "urgent" : "normal", reporter_id: actor ? actor.id : null, reporter_name: actor ? actor.name : "?", created_at: t,
  };
  let ev;
  tx(db, () => {
    const info = targetInfo(db, type, targetId);
    db.prepare(`INSERT INTO repairs(id, target_type, target_id, title, body, image, status, urgency, reporter_id, reporter_name, created_at, updated_at)
      VALUES(@id, @target_type, @target_id, @title, @body, @image, 'open', @urgency, @reporter_id, @reporter_name, @created_at, @created_at)`).run(row);
    // 장비를 수리 중으로 표시(대여 중이면 그대로 둔다)
    if (type === "asset" && input.mark_repair && info.asset.status === "available") {
      db.prepare("UPDATE assets SET status = 'repair', updated_at = ? WHERE id = ?").run(t, targetId);
    }
    ev = logEvent(db, actor, {
      action: "repair_open", item_id: info.item_id || null, asset_id: type === "asset" ? targetId : null, location_id: info.location_id || null,
      repair_id: row.id, summary: `고장 신고: ${info.name} · ${title}${row.urgency === "urgent" ? " (급함)" : ""}`,
    });
  });
  ctx.changed({ kind: "repair", id: row.id, events: [ev] });
  return row;
}

export function updateRepair(ctx, actor, id, input) {
  const db = ctx.db;
  const cur = db.prepare("SELECT * FROM repairs WHERE id = ?").get(id);
  if (!cur) throw notFound("신고가 없습니다");
  const next = { ...cur };
  if (input.status !== undefined) next.status = oneOf(input.status, REPAIR_STATUS, "상태");
  if (input.urgency !== undefined) next.urgency = input.urgency === "urgent" ? "urgent" : "normal";
  if (input.title !== undefined) next.title = reqStr(input.title, "제목", 100);
  if (input.body !== undefined) next.body = str(input.body, 2000);
  if (input.resolution !== undefined) next.resolution = optStr(input.resolution, 1000);
  if (input.cost_amount !== undefined) next.cost_amount = optNum(input.cost_amount, "수리비", { min: 0, max: 1e12 });
  if (input.cost_vendor !== undefined) next.cost_vendor = optStr(input.cost_vendor, 80);
  if (input.cost_budget !== undefined) next.cost_budget = optStr(input.cost_budget, 100);
  if (input.cost_at !== undefined) next.cost_at = optDate(input.cost_at, "비용 날짜");
  const t = nowIso();
  next.updated_at = t;
  const closing = ["done", "rejected"].includes(next.status) && !["done", "rejected"].includes(cur.status);
  if (closing) next.closed_at = t;
  if (!["done", "rejected"].includes(next.status)) next.closed_at = null;
  let ev;
  tx(db, () => {
    db.prepare(`UPDATE repairs SET status=@status, urgency=@urgency, title=@title, body=@body, resolution=@resolution, cost_amount=@cost_amount,
      cost_vendor=@cost_vendor, cost_budget=@cost_budget, cost_at=@cost_at, updated_at=@updated_at, closed_at=@closed_at WHERE id=@id`).run(next);
    let info;
    try { info = targetInfo(db, cur.target_type, cur.target_id); } catch { info = { name: "?" }; }
    // 수리가 끝나면 장비를 다시 쓸 수 있게
    if (cur.target_type === "asset") {
      if (next.status === "done" && input.release !== false) {
        db.prepare("UPDATE assets SET status = 'available', updated_at = ? WHERE id = ? AND status = 'repair'").run(t, cur.target_id);
      } else if (next.status === "in_progress" && input.mark_repair) {
        db.prepare("UPDATE assets SET status = 'repair', updated_at = ? WHERE id = ? AND status = 'available'").run(t, cur.target_id);
      }
    }
    if (next.status !== cur.status || input.cost_amount !== undefined) {
      ev = logEvent(db, actor, {
        action: "repair_update", item_id: info.item_id || null, asset_id: cur.target_type === "asset" ? cur.target_id : null, repair_id: id,
        summary: `수리: ${info.name} · ${REPAIR_STATUS_LABEL[cur.status]} → ${REPAIR_STATUS_LABEL[next.status]}${next.cost_amount ? ` · ${Number(next.cost_amount).toLocaleString("ko-KR")}원` : ""}`,
      });
    }
  });
  ctx.changed({ kind: "repair", id, events: ev ? [ev] : [] });
  return next;
}

export function listRepairs(ctx, f = {}) {
  const db = ctx.db;
  const where = [];
  const args = {};
  if (f.status === "open") where.push("r.status IN ('open','in_progress')");
  else if (f.status === "closed") where.push("r.status IN ('done','rejected')");
  else if (f.status && REPAIR_STATUS.includes(f.status)) { where.push("r.status = @status"); args.status = f.status; }
  if (f.mine && f.user_id) { where.push("r.reporter_id = @uid"); args.uid = f.user_id; }
  if (f.target_id) { where.push("r.target_id = @tid"); args.tid = f.target_id; }
  const rows = db.prepare(`SELECT r.* FROM repairs r ${where.length ? `WHERE ${where.join(" AND ")}` : ""}
    ORDER BY CASE r.status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END, r.urgency = 'urgent' DESC, r.created_at DESC LIMIT 300`).all(args);
  const snap = ctx.snapshot();
  return rows.map((r) => {
    let target = { name: "?" };
    if (r.target_type === "asset") {
      const a = snap.assets.get(r.target_id);
      const it = a && snap.items.get(a.item_id);
      const loc = a && a.location_id && snap.locations.get(a.location_id);
      target = { name: it ? it.name : "(지운 장비)", unit: a ? a.management_number || a.label : null, item_id: a ? a.item_id : null, asset_id: r.target_id, thumb: it ? it.thumb : null, where: loc ? loc.path : "" };
    } else if (r.target_type === "item") {
      const it = snap.items.get(r.target_id);
      target = { name: it ? it.name : "(지운 품목)", item_id: r.target_id, thumb: it ? it.thumb : null };
    } else {
      const l = snap.locations.get(r.target_id);
      target = { name: l ? l.name : "(지운 장소)", location_id: r.target_id, where: l ? l.path : "" };
    }
    return { ...r, status_label: REPAIR_STATUS_LABEL[r.status], target };
  });
}

export function repairCosts(ctx, { year } = {}) {
  const y = Number(year) || new Date().getFullYear();
  const rows = ctx.db.prepare(`SELECT substr(COALESCE(cost_at, closed_at, created_at), 1, 7) ym, COUNT(*) n, SUM(cost_amount) total
    FROM repairs WHERE cost_amount IS NOT NULL AND substr(COALESCE(cost_at, closed_at, created_at), 1, 4) = ? GROUP BY ym ORDER BY ym`).all(String(y));
  const total = rows.reduce((s, r) => s + (r.total || 0), 0);
  return { year: y, months: rows, total };
}

export function deleteRepair(ctx, actor, id) {
  const cur = ctx.db.prepare("SELECT * FROM repairs WHERE id = ?").get(id);
  if (!cur) throw notFound("신고가 없습니다");
  if (cur.status === "in_progress") throw conflict("처리 중인 신고는 먼저 완료나 반려로 바꾸세요");
  ctx.db.prepare("DELETE FROM repairs WHERE id = ?").run(id);
  ctx.changed({ kind: "repair", id });
}

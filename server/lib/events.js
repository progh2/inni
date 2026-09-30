// 이력: 누가·언제·무엇을. 되돌리기에 필요한 전후 값은 data 에 둔다.
import { newId, nowIso, jsonParse } from "./util.js";

export const ACTION_LABEL = {
  create: "등록", update: "수정", archive: "보관함으로", unarchive: "보관 해제", move: "이동", loan: "대여", return: "반납",
  use: "사용", restock: "입고", adjust: "수량 보정", status: "상태 변경", retire: "폐기", undo: "되돌리기",
  unit_add: "개체 추가", repair_open: "고장 신고", repair_update: "수리 처리", audit_start: "실사 시작", audit_finish: "실사 종료",
  audit_resolve: "실사 보정", import: "가져오기", location: "장소", user: "사용자", settings: "설정", backup: "백업", restore: "복원",
};

const INSERT = `INSERT INTO events(id, at, actor_id, actor_name, action, item_id, asset_id, location_id, to_location_id, loan_id, repair_id, qty, summary, data, via, undo_of)
  VALUES(@id, @at, @actor_id, @actor_name, @action, @item_id, @asset_id, @location_id, @to_location_id, @loan_id, @repair_id, @qty, @summary, @data, @via, @undo_of)`;

export function logEvent(db, actor, e) {
  const row = {
    id: newId(),
    at: e.at || nowIso(),
    actor_id: (actor && actor.id) || null,
    actor_name: (actor && actor.name) || "시스템",
    action: e.action,
    item_id: e.item_id ?? null,
    asset_id: e.asset_id ?? null,
    location_id: e.location_id ?? null,
    to_location_id: e.to_location_id ?? null,
    loan_id: e.loan_id ?? null,
    repair_id: e.repair_id ?? null,
    qty: e.qty ?? null,
    summary: String(e.summary || "").slice(0, 300),
    data: e.data ? JSON.stringify(e.data) : null,
    via: e.via || (actor && actor.via) || "app",
    undo_of: e.undo_of ?? null,
  };
  db.prepare(INSERT).run(row);
  return row;
}

export function eventView(row) {
  if (!row) return null;
  const data = jsonParse(row.data, null);
  return {
    id: row.id, at: row.at, actor_id: row.actor_id, actor_name: row.actor_name, action: row.action,
    label: ACTION_LABEL[row.action] || row.action, item_id: row.item_id, asset_id: row.asset_id,
    location_id: row.location_id, to_location_id: row.to_location_id, loan_id: row.loan_id, repair_id: row.repair_id,
    qty: row.qty, summary: row.summary, via: row.via, undone_at: row.undone_at, undo_of: row.undo_of,
    undoable: Boolean(data && data.undo) && !row.undone_at,
  };
}

export function listEvents(db, f = {}) {
  const where = [];
  const args = {};
  if (f.item_id) { where.push("item_id = @item_id"); args.item_id = f.item_id; }
  if (f.asset_id) { where.push("asset_id = @asset_id"); args.asset_id = f.asset_id; }
  if (f.location_ids && f.location_ids.length) {
    const keys = f.location_ids.slice(0, 400).map((id, i) => { args[`l${i}`] = id; return `@l${i}`; });
    where.push(`(location_id IN (${keys.join(",")}) OR to_location_id IN (${keys.join(",")}))`);
  }
  if (f.actor_id) { where.push("actor_id = @actor_id"); args.actor_id = f.actor_id; }
  if (f.actions && f.actions.length) {
    const keys = f.actions.map((a, i) => { args[`a${i}`] = a; return `@a${i}`; });
    where.push(`action IN (${keys.join(",")})`);
  }
  if (f.before) { where.push("at < @before"); args.before = f.before; }
  if (f.since) { where.push("at >= @since"); args.since = f.since; }
  if (f.q) { where.push("summary LIKE @q"); args.q = `%${f.q}%`; }
  const limit = Math.max(1, Math.min(500, Number(f.limit) || 50));
  const sql = `SELECT * FROM events ${where.length ? `WHERE ${where.join(" AND ")}` : ""} ORDER BY at DESC, rowid DESC LIMIT ${limit}`;
  return db.prepare(sql).all(args).map(eventView);
}

// 도면: 층(또는 단층 건물)마다 도면 그림 한 장. 그 위에 그린 다각형을 실·구역·보관함과 잇고,
// 현관·계단·승강기 표시를 둔다. 3D 선내 지도는 이걸로 층을 쌓고 실을 세운다(없으면 예전처럼 상자 배치).
import { newId, nowIso, badRequest, notFound, conflict } from "./util.js";
import { logEvent } from "./events.js";

export const PLAN_HOSTS = ["building", "floor"]; // 도면을 붙일 수 있는 장소
export const SHAPE_KINDS = ["room", "zone", "storage", "bin"]; // 도면에 그릴 수 있는 장소
export const MARK_TYPES = ["entrance", "stairs", "elevator"];
export const MARK_LABEL = { entrance: "현관", stairs: "계단", elevator: "승강기" };
const MAX_SHAPES = 400;
const MAX_POINTS = 200;
const MAX_MARKS = 100;
const IMAGE_RE = /^\d{4}\/\d{2}\/[\w-]+\.(png|jpg|webp|gif)$/;

function view(r) {
  return {
    id: r.id, location_id: r.location_id, image: r.image || null, image_url: r.image ? `/uploads/${r.image}` : null,
    width: r.width, height: r.height, level: r.level, meters_per_px: r.meters_per_px,
    shapes: safeJson(r.shapes), marks: safeJson(r.marks), updated_at: r.updated_at,
  };
}
function safeJson(s) {
  try { const v = JSON.parse(s || "[]"); return Array.isArray(v) ? v : []; } catch { return []; }
}

export function listPlans(ctx) {
  return ctx.db.prepare("SELECT * FROM floor_plans ORDER BY level, created_at").all().map(view);
}

export function getPlan(ctx, id) {
  const r = ctx.db.prepare("SELECT * FROM floor_plans WHERE id = ?").get(String(id || ""));
  if (!r) throw notFound("도면이 없습니다");
  return view(r);
}

// "지하1층"·"B1" → -1, "2층"·"2F" → 2. 모르면 1
export function guessLevel(name) {
  const s = String(name || "");
  let m = /지하\s*(\d+)/.exec(s) || /\bB\s*(\d+)/i.exec(s);
  if (m) return -Number(m[1]);
  m = /(\d+)\s*(층|F\b)/i.exec(s);
  if (m) return Number(m[1]);
  return 1;
}

const int = (v, name, min, max) => {
  const n = Number(v);
  if (!Number.isFinite(n) || Math.round(n) !== n || n < min || n > max) throw badRequest(`${name}이(가) 올바르지 않습니다`);
  return n;
};

function cleanImage(v) {
  if (v === null || v === "") return null;
  const rel = String(v).replace(/^\/?uploads\//, "");
  if (!IMAGE_RE.test(rel)) throw badRequest("도면 그림 경로가 올바르지 않습니다(먼저 그림을 올리세요)");
  return rel;
}

function locationRow(db, id) {
  return db.prepare("SELECT id, kind, name, parent_id FROM locations WHERE id = ?").get(String(id || ""));
}

function cleanShapes(ctx, plan, list) {
  if (!Array.isArray(list)) throw badRequest("실 모양 목록이 올바르지 않습니다");
  if (list.length > MAX_SHAPES) throw badRequest(`실은 도면 하나에 ${MAX_SHAPES}개까지 그릴 수 있습니다`);
  const db = ctx.db;
  // 다른 도면에 이미 그린 장소는 다시 그리지 않는다(한 장소 = 한 자리)
  const elsewhere = new Map();
  for (const p of db.prepare("SELECT id, location_id, shapes FROM floor_plans WHERE id <> ?").all(plan.id)) {
    for (const s of safeJson(p.shapes)) elsewhere.set(s.location_id, p.location_id);
  }
  const seen = new Set();
  const W = plan.width || 100000;
  const H = plan.height || 100000;
  return list.map((s) => {
    const loc = locationRow(db, s && s.location_id);
    if (!loc) throw badRequest("없는 장소를 그렸습니다");
    if (!SHAPE_KINDS.includes(loc.kind)) throw badRequest(`${loc.name}: 실·구역·보관함만 도면에 그릴 수 있습니다`);
    if (seen.has(loc.id)) throw badRequest(`${loc.name}을(를) 두 번 그렸습니다`);
    if (elsewhere.has(loc.id)) {
      const host = locationRow(db, elsewhere.get(loc.id));
      throw conflict(`${loc.name}은(는) 이미 ${host ? host.name : "다른"} 도면에 있습니다`);
    }
    seen.add(loc.id);
    const pts = (Array.isArray(s.pts) ? s.pts : []).slice(0, MAX_POINTS).map((p) => {
      const x = Number(p && p[0]);
      const y = Number(p && p[1]);
      if (!Number.isFinite(x) || !Number.isFinite(y)) throw badRequest(`${loc.name}: 점 좌표가 올바르지 않습니다`);
      return [Math.round(Math.min(W, Math.max(0, x)) * 10) / 10, Math.round(Math.min(H, Math.max(0, y)) * 10) / 10];
    });
    if (pts.length < 3) throw badRequest(`${loc.name}: 점이 세 개 이상이어야 합니다`);
    return { location_id: loc.id, pts };
  });
}

function cleanMarks(plan, list) {
  if (!Array.isArray(list)) throw badRequest("표시 목록이 올바르지 않습니다");
  if (list.length > MAX_MARKS) throw badRequest(`표시는 ${MAX_MARKS}개까지입니다`);
  const W = plan.width || 100000;
  const H = plan.height || 100000;
  return list.map((m) => {
    if (!m || !MARK_TYPES.includes(m.type)) throw badRequest("표시 종류는 현관·계단·승강기입니다");
    const x = Number(m.x);
    const y = Number(m.y);
    if (!Number.isFinite(x) || !Number.isFinite(y)) throw badRequest("표시 위치가 올바르지 않습니다");
    return { type: m.type, x: Math.round(Math.min(W, Math.max(0, x))), y: Math.round(Math.min(H, Math.max(0, y))), label: String(m.label || "").trim().slice(0, 30) };
  });
}

export function createPlan(ctx, actor, input = {}) {
  const db = ctx.db;
  const host = locationRow(db, input.location_id);
  if (!host) throw badRequest("도면을 붙일 건물·층을 고르세요");
  if (!PLAN_HOSTS.includes(host.kind)) throw badRequest("도면은 건물이나 층에 붙입니다");
  if (db.prepare("SELECT 1 FROM floor_plans WHERE location_id = ?").get(host.id)) throw conflict(`${host.name}에는 이미 도면이 있습니다`);
  const t = nowIso();
  const plan = {
    id: newId(), location_id: host.id, image: input.image ? cleanImage(input.image) : null,
    width: input.width ? int(input.width, "그림 너비", 1, 20000) : 0, height: input.height ? int(input.height, "그림 높이", 1, 20000) : 0,
    level: input.level !== undefined && input.level !== "" ? int(input.level, "층", -10, 200) : guessLevel(host.name),
    meters_per_px: null, shapes: "[]", marks: "[]", created_at: t, updated_at: t,
  };
  db.prepare(`INSERT INTO floor_plans(id, location_id, image, width, height, level, meters_per_px, shapes, marks, created_at, updated_at)
    VALUES(@id, @location_id, @image, @width, @height, @level, @meters_per_px, @shapes, @marks, @created_at, @updated_at)`).run(plan);
  logEvent(db, actor, { action: "settings", location_id: host.id, summary: `도면 추가: ${host.name}` });
  return getPlan(ctx, plan.id);
}

export function updatePlan(ctx, actor, id, input = {}) {
  const db = ctx.db;
  const cur = db.prepare("SELECT * FROM floor_plans WHERE id = ?").get(String(id || ""));
  if (!cur) throw notFound("도면이 없습니다");
  const next = { ...cur };
  if (input.image !== undefined) next.image = cleanImage(input.image);
  if (input.width !== undefined) next.width = int(input.width, "그림 너비", 1, 20000);
  if (input.height !== undefined) next.height = int(input.height, "그림 높이", 1, 20000);
  if (input.level !== undefined) next.level = int(input.level, "층", -10, 200);
  if (input.meters_per_px !== undefined) {
    const m = input.meters_per_px === null || input.meters_per_px === "" ? null : Number(input.meters_per_px);
    if (m !== null && (!Number.isFinite(m) || m <= 0 || m > 10)) throw badRequest("축척이 올바르지 않습니다");
    next.meters_per_px = m;
  }
  if (input.shapes !== undefined) next.shapes = JSON.stringify(cleanShapes(ctx, next, input.shapes));
  if (input.marks !== undefined) next.marks = JSON.stringify(cleanMarks(next, input.marks));
  next.updated_at = nowIso();
  db.prepare(`UPDATE floor_plans SET image = @image, width = @width, height = @height, level = @level, meters_per_px = @meters_per_px,
    shapes = @shapes, marks = @marks, updated_at = @updated_at WHERE id = @id`).run(next);
  // 실 배치는 자주 저장된다 → 그림·층이 바뀔 때만 기록에 남긴다
  if (input.image !== undefined || input.level !== undefined) {
    const host = locationRow(db, cur.location_id);
    logEvent(db, actor, { action: "settings", location_id: cur.location_id, summary: `도면 ${input.image !== undefined ? "그림 바꿈" : "층 바꿈"}: ${host ? host.name : ""}` });
  }
  return getPlan(ctx, id);
}

export function deletePlan(ctx, actor, id) {
  const db = ctx.db;
  const cur = db.prepare("SELECT * FROM floor_plans WHERE id = ?").get(String(id || ""));
  if (!cur) throw notFound("도면이 없습니다");
  db.prepare("DELETE FROM floor_plans WHERE id = ?").run(cur.id);
  const host = locationRow(db, cur.location_id);
  logEvent(db, actor, { action: "settings", location_id: cur.location_id, summary: `도면 지움: ${host ? host.name : ""}` });
  return { ok: true };
}

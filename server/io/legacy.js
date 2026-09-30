// 예전 inni(PHP·SQLite) 데이터 가져오기. 아이디와 QR 값을 그대로 옮겨서, 이미 붙인 라벨이 계속 통한다.
// 새 inni 에 물품이 하나도 없을 때만 가져온다(섞이지 않게).
import fs from "node:fs";
import path from "node:path";
import Database from "better-sqlite3";
import { tx } from "../lib/db.js";
import { newId, nowIso, badRequest, conflict, jsonParse } from "../lib/util.js";
import { logEvent } from "../lib/events.js";
import { setSetting } from "../lib/db.js";
import { sniffImage } from "../lib/uploads.js";

const DEMO_USERS = new Set(["demo-owner", "demo-teacher"]);

export function detectLegacy(ctx, file = ctx.cfg.legacyDb) {
  if (!file || !fs.existsSync(file)) return { found: false, path: file };
  try {
    const db = new Database(file, { readonly: true, fileMustExist: true });
    const has = (t) => Boolean(db.prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?").get(t));
    if (!has("catalog_items") || !has("assets")) {
      db.close();
      return { found: false, path: file, reason: "예전 inni 형식이 아닙니다" };
    }
    const n = (t) => (has(t) ? db.prepare(`SELECT COUNT(*) n FROM ${t}`).get().n : 0);
    const school = has("settings") ? (db.prepare("SELECT value FROM settings WHERE key = 'school_name'").get() || {}).value : "";
    const demo = db.prepare("SELECT COUNT(*) n FROM users WHERE id IN ('demo-owner','demo-teacher')").get().n;
    const out = {
      found: true, path: file, school: school || "", counts: { items: n("catalog_items"), assets: n("assets"), stocks: n("stock_lots"), locations: n("locations"), loans: n("loans"), reports: n("reports"), users: n("users"), logs: n("activity_logs") },
      demo_only: demo > 0 && n("users") === demo,
    };
    db.close();
    return out;
  } catch (e) {
    return { found: false, path: file, reason: e.message };
  }
}

function copyPhoto(ctx, legacyRel, photoRoots) {
  if (!legacyRel) return null;
  const rel = String(legacyRel).replace(/^\/?(public\/)?/, "").replace(/^uploads\//, "");
  for (const root of photoRoots) {
    const src = path.join(root, rel);
    if (!src.startsWith(root) || !fs.existsSync(src)) continue;
    const buf = fs.readFileSync(src);
    const type = sniffImage(buf);
    if (!type) return null;
    const out = `legacy/${newId()}.${type.ext}`;
    fs.mkdirSync(path.join(ctx.cfg.uploadsDir, "legacy"), { recursive: true });
    fs.writeFileSync(path.join(ctx.cfg.uploadsDir, out), buf);
    return out;
  }
  return null;
}

export function importLegacy(ctx, actor, { file = ctx.cfg.legacyDb, includeDemoUsers = false, includeLogs = true } = {}) {
  const info = detectLegacy(ctx, file);
  if (!info.found) throw badRequest(`예전 데이터를 찾지 못했습니다: ${info.reason || file}`);
  const db = ctx.db;
  if (db.prepare("SELECT 1 FROM items LIMIT 1").get()) throw conflict("이미 물품이 있어 가져올 수 없습니다. 새로 설치한 inni 에서 가져오세요.");
  const old = new Database(file, { readonly: true });
  const has = (t) => Boolean(old.prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?").get(t));
  const cols = (t) => new Set(old.prepare(`PRAGMA table_info(${t})`).all().map((c) => c.name));
  const photoRoots = [ctx.cfg.legacyUploads, path.join(path.dirname(file), "..", "public", "uploads"), path.join(path.dirname(file), "uploads")].map((p) => path.resolve(p));
  const t = nowIso();
  const skipUser = (id) => !includeDemoUsers && DEMO_USERS.has(id);
  const userIds = new Set();
  const report = { users: 0, locations: 0, categories: 0, items: 0, units: 0, stocks: 0, loans: 0, repairs: 0, events: 0, photos: 0 };

  tx(db, () => {
    for (const u of old.prepare("SELECT * FROM users").all()) {
      if (skipUser(u.id)) continue;
      const exists = db.prepare("SELECT id FROM users WHERE email = ?").get(u.email);
      if (exists) { userIds.add(u.id); continue; }
      db.prepare("INSERT INTO users(id, email, name, role, status, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?)")
        .run(u.id, String(u.email).toLowerCase(), u.display_name || u.email, ["owner", "manager", "teacher", "student"].includes(u.role) ? u.role : "teacher", ["pending", "active", "disabled"].includes(u.status) ? u.status : "pending", u.created_at || t, u.updated_at || t);
      userIds.add(u.id);
      report.users += 1;
    }
    const uid = (id) => (id && userIds.has(id) ? id : null);

    // 장소: 부모부터
    const locs = old.prepare("SELECT * FROM locations ORDER BY sort_order").all();
    const done = new Set();
    let guard = 0;
    while (done.size < locs.length && guard++ < 50) {
      for (const l of locs) {
        if (done.has(l.id) || (l.parent_id && !done.has(l.parent_id) && locs.some((x) => x.id === l.parent_id))) continue;
        db.prepare(`INSERT INTO locations(id, parent_id, kind, name, code, description, sort, qr, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`)
          .run(l.id, l.parent_id && locs.some((x) => x.id === l.parent_id) ? l.parent_id : null, ["building", "floor", "room", "zone", "storage", "bin"].includes(l.kind) ? l.kind : "room", l.name, l.code || null, l.notes || null, l.sort_order || 0, l.qr_code || `L${newId()}`, l.created_at || t, l.updated_at || t);
        done.add(l.id);
        report.locations += 1;
      }
    }
    if (has("categories")) {
      for (const c of old.prepare("SELECT * FROM categories").all()) {
        db.prepare("INSERT INTO categories(id, parent_id, name, sort, created_at) VALUES(?, NULL, ?, ?, ?)").run(c.id, c.name, c.sort_order || 0, t);
        report.categories += 1;
      }
    }
    const ic = cols("catalog_items");
    for (const it of old.prepare("SELECT * FROM catalog_items").all()) {
      const image = copyPhoto(ctx, it.image_path, photoRoots);
      if (image) report.photos += 1;
      const tags = jsonParse(it.tags, []);
      db.prepare(`INSERT INTO items(id, name, kind, description, tags, unit, min_stock, edufine_number, manufacturer, budget_program, budget_year, image, thumb, favorite, qr, created_at, updated_at)
        VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`).run(
        it.id, it.name, ["equipment", "fixture", "consumable", "part"].includes(it.type) ? it.type : "equipment", it.description || null,
        JSON.stringify(Array.isArray(tags) ? tags : []), !it.unit || it.unit === "ea" ? "개" : it.unit, it.min_stock ?? null, it.edufine_number || null,
        it.manufacturer || null, ic.has("budget_program") ? it.budget_program || null : null, ic.has("budget_year") ? it.budget_year || null : null,
        image, image, it.favorite ? 1 : 0, it.qr_code || `I${newId()}`, it.created_at || t, it.updated_at || t,
      );
      report.items += 1;
    }
    const ac = cols("assets");
    for (const a of old.prepare("SELECT * FROM assets").all()) {
      const image = copyPhoto(ctx, a.image_path, photoRoots);
      if (image) report.photos += 1;
      const status = a.status === "moving" ? "available" : ["available", "on_loan", "repair", "lost", "retired"].includes(a.status) ? a.status : "available";
      db.prepare(`INSERT INTO assets(id, item_id, label, management_number, serial_number, edufine_number, status, location_id, image, thumb, notes, purchase_date, useful_life_years, budget_program, budget_year, qr, retired_at, retire_kind, retire_reason, retire_evidence, created_at, updated_at)
        VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`).run(
        a.id, a.catalog_item_id, a.name || null, a.management_number || null, a.serial_number || null, a.edufine_number || null, status, a.location_id,
        image, image, a.notes || null, ac.has("purchase_date") ? a.purchase_date || null : null, ac.has("useful_life_years") ? a.useful_life_years || null : null,
        ac.has("budget_program") ? a.budget_program || null : null, ac.has("budget_year") ? a.budget_year || null : null, a.qr_code || `A${newId()}`,
        ac.has("retired_at") ? a.retired_at || null : null, ac.has("retire_kind") ? a.retire_kind || null : null, ac.has("retire_reason") ? a.retire_reason || null : null,
        ac.has("retire_evidence") ? a.retire_evidence || null : null, a.created_at || t, a.updated_at || t,
      );
      report.units += 1;
    }
    const sc = cols("stock_lots");
    for (const s of old.prepare("SELECT * FROM stock_lots").all()) {
      db.prepare("INSERT INTO stocks(id, item_id, location_id, quantity, lot_code, expires_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?)")
        .run(s.id, s.catalog_item_id, s.location_id, Math.max(0, Number(s.quantity) || 0), sc.has("lot_code") ? s.lot_code || "" : "", sc.has("expires_at") ? s.expires_at || null : null, s.updated_at || t);
      report.stocks += 1;
    }
    for (const l of old.prepare("SELECT * FROM loans").all()) {
      const itemId = l.catalog_item_id || (l.asset_id && (db.prepare("SELECT item_id FROM assets WHERE id = ?").get(l.asset_id) || {}).item_id);
      if (!itemId) continue;
      const status = l.status === "returned" ? "returned" : "active";
      if (status === "active" && l.asset_id && db.prepare("SELECT 1 FROM loans WHERE asset_id = ? AND status = 'active'").get(l.asset_id)) continue;
      db.prepare(`INSERT INTO loans(id, item_id, asset_id, quantity, from_location_id, borrower_user_id, borrower_name, borrower_note, purpose, due_at, status, returned_at, return_condition, return_note, created_by, created_at)
        VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`).run(
        l.id, itemId, l.asset_id || null, l.quantity || 1, l.from_location_id || null, uid(l.borrower_user_id), l.borrower_name || "?", l.borrower_note || null,
        l.purpose || null, l.due_at || null, status, l.returned_at || null, l.return_condition || null, l.return_note || null, uid(l.created_by), l.created_at || t,
      );
      report.loans += 1;
    }
    if (has("reports")) {
      const rc = cols("reports");
      for (const r of old.prepare("SELECT * FROM reports").all()) {
        db.prepare(`INSERT INTO repairs(id, target_type, target_id, title, body, status, urgency, reporter_id, reporter_name, cost_amount, cost_vendor, cost_budget, cost_at, created_at, updated_at)
          VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`).run(
          r.id, r.target_type === "room" ? "location" : "asset", r.target_id, r.title, r.body || "", ["open", "in_progress", "done", "rejected"].includes(r.status) ? r.status : "open",
          rc.has("urgency") && r.urgency === "urgent" ? "urgent" : "normal", uid(r.reporter_user_id), r.reporter_name || "?", rc.has("cost_amount") ? r.cost_amount ?? null : null,
          rc.has("cost_vendor") ? r.cost_vendor || null : null, rc.has("cost_budget_line") ? r.cost_budget_line || null : null, rc.has("cost_at") ? r.cost_at || null : null, r.created_at || t, r.updated_at || t,
        );
        report.repairs += 1;
      }
    }
    if (includeLogs && has("activity_logs")) {
      for (const e of old.prepare("SELECT * FROM activity_logs ORDER BY created_at").all()) {
        const f = { item_id: null, asset_id: null, location_id: null };
        if (e.entity_type === "asset") { f.asset_id = e.entity_id; f.item_id = (db.prepare("SELECT item_id FROM assets WHERE id = ?").get(e.entity_id) || {}).item_id || null; }
        else if (e.entity_type === "catalog_item") f.item_id = e.entity_id;
        else if (e.entity_type === "location") f.location_id = e.entity_id;
        db.prepare("INSERT INTO events(id, at, actor_id, actor_name, action, item_id, asset_id, location_id, summary, via) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, 'import')")
          .run(e.id, e.created_at || t, uid(e.actor_id), e.actor_name || "?", `legacy_${e.action}`.slice(0, 40), f.item_id, f.asset_id, f.location_id, `[예전] ${e.summary}`);
        report.events += 1;
      }
    }
    if (info.school) setSetting(db, "school", { name: info.school, short_name: "" });
    logEvent(db, actor, { action: "import", summary: `예전 inni 데이터 가져오기: 품목 ${report.items}, 장비 ${report.units}, 재고 ${report.stocks}, 장소 ${report.locations}, 대여 ${report.loans}` });
  });
  old.close();
  ctx.changed({ kind: "import" });
  return report;
}

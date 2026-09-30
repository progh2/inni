// 장소·품목·개체(장비 한 대)·재고. 화면에 내려줄 모양(view)도 여기서 만든다.
import {
  newId, nowIso, randomCode, str, optStr, reqStr, optNum, optDate, optYear, oneOf, tagsFrom, badRequest, notFound, conflict, fmtQty,
} from "./util.js";
import { tx } from "./db.js";
import { logEvent, listEvents } from "./events.js";
import { subtree, lifeEnd } from "./snapshot.js";

export const KINDS = ["equipment", "fixture", "consumable", "part"];
export const KIND_LABEL = { equipment: "장비", fixture: "비품", consumable: "소모품", part: "부품" };
export const LOCATION_KINDS = ["building", "floor", "room", "zone", "storage", "bin"];
export const LOCATION_KIND_LABEL = { building: "건물", floor: "층", room: "실", zone: "구역", storage: "보관함", bin: "칸" };
export const ASSET_STATUS = ["available", "on_loan", "repair", "lost", "retired"];
export const ASSET_STATUS_LABEL = { available: "보관 중", on_loan: "대여 중", repair: "수리 중", lost: "분실", retired: "폐기" };

// ---------------------------------------------------------------- 코드
export function uniqueQr(db, prefix) {
  for (let i = 0; i < 20; i++) {
    const code = `${prefix}${randomCode(7)}`;
    const hit = db.prepare("SELECT 1 FROM items WHERE qr = ? UNION SELECT 1 FROM assets WHERE qr = ? UNION SELECT 1 FROM locations WHERE qr = ?").get(code, code, code);
    if (!hit) return code;
  }
  throw new Error("QR 코드를 만들지 못했습니다");
}

// "전장-2026-" → 다음 번호 "전장-2026-018". 앞머리가 없으면 올해-0001
export function nextManagementNumbers(db, prefix, count = 1) {
  let p = str(prefix, 40);
  if (!p) p = `${new Date().getFullYear()}-`;
  const rows = db.prepare("SELECT management_number FROM assets WHERE management_number LIKE ? ESCAPE '\\'").all(`${p.replace(/[\\%_]/g, (c) => `\\${c}`)}%`);
  let max = 0;
  let width = 3;
  for (const r of rows) {
    const rest = r.management_number.slice(p.length);
    if (!/^\d+$/.test(rest)) continue;
    max = Math.max(max, Number(rest));
    width = Math.max(width, rest.length);
  }
  const out = [];
  for (let i = 1; i <= count; i++) out.push(`${p}${String(max + i).padStart(width, "0")}`);
  return out;
}

// ---------------------------------------------------------------- 장소
export function createLocation(ctx, actor, input) {
  const db = ctx.db;
  const name = reqStr(input.name, "장소 이름", 60);
  const kind = oneOf(str(input.kind) || "room", LOCATION_KINDS, "장소 종류");
  const parentId = optStr(input.parent_id, 40);
  if (parentId && !db.prepare("SELECT 1 FROM locations WHERE id = ?").get(parentId)) throw badRequest("상위 장소가 없습니다");
  const t = nowIso();
  const row = {
    id: newId(), parent_id: parentId, kind, name, code: optStr(input.code, 30), description: optStr(input.description, 500),
    manager_id: optStr(input.manager_id, 40), image: optStr(input.image, 200), sort: Number(input.sort) || 0, qr: uniqueQr(db, "L"),
    created_at: t, updated_at: t,
  };
  tx(db, () => {
    db.prepare(`INSERT INTO locations(id, parent_id, kind, name, code, description, manager_id, image, sort, qr, created_at, updated_at)
      VALUES(@id, @parent_id, @kind, @name, @code, @description, @manager_id, @image, @sort, @qr, @created_at, @updated_at)`).run(row);
    logEvent(db, actor, { action: "location", location_id: row.id, summary: `장소 "${name}"을(를) 만들었습니다` });
  });
  ctx.changed({ kind: "location", id: row.id });
  return row;
}

export function updateLocation(ctx, actor, id, input) {
  const db = ctx.db;
  const cur = db.prepare("SELECT * FROM locations WHERE id = ?").get(id);
  if (!cur) throw notFound("장소가 없습니다");
  const next = { ...cur };
  if (input.name !== undefined) next.name = reqStr(input.name, "장소 이름", 60);
  if (input.kind !== undefined) next.kind = oneOf(str(input.kind), LOCATION_KINDS, "장소 종류");
  if (input.code !== undefined) next.code = optStr(input.code, 30);
  if (input.description !== undefined) next.description = optStr(input.description, 500);
  if (input.manager_id !== undefined) next.manager_id = optStr(input.manager_id, 40);
  if (input.image !== undefined) next.image = optStr(input.image, 200);
  if (input.sort !== undefined) next.sort = Number(input.sort) || 0;
  if (input.parent_id !== undefined) {
    const p = optStr(input.parent_id, 40);
    if (p) {
      if (p === id) throw badRequest("자기 자신 아래로 옮길 수 없습니다");
      const snap = ctx.snapshot();
      if (subtree(snap, id).has(p)) throw badRequest("하위 장소 아래로 옮길 수 없습니다");
      if (!snap.locations.get(p)) throw badRequest("상위 장소가 없습니다");
    }
    next.parent_id = p;
  }
  next.updated_at = nowIso();
  tx(db, () => {
    db.prepare(`UPDATE locations SET parent_id=@parent_id, kind=@kind, name=@name, code=@code, description=@description,
      manager_id=@manager_id, image=@image, sort=@sort, updated_at=@updated_at WHERE id=@id`).run(next);
    logEvent(db, actor, { action: "location", location_id: id, summary: `장소 "${next.name}" 정보를 고쳤습니다` });
  });
  ctx.changed({ kind: "location", id });
  return next;
}

export function deleteLocation(ctx, actor, id) {
  const db = ctx.db;
  const cur = db.prepare("SELECT * FROM locations WHERE id = ?").get(id);
  if (!cur) throw notFound("장소가 없습니다");
  const kids = db.prepare("SELECT COUNT(*) n FROM locations WHERE parent_id = ? AND archived_at IS NULL").get(id).n;
  const units = db.prepare("SELECT COUNT(*) n FROM assets WHERE location_id = ? AND status != 'retired'").get(id).n;
  const stock = db.prepare("SELECT COUNT(*) n FROM stocks WHERE location_id = ? AND quantity > 0").get(id).n;
  if (kids || units || stock) {
    throw conflict(`이 장소에 하위 장소 ${kids}곳, 장비 ${units}대, 재고 ${stock}건이 있어 지울 수 없습니다. 먼저 옮겨 주세요.`);
  }
  tx(db, () => {
    db.prepare("DELETE FROM stocks WHERE location_id = ? AND quantity = 0").run(id);
    const used = db.prepare("SELECT (SELECT COUNT(*) FROM assets WHERE location_id = ?) + (SELECT COUNT(*) FROM stocks WHERE location_id = ?) + (SELECT COUNT(*) FROM audits WHERE location_id = ?) n").get(id, id, id).n;
    if (used) db.prepare("UPDATE locations SET archived_at = ?, updated_at = ? WHERE id = ?").run(nowIso(), nowIso(), id);
    else db.prepare("DELETE FROM locations WHERE id = ?").run(id);
    logEvent(db, actor, { action: "location", location_id: id, summary: `장소 "${cur.name}"을(를) 지웠습니다` });
  });
  ctx.changed({ kind: "location", id });
}

export function locationView(snap, l) {
  if (!l) return null;
  const la = snap.locAgg.get(l.id);
  let items = 0;
  let units = 0;
  let alerts = 0;
  const all = new Set();
  for (const id of subtree(snap, l.id)) {
    const a = snap.locAgg.get(id);
    if (!a) continue;
    for (const x of a.items) all.add(x);
    units += a.units;
    alerts += a.alerts;
  }
  items = all.size;
  const mgr = l.manager_id && snap.users.get(l.manager_id);
  return {
    id: l.id, parent_id: l.parent_id, kind: l.kind, kind_label: LOCATION_KIND_LABEL[l.kind], name: l.name, code: l.code,
    description: l.description, image: l.image, sort: l.sort, qr: l.qr, path: l.path, path_ids: l.path_ids, depth: l.depth,
    room_id: l.room_id, children: l.children, manager: mgr ? { id: mgr.id, name: mgr.name } : null,
    direct_items: la ? la.items.size : 0, items, units, alerts,
  };
}

export function listLocations(ctx) {
  const snap = ctx.snapshot();
  return [...snap.locations.values()].map((l) => locationView(snap, l));
}

// 한 장소(하위 포함)의 물건들
export function locationContents(ctx, id, { deep = true } = {}) {
  const snap = ctx.snapshot();
  const l = snap.locations.get(id);
  if (!l) throw notFound("장소가 없습니다");
  const scope = deep ? subtree(snap, id) : new Set([id]);
  const byItem = new Map();
  const touch = (itemId) => {
    if (!byItem.has(itemId)) byItem.set(itemId, { units: [], stocks: [] });
    return byItem.get(itemId);
  };
  for (const a of snap.assets.values()) {
    if (a.status === "retired" || !scope.has(a.location_id)) continue;
    const it = snap.items.get(a.item_id);
    if (!it || it.archived_at) continue;
    touch(a.item_id).units.push(unitView(snap, a));
  }
  for (const s of snap.stocks.values()) {
    if (!scope.has(s.location_id)) continue;
    const it = snap.items.get(s.item_id);
    if (!it || it.archived_at) continue;
    touch(s.item_id).stocks.push(stockView(snap, s));
  }
  const rows = [];
  for (const [itemId, v] of byItem) {
    const card = itemCard(snap, snap.items.get(itemId));
    card.here = {
      units: v.units.length,
      available: v.units.filter((u) => u.status === "available").length,
      qty: Math.round(v.stocks.reduce((s, x) => s + x.quantity, 0) * 100) / 100,
      unit_list: v.units.slice(0, 50),
      stock_list: v.stocks,
    };
    rows.push(card);
  }
  rows.sort((a, b) => a.name.localeCompare(b.name, "ko"));
  return { location: locationView(snap, l), items: rows };
}

// ---------------------------------------------------------------- 분류
export function listCategories(ctx) {
  return [...ctx.snapshot().categories.values()];
}

export function createCategory(ctx, actor, input) {
  const name = reqStr(input.name, "분류 이름", 40);
  const hit = ctx.db.prepare("SELECT * FROM categories WHERE name = ?").get(name);
  if (hit) return hit;
  const row = { id: newId(), parent_id: optStr(input.parent_id, 40), name, sort: Number(input.sort) || 0, created_at: nowIso() };
  ctx.db.prepare("INSERT INTO categories(id, parent_id, name, sort, created_at) VALUES(@id, @parent_id, @name, @sort, @created_at)").run(row);
  ctx.changed({ kind: "category", id: row.id });
  return row;
}

export function updateCategory(ctx, actor, id, input) {
  const cur = ctx.db.prepare("SELECT * FROM categories WHERE id = ?").get(id);
  if (!cur) throw notFound("분류가 없습니다");
  const name = input.name !== undefined ? reqStr(input.name, "분류 이름", 40) : cur.name;
  ctx.db.prepare("UPDATE categories SET name = ?, sort = ? WHERE id = ?").run(name, input.sort !== undefined ? Number(input.sort) || 0 : cur.sort, id);
  ctx.changed({ kind: "category", id });
  return { ...cur, name };
}

export function deleteCategory(ctx, actor, id) {
  ctx.db.prepare("DELETE FROM categories WHERE id = ?").run(id);
  ctx.changed({ kind: "category", id });
}

// ---------------------------------------------------------------- 품목 입력
const ITEM_FIELDS = {
  name: (v) => reqStr(v, "이름", 120),
  kind: (v) => oneOf(str(v), KINDS, "종류"),
  category_id: (v) => optStr(v, 40),
  aliases: (v) => str(Array.isArray(v) ? v.join(", ") : v, 300),
  manufacturer: (v) => optStr(v, 80),
  model: (v) => optStr(v, 80),
  spec: (v) => optStr(v, 500),
  description: (v) => optStr(v, 2000),
  unit: (v) => str(v, 12) || "개",
  min_stock: (v) => optNum(v, "최소 재고", { min: 0, max: 1e9 }),
  tags: (v) => JSON.stringify(tagsFrom(v)),
  image: (v) => optStr(v, 200),
  thumb: (v) => optStr(v, 200),
  price: (v) => optNum(v, "가격", { min: 0, max: 1e12 }),
  vendor: (v) => optStr(v, 80),
  product_url: (v) => {
    const s = optStr(v, 1000);
    if (s && !/^https?:\/\//i.test(s)) throw badRequest("제품 링크는 http:// 또는 https:// 로 시작해야 합니다");
    return s;
  },
  barcode: (v) => optStr(v, 60),
  edufine_number: (v) => optStr(v, 60),
  class_number: (v) => optStr(v, 20),
  budget_program: (v) => optStr(v, 100),
  budget_year: (v) => optYear(v, "예산 연도"),
  useful_life_years: (v) => optNum(v, "내용연수", { min: 1, max: 100, integer: true }),
  notes: (v) => optStr(v, 2000),
  favorite: (v) => (v ? 1 : 0),
};

function readItemFields(input, { partial = false } = {}) {
  const out = {};
  for (const [k, fn] of Object.entries(ITEM_FIELDS)) {
    if (input[k] === undefined) {
      if (!partial && (k === "name" || k === "kind")) out[k] = fn(input[k]);
      continue;
    }
    out[k] = fn(input[k]);
  }
  return out;
}

function readUnitFields(input) {
  const out = {};
  if (input.label !== undefined) out.label = optStr(input.label, 60);
  if (input.management_number !== undefined) out.management_number = optStr(input.management_number, 60);
  if (input.serial_number !== undefined) out.serial_number = optStr(input.serial_number, 80);
  if (input.edufine_number !== undefined) out.edufine_number = optStr(input.edufine_number, 60);
  if (input.notes !== undefined) out.notes = optStr(input.notes, 2000);
  if (input.image !== undefined) out.image = optStr(input.image, 200);
  if (input.thumb !== undefined) out.thumb = optStr(input.thumb, 200);
  if (input.purchase_date !== undefined) out.purchase_date = optDate(input.purchase_date, "도입일");
  if (input.purchase_price !== undefined) out.purchase_price = optNum(input.purchase_price, "구입 가격", { min: 0, max: 1e12 });
  if (input.useful_life_years !== undefined) out.useful_life_years = optNum(input.useful_life_years, "내용연수", { min: 1, max: 100, integer: true });
  if (input.budget_program !== undefined) out.budget_program = optStr(input.budget_program, 100);
  if (input.budget_year !== undefined) out.budget_year = optYear(input.budget_year, "예산 연도");
  return out;
}

function requireLocation(db, id, what = "위치") {
  if (!id) throw badRequest(`${what}를 고르세요`);
  const l = db.prepare("SELECT id, name, archived_at FROM locations WHERE id = ?").get(id);
  if (!l || l.archived_at) throw badRequest(`${what}가 없습니다`);
  return l;
}

function insertUnits(db, item, units, { locationId, defaults = {}, t }) {
  const created = [];
  const stmt = db.prepare(`INSERT INTO assets(id, item_id, label, management_number, serial_number, edufine_number, status, location_id, image, thumb, notes,
    purchase_date, purchase_price, useful_life_years, budget_program, budget_year, qr, created_at, updated_at)
    VALUES(@id, @item_id, @label, @management_number, @serial_number, @edufine_number, 'available', @location_id, @image, @thumb, @notes,
    @purchase_date, @purchase_price, @useful_life_years, @budget_program, @budget_year, @qr, @t, @t)`);
  for (const u of units) {
    const f = { ...defaults, ...readUnitFields(u) };
    const row = {
      id: newId(), item_id: item.id, label: f.label ?? null, management_number: f.management_number ?? null, serial_number: f.serial_number ?? null,
      edufine_number: f.edufine_number ?? null, location_id: u.location_id || locationId, image: f.image ?? null, thumb: f.thumb ?? null,
      notes: f.notes ?? null, purchase_date: f.purchase_date ?? null, purchase_price: f.purchase_price ?? null,
      useful_life_years: f.useful_life_years ?? null, budget_program: f.budget_program ?? null, budget_year: f.budget_year ?? null,
      qr: uniqueQr(db, "A"), t,
    };
    if (row.management_number && db.prepare("SELECT 1 FROM assets WHERE management_number = ?").get(row.management_number)) {
      throw conflict(`관리번호 ${row.management_number}은(는) 이미 있습니다`);
    }
    requireLocation(db, row.location_id);
    stmt.run(row);
    created.push(row);
  }
  return created;
}

/**
 * 새 품목. 장비는 개체(한 대씩)를, 나머지는 위치별 수량을 함께 만든다.
 * input: 품목 필드 + { location_id, quantity, lot_code, expires_at, units:[...] | unit_count, number_prefix, unit_defaults }
 */
export function createItem(ctx, actor, input) {
  const db = ctx.db;
  const f = readItemFields(input);
  const t = nowIso();
  const item = {
    id: newId(), category_id: null, aliases: "", manufacturer: null, model: null, spec: null, description: null, unit: "개",
    min_stock: null, tags: "[]", image: null, thumb: null, price: null, vendor: null, product_url: null, barcode: null,
    edufine_number: null, class_number: null, budget_program: null, budget_year: null, useful_life_years: null, notes: null, favorite: 0,
    ...f, qr: null, created_by: actor ? actor.id : null, created_at: t, updated_at: t,
  };
  const locationId = optStr(input.location_id, 40);
  let created = { units: [], stock: null };
  tx(db, () => {
    item.qr = uniqueQr(db, "I");
    if (item.category_id && !db.prepare("SELECT 1 FROM categories WHERE id = ?").get(item.category_id)) item.category_id = null;
    db.prepare(`INSERT INTO items(id, name, kind, category_id, aliases, manufacturer, model, spec, description, unit, min_stock, tags, image, thumb,
      price, vendor, product_url, barcode, edufine_number, class_number, budget_program, budget_year, useful_life_years, favorite, qr, notes,
      created_by, created_at, updated_at)
      VALUES(@id, @name, @kind, @category_id, @aliases, @manufacturer, @model, @spec, @description, @unit, @min_stock, @tags, @image, @thumb,
      @price, @vendor, @product_url, @barcode, @edufine_number, @class_number, @budget_program, @budget_year, @useful_life_years, @favorite, @qr, @notes,
      @created_by, @created_at, @updated_at)`).run(item);
    let where = "";
    if (item.kind === "equipment") {
      let units = Array.isArray(input.units) ? input.units.slice(0, 500) : [];
      const count = Math.max(0, Math.min(500, Math.floor(Number(input.unit_count ?? (units.length ? 0 : 1)) || 0)));
      if (!units.length && count) {
        const numbers = input.auto_number === false ? [] : nextManagementNumbers(db, input.number_prefix, count);
        units = Array.from({ length: count }, (_, i) => ({ management_number: numbers[i] || null, label: count > 1 ? `#${i + 1}` : null }));
      }
      if (units.length) {
        requireLocation(db, locationId);
        created.units = insertUnits(db, item, units, { locationId, defaults: input.unit_defaults || {}, t });
        where = `${created.units.length}대`;
      }
    } else if (locationId) {
      requireLocation(db, locationId);
      const q = Math.max(0, Math.round((Number(input.quantity) || 0) * 100) / 100);
      const st = {
        id: newId(), item_id: item.id, location_id: locationId, quantity: q, lot_code: str(input.lot_code, 40),
        expires_at: optDate(input.expires_at, "유통기한"), updated_at: t,
      };
      db.prepare("INSERT INTO stocks(id, item_id, location_id, quantity, lot_code, expires_at, updated_at) VALUES(@id, @item_id, @location_id, @quantity, @lot_code, @expires_at, @updated_at)").run(st);
      created.stock = st;
      where = `${fmtQty(q)}${item.unit}`;
    }
    const locName = locationId ? (db.prepare("SELECT name FROM locations WHERE id = ?").get(locationId) || {}).name : "";
    logEvent(db, actor, {
      action: "create", item_id: item.id, location_id: locationId, qty: created.stock ? created.stock.quantity : created.units.length || null,
      summary: `${item.name} 등록${locName ? ` · ${locName}` : ""}${where ? ` ${where}` : ""}`,
    });
  });
  ctx.changed({ kind: "item", id: item.id });
  return { item, ...created };
}

export function updateItem(ctx, actor, id, input) {
  const db = ctx.db;
  const cur = db.prepare("SELECT * FROM items WHERE id = ?").get(id);
  if (!cur) throw notFound("품목이 없습니다");
  const f = readItemFields(input, { partial: true });
  if (f.kind && f.kind !== cur.kind) {
    const hasUnits = db.prepare("SELECT COUNT(*) n FROM assets WHERE item_id = ?").get(id).n;
    const hasStock = db.prepare("SELECT COUNT(*) n FROM stocks WHERE item_id = ?").get(id).n;
    if ((f.kind === "equipment" && hasStock) || (f.kind !== "equipment" && hasUnits)) {
      throw conflict("개체(한 대씩) 관리와 수량 관리는 서로 바꿀 수 없습니다. 새 품목으로 등록하세요.");
    }
  }
  const next = { ...cur, ...f, updated_at: nowIso() };
  const changed = Object.keys(f).filter((k) => String(f[k] ?? "") !== String(cur[k] ?? ""));
  if (!changed.length) return next;
  tx(db, () => {
    const sets = Object.keys(f).map((k) => `${k} = @${k}`).join(", ");
    db.prepare(`UPDATE items SET ${sets}, updated_at = @updated_at WHERE id = @id`).run({ ...f, updated_at: next.updated_at, id });
    const LABEL = { name: "이름", image: "사진", min_stock: "최소 재고", category_id: "분류", favorite: "즐겨찾기" };
    logEvent(db, actor, { action: "update", item_id: id, summary: `${next.name} 정보 수정 (${changed.map((k) => LABEL[k] || k).slice(0, 5).join(", ")})`, data: { fields: changed } });
  });
  ctx.changed({ kind: "item", id });
  return next;
}

export function setArchived(ctx, actor, id, archived) {
  const db = ctx.db;
  const cur = db.prepare("SELECT * FROM items WHERE id = ?").get(id);
  if (!cur) throw notFound("품목이 없습니다");
  if (archived) {
    const open = db.prepare("SELECT COUNT(*) n FROM loans WHERE item_id = ? AND status = 'active'").get(id).n;
    if (open) throw conflict(`대여 중인 것이 ${open}건 있어 보관함으로 옮길 수 없습니다. 먼저 반납하세요.`);
  }
  tx(db, () => {
    db.prepare("UPDATE items SET archived_at = ?, updated_at = ? WHERE id = ?").run(archived ? nowIso() : null, nowIso(), id);
    logEvent(db, actor, { action: archived ? "archive" : "unarchive", item_id: id, summary: `${cur.name} ${archived ? "보관함으로 옮김(목록에서 숨김)" : "보관 해제"}` });
  });
  ctx.changed({ kind: "item", id });
}

export function addUnits(ctx, actor, itemId, input) {
  const db = ctx.db;
  const item = db.prepare("SELECT * FROM items WHERE id = ?").get(itemId);
  if (!item) throw notFound("품목이 없습니다");
  if (item.kind !== "equipment") throw badRequest("수량으로 관리하는 품목입니다. 입고를 쓰세요.");
  const locationId = reqStr(input.location_id, "위치", 40);
  let units = Array.isArray(input.units) ? input.units.slice(0, 500) : [];
  const count = Math.max(0, Math.min(500, Math.floor(Number(input.unit_count) || 0)));
  let created = [];
  tx(db, () => {
    if (!units.length && count) {
      const numbers = input.auto_number === false ? [] : nextManagementNumbers(db, input.number_prefix, count);
      const have = db.prepare("SELECT COUNT(*) n FROM assets WHERE item_id = ?").get(itemId).n;
      units = Array.from({ length: count }, (_, i) => ({ management_number: numbers[i] || null, label: `#${have + i + 1}` }));
    }
    if (!units.length) throw badRequest("추가할 대수를 입력하세요");
    created = insertUnits(db, item, units, { locationId, defaults: input.unit_defaults || {}, t: nowIso() });
    const loc = db.prepare("SELECT name FROM locations WHERE id = ?").get(locationId);
    logEvent(db, actor, { action: "unit_add", item_id: itemId, location_id: locationId, qty: created.length, summary: `${item.name} ${created.length}대 추가 · ${loc ? loc.name : ""}` });
  });
  ctx.changed({ kind: "item", id: itemId });
  return created;
}

export function updateUnit(ctx, actor, id, input) {
  const db = ctx.db;
  const cur = db.prepare("SELECT * FROM assets WHERE id = ?").get(id);
  if (!cur) throw notFound("장비가 없습니다");
  const f = readUnitFields(input);
  if (f.management_number && f.management_number !== cur.management_number
    && db.prepare("SELECT 1 FROM assets WHERE management_number = ? AND id != ?").get(f.management_number, id)) {
    throw conflict(`관리번호 ${f.management_number}은(는) 이미 있습니다`);
  }
  if (!Object.keys(f).length) return cur;
  const item = db.prepare("SELECT name FROM items WHERE id = ?").get(cur.item_id);
  tx(db, () => {
    const sets = Object.keys(f).map((k) => `${k} = @${k}`).join(", ");
    db.prepare(`UPDATE assets SET ${sets}, updated_at = @t WHERE id = @id`).run({ ...f, t: nowIso(), id });
    logEvent(db, actor, { action: "update", item_id: cur.item_id, asset_id: id, summary: `${item.name} ${cur.management_number || cur.label || ""} 정보 수정`.trim() });
  });
  ctx.changed({ kind: "item", id: cur.item_id });
  return { ...cur, ...f };
}

// ---------------------------------------------------------------- 화면용 모양
export function unitView(snap, a) {
  const loan = snap.loanByAsset.get(a.id);
  const item = snap.items.get(a.item_id);
  const end = lifeEnd(a, item);
  const loc = a.location_id && snap.locations.get(a.location_id);
  return {
    id: a.id, item_id: a.item_id, label: a.label, management_number: a.management_number, serial_number: a.serial_number,
    edufine_number: a.edufine_number, status: a.status, status_label: ASSET_STATUS_LABEL[a.status], location_id: a.location_id,
    location_path: loc ? loc.path : "", room_id: loc ? loc.room_id : null, image: a.image, thumb: a.thumb, notes: a.notes, qr: a.qr,
    purchase_date: a.purchase_date, purchase_price: a.purchase_price, useful_life_years: a.useful_life_years ?? null,
    budget_program: a.budget_program, budget_year: a.budget_year, life_end: end ? new Date(end).toISOString().slice(0, 10) : null,
    aging: Boolean(end && end < Date.now()), retired_at: a.retired_at, retire_reason: a.retire_reason,
    loan: loan ? loanBrief(loan) : null,
    repairs: (snap.repairsByTarget.get(`asset:${a.id}`) || []).length,
  };
}

export function loanBrief(l) {
  return {
    id: l.id, borrower_name: l.borrower_name, borrower_user_id: l.borrower_user_id, borrower_note: l.borrower_note, due_at: l.due_at,
    created_at: l.created_at, overdue: Boolean(l.overdue), due_today: Boolean(l.due_today), purpose: l.purpose, quantity: l.quantity,
  };
}

export function stockView(snap, s) {
  const loc = snap.locations.get(s.location_id);
  return {
    id: s.id, item_id: s.item_id, location_id: s.location_id, location_path: loc ? loc.path : "(없는 장소)", room_id: loc ? loc.room_id : null,
    quantity: s.quantity, lot_code: s.lot_code || "", expires_at: s.expires_at, updated_at: s.updated_at,
    expired: Boolean(s.expires_at && Date.parse(s.expires_at) < Date.now()),
  };
}

function statusOf(item, a) {
  if (item.archived_at) return ["archived", "보관함"];
  if (item.kind === "equipment") {
    if (!a.units) return ["empty", "등록된 장비 없음"];
    if (a.available === a.units) return ["available", a.units > 1 ? `${a.units}대 모두 보관 중` : "보관 중"];
    if (a.available > 0) return ["partial", `${a.available}/${a.units}대 사용 가능`];
    if (a.on_loan) return ["on_loan", a.units > 1 ? `${a.on_loan}대 대여 중` : "대여 중"];
    if (a.repair) return ["repair", "수리 중"];
    return ["lost", "분실"];
  }
  if (a.qty <= 0) return ["empty", "재고 없음"];
  if (a.low) return ["low", "재고 부족"];
  return ["available", "재고 있음"];
}

export function itemCard(snap, it) {
  const a = snap.agg.get(it.id) || { location_ids: new Set() };
  const places = [...a.location_ids].map((id) => snap.locations.get(id)).filter(Boolean);
  const [status, statusLabel] = statusOf(it, a);
  const cat = it.category_id && snap.categories.get(it.category_id);
  // 어디에 몇 개 있는지 짧게
  const where = places.slice(0, 3).map((l) => {
    let qty = 0;
    let units = 0;
    for (const s of snap.stocksByItem.get(it.id) || []) if (s.location_id === l.id) qty += s.quantity;
    for (const u of snap.assetsByItem.get(it.id) || []) if (u.location_id === l.id && u.status !== "retired") units += 1;
    return { id: l.id, path: l.path, name: l.name, room_id: l.room_id, qty: Math.round(qty * 100) / 100, units };
  });
  return {
    id: it.id, name: it.name, kind: it.kind, kind_label: KIND_LABEL[it.kind], unit: it.unit, image: it.image, thumb: it.thumb,
    category: cat ? { id: cat.id, name: cat.name } : null, manufacturer: it.manufacturer, model: it.model, favorite: Boolean(it.favorite),
    min_stock: it.min_stock, qty: a.qty || 0, units: a.units || 0, available: a.available || 0, on_loan: a.on_loan || 0, repair: a.repair || 0,
    lost: a.lost || 0, loaned_qty: a.loaned_qty || 0, low: Boolean(a.low), overdue: Boolean(a.overdue), due_today: Boolean(a.due_today),
    expiring: Boolean(a.expiring), expired: Boolean(a.expired), aging: Boolean(a.aging), repairs: a.repairs || 0,
    status, status_label: statusLabel, where, where_more: Math.max(0, places.length - where.length), qr: it.qr,
    archived: Boolean(it.archived_at), budget_program: it.budget_program, budget_year: it.budget_year, updated_at: it.updated_at,
  };
}

export function itemDetail(ctx, id) {
  const snap = ctx.snapshot();
  const it = snap.items.get(id);
  if (!it) throw notFound("품목이 없습니다");
  const card = itemCard(snap, it);
  const units = (snap.assetsByItem.get(id) || []).map((a) => unitView(snap, a));
  const stocks = (snap.stocksByItem.get(id) || []).map((s) => stockView(snap, s)).sort((a, b) => b.quantity - a.quantity);
  const loans = (snap.loansByItem.get(id) || []).map((l) => ({
    ...loanBrief(l), asset_id: l.asset_id, from_location_id: l.from_location_id,
    unit: l.asset_id ? (() => { const u = snap.assets.get(l.asset_id); return u ? { management_number: u.management_number, label: u.label } : null; })() : null,
  }));
  const repairs = [
    ...(snap.repairsByTarget.get(`item:${id}`) || []),
    ...units.flatMap((u) => snap.repairsByTarget.get(`asset:${u.id}`) || []),
  ].map((r) => ({ id: r.id, title: r.title, status: r.status, urgency: r.urgency, target_type: r.target_type, target_id: r.target_id, created_at: r.created_at }));
  const events = listEvents(ctx.db, { item_id: id, limit: 40 });
  return {
    item: {
      ...it, tags: it.tags, kind_label: KIND_LABEL[it.kind], favorite: Boolean(it.favorite), archived: Boolean(it.archived_at),
    },
    card, units: units.filter((u) => u.status !== "retired"), retired_units: units.filter((u) => u.status === "retired"),
    stocks, loans, repairs, events,
  };
}

/**
 * 목록·필터. q 는 검색 순서를 따르고, 없으면 이름순.
 * f: { q, kind, location_id, status: low|on_loan|overdue|repair|aging|expiring|available|favorite|archived, category_id, budget_program, budget_year, sort, limit, offset }
 */
export function listItems(ctx, f = {}, searchFn) {
  const snap = ctx.snapshot();
  let list;
  let scores = null;
  if (f.q && searchFn) {
    const r = searchFn(snap, f.q, { limit: 5000 });
    scores = new Map(r.items.map((x, i) => [x.id, { rank: i, unit_id: x.unit_id }]));
    list = r.items.map((x) => snap.items.get(x.id)).filter(Boolean);
  } else {
    list = [...snap.items.values()];
  }
  const scope = f.location_id ? subtree(snap, f.location_id) : null;
  const statuses = String(f.status || "").split(",").filter(Boolean);
  const out = [];
  for (const it of list) {
    const archivedWanted = statuses.includes("archived");
    if (Boolean(it.archived_at) !== archivedWanted) continue;
    if (f.kind) {
      const kinds = String(f.kind).split(",");
      if (!kinds.includes(it.kind)) continue;
    }
    if (f.category_id && it.category_id !== f.category_id) continue;
    if (f.budget_program && it.budget_program !== f.budget_program) continue;
    if (f.budget_year && Number(it.budget_year) !== Number(f.budget_year)) continue;
    const a = snap.agg.get(it.id);
    if (scope && !(a && [...a.location_ids].some((id) => scope.has(id)))) continue;
    let ok = true;
    for (const s of statuses) {
      if (s === "archived") continue;
      if (s === "low" && !a.low) ok = false;
      else if (s === "on_loan" && !(a.on_loan || a.loaned_qty)) ok = false;
      else if (s === "overdue" && !a.overdue) ok = false;
      else if (s === "repair" && !(a.repair || a.repairs)) ok = false;
      else if (s === "aging" && !a.aging) ok = false;
      else if (s === "expiring" && !(a.expiring || a.expired)) ok = false;
      else if (s === "available" && !(a.available || a.qty > 0)) ok = false;
      else if (s === "favorite" && !it.favorite) ok = false;
      else if (s === "empty" && (a.units || a.qty > 0)) ok = false;
    }
    if (!ok) continue;
    const card = itemCard(snap, it);
    if (scores) card.match_unit_id = scores.get(it.id).unit_id;
    out.push(card);
  }
  const sort = f.sort || (scores ? "relevance" : "name");
  if (sort === "name") out.sort((a, b) => a.name.localeCompare(b.name, "ko"));
  else if (sort === "recent") out.sort((a, b) => String(b.updated_at).localeCompare(String(a.updated_at)));
  else if (sort === "qty") out.sort((a, b) => (a.qty + a.units) - (b.qty + b.units));
  const offset = Math.max(0, Number(f.offset) || 0);
  const limit = Math.max(1, Math.min(1000, Number(f.limit) || 200));
  return { total: out.length, items: out.slice(offset, offset + limit) };
}

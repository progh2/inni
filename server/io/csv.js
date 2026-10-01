// 엑셀과 주고받는 CSV. 장비는 한 대에 한 줄, 수량 품목은 위치마다 한 줄.
// 가져오기는 먼저 "미리보기"(바꾸지 않음)로 확인한 뒤 실제로 넣는다.
import { tx } from "../lib/db.js";
import { nowIso, badRequest, newId, fmtQty } from "../lib/util.js";
import { createItem, addUnits, createLocation, KIND_LABEL, ASSET_STATUS_LABEL, uniqueQr } from "../lib/inventory.js";
import { normalize } from "../../web/js/shared/hangul.js";
import { logEvent } from "../lib/events.js";

export const COLUMNS = [
  ["item_id", "품목ID"], ["name", "품명"], ["kind", "종류"], ["category", "분류"], ["manufacturer", "제조사"], ["model", "모델"], ["spec", "규격"],
  ["unit", "단위"], ["min_stock", "최소재고"], ["aliases", "별칭"], ["tags", "태그"], ["price", "가격"], ["vendor", "구입처"], ["product_url", "제품링크"],
  ["barcode", "바코드"], ["edufine_number", "에듀파인번호"], ["class_number", "물품분류번호"], ["budget_program", "사업명"], ["budget_year", "예산연도"],
  ["useful_life_years", "내용연수"], ["notes", "메모"], ["location", "위치"], ["quantity", "수량"], ["management_number", "관리번호"],
  ["serial_number", "시리얼"], ["purchase_date", "도입일"], ["status", "상태"],
];

const ALIASES = {
  품목id: "item_id", 아이디: "item_id", id: "item_id", 품명: "name", 이름: "name", 품목명: "name", 물품명: "name", name: "name",
  종류: "kind", 유형: "kind", 타입: "kind", 분류: "category", 카테고리: "category", 제조사: "manufacturer", 제조원: "manufacturer", 브랜드: "manufacturer",
  모델: "model", 모델명: "model", 규격: "spec", 사양: "spec", 스펙: "spec", 단위: "unit", 최소재고: "min_stock", 최소수량: "min_stock", 별칭: "aliases",
  태그: "tags", 가격: "price", 단가: "price", 구입가: "price", 구입처: "vendor", 업체: "vendor", 제품링크: "product_url", 링크: "product_url", url: "product_url",
  바코드: "barcode", 에듀파인번호: "edufine_number", 에듀파인: "edufine_number", 자산번호: "edufine_number", 물품분류번호: "class_number", 분류번호: "class_number",
  사업명: "budget_program", 구입사업명: "budget_program", 예산연도: "budget_year", 구입연도: "budget_year", 내용연수: "useful_life_years", 내용연한: "useful_life_years",
  메모: "notes", 비고: "notes", 설명: "notes", 위치: "location", 장소: "location", 실: "location", 보관장소: "location", 수량: "quantity", 재고: "quantity", 개수: "quantity",
  관리번호: "management_number", 시리얼: "serial_number", 시리얼번호: "serial_number", 제조번호: "serial_number", 도입일: "purchase_date", 구입일: "purchase_date",
  취득일: "purchase_date", 상태: "status",
};

const KIND_FROM = { 장비: "equipment", 기자재: "equipment", equipment: "equipment", 비품: "fixture", fixture: "fixture", 소모품: "consumable", consumable: "consumable", 재료: "consumable", 부품: "part", part: "part" };

// ---------------------------------------------------------------- CSV 형식
export function parseCsv(text) {
  const rows = [];
  let row = [];
  let cell = "";
  let q = false;
  const s = String(text).replace(/^﻿/, "");
  for (let i = 0; i < s.length; i++) {
    const c = s[i];
    if (q) {
      if (c === '"') {
        if (s[i + 1] === '"') { cell += '"'; i++; } else q = false;
      } else cell += c;
    } else if (c === '"') q = true;
    else if (c === ",") { row.push(cell); cell = ""; }
    else if (c === "\n" || c === "\r") {
      if (c === "\r" && s[i + 1] === "\n") i++;
      row.push(cell);
      rows.push(row);
      row = [];
      cell = "";
    } else cell += c;
  }
  if (cell !== "" || row.length) { row.push(cell); rows.push(row); }
  return rows.filter((r) => r.some((x) => String(x).trim() !== ""));
}

export function toCsv(rows) {
  const cell = (v) => {
    const s = v === null || v === undefined ? "" : String(v);
    return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  return `﻿${rows.map((r) => r.map(cell).join(",")).join("\r\n")}\r\n`;
}

// 엑셀(한국어 윈도우)이 저장한 CP949 도 읽는다
export function decodeCsv(buf) {
  const utf8 = new TextDecoder("utf-8", { fatal: false }).decode(buf);
  if (!utf8.includes("�")) return utf8;
  try { return new TextDecoder("euc-kr").decode(buf); } catch { return utf8; }
}

// ---------------------------------------------------------------- 내보내기
export function exportItemsCsv(ctx) {
  const snap = ctx.snapshot();
  const rows = [COLUMNS.map(([, label]) => label)];
  const items = [...snap.items.values()].filter((it) => !it.archived_at).sort((a, b) => a.name.localeCompare(b.name, "ko"));
  for (const it of items) {
    const cat = it.category_id && snap.categories.get(it.category_id);
    const base = {
      item_id: it.id, name: it.name, kind: KIND_LABEL[it.kind], category: cat ? cat.name : "", manufacturer: it.manufacturer, model: it.model, spec: it.spec,
      unit: it.unit, min_stock: it.min_stock, aliases: it.aliases, tags: (it.tags || []).join(", "), price: it.price, vendor: it.vendor, product_url: it.product_url,
      barcode: it.barcode, edufine_number: it.edufine_number, class_number: it.class_number, budget_program: it.budget_program, budget_year: it.budget_year,
      useful_life_years: it.useful_life_years, notes: it.notes,
    };
    const lines = [];
    if (it.kind === "equipment") {
      for (const u of snap.assetsByItem.get(it.id) || []) {
        lines.push({ ...base, location: (snap.locations.get(u.location_id) || {}).path || "", quantity: 1, management_number: u.management_number, serial_number: u.serial_number,
          purchase_date: u.purchase_date, status: ASSET_STATUS_LABEL[u.status], edufine_number: u.edufine_number || base.edufine_number, budget_program: u.budget_program || base.budget_program, budget_year: u.budget_year || base.budget_year });
      }
    } else {
      for (const s of snap.stocksByItem.get(it.id) || []) lines.push({ ...base, location: (snap.locations.get(s.location_id) || {}).path || "", quantity: fmtQty(s.quantity) });
    }
    if (!lines.length) lines.push(base);
    for (const l of lines) rows.push(COLUMNS.map(([k]) => l[k] ?? ""));
  }
  return toCsv(rows);
}

export function templateCsv() {
  return toCsv([
    COLUMNS.map(([, l]) => l),
    ["", "디지털 멀티미터", "장비", "계측기", "Fluke", "87V", "", "대", "", "멀티테스터", "계측", "780000", "", "", "", "", "", "기자재 확충", "2026", "7", "", "실습동 › 전자실습실", "1", "전장-2026-001", "SN1234", "2026-03-02", ""],
    ["", "납땜 실납", "소모품", "소모품", "", "", "0.8mm", "롤", "5", "실납", "", "", "", "", "", "", "", "", "", "", "", "전자실습실", "12", "", "", "", ""],
  ]);
}

// ---------------------------------------------------------------- 가져오기
function mapHeader(header) {
  return header.map((h) => {
    const k = String(h || "").trim().toLowerCase().replace(/\s+/g, "");
    return ALIASES[k] || COLUMNS.find(([key]) => key === k)?.[0] || null;
  });
}

function findLocationByText(snap, text) {
  const t = String(text || "").trim();
  if (!t) return null;
  const parts = t.split(/\s*(?:›|>|\/|\\)\s*/).filter(Boolean);
  const leaf = normalize(parts[parts.length - 1]);
  const full = normalize(parts.join(""));
  const all = [...snap.locations.values()];
  const byPath = all.filter((l) => normalize(l.path) === full || normalize(l.path).endsWith(full));
  if (byPath.length === 1) return byPath[0];
  const byName = all.filter((l) => normalize(l.name) === leaf || (l.code && normalize(l.code) === leaf));
  if (byName.length === 1) return byName[0];
  return null;
}

/**
 * dry=true 면 바꾸지 않고 무엇을 할지만 알려 준다.
 * opts.createLocations: 없는 장소는 새 실로 만든다
 */
export function importItemsCsv(ctx, actor, text, { dry = true, createLocations = true } = {}) {
  const rows = parseCsv(text);
  if (rows.length < 2) throw badRequest("CSV 에 내용이 없습니다(첫 줄은 제목 줄)");
  const keys = mapHeader(rows[0]);
  if (!keys.includes("name") && !keys.includes("item_id")) throw badRequest("'품명' 열이 필요합니다");
  const snap = ctx.snapshot();
  const plan = { new_items: 0, new_units: 0, stock_lines: 0, new_locations: [], updated: 0, errors: [], rows: rows.length - 1 };
  const records = rows.slice(1).map((r, i) => {
    const o = { line: i + 2 };
    keys.forEach((k, j) => { if (k) o[k] = String(r[j] ?? "").trim(); });
    return o;
  });
  // 같은 품목끼리 묶는다(품목ID 또는 품명+종류)
  const groups = new Map();
  for (const r of records) {
    if (!r.name && !r.item_id) { plan.errors.push({ line: r.line, reason: "품명이 비어 있습니다" }); continue; }
    const kind = KIND_FROM[normalize(r.kind || "")] || (r.management_number ? "equipment" : r.kind ? null : "equipment");
    if (!kind) { plan.errors.push({ line: r.line, reason: `종류를 모릅니다: ${r.kind}` }); continue; }
    r.kind = kind;
    const key = r.item_id && snap.items.get(r.item_id) ? `id:${r.item_id}` : `n:${normalize(r.name)}|${kind}`;
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(r);
  }
  const newLocs = new Map();
  const locFor = (r) => {
    if (!r.location) return null;
    const hit = findLocationByText(snap, r.location);
    if (hit) return hit.id;
    const k = normalize(r.location);
    if (!createLocations) throw new Error(`없는 장소입니다: ${r.location}`);
    if (!newLocs.has(k)) newLocs.set(k, { name: r.location.split(/\s*(?:›|>|\/)\s*/).pop(), id: null });
    return `new:${k}`;
  };
  const existingNumbers = new Set([...snap.assets.values()].map((a) => a.management_number).filter(Boolean));
  const work = [];
  for (const [key, list] of groups) {
    const first = list[0];
    const existing = key.startsWith("id:") ? snap.items.get(first.item_id)
      : [...snap.items.values()].find((it) => !it.archived_at && normalize(it.name) === normalize(first.name) && it.kind === first.kind);
    if (!existing) plan.new_items += 1;
    else plan.updated += 1;
    const lines = [];
    for (const r of list) {
      try {
        const loc = locFor(r);
        if (first.kind === "equipment") {
          if (r.management_number && existingNumbers.has(r.management_number)) continue;
          if (!loc) throw new Error("장비는 위치가 필요합니다");
          const n = r.management_number ? 1 : Math.max(1, Math.min(200, Math.round(Number(r.quantity) || 1)));
          plan.new_units += n;
          lines.push({ r, loc, n });
          if (r.management_number) existingNumbers.add(r.management_number);
        } else if (loc) {
          const q = Number(String(r.quantity || "0").replace(/,/g, ""));
          if (!Number.isFinite(q) || q < 0) throw new Error(`수량이 이상합니다: ${r.quantity}`);
          plan.stock_lines += 1;
          lines.push({ r, loc, q });
        }
      } catch (e) {
        plan.errors.push({ line: r.line, reason: e.message });
      }
    }
    work.push({ first, existing, lines });
  }
  plan.new_locations = [...newLocs.values()].map((l) => l.name);
  if (dry) return plan;

  const t0 = nowIso();
  const categories = new Map([...snap.categories.values()].map((c) => [normalize(c.name), c.id]));
  const catId = (name) => {
    if (!name) return null;
    const k = normalize(name);
    if (!categories.has(k)) {
      const id = newId();
      ctx.db.prepare("INSERT INTO categories(id, name, sort, created_at) VALUES(?, ?, 0, ?)").run(id, name.slice(0, 40), t0);
      categories.set(k, id);
    }
    return categories.get(k);
  };
  const who = { ...actor, via: "import" };
  tx(ctx.db, () => {
    for (const [k, l] of newLocs) l.id = createLocation(ctx, who, { name: l.name, kind: "room" }).id;
    const realLoc = (loc) => (loc && loc.startsWith("new:") ? newLocs.get(loc.slice(4)).id : loc);
    for (const w of work) {
      const f = w.first;
      let itemId = w.existing ? w.existing.id : null;
      if (!itemId) {
        const out = createItem(ctx, who, {
          name: f.name, kind: f.kind, category_id: catId(f.category), manufacturer: f.manufacturer, model: f.model, spec: f.spec, unit: f.unit || (f.kind === "equipment" ? "대" : "개"),
          min_stock: f.min_stock || null, aliases: f.aliases, tags: f.tags, price: f.price || null, vendor: f.vendor, product_url: /^https?:\/\//.test(f.product_url || "") ? f.product_url : null,
          barcode: f.barcode, edufine_number: f.kind === "equipment" ? null : f.edufine_number, class_number: f.class_number, budget_program: f.budget_program,
          budget_year: f.budget_year || null, useful_life_years: f.useful_life_years || null, notes: f.notes, unit_count: 0,
        });
        itemId = out.item.id;
      }
      for (const ln of w.lines) {
        const loc = realLoc(ln.loc);
        if (f.kind === "equipment") {
          const u = { management_number: ln.r.management_number || null, serial_number: ln.r.serial_number || null, purchase_date: ln.r.purchase_date || null,
            edufine_number: ln.r.edufine_number || null, budget_program: ln.r.budget_program || null, budget_year: ln.r.budget_year || null };
          const units = ln.r.management_number ? [u] : Array.from({ length: ln.n }, () => ({ ...u }));
          addUnits(ctx, who, itemId, { location_id: loc, units, auto_number: false });
        } else {
          const hit = ctx.db.prepare("SELECT id FROM stocks WHERE item_id = ? AND location_id = ? AND lot_code = ''").get(itemId, loc);
          if (hit) ctx.db.prepare("UPDATE stocks SET quantity = round(quantity + ?, 2), updated_at = ? WHERE id = ?").run(ln.q, t0, hit.id);
          else ctx.db.prepare("INSERT INTO stocks(id, item_id, location_id, quantity, lot_code, updated_at) VALUES(?, ?, ?, ?, '', ?)").run(newId(), itemId, loc, ln.q, t0);
        }
      }
    }
    logEvent(ctx.db, actor, { action: "import", summary: `CSV 가져오기: 새 품목 ${plan.new_items}, 장비 ${plan.new_units}대, 재고 ${plan.stock_lines}줄${plan.errors.length ? `, 건너뜀 ${plan.errors.length}줄` : ""}` });
  });
  ctx.changed({ kind: "import" });
  return { ...plan, done: true };
}

export { uniqueQr };

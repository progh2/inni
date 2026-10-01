// 통합 검색. 이름·별칭·초성·관리번호·시리얼·바코드·장소 이름까지 한 번에 찾는다.
// "용접실 드릴"처럼 장소와 이름을 섞어도 된다(낱말마다 어딘가 맞으면 됨).
import { normalize, tokens, choseong, isChoseong, editDistance } from "../../web/js/shared/hangul.js";

const cache = new WeakMap();

function index(snap) {
  let idx = cache.get(snap);
  if (idx) return idx;
  const items = [];
  for (const it of snap.items.values()) {
    if (it.archived_at) continue;
    const ag = snap.agg.get(it.id);
    const places = [...(ag ? ag.location_ids : [])].map((id) => snap.locations.get(id)).filter(Boolean);
    const cat = it.category_id && snap.categories.get(it.category_id);
    const aliasList = String(it.aliases || "").split(/[,\n]/).map((s) => s.trim()).filter(Boolean);
    const units = (snap.assetsByItem.get(it.id) || []).filter((a) => a.status !== "retired");
    const codes = [it.qr, it.barcode, it.edufine_number, it.class_number].filter(Boolean).map(normalize);
    const unitCodes = [];
    for (const u of units) {
      for (const c of [u.qr, u.management_number, u.serial_number, u.edufine_number]) {
        if (c) unitCodes.push({ code: normalize(c), unit: u });
      }
    }
    items.push({
      type: "item",
      id: it.id,
      name: normalize(it.name),
      nameWords: String(it.name).toLowerCase().split(/\s+/).map(normalize).filter(Boolean),
      aliases: aliasList.map(normalize).join("|"),
      aliasWords: aliasList.map(normalize),
      cho: `${choseong(it.name)}|${aliasList.map(choseong).join("|")}`,
      codes,
      unitCodes,
      other: [it.manufacturer, it.model, it.spec, cat && cat.name, ...(it.tags || []), it.budget_program, it.vendor, ...units.map((u) => u.label)]
        .filter(Boolean).map(normalize).join("|"),
      places: places.map((p) => normalize(p.path)).join("|"),
      desc: normalize(it.description || "").slice(0, 400),
      favorite: it.favorite,
    });
  }
  const locations = [];
  for (const l of snap.locations.values()) {
    locations.push({
      type: "location", id: l.id, name: normalize(l.name), cho: choseong(l.name), codes: [l.qr, l.code].filter(Boolean).map(normalize),
      other: normalize(l.path),
    });
  }
  idx = { items, locations };
  cache.set(snap, idx);
  return idx;
}

function fuzzyHit(words, t) {
  if (t.length < 3) return false;
  const max = t.length >= 6 ? 2 : 1;
  return words.some((w) => w.length >= 2 && editDistance(w.slice(0, t.length + max), t, max) <= max);
}

function scoreItemToken(d, t) {
  if (d.codes.includes(t)) return { s: 1000 };
  const unit = d.unitCodes.find((u) => u.code === t);
  if (unit) return { s: 1000, unit: unit.unit };
  if (isChoseong(t)) return d.cho.includes(t) ? { s: d.cho.startsWith(t) ? 80 : 60 } : null;
  if (d.name === t) return { s: 160 };
  if (d.name.startsWith(t)) return { s: 125 };
  if (d.name.includes(t)) return { s: 100 };
  if (d.aliases.includes(t)) return { s: 90 };
  if (t.length >= 3) {
    const pu = d.unitCodes.find((u) => u.code.includes(t));
    if (pu) return { s: 75, unit: pu.unit };
    if (d.codes.some((c) => c.includes(t))) return { s: 70 };
  }
  if (d.other.includes(t)) return { s: 50 };
  if (d.places.includes(t)) return { s: 40, place: true };
  if (t.length >= 2 && d.desc.includes(t)) return { s: 20 };
  if (fuzzyHit(d.nameWords, t) || fuzzyHit(d.aliasWords, t)) return { s: 30, fuzzy: true };
  return null;
}

function scoreLocationToken(d, t) {
  if (d.codes.includes(t)) return 1000;
  if (isChoseong(t)) return d.cho.includes(t) ? 60 : 0;
  if (d.name === t) return 150;
  if (d.name.startsWith(t)) return 120;
  if (d.name.includes(t)) return 95;
  if (d.codes.some((c) => c.includes(t))) return 70;
  if (d.other.includes(t)) return 35;
  return 0;
}

/**
 * @returns {{ items: Array<{id, score, unit_id?, exact?, fuzzy?}>, locations: Array<{id, score}>, exact: object|null }}
 */
export function search(snap, q, { limit = 30, locLimit = 8 } = {}) {
  const toks = tokens(q);
  if (!toks.length) return { items: [], locations: [], exact: null };
  const idx = index(snap);
  const items = [];
  for (const d of idx.items) {
    let total = 0;
    let unit = null;
    let fuzzy = false;
    let placeOnly = true;
    let ok = true;
    for (const t of toks) {
      const r = scoreItemToken(d, t);
      if (!r) { ok = false; break; }
      total += r.s;
      if (r.unit) unit = r.unit;
      if (r.fuzzy) fuzzy = true;
      if (!r.place) placeOnly = false;
    }
    if (!ok) continue;
    // 장소 이름만 맞은 물건은 뒤로(장소 결과가 따로 뜬다)
    if (placeOnly) total = Math.min(total, 25 * toks.length);
    if (d.favorite) total += 4;
    items.push({ id: d.id, score: total, unit_id: unit ? unit.id : null, exact: total >= 1000, fuzzy });
  }
  items.sort((a, b) => b.score - a.score);

  const locations = [];
  for (const d of idx.locations) {
    let total = 0;
    let ok = true;
    for (const t of toks) {
      const s = scoreLocationToken(d, t);
      if (!s) { ok = false; break; }
      total += s;
    }
    if (ok) locations.push({ id: d.id, score: total, exact: total >= 1000 });
  }
  locations.sort((a, b) => b.score - a.score);

  let exact = null;
  if (items[0] && items[0].exact && !(locations[0] && locations[0].exact)) exact = { type: "item", id: items[0].id, unit_id: items[0].unit_id };
  else if (locations[0] && locations[0].exact) exact = { type: "location", id: locations[0].id };
  return { items: items.slice(0, limit), locations: locations.slice(0, locLimit), exact, total: items.length };
}

// 코드(QR·관리번호·바코드) 하나로 정확히 찾기. 스캔용.
export function resolveCode(snap, raw) {
  let code = String(raw || "").trim();
  // 라벨 QR 은 주소를 담는다: https://학교/q/ABC1234
  const m = /\/q\/([A-Za-z0-9_-]+)\/?(?:[?#].*)?$/.exec(code);
  if (m) code = m[1];
  const n = normalize(code);
  if (!n) return null;
  for (const l of snap.locations.values()) {
    if (normalize(l.qr) === n) return { type: "location", id: l.id };
  }
  for (const it of snap.items.values()) {
    if (normalize(it.qr) === n) return { type: "item", id: it.id };
  }
  for (const a of snap.assets.values()) {
    if (normalize(a.qr) === n) return { type: "asset", id: a.id, item_id: a.item_id };
  }
  for (const a of snap.assets.values()) {
    if ((a.management_number && normalize(a.management_number) === n) || (a.serial_number && normalize(a.serial_number) === n)) {
      return { type: "asset", id: a.id, item_id: a.item_id };
    }
  }
  for (const it of snap.items.values()) {
    if (it.barcode && normalize(it.barcode) === n) return { type: "item", id: it.id };
  }
  for (const l of snap.locations.values()) {
    if (l.code && normalize(l.code) === n) return { type: "location", id: l.id };
  }
  return null;
}

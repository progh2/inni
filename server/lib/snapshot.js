// 메모리 현황판. 학교 규모(품목 수천·실 수십)에서는 전부 메모리에 올려 두고 모아 보는 편이 빠르고 단순하다.
// 쓰기가 일어나면 invalidate() 로 표시만 하고, 다음 읽기 때 다시 만든다.
import { jsonParse } from "./util.js";

const DAY = 86400000;

export function createSnapshotCache(getDb) {
  let snap = null;
  let dirty = true;
  let version = 0;
  return {
    get() {
      if (dirty || !snap) {
        snap = buildSnapshot(getDb(), ++version);
        dirty = false;
      }
      return snap;
    },
    invalidate() { dirty = true; },
    get version() { return version; },
  };
}

export function buildSnapshot(db, version = 1, now = Date.now()) {
  const locations = new Map();
  for (const l of db.prepare("SELECT * FROM locations WHERE archived_at IS NULL ORDER BY sort, name").all()) {
    locations.set(l.id, { ...l, children: [] });
  }
  const roots = [];
  for (const l of locations.values()) {
    const p = l.parent_id && locations.get(l.parent_id);
    if (p) p.children.push(l.id);
    else roots.push(l.id);
  }
  // 경로(건물 › 실 › 선반)
  for (const l of locations.values()) {
    const chain = [];
    let cur = l;
    const seen = new Set();
    while (cur && !seen.has(cur.id)) {
      seen.add(cur.id);
      chain.unshift(cur.id);
      cur = cur.parent_id ? locations.get(cur.parent_id) : null;
    }
    l.path_ids = chain;
    l.path = chain.map((id) => locations.get(id).name).join(" › ");
    l.depth = chain.length - 1;
    // 가장 가까운 "실"(room) — 화면에서 "어느 실"을 보여 줄 때
    const roomId = [...chain].reverse().find((id) => locations.get(id).kind === "room");
    l.room_id = roomId || null;
  }

  const categories = new Map(db.prepare("SELECT * FROM categories ORDER BY sort, name").all().map((c) => [c.id, c]));

  const items = new Map();
  for (const it of db.prepare("SELECT * FROM items").all()) {
    items.set(it.id, { ...it, tags: jsonParse(it.tags, []) });
  }

  const assets = new Map();
  const assetsByItem = new Map();
  for (const a of db.prepare("SELECT * FROM assets ORDER BY management_number, created_at").all()) {
    assets.set(a.id, a);
    if (!assetsByItem.has(a.item_id)) assetsByItem.set(a.item_id, []);
    assetsByItem.get(a.item_id).push(a);
  }

  const stocks = new Map();
  const stocksByItem = new Map();
  for (const s of db.prepare("SELECT * FROM stocks ORDER BY updated_at DESC").all()) {
    stocks.set(s.id, s);
    if (!stocksByItem.has(s.item_id)) stocksByItem.set(s.item_id, []);
    stocksByItem.get(s.item_id).push(s);
  }

  const loansActive = db.prepare("SELECT * FROM loans WHERE status = 'active' ORDER BY due_at IS NULL, due_at").all();
  const loanByAsset = new Map();
  const loansByItem = new Map();
  const todayEnd = endOfSeoulDay(now);
  for (const l of loansActive) {
    l.overdue = Boolean(l.due_at && Date.parse(l.due_at) < now);
    l.due_today = Boolean(l.due_at && !l.overdue && Date.parse(l.due_at) <= todayEnd);
    if (l.asset_id) loanByAsset.set(l.asset_id, l);
    if (!loansByItem.has(l.item_id)) loansByItem.set(l.item_id, []);
    loansByItem.get(l.item_id).push(l);
  }

  const repairsOpen = db.prepare("SELECT * FROM repairs WHERE status IN ('open','in_progress') ORDER BY urgency = 'urgent' DESC, created_at").all();
  const repairsByTarget = new Map();
  for (const r of repairsOpen) {
    const k = `${r.target_type}:${r.target_id}`;
    if (!repairsByTarget.has(k)) repairsByTarget.set(k, []);
    repairsByTarget.get(k).push(r);
  }

  const users = new Map(db.prepare("SELECT id, email, name, role, status, photo_url FROM users").all().map((u) => [u.id, u]));

  // ---- 품목별 집계
  const agg = new Map();
  const soon = now + 30 * DAY;
  for (const it of items.values()) {
    const a = {
      units: 0, available: 0, on_loan: 0, repair: 0, lost: 0, retired: 0, qty: 0, loaned_qty: 0,
      location_ids: new Set(), low: false, overdue: false, due_today: false, expiring: false, expired: false,
      aging: false, repairs: 0,
    };
    for (const as of assetsByItem.get(it.id) || []) {
      if (as.status === "retired") { a.retired += 1; continue; }
      a.units += 1;
      a[as.status] = (a[as.status] || 0) + 1;
      if (as.location_id) a.location_ids.add(as.location_id);
      if (lifeEnd(as, it) && lifeEnd(as, it) < now) a.aging = true;
      a.repairs += (repairsByTarget.get(`asset:${as.id}`) || []).length;
    }
    for (const s of stocksByItem.get(it.id) || []) {
      a.qty += s.quantity;
      if (s.quantity > 0) a.location_ids.add(s.location_id);
      if (s.expires_at && s.quantity > 0) {
        const t = Date.parse(s.expires_at);
        if (t < now) a.expired = true;
        else if (t < soon) a.expiring = true;
      }
    }
    a.qty = Math.round(a.qty * 100) / 100;
    for (const l of loansByItem.get(it.id) || []) {
      if (!l.asset_id) a.loaned_qty += l.quantity;
      if (l.overdue) a.overdue = true;
      if (l.due_today) a.due_today = true;
    }
    a.repairs += (repairsByTarget.get(`item:${it.id}`) || []).length;
    if (it.kind !== "equipment" && it.min_stock !== null && it.min_stock !== undefined && !it.archived_at) {
      a.low = a.qty < Number(it.min_stock);
    }
    agg.set(it.id, a);
  }

  // ---- 장소별 집계(바로 아래 것만, 하위는 subtree 로)
  const locAgg = new Map();
  for (const id of locations.keys()) locAgg.set(id, { items: new Set(), units: 0, stock_lines: 0, alerts: 0 });
  for (const as of assets.values()) {
    if (as.status === "retired" || !as.location_id) continue;
    const it = items.get(as.item_id);
    if (!it || it.archived_at) continue;
    const la = locAgg.get(as.location_id);
    if (!la) continue;
    la.items.add(as.item_id);
    la.units += 1;
    const loan = loanByAsset.get(as.id);
    if (loan && loan.overdue) la.alerts += 1;
  }
  for (const s of stocks.values()) {
    const it = items.get(s.item_id);
    if (!it || it.archived_at) continue;
    const la = locAgg.get(s.location_id);
    if (!la) continue;
    if (s.quantity > 0) la.items.add(s.item_id);
    la.stock_lines += 1;
    const ag = agg.get(s.item_id);
    if (ag && ag.low) la.alerts += 1;
  }

  return {
    version, builtAt: now, locations, roots, categories, items, assets, assetsByItem, stocks, stocksByItem,
    loansActive, loanByAsset, loansByItem, repairsOpen, repairsByTarget, users, agg, locAgg,
  };
}

export function lifeEnd(asset, item) {
  const years = asset.useful_life_years ?? (item && item.useful_life_years);
  if (!asset.purchase_date || !years) return null;
  const d = new Date(`${asset.purchase_date}T00:00:00+09:00`);
  if (Number.isNaN(d.getTime())) return null;
  d.setFullYear(d.getFullYear() + Number(years));
  return d.getTime();
}

export function endOfSeoulDay(now = Date.now()) {
  // 서울(UTC+9) 기준 오늘 23:59:59
  const kst = new Date(now + 9 * 3600000);
  kst.setUTCHours(23, 59, 59, 999);
  return kst.getTime() - 9 * 3600000;
}

// 하위 장소까지 모두
export function subtree(snap, locationId) {
  const out = new Set();
  const stack = [locationId];
  while (stack.length) {
    const id = stack.pop();
    if (out.has(id)) continue;
    const l = snap.locations.get(id);
    if (!l) continue;
    out.add(id);
    stack.push(...l.children);
  }
  return out;
}

export function locPath(snap, id) {
  const l = id && snap.locations.get(id);
  return l ? l.path : "";
}

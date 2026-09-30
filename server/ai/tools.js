// 이니가 쓰는 도구.
// - read: 읽기만 한다
// - propose: 바꾸는 일은 "제안 카드"로만 만든다. 선생님이 [실행]을 누르면 화면이 기존 API 를 부른다
// - client: 화면 이동·지도 표시·등록 화면 열기처럼 브라우저가 할 일
import { search } from "../lib/search.js";
import { subtree } from "../lib/snapshot.js";
import { itemCard, KIND_LABEL, ASSET_STATUS_LABEL, KINDS } from "../lib/inventory.js";
import { computeAlerts, dashboardCounts } from "../lib/alerts.js";
import { listEvents } from "../lib/events.js";
import { normalize } from "../../web/js/shared/hangul.js";
import { newId, fmtQty } from "../lib/util.js";

const LIST = 8;

function toolError(message) {
  const e = new Error(message);
  e.tool = true;
  return e;
}

const proposalId = () => `p_${newId()}`;

// ---------------------------------------------------------------- 대상 찾기
export function findItem(snap, name) {
  const q = String(name || "").trim();
  if (!q) throw toolError("어떤 물건인지 이름을 알려 주세요");
  const r = search(snap, q, { limit: 6 });
  if (!r.items.length) throw toolError(`"${q}"에 맞는 물건이 없습니다. 이름을 다르게 말해 주세요.`);
  const top = r.items[0];
  const second = r.items[1];
  const exactName = r.items.filter((x) => normalize(snap.items.get(x.id).name) === normalize(q));
  if (exactName.length === 1) return { item: snap.items.get(exactName[0].id), unit_id: exactName[0].unit_id };
  if (!second || top.score >= second.score * 1.3 || top.exact) return { item: snap.items.get(top.id), unit_id: top.unit_id };
  const names = r.items.slice(0, 5).map((x) => snap.items.get(x.id).name);
  throw toolError(`"${q}"에 맞는 물건이 여러 개입니다: ${names.join(", ")}. 어느 것인지 물어보세요.`);
}

export function findLocation(snap, name) {
  const q = String(name || "").trim();
  if (!q) throw toolError("장소 이름을 알려 주세요");
  const n = normalize(q);
  const all = [...snap.locations.values()];
  const exact = all.filter((l) => normalize(l.name) === n || (l.code && normalize(l.code) === n) || normalize(l.path) === n);
  if (exact.length === 1) return exact[0];
  const r = search(snap, q, { limit: 1, locLimit: 6 });
  const hits = r.locations.map((x) => snap.locations.get(x.id));
  if (hits.length === 1 || (hits.length > 1 && r.locations[0].score >= r.locations[1].score * 1.3)) return hits[0];
  if (exact.length > 1) throw toolError(`"${q}" 장소가 여러 곳입니다: ${exact.map((l) => l.path).join(", ")}. 어느 곳인지 물어보세요.`);
  if (hits.length > 1) throw toolError(`"${q}"에 맞는 장소가 여러 곳입니다: ${hits.map((l) => l.path).join(", ")}. 어느 곳인지 물어보세요.`);
  const rooms = all.filter((l) => l.kind === "room").slice(0, 30).map((l) => l.name).join(", ");
  throw toolError(`"${q}" 장소를 찾지 못했습니다. 있는 실: ${rooms}`);
}

function unitsOf(snap, itemId) {
  return (snap.assetsByItem.get(itemId) || []).filter((a) => a.status !== "retired");
}

function findUnit(snap, item, ref) {
  const units = unitsOf(snap, item.id);
  const n = normalize(ref);
  const hit = units.filter((u) => [u.management_number, u.label, u.serial_number, u.qr].some((v) => v && (normalize(v) === n || normalize(v).endsWith(n))));
  if (hit.length === 1) return hit[0];
  if (hit.length > 1) throw toolError(`"${ref}"에 맞는 장비가 여러 대입니다: ${hit.map((u) => u.management_number || u.label).join(", ")}`);
  throw toolError(`${item.name} 중에 "${ref}" 장비가 없습니다. 있는 번호: ${units.slice(0, 10).map((u) => u.management_number || u.label).join(", ")}`);
}

const unitTag = (u) => u.management_number || u.label || u.qr;
const where = (snap, id) => (snap.locations.get(id) || {}).path || "(장소 없음)";

function describe(snap, it) {
  const c = itemCard(snap, it);
  const places = [];
  for (const u of unitsOf(snap, it.id)) {
    if (u.status === "on_loan") continue;
    places.push(where(snap, u.location_id));
  }
  const tally = {};
  for (const p of places) tally[p] = (tally[p] || 0) + 1;
  for (const s of snap.stocksByItem.get(it.id) || []) {
    if (s.quantity > 0) tally[where(snap, s.location_id)] = `${fmtQty((Number(tally[where(snap, s.location_id)]) || 0) + s.quantity)}${it.unit}`;
  }
  return {
    name: it.name, kind: KIND_LABEL[it.kind], status: c.status_label,
    where: Object.entries(tally).slice(0, 6).map(([p, n]) => `${p} ${typeof n === "number" ? `${n}대` : n}`),
    ...(it.kind === "equipment" ? { units: c.units, available: c.available, on_loan: c.on_loan, repair: c.repair } : { quantity: `${fmtQty(c.qty)}${it.unit}`, min_stock: it.min_stock ?? undefined, low: c.low || undefined }),
    maker: [it.manufacturer, it.model].filter(Boolean).join(" ") || undefined,
  };
}

function loanLine(snap, l) {
  const it = snap.items.get(l.item_id) || {};
  const u = l.asset_id && snap.assets.get(l.asset_id);
  const due = l.due_at ? new Date(l.due_at).toLocaleString("ko-KR", { timeZone: "Asia/Seoul", month: "numeric", day: "numeric", hour: "2-digit", minute: "2-digit" }) : "기한 없음";
  return {
    what: `${it.name || "?"}${u ? ` ${unitTag(u)}` : l.quantity > 1 ? ` ${fmtQty(l.quantity)}${it.unit}` : ""}`, borrower: l.borrower_name,
    note: l.borrower_note || undefined, due, overdue: l.overdue || undefined, from: where(snap, l.from_location_id),
  };
}

// 반납 예정 말 → 시각. "오늘" "내일" "3일" "다음 주" "10/5" "2026-10-05"
export function dueFrom(text, dayEnd = "17:00", now = new Date()) {
  const s = String(text || "").trim();
  if (!s || /없|무기한|none/i.test(s)) return null;
  const [hh, mm] = dayEnd.split(":").map(Number);
  const kst = new Date(now.getTime() + 9 * 3600000);
  const at = (y, m, d) => new Date(Date.UTC(y, m, d, hh - 9, mm)).toISOString();
  const addDays = (n) => at(kst.getUTCFullYear(), kst.getUTCMonth(), kst.getUTCDate() + n);
  if (/오늘|today/i.test(s)) return addDays(0);
  if (/모레/.test(s)) return addDays(2);
  if (/내일|tomorrow/i.test(s)) return addDays(1);
  if (/다음\s*주|일주일|1주/.test(s)) return addDays(7);
  let m = /(\d+)\s*일\s*(?:뒤|후|동안)?$/.exec(s);
  if (m && !/\d+\s*월/.test(s)) return addDays(Number(m[1]));
  m = /(?:(\d{4})[-./년]\s*)?(\d{1,2})[-./월]\s*(\d{1,2})/.exec(s);
  if (m) {
    const y = m[1] ? Number(m[1]) : kst.getUTCFullYear();
    return at(y, Number(m[2]) - 1, Number(m[3]));
  }
  const t = Date.parse(s);
  return Number.isFinite(t) ? new Date(t).toISOString() : null;
}

// ---------------------------------------------------------------- 도구
export function toolsFor({ caps, role }) {
  const t = [];
  t.push({
    name: "search_items", kind: "read", label: "물건 찾기",
    description: "물건을 이름·별칭·초성·관리번호·장소로 찾는다. 위치(경로)·상태·수량을 돌려준다. '어디 있어?' 질문에 쓴다.",
    parameters: { type: "object", properties: {
      query: { type: "string", description: "찾을 말. 예: 멀티미터, 용접실 드릴, 전장-2024-017" },
      status: { type: "string", enum: ["all", "available", "on_loan", "overdue", "low", "repair", "aging"], description: "상태로 좁히기" },
    }, required: ["query"] },
    run: ({ query, status }, ctx) => {
      const snap = ctx.snapshot();
      const r = search(snap, query, { limit: 30 });
      let list = r.items.map((x) => snap.items.get(x.id));
      if (status && status !== "all") {
        list = list.filter((it) => {
          const a = snap.agg.get(it.id);
          if (status === "available") return a.available || a.qty > 0;
          if (status === "on_loan") return a.on_loan || a.loaned_qty;
          if (status === "overdue") return a.overdue;
          if (status === "low") return a.low;
          if (status === "repair") return a.repair || a.repairs;
          if (status === "aging") return a.aging;
          return true;
        });
      }
      const items = list.slice(0, LIST).map((it) => describe(snap, it));
      const locations = r.locations.slice(0, 3).map((x) => snap.locations.get(x.id).path);
      return {
        result: { count: list.length, items, locations: locations.length ? locations : undefined, more: list.length > LIST ? list.length - LIST : undefined },
        summary: list.length ? `${list.length}개 찾음` : "없음",
        found: list.slice(0, LIST).map((it) => it.id),
      };
    },
  });

  t.push({
    name: "get_item", kind: "read", label: "물건 자세히",
    description: "물건 하나의 자세한 정보: 장비 한 대씩의 번호·상태·위치·빌린 사람, 위치별 수량, 대여, 수리, 최근 기록.",
    parameters: { type: "object", properties: { item: { type: "string", description: "물건 이름이나 관리번호" } }, required: ["item"] },
    run: ({ item }, ctx) => {
      const snap = ctx.snapshot();
      const { item: it } = findItem(snap, item);
      const staff = role !== "student";
      const units = unitsOf(snap, it.id).slice(0, 20).map((u) => {
        const loan = snap.loanByAsset.get(u.id);
        return { number: unitTag(u), status: ASSET_STATUS_LABEL[u.status], where: where(snap, u.location_id), borrower: loan && staff ? loan.borrower_name : undefined, due: loan && loan.due_at ? loan.due_at.slice(0, 16) : undefined, overdue: loan && loan.overdue ? true : undefined };
      });
      const stocks = (snap.stocksByItem.get(it.id) || []).map((s) => ({ where: where(snap, s.location_id), quantity: `${fmtQty(s.quantity)}${it.unit}`, lot: s.lot_code || undefined, expires: s.expires_at || undefined }));
      const loans = staff ? (snap.loansByItem.get(it.id) || []).filter((l) => !l.asset_id).map((l) => loanLine(snap, l)) : [];
      const events = staff ? listEvents(ctx.db, { item_id: it.id, limit: 5 }).map((e) => `${e.at.slice(5, 16).replace("T", " ")} ${e.actor_name}: ${e.summary}`) : [];
      return {
        result: { ...describe(snap, it), spec: it.spec || undefined, units: units.length ? units : undefined, stocks: stocks.length ? stocks : undefined, loans: loans.length ? loans : undefined, recent: events.length ? events : undefined },
        summary: it.name, found: [it.id],
      };
    },
  });

  t.push({
    name: "location_contents", kind: "read", label: "장소 살펴보기",
    description: "한 장소(하위 선반 포함)에 있는 물건 목록. '공구실에 뭐 있어?'에 쓴다.",
    parameters: { type: "object", properties: { location: { type: "string", description: "장소 이름. 예: 공구실, E-201" } }, required: ["location"] },
    run: ({ location }, ctx) => {
      const snap = ctx.snapshot();
      const loc = findLocation(snap, location);
      const scope = subtree(snap, loc.id);
      const tally = new Map();
      for (const u of snap.assets.values()) {
        if (u.status === "retired" || !scope.has(u.location_id)) continue;
        const it = snap.items.get(u.item_id);
        if (!it || it.archived_at) continue;
        tally.set(it.name, (tally.get(it.name) || 0) + 1);
      }
      const lines = [...tally].map(([n, c]) => `${n} ${c}대`);
      for (const s of snap.stocks.values()) {
        if (!scope.has(s.location_id) || s.quantity <= 0) continue;
        const it = snap.items.get(s.item_id);
        if (!it || it.archived_at) continue;
        lines.push(`${it.name} ${fmtQty(s.quantity)}${it.unit}`);
      }
      return { result: { location: loc.path, count: lines.length, items: lines.slice(0, 25), more: lines.length > 25 ? lines.length - 25 : undefined }, summary: `${loc.name} ${lines.length}종`, location_id: loc.id };
    },
  });

  t.push({
    name: "list_loans", kind: "read", label: "대여 현황",
    description: "빌려 간 것 목록. 연체·오늘 반납·특정 사람이 빌린 것.",
    parameters: { type: "object", properties: {
      filter: { type: "string", enum: ["active", "overdue", "today", "mine"] },
      borrower: { type: "string", description: "빌린 사람 이름(일부)" },
    } },
    run: ({ filter = "active", borrower }, ctx) => {
      const snap = ctx.snapshot();
      let list = snap.loansActive;
      if (filter === "overdue") list = list.filter((l) => l.overdue);
      else if (filter === "today") list = list.filter((l) => l.due_today || l.overdue);
      if (filter === "mine" || role === "student") list = list.filter((l) => l.borrower_user_id === ctx.user.id);
      if (borrower) list = list.filter((l) => normalize(l.borrower_name).includes(normalize(borrower)));
      return { result: { count: list.length, loans: list.slice(0, 15).map((l) => loanLine(snap, l)) }, summary: `${list.length}건` };
    },
  });

  if (role !== "student") {
    t.push({
      name: "list_alerts", kind: "read", label: "경보 확인",
      description: "지금 챙겨야 할 일: 연체, 재고 부족, 유통기한, 고장 신고, 내용연수 지난 장비.",
      parameters: { type: "object", properties: {} },
      run: (_a, ctx) => {
        const snap = ctx.snapshot();
        const { level, alerts } = computeAlerts(snap, { user: ctx.user, caps });
        return { result: { condition: level, alerts: alerts.map((a) => ({ title: a.title, detail: a.detail })) }, summary: `${alerts.length}건` };
      },
    });
    t.push({
      name: "recent_activity", kind: "read", label: "최근 기록",
      description: "최근 작업 기록(등록·이동·대여·반납·사용). 물건이나 장소로 좁힐 수 있다. '드릴 누가 가져갔어?'에 쓴다.",
      parameters: { type: "object", properties: { item: { type: "string" }, location: { type: "string" }, limit: { type: "integer" } } },
      run: ({ item, location, limit }, ctx) => {
        const snap = ctx.snapshot();
        const f = { limit: Math.min(15, Number(limit) || 10) };
        if (item) f.item_id = findItem(snap, item).item.id;
        if (location) f.location_ids = [...subtree(snap, findLocation(snap, location).id)];
        const ev = listEvents(ctx.db, f);
        return { result: { events: ev.map((e) => `${new Date(e.at).toLocaleString("ko-KR", { timeZone: "Asia/Seoul", month: "numeric", day: "numeric", hour: "2-digit", minute: "2-digit" })} ${e.actor_name}: ${e.summary}`) }, summary: `${ev.length}건` };
      },
    });
  }

  // ---- 제안 카드
  if (caps.has("move")) {
    t.push({
      name: "propose_move", kind: "propose", label: "이동 제안",
      description: "물건을 다른 장소로 옮기는 제안 카드. 장비는 번호(unit)나 대수(count), 수량 품목은 quantity 를 준다.",
      parameters: { type: "object", properties: {
        items: { type: "array", items: { type: "object", properties: {
          item: { type: "string", description: "물건 이름" }, unit: { type: "string", description: "장비 관리번호(있으면)" },
          count: { type: "integer", description: "장비를 몇 대 옮길지(번호가 없을 때)" }, quantity: { type: "number", description: "수량 품목을 얼마나" },
          from: { type: "string", description: "어디에서(여러 곳에 있을 때)" },
        }, required: ["item"] } },
        to: { type: "string", description: "옮길 장소" },
      }, required: ["items", "to"] },
      run: ({ items, to }, ctx) => {
        const snap = ctx.snapshot();
        const dest = findLocation(snap, to);
        const targets = [];
        const lines = [];
        for (const x of [].concat(items || [])) {
          const { item: it, unit_id } = findItem(snap, x.item);
          const fromLoc = x.from ? findLocation(snap, x.from) : null;
          const scope = fromLoc ? subtree(snap, fromLoc.id) : null;
          if (it.kind === "equipment") {
            let pool = unitsOf(snap, it.id).filter((u) => u.status !== "on_loan" && u.location_id !== dest.id && (!scope || scope.has(u.location_id)));
            if (x.unit) pool = [findUnit(snap, it, x.unit)];
            else if (unit_id) pool = pool.filter((u) => u.id === unit_id);
            const n = x.unit || unit_id ? 1 : Math.max(1, Number(x.count) || pool.length);
            if (!pool.length) throw toolError(`${it.name} 중 옮길 수 있는 장비가 없습니다(대여 중이거나 이미 ${dest.name}에 있음)`);
            for (const u of pool.slice(0, n)) {
              targets.push({ asset_id: u.id });
              lines.push(`${it.name} ${unitTag(u)} · ${where(snap, u.location_id)} → ${dest.path}`);
            }
          } else {
            const stocks = (snap.stocksByItem.get(it.id) || []).filter((s) => s.quantity > 0 && s.location_id !== dest.id && (!scope || scope.has(s.location_id)));
            if (!stocks.length) throw toolError(`${it.name}을(를) 옮길 재고가 없습니다`);
            let need = x.quantity ? Number(x.quantity) : null;
            for (const s of stocks.sort((a, b) => b.quantity - a.quantity)) {
              const q = need === null ? s.quantity : Math.min(need, s.quantity);
              if (q <= 0) break;
              targets.push({ stock_id: s.id, qty: q });
              lines.push(`${it.name} ${fmtQty(q)}${it.unit} · ${where(snap, s.location_id)} → ${dest.path}`);
              if (need !== null) need -= q;
              if (need !== null && need <= 0) break;
            }
            if (need !== null && need > 0) throw toolError(`${it.name} 재고가 모자랍니다(${fmtQty(need)}${it.unit} 부족)`);
          }
        }
        if (!targets.length) throw toolError("옮길 것이 없습니다");
        const p = {
          id: proposalId(), kind: "move", title: `${dest.name}(으)로 ${targets.length}건 이동`, summary: `옮길 곳: ${dest.path}`,
          lines: lines.slice(0, 10).concat(lines.length > 10 ? [`…외 ${lines.length - 10}건`] : []), confirm: "옮기기", tone: "primary",
          request: { path: "/api/actions/move", body: { targets, to_location_id: dest.id } }, after: { station: "decks", params: { loc: dest.id } },
        };
        return { proposal: p, result: { proposal_shown: true, title: p.title, status: "아직 실행 전. 선생님이 카드의 [옮기기]를 눌러야 진행됩니다." }, summary: p.title };
      },
    });
  }

  if (caps.has("loan")) {
    t.push({
      name: "propose_loan", kind: "propose", label: "대여 제안",
      description: "물건을 빌려주는 제안 카드. borrower 는 빌리는 사람 이름. due 는 '오늘', '내일', '3일', '10/5' 같은 말.",
      parameters: { type: "object", properties: {
        item: { type: "string" }, unit: { type: "string", description: "장비 관리번호(있으면)" }, count: { type: "integer", description: "장비 몇 대" },
        quantity: { type: "number", description: "수량 품목을 얼마나" }, borrower: { type: "string", description: "빌리는 사람" },
        borrower_note: { type: "string", description: "반·번호·연락처 등" }, due: { type: "string", description: "반납 예정" }, purpose: { type: "string" },
      }, required: ["item"] },
      run: (a, ctx) => {
        const snap = ctx.snapshot();
        const { item: it, unit_id } = findItem(snap, a.item);
        const borrower = String(a.borrower || "").trim() || ctx.user.name;
        const due = dueFrom(a.due || ctx.defaultDue, ctx.dayEnd);
        const dueText = due ? new Date(due).toLocaleString("ko-KR", { timeZone: "Asia/Seoul", month: "numeric", day: "numeric", weekday: "short", hour: "2-digit", minute: "2-digit" }) : "기한 없음";
        const body = { borrower_name: borrower, borrower_note: a.borrower_note || null, due_at: due, purpose: a.purpose || null };
        const matchUser = [...snap.users.values()].find((u) => u.status === "active" && normalize(u.name) === normalize(borrower));
        if (matchUser) body.borrower_user_id = matchUser.id;
        let lines;
        if (it.kind === "equipment") {
          let pool = unitsOf(snap, it.id).filter((u) => u.status === "available");
          if (a.unit) {
            const u = findUnit(snap, it, a.unit);
            if (u.status !== "available") throw toolError(`${it.name} ${unitTag(u)}은(는) 지금 ${ASSET_STATUS_LABEL[u.status]}입니다`);
            pool = [u];
          } else if (unit_id) pool = pool.filter((u) => u.id === unit_id);
          const n = Math.max(1, Number(a.count) || 1);
          if (pool.length < n) throw toolError(`${it.name} 중 빌려줄 수 있는 것이 ${pool.length}대뿐입니다`);
          const picked = pool.slice(0, n);
          body.asset_ids = picked.map((u) => u.id);
          lines = picked.map((u) => `${it.name} ${unitTag(u)} (${where(snap, u.location_id)})`);
        } else {
          const q = Number(a.quantity) || 1;
          const s = (snap.stocksByItem.get(it.id) || []).filter((x) => x.quantity >= q).sort((x, y) => y.quantity - x.quantity)[0];
          if (!s) throw toolError(`${it.name} ${fmtQty(q)}${it.unit}을(를) 빌려줄 만큼 한곳에 있는 재고가 없습니다`);
          body.stock_id = s.id;
          body.qty = q;
          lines = [`${it.name} ${fmtQty(q)}${it.unit} (${where(snap, s.location_id)})`];
        }
        const p = {
          id: proposalId(), kind: "loan", title: `${borrower}에게 대여`, summary: `반납 예정: ${dueText}${a.purpose ? ` · ${a.purpose}` : ""}`,
          lines, confirm: "빌려주기", tone: "primary", request: { path: "/api/actions/loan", body }, after: { station: "item", params: { id: it.id } },
        };
        return { proposal: p, result: { proposal_shown: true, title: p.title, status: "아직 실행 전입니다. 카드의 [빌려주기]를 눌러야 진행됩니다." }, summary: p.title };
      },
    });
    t.push({
      name: "propose_return", kind: "propose", label: "반납 제안",
      description: "빌려 간 것을 돌려받는 제안 카드. 물건 이름이나 빌린 사람으로 대여를 찾는다.",
      parameters: { type: "object", properties: { item: { type: "string" }, unit: { type: "string" }, borrower: { type: "string" }, to: { type: "string", description: "돌려놓을 곳(없으면 빌려 간 곳)" } } },
      run: (a, ctx) => {
        const snap = ctx.snapshot();
        let list = snap.loansActive;
        if (a.item) {
          const { item: it } = findItem(snap, a.item);
          list = list.filter((l) => l.item_id === it.id);
          if (a.unit) {
            const u = findUnit(snap, it, a.unit);
            list = list.filter((l) => l.asset_id === u.id);
          }
        }
        if (a.borrower) list = list.filter((l) => normalize(l.borrower_name).includes(normalize(a.borrower)));
        if (!a.item && !a.borrower) throw toolError("무엇을(또는 누가 빌린 것을) 반납할지 알려 주세요");
        if (!list.length) throw toolError("맞는 대여가 없습니다");
        if (list.length > 12) throw toolError(`맞는 대여가 ${list.length}건이라 너무 많습니다. 물건이나 사람을 좁혀 주세요.`);
        const body = { loan_ids: list.map((l) => l.id), condition: "ok" };
        let dest = null;
        if (a.to) { dest = findLocation(snap, a.to); body.to_location_id = dest.id; }
        const p = {
          id: proposalId(), kind: "return", title: `${list.length}건 반납`, summary: dest ? `돌려놓을 곳: ${dest.path}` : "빌려 간 곳으로 돌려놓습니다",
          lines: list.map((l) => { const x = loanLine(snap, l); return `${x.what} · ${x.borrower}${x.overdue ? " (연체)" : ""}`; }),
          confirm: "반납 받기", tone: "primary", request: { path: "/api/actions/return", body }, after: { station: "dock", params: { tab: "loans" } },
        };
        return { proposal: p, result: { proposal_shown: true, title: p.title, status: "아직 실행 전입니다." }, summary: p.title };
      },
    });
  }

  if (caps.has("stock")) {
    t.push({
      name: "propose_use", kind: "propose", label: "사용 제안",
      description: "소모품·부품을 써서 줄이는(출고) 제안 카드.",
      parameters: { type: "object", properties: { item: { type: "string" }, quantity: { type: "number" }, location: { type: "string", description: "어디 것을(여러 곳에 있을 때)" }, purpose: { type: "string" } }, required: ["item", "quantity"] },
      run: (a, ctx) => {
        const snap = ctx.snapshot();
        const { item: it } = findItem(snap, a.item);
        if (it.kind === "equipment") throw toolError(`${it.name}은(는) 장비라 '사용'이 아니라 대여로 처리합니다`);
        const q = Number(a.quantity);
        if (!(q > 0)) throw toolError("얼마나 썼는지 수량을 알려 주세요");
        let stocks = (snap.stocksByItem.get(it.id) || []).filter((s) => s.quantity > 0);
        if (a.location) { const scope = subtree(snap, findLocation(snap, a.location).id); stocks = stocks.filter((s) => scope.has(s.location_id)); }
        const s = stocks.filter((x) => x.quantity >= q).sort((x, y) => y.quantity - x.quantity)[0];
        if (!s) throw toolError(`${it.name} ${fmtQty(q)}${it.unit}이(가) 한곳에 없습니다. 남은 곳: ${stocks.map((x) => `${where(snap, x.location_id)} ${fmtQty(x.quantity)}`).join(", ") || "없음"}`);
        const p = {
          id: proposalId(), kind: "use", title: `${it.name} ${fmtQty(q)}${it.unit} 사용`, summary: `${where(snap, s.location_id)} · 남을 수량 ${fmtQty(s.quantity - q)}${it.unit}${a.purpose ? ` · ${a.purpose}` : ""}`,
          lines: [], confirm: "사용 처리", tone: "primary", request: { path: "/api/actions/use", body: { stock_id: s.id, qty: q, purpose: a.purpose || null } }, after: { station: "item", params: { id: it.id } },
        };
        return { proposal: p, result: { proposal_shown: true, title: p.title, status: "아직 실행 전입니다." }, summary: p.title };
      },
    });
    t.push({
      name: "propose_restock", kind: "propose", label: "입고 제안",
      description: "소모품·부품을 채우는(입고) 제안 카드.",
      parameters: { type: "object", properties: { item: { type: "string" }, quantity: { type: "number" }, location: { type: "string" } }, required: ["item", "quantity"] },
      run: (a, ctx) => {
        const snap = ctx.snapshot();
        const { item: it } = findItem(snap, a.item);
        if (it.kind === "equipment") throw toolError(`${it.name}은(는) 장비라 등록 화면에서 대수를 추가합니다`);
        const q = Number(a.quantity);
        if (!(q > 0)) throw toolError("넣을 수량을 알려 주세요");
        let loc;
        if (a.location) loc = findLocation(snap, a.location);
        else {
          const s = (snap.stocksByItem.get(it.id) || []).sort((x, y) => y.quantity - x.quantity)[0];
          if (!s) throw toolError("어디에 넣을지 장소를 알려 주세요");
          loc = snap.locations.get(s.location_id);
        }
        const p = {
          id: proposalId(), kind: "restock", title: `${it.name} ${fmtQty(q)}${it.unit} 입고`, summary: `넣을 곳: ${loc.path}`, lines: [],
          confirm: "입고", tone: "primary", request: { path: "/api/actions/restock", body: { item_id: it.id, location_id: loc.id, qty: q } }, after: { station: "item", params: { id: it.id } },
        };
        return { proposal: p, result: { proposal_shown: true, title: p.title, status: "아직 실행 전입니다." }, summary: p.title };
      },
    });
  }

  if (caps.has("repair")) {
    t.push({
      name: "propose_repair", kind: "propose", label: "고장 신고 제안",
      description: "고장·이상 신고 카드.",
      parameters: { type: "object", properties: { item: { type: "string" }, unit: { type: "string" }, title: { type: "string", description: "짧은 증상" }, detail: { type: "string" }, urgent: { type: "boolean" } }, required: ["item", "title"] },
      run: (a, ctx) => {
        const snap = ctx.snapshot();
        const { item: it, unit_id } = findItem(snap, a.item);
        let target = { target_type: "item", target_id: it.id };
        let label = it.name;
        if (it.kind === "equipment") {
          const u = a.unit ? findUnit(snap, it, a.unit) : unit_id ? snap.assets.get(unit_id) : unitsOf(snap, it.id).length === 1 ? unitsOf(snap, it.id)[0] : null;
          if (u) { target = { target_type: "asset", target_id: u.id }; label = `${it.name} ${unitTag(u)}`; }
        }
        const p = {
          id: proposalId(), kind: "repair", title: `고장 신고: ${label}`, summary: a.title, lines: a.detail ? [a.detail] : [],
          confirm: "신고하기", tone: a.urgent ? "warn" : "primary", request: { path: "/api/repairs", body: { ...target, title: a.title, body: a.detail || "", urgency: a.urgent ? "urgent" : "normal" } },
          after: { station: "repair", params: {} },
        };
        return { proposal: p, result: { proposal_shown: true, title: p.title, status: "아직 실행 전입니다." }, summary: p.title };
      },
    });
  }

  if (caps.has("register")) {
    t.push({
      name: "draft_new_item", kind: "client", label: "등록 화면 채우기",
      description: "새 물건 등록 화면을 알맞게 채워서 연다(저장은 선생님이 한다). kind: equipment(한 대씩 관리하는 장비), fixture(비품), consumable(소모품), part(부품).",
      parameters: { type: "object", properties: {
        name: { type: "string" }, kind: { type: "string", enum: KINDS }, location: { type: "string" }, quantity: { type: "number", description: "수량(장비면 대수)" },
        manufacturer: { type: "string" }, model: { type: "string" }, spec: { type: "string" }, category: { type: "string" }, aliases: { type: "string" },
      }, required: ["name"] },
      run: (a, ctx) => {
        const snap = ctx.snapshot();
        const draft = { name: a.name, kind: KINDS.includes(a.kind) ? a.kind : undefined, manufacturer: a.manufacturer, model: a.model, spec: a.spec, aliases: a.aliases, quantity: a.quantity };
        if (a.location) { try { draft.location_id = findLocation(snap, a.location).id; } catch { /* 모르면 비워 둔다 */ } }
        if (a.category) {
          const c = [...snap.categories.values()].find((x) => normalize(x.name) === normalize(a.category));
          if (c) draft.category_id = c.id;
        }
        return { action: { type: "open_add", draft }, result: { opened: true, note: "등록 화면을 채워서 열었습니다. 사진을 찍고 [저장]만 누르면 됩니다." }, summary: "등록 화면" };
      },
    });
  }

  t.push({
    name: "navigate", kind: "client", label: "화면 열기",
    description: "화면을 연다. view: home(함교), search(찾기·목록), item(물건 화면), location(장소 화면), loans(대여), repairs(고장), audit(실사), log(기록), add(등록).",
    parameters: { type: "object", properties: {
      view: { type: "string", enum: ["home", "search", "item", "location", "loans", "repairs", "audit", "log", "add"] },
      item: { type: "string" }, location: { type: "string" }, query: { type: "string" },
      filter: { type: "string", enum: ["low", "on_loan", "overdue", "repair", "aging", "expiring", "favorite"], description: "찾기 화면 필터" },
    }, required: ["view"] },
    run: (a, ctx) => {
      const snap = ctx.snapshot();
      let nav;
      if (a.view === "item") {
        const { item: it, unit_id } = findItem(snap, a.item || a.query);
        nav = { station: "item", params: { id: it.id, ...(unit_id ? { unit: unit_id } : {}) } };
      } else if (a.view === "location") {
        const loc = findLocation(snap, a.location || a.query);
        nav = { station: "decks", params: { loc: loc.id } };
      } else if (a.view === "search") nav = { station: "search", params: { q: a.query || "", status: a.filter || "" } };
      else if (a.view === "loans") nav = { station: "dock", params: { tab: "loans", filter: a.filter === "overdue" ? "overdue" : "" } };
      else if (a.view === "repairs") nav = { station: "repair", params: {} };
      else if (a.view === "audit") nav = { station: "audit", params: {} };
      else if (a.view === "log") nav = { station: "log", params: {} };
      else if (a.view === "add") return { action: { type: "open_add", draft: {} }, result: { opened: true }, summary: "등록 화면" };
      else nav = { station: "bridge", params: {} };
      return { navigate: nav, result: { opened: true, view: a.view }, summary: "화면 이동" };
    },
  });

  t.push({
    name: "show_on_map", kind: "client", label: "지도에 표시",
    description: "선내 지도(홀로그램)에서 장소나 물건이 있는 곳을 반짝이게 보여 준다.",
    parameters: { type: "object", properties: { location: { type: "string" }, item: { type: "string" } } },
    run: (a, ctx) => {
      const snap = ctx.snapshot();
      let ids = [];
      if (a.item) {
        const { item: it } = findItem(snap, a.item);
        ids = [...(snap.agg.get(it.id) || { location_ids: new Set() }).location_ids];
      } else if (a.location) ids = [findLocation(snap, a.location).id];
      if (!ids.length) throw toolError("표시할 장소가 없습니다");
      return { action: { type: "highlight", location_ids: ids }, result: { shown: ids.map((id) => where(snap, id)) }, summary: "지도 표시" };
    },
  });

  return t;
}

// 이니에게 주는 현황표(시스템 프롬프트에 들어간다)
export function snapshotText(ctx, user, caps) {
  const snap = ctx.snapshot();
  const c = dashboardCounts(snap);
  const { alerts } = computeAlerts(snap, { user, caps });
  const rooms = [];
  for (const id of snap.roots) {
    const b = snap.locations.get(id);
    const kids = [...subtree(snap, id)].map((x) => snap.locations.get(x)).filter((l) => l.kind === "room").map((l) => `${l.name}${l.code ? `(${l.code})` : ""}`);
    rooms.push(kids.length ? `${b.name}: ${kids.join(", ")}` : b.name);
  }
  const lines = [
    `품목 ${c.items}종(장비 ${c.units}대), 실 ${c.rooms}곳, 대여 중 ${c.loans}건(연체 ${c.overdue}건), 재고 부족 ${c.low}품목, 수리 중·고장 ${c.repairs}건`,
    alerts.length ? `경보:\n${alerts.slice(0, 6).map((a) => `- ${a.title}${a.detail ? `: ${a.detail}` : ""}`).join("\n")}` : "경보: 없음",
    `장소: ${rooms.join(" / ").slice(0, 900)}`,
  ];
  return lines.join("\n");
}

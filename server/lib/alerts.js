// 경보(CONDITION)와 개요 숫자. 규칙으로만 계산한다(AI 불필요).
import { KIND_LABEL } from "./inventory.js";
import { lifeEnd } from "./snapshot.js";
import { fmtQty } from "./util.js";

const DAY = 86400000;

function daysLate(due, now) {
  return Math.max(1, Math.ceil((now - Date.parse(due)) / DAY));
}

export function computeAlerts(snap, { now = Date.now(), user = null, caps = new Set(), pendingUsers = 0, update = null } = {}) {
  const alerts = [];
  const itemName = (id) => (snap.items.get(id) || {}).name || "?";

  const overdue = snap.loansActive.filter((l) => l.overdue);
  if (overdue.length) {
    alerts.push({
      id: "overdue", level: "crit", kind: "overdue", count: overdue.length,
      title: `반납이 늦은 대여 ${overdue.length}건`,
      detail: overdue.slice(0, 4).map((l) => `${itemName(l.item_id)} · ${l.borrower_name} (${daysLate(l.due_at, now)}일)`).join(", "),
      go: { station: "dock", params: { tab: "loans", filter: "overdue" } },
      entities: overdue.slice(0, 20).map((l) => ({ loan_id: l.id, item_id: l.item_id })),
    });
  }

  const urgent = snap.repairsOpen.filter((r) => r.urgency === "urgent");
  if (urgent.length) {
    alerts.push({
      id: "repair-urgent", level: "crit", kind: "repair", count: urgent.length,
      title: `급한 고장 신고 ${urgent.length}건`, detail: urgent.slice(0, 3).map((r) => r.title).join(", "),
      go: { station: "repair", params: { status: "open" } },
    });
  }

  const expired = [];
  const expiring = [];
  for (const s of snap.stocks.values()) {
    if (!s.expires_at || s.quantity <= 0) continue;
    const it = snap.items.get(s.item_id);
    if (!it || it.archived_at) continue;
    const t = Date.parse(s.expires_at);
    if (t < now) expired.push(s);
    else if (t < now + 30 * DAY) expiring.push(s);
  }
  if (expired.length) {
    alerts.push({
      id: "expired", level: "warn", kind: "expired", count: expired.length, title: `유통기한 지난 재고 ${expired.length}건`,
      detail: expired.slice(0, 4).map((s) => itemName(s.item_id)).join(", "), go: { station: "search", params: { status: "expiring" } },
    });
  }

  const low = [...snap.items.values()].filter((it) => !it.archived_at && snap.agg.get(it.id) && snap.agg.get(it.id).low);
  if (low.length) {
    alerts.push({
      id: "low", level: "warn", kind: "low", count: low.length, title: `재고 부족 ${low.length}품목`,
      detail: low.slice(0, 5).map((it) => `${it.name} ${fmtQty(snap.agg.get(it.id).qty)}/${fmtQty(it.min_stock)}${it.unit}`).join(", "),
      go: { station: "search", params: { status: "low" } },
      entities: low.slice(0, 30).map((it) => ({ item_id: it.id })),
    });
  }

  const dueToday = snap.loansActive.filter((l) => l.due_today);
  if (dueToday.length) {
    alerts.push({
      id: "due-today", level: "info", kind: "due", count: dueToday.length, title: `오늘 반납 예정 ${dueToday.length}건`,
      detail: dueToday.slice(0, 4).map((l) => `${itemName(l.item_id)} · ${l.borrower_name}`).join(", "),
      go: { station: "dock", params: { tab: "loans" } },
    });
  }
  if (expiring.length) {
    alerts.push({
      id: "expiring", level: "info", kind: "expiring", count: expiring.length, title: `30일 안에 유통기한 ${expiring.length}건`,
      detail: expiring.slice(0, 4).map((s) => itemName(s.item_id)).join(", "), go: { station: "search", params: { status: "expiring" } },
    });
  }
  const openRepairs = snap.repairsOpen.filter((r) => r.urgency !== "urgent");
  if (openRepairs.length) {
    alerts.push({
      id: "repair", level: "info", kind: "repair", count: openRepairs.length, title: `처리할 고장 신고 ${openRepairs.length}건`,
      detail: openRepairs.slice(0, 3).map((r) => r.title).join(", "), go: { station: "repair", params: { status: "open" } },
    });
  }
  // NAS 자동 업데이트가 실패했거나 한동안 돌지 않으면 관리자에게
  if (update && caps.has("system")) {
    const go = { station: "systems", params: { tab: "about" } };
    if (update.status === "error" || update.status === "rolled_back" || update.status === "held") {
      const title = { rolled_back: "자동 업데이트를 되돌렸어요", held: "새 버전이 보류 중이에요(이전 버전으로 정상 운영)" }[update.status] || "자동 업데이트가 멈췄어요";
      alerts.push({ id: "auto-update", level: "warn", kind: "system", count: 1, title, detail: String(update.message || ""), go });
    } else if (update.checked_at && now - Date.parse(update.checked_at) > 3 * 3600000) {
      alerts.push({ id: "auto-update", level: "info", kind: "system", count: 1, title: "자동 업데이트 확인이 3시간 넘게 없어요", detail: "NAS 작업 스케줄러가 켜져 있는지 확인하세요.", go });
    }
  }
  if (pendingUsers && caps.has("users")) {
    alerts.push({
      id: "pending-users", level: "warn", kind: "users", count: pendingUsers, title: `승인을 기다리는 사용자 ${pendingUsers}명`,
      detail: "승무원 화면에서 역할을 정하고 승인하세요.", go: { station: "crew", params: { status: "pending" } },
    });
  }
  let aging = 0;
  for (const a of snap.assets.values()) {
    if (a.status === "retired") continue;
    const end = lifeEnd(a, snap.items.get(a.item_id));
    if (end && end < now) aging += 1;
  }
  if (aging) {
    alerts.push({
      id: "aging", level: "info", kind: "aging", count: aging, title: `내용연수가 지난 장비 ${aging}대`,
      detail: "교체·폐기 계획을 세울 때 참고하세요.", go: { station: "search", params: { status: "aging" } },
    });
  }
  // 내 대여
  if (user) {
    const mine = snap.loansActive.filter((l) => l.borrower_user_id === user.id);
    const late = mine.filter((l) => l.overdue);
    if (late.length) {
      alerts.unshift({
        id: "my-overdue", level: "crit", kind: "mine", count: late.length, title: `내가 빌린 것 중 반납이 늦은 것 ${late.length}건`,
        detail: late.slice(0, 3).map((l) => itemName(l.item_id)).join(", "), go: { station: "dock", params: { tab: "mine" } },
      });
    }
  }
  const level = alerts.some((a) => a.level === "crit") ? "red" : alerts.some((a) => a.level === "warn") ? "yellow" : "green";
  return { level, alerts };
}

export function dashboardCounts(snap) {
  let items = 0;
  let units = 0;
  let qtyLines = 0;
  const byKind = { equipment: 0, fixture: 0, consumable: 0, part: 0 };
  for (const it of snap.items.values()) {
    if (it.archived_at) continue;
    items += 1;
    byKind[it.kind] = (byKind[it.kind] || 0) + 1;
    const a = snap.agg.get(it.id);
    if (a) units += a.units;
  }
  for (const s of snap.stocks.values()) if (s.quantity > 0) qtyLines += 1;
  const rooms = [...snap.locations.values()].filter((l) => l.kind === "room").length;
  return {
    items, units, stock_lines: qtyLines, locations: snap.locations.size, rooms,
    loans: snap.loansActive.length, overdue: snap.loansActive.filter((l) => l.overdue).length,
    low: [...snap.agg.values()].filter((a) => a.low).length, repairs: snap.repairsOpen.length,
    by_kind: Object.entries(byKind).map(([k, n]) => ({ kind: k, label: KIND_LABEL[k], count: n })),
  };
}

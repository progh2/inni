// 텔레그램 알림: 연체·재고 부족·고장 신고·백업. 같은 일은 한 번만 보낸다.
import { getSecret } from "./db.js";
import { section, schoolName } from "./settings.js";
import { nowIso } from "./util.js";

export async function sendTelegram(ctx, text, { token, chatIds } = {}) {
  const tg = section(ctx.db, "telegram");
  const bot = token || getSecret(ctx.db, "telegram_token");
  const ids = chatIds || tg.chat_ids || [];
  if (!bot) throw new Error("텔레그램 봇 토큰이 없습니다");
  if (!ids.length) throw new Error("보낼 채팅 ID 가 없습니다");
  const f = ctx.cfg.fetch;
  const results = [];
  for (const id of ids) {
    const resp = await f(`https://api.telegram.org/bot${bot}/sendMessage`, {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ chat_id: id, text, disable_web_page_preview: true }),
      signal: AbortSignal.timeout(10000),
    });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || !data.ok) throw new Error(`텔레그램 전송 실패 (${id}): ${data.description || resp.status}`);
    results.push(id);
  }
  return results;
}

function once(ctx, key) {
  const hit = ctx.db.prepare("SELECT 1 FROM alert_dispatches WHERE key = ?").get(key);
  if (hit) return false;
  ctx.db.prepare("INSERT INTO alert_dispatches(key, sent_at) VALUES(?, ?)").run(key, nowIso());
  return true;
}

// 주기적으로 부른다. 새로 생긴 일만 모아 한 번에 보낸다.
export async function runTelegramAlerts(ctx) {
  const tg = section(ctx.db, "telegram");
  if (!tg.enabled || !getSecret(ctx.db, "telegram_token") || !(tg.chat_ids || []).length) return { sent: 0 };
  const snap = ctx.snapshot();
  const lines = [];
  if (tg.overdue) {
    for (const l of snap.loansActive.filter((x) => x.overdue)) {
      if (!once(ctx, `overdue:${l.id}`)) continue;
      const it = snap.items.get(l.item_id) || {};
      const u = l.asset_id && snap.assets.get(l.asset_id);
      lines.push(`⏰ 연체: ${it.name || "?"}${u && u.management_number ? ` ${u.management_number}` : ""} · ${l.borrower_name} (${l.due_at.slice(0, 10)}까지)`);
    }
  }
  if (tg.low) {
    const lowNow = new Set();
    for (const it of snap.items.values()) {
      const a = snap.agg.get(it.id);
      if (!a || !a.low || it.archived_at) continue;
      lowNow.add(it.id);
      if (!once(ctx, `low:${it.id}`)) continue;
      lines.push(`📦 재고 부족: ${it.name} ${a.qty}/${it.min_stock}${it.unit}`);
    }
    // 채워진 품목은 다음에 다시 알릴 수 있게 지운다
    for (const r of ctx.db.prepare("SELECT key FROM alert_dispatches WHERE key LIKE 'low:%'").all()) {
      if (!lowNow.has(r.key.slice(4))) ctx.db.prepare("DELETE FROM alert_dispatches WHERE key = ?").run(r.key);
    }
  }
  if (tg.repair) {
    for (const r of snap.repairsOpen) {
      if (!once(ctx, `repair:${r.id}`)) continue;
      lines.push(`🔧 고장 신고${r.urgency === "urgent" ? "(급함)" : ""}: ${r.title} · ${r.reporter_name}`);
    }
  }
  if (!lines.length) return { sent: 0 };
  const text = `[inni · ${schoolName(ctx)}]\n${lines.slice(0, 30).join("\n")}${lines.length > 30 ? `\n…외 ${lines.length - 30}건` : ""}`;
  await sendTelegram(ctx, text);
  return { sent: lines.length };
}

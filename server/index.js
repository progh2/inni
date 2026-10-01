// inni 서버 시작점. 정해진 때 자동 백업·텔레그램 알림·세션 정리를 한다.
import { createApp } from "./app.js";
import { validateConfig } from "./lib/config.js";
import { section } from "./lib/settings.js";
import { createBackup, listBackups, pruneBackups } from "./io/backup.js";
import { runTelegramAlerts, sendTelegram } from "./lib/telegram.js";
import { purgeSessions } from "./lib/auth.js";
import { seoulParts, fmtBytes } from "./lib/util.js";

const { app, ctx, close } = createApp();
const problems = validateConfig(ctx.cfg);
if (problems.length) {
  console.error(`설정을 확인하세요:\n - ${problems.join("\n - ")}`);
  if (ctx.cfg.authMode === "firebase") console.error("(Firebase 없이 화면만 보려면 AUTH_MODE=dev SEED_DEMO=1 로 띄우세요)");
}

const server = app.listen(ctx.cfg.port, ctx.cfg.host, () => {
  console.log(`inni ${ctx.cfg.version} · http://${ctx.cfg.host}:${ctx.cfg.port} · 로그인 ${ctx.cfg.authMode} · 데이터 ${ctx.cfg.dataDir}`);
});

// ---------------------------------------------------------------- 주기 작업
let backingUp = false;
async function autoBackup() {
  const cfg = section(ctx.db, "backup");
  if (!cfg.auto || backingUp) return;
  const now = seoulParts();
  if (now.hh < cfg.hour) return;
  const today = now.ymd.replace(/-/g, "");
  if (listBackups(ctx).some((b) => b.auto && b.name.includes(`-${today}-`))) return;
  backingUp = true;
  try {
    const out = await createBackup(ctx, { reason: "auto" });
    const removed = pruneBackups(ctx, cfg.keep);
    console.log(`[백업] 자동 백업 ${out.name} (${fmtBytes(out.bytes)})${removed ? ` · 오래된 것 ${removed}개 정리` : ""}`);
    const tg = section(ctx.db, "telegram");
    if (tg.enabled && tg.backup) await sendTelegram(ctx, `[inni] 자동 백업 완료: ${out.name}`).catch(() => {});
  } catch (e) {
    console.error("[백업] 자동 백업 실패:", e.message);
    const tg = section(ctx.db, "telegram");
    if (tg.enabled) await sendTelegram(ctx, `[inni] ⚠ 자동 백업 실패: ${e.message}`).catch(() => {});
  } finally {
    backingUp = false;
  }
}

async function alerts() {
  try {
    const out = await runTelegramAlerts(ctx);
    if (out.sent) console.log(`[알림] 텔레그램 ${out.sent}건`);
  } catch (e) {
    console.error("[알림] 텔레그램 실패:", e.message);
  }
}

// 대여 기한이 지나는 순간을 화면에 알리려고 현황판을 가끔 새로 만든다
let lastOverdue = -1;
function tick() {
  ctx.invalidate();
  const n = ctx.snapshot().loansActive.filter((l) => l.overdue).length;
  if (lastOverdue >= 0 && n !== lastOverdue) ctx.changed({ kind: "overdue" });
  lastOverdue = n;
}

const timers = [];
if (ctx.cfg.jobs) {
  timers.push(setInterval(autoBackup, 10 * 60000));
  timers.push(setInterval(alerts, 15 * 60000));
  timers.push(setInterval(() => purgeSessions(ctx), 6 * 3600000));
  timers.push(setInterval(tick, 60000));
  setTimeout(autoBackup, 30000);
  setTimeout(alerts, 60000);
}

function stop() {
  for (const t of timers) clearInterval(t);
  server.close();
  close();
  process.exit(0);
}
process.on("SIGTERM", stop);
process.on("SIGINT", stop);

// 명령줄 도구. 도커에서는 컨테이너 안에서 부른다:
//   docker compose exec -T inni node server/cli.js backup --reason before-update
//   docker compose exec -T inni node server/cli.js version
// NAS 자동 업데이트가 새 버전을 받기 직전에 백업을 남길 때 쓴다(서버가 돌고 있어도 안전: SQLite 온라인 백업).
import { loadConfig } from "./lib/config.js";
import { openDb } from "./lib/db.js";
import { createBackup, pruneBackups } from "./io/backup.js";
import { section } from "./lib/settings.js";

const [cmd, ...rest] = process.argv.slice(2);
const opt = (name, fallback) => {
  const i = rest.indexOf(`--${name}`);
  return i >= 0 && rest[i + 1] ? rest[i + 1] : fallback;
};

async function main() {
  const cfg = loadConfig();
  if (cmd === "version") {
    console.log(`${cfg.version}${cfg.commit ? ` ${cfg.commit}` : ""}`);
    return;
  }
  if (cmd === "backup") {
    const reason = opt("reason", "manual");
    if (!["manual", "before-update"].includes(reason)) throw new Error(`--reason 은 manual 또는 before-update 입니다: ${reason}`);
    const db = openDb(cfg.dbPath);
    const ctx = { cfg, db };
    try {
      const out = await createBackup(ctx, { reason });
      pruneBackups(ctx, section(db, "backup").keep);
      console.log(out.name);
    } finally {
      db.close();
    }
    return;
  }
  console.error("사용법: node server/cli.js backup [--reason before-update] | version");
  process.exitCode = 2;
}

main().catch((e) => {
  console.error(`[inni] ${e.message}`);
  process.exitCode = 1;
});

// NAS 자동 업데이트: 스크립트(가짜 GitHub·docker), 업데이트 전 백업 명령, 상태 표시·경보
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { startApp } from "./helpers.js";
import { computeAlerts } from "../server/lib/alerts.js";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const has = (cmd) => spawnSync(cmd, ["--version"], { stdio: "ignore" }).status === 0;

test("자동 업데이트 스크립트: 적용·백업·대기·되돌림·잠금", { skip: !(has("bash") && has("git")) && "bash·git 이 없음" }, () => {
  const r = spawnSync("bash", [path.join(ROOT, "scripts/nas-auto-update.test.sh")], { encoding: "utf8" });
  assert.equal(r.status, 0, `${r.stdout}\n${r.stderr}`);
});

test("업데이트 전 백업 명령(server/cli.js)", () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "inni-cli-"));
  try {
    const env = { ...process.env, INNI_DATA_DIR: dir };
    const r = spawnSync(process.execPath, [path.join(ROOT, "server/cli.js"), "backup", "--reason", "before-update"], { env, encoding: "utf8" });
    assert.equal(r.status, 0, r.stderr);
    const name = r.stdout.trim();
    assert.match(name, /^inni-backup-[\w-]+-before-update\.zip$/);
    assert.ok(fs.existsSync(path.join(dir, "backups", name)));
    assert.notEqual(spawnSync(process.execPath, [path.join(ROOT, "server/cli.js"), "backup", "--reason", "x"], { env }).status, 0);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test("자동 업데이트 상태: 정보 화면·관리자 경보", async (t) => {
  const env = await startApp();
  t.after(() => env.close());
  const admin = env.client("a");
  await admin.login("boss@school.kr", "김담당");
  const health = await admin.ok("GET", "/health");
  assert.equal(health.status, "ok");
  // 아직 설정 안 됨
  let s = await admin.ok("GET", "/api/settings");
  assert.equal(s.env.auto_update, null);
  // 스크립트가 남긴 결과를 읽는다
  const write = (o) => fs.writeFileSync(path.join(env.dir, "auto-update.json"), JSON.stringify({ checked_at: new Date().toISOString(), branch: "main", commit: "abc1234", ...o }));
  write({ status: "rolled_back", message: "새 버전이 제대로 뜨지 않아 되돌렸습니다" });
  s = await admin.ok("GET", "/api/settings");
  assert.equal(s.env.auto_update.status, "rolled_back");
  const boot = await admin.ok("GET", "/api/bootstrap");
  assert.ok(boot.alerts.alerts.some((a) => a.id === "auto-update" && a.kind === "system"));
  write({ status: "ok", message: "최신 상태입니다" });
  assert.ok(!(await admin.ok("GET", "/api/bootstrap")).alerts.alerts.some((a) => a.id === "auto-update"));
  // 관리자가 아니면 경보도 없다
  const snap = env.ctx.snapshot();
  const upd = { status: "error", message: "x", checked_at: new Date().toISOString() };
  assert.ok(computeAlerts(snap, { caps: new Set(["system"]), update: upd }).alerts.some((a) => a.id === "auto-update"));
  assert.ok(!computeAlerts(snap, { caps: new Set(["view"]), update: upd }).alerts.some((a) => a.id === "auto-update"));
  // 3시간 넘게 확인이 없으면 알림
  const stale = { status: "ok", checked_at: new Date(Date.now() - 4 * 3600000).toISOString() };
  assert.ok(computeAlerts(snap, { caps: new Set(["system"]), update: stale }).alerts.some((a) => a.id === "auto-update" && a.level === "info"));
});

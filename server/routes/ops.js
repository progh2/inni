// 운영 API: 백업·복원·이전, CSV, 예전 데이터 가져오기, 텔레그램, 처음 설정.
import fs from "node:fs";
import express from "express";
import { need } from "../lib/http.js";
import { badRequest, forbidden, str } from "../lib/util.js";
import { createBackup, listBackups, backupPath, deleteBackup, restoreBackup, writeUploadToTemp, pruneBackups } from "../io/backup.js";
import { exportItemsCsv, templateCsv, importItemsCsv, decodeCsv, toCsv } from "../io/csv.js";
import { detectLegacy, importLegacy } from "../io/legacy.js";
import { sendTelegram } from "../lib/telegram.js";
import { seedDemo } from "../lib/seed.js";
import { section, schoolName } from "../lib/settings.js";
import { listEvents } from "../lib/events.js";
import { orphanUploads } from "../lib/uploads.js";
import { repairCosts } from "../lib/repairs.js";

function seoulStamp() {
  return new Date(Date.now() + 9 * 3600000).toISOString().slice(0, 10).replace(/-/g, "");
}

function sendCsv(res, name, csv) {
  res.setHeader("Content-Type", "text/csv; charset=utf-8");
  res.setHeader("Content-Disposition", `attachment; filename*=UTF-8''${encodeURIComponent(name)}`);
  res.send(csv);
}

export function opsRouter(ctx) {
  const r = express.Router();

  // ---------------------------------------------------------------- 백업
  r.get("/backups", need("system"), (req, res) => {
    const cfg = section(ctx.db, "backup");
    res.json({ backups: listBackups(ctx), settings: cfg, dir: ctx.cfg.backupsDir });
  });
  r.post("/backups", need("system"), async (req, res) => {
    const out = await createBackup(ctx, { reason: "manual", includeSecrets: !(req.body && req.body.exclude_secrets), actor: req.actor });
    res.status(201).json({ name: out.name, bytes: out.bytes, manifest: out.manifest });
  });
  r.get("/backups/:name", need("system"), (req, res) => {
    res.download(backupPath(ctx, req.params.name), req.params.name);
  });
  r.delete("/backups/:name", need("system"), (req, res) => {
    deleteBackup(ctx, req.params.name);
    res.json({ ok: true });
  });
  r.post("/backups/:name/restore", need("system"), async (req, res) => {
    if (str(req.body && req.body.confirm, 20) !== "복원") throw badRequest("확인 낱말 '복원'을 입력하세요");
    res.json(await restoreBackup(ctx, backupPath(ctx, req.params.name), req.actor));
  });
  // zip 파일을 그대로 올려 복원(다른 NAS 에서 옮겨 올 때)
  r.post("/restore-upload", need("system"), async (req, res) => {
    if (req.get("x-inni-confirm") !== encodeURIComponent("복원")) throw badRequest("확인 낱말 '복원'을 입력하세요");
    const tmp = await writeUploadToTemp(ctx, req);
    try {
      res.json(await restoreBackup(ctx, tmp, req.actor));
    } finally {
      fs.rmSync(tmp, { force: true });
    }
  });

  // ---------------------------------------------------------------- 처음 설정(빈 inni)
  r.get("/setup/status", need("system"), (req, res) => {
    const empty = !ctx.db.prepare("SELECT 1 FROM items LIMIT 1").get() && !ctx.db.prepare("SELECT 1 FROM locations LIMIT 1").get();
    res.json({ empty, legacy: empty ? detectLegacy(ctx) : { found: false }, school: schoolName(ctx) });
  });
  r.post("/setup/demo", need("system"), (req, res) => {
    if (ctx.db.prepare("SELECT 1 FROM items LIMIT 1").get()) throw badRequest("이미 물품이 있습니다");
    seedDemo(ctx, { users: false });
    res.json({ ok: true });
  });
  r.post("/setup/legacy", need("system"), (req, res) => {
    res.json(importLegacy(ctx, req.actor, { includeDemoUsers: Boolean(req.body && req.body.include_demo_users) }));
  });

  // ---------------------------------------------------------------- CSV
  r.get("/export/items.csv", need("view"), (req, res) => {
    if (req.user.role === "student") throw forbidden();
    sendCsv(res, `inni-물품-${seoulStamp()}.csv`, exportItemsCsv(ctx));
  });
  r.get("/export/template.csv", need("view"), (req, res) => sendCsv(res, "inni-가져오기-양식.csv", templateCsv()));
  r.get("/export/events.csv", need("edit"), (req, res) => {
    const ev = listEvents(ctx.db, { limit: 500, since: req.query.since, before: req.query.before });
    sendCsv(res, `inni-기록-${seoulStamp()}.csv`, toCsv([["시각", "누가", "작업", "내용", "되돌림"], ...ev.map((e) => [e.at, e.actor_name, e.label, e.summary, e.undone_at ? "되돌림" : ""])]));
  });
  r.get("/export/repair-costs.csv", need("repair_manage"), (req, res) => {
    const c = repairCosts(ctx, { year: req.query.year });
    sendCsv(res, `inni-수리비-${c.year}.csv`, toCsv([["월", "건수", "금액(원)"], ...c.months.map((m) => [m.ym, m.n, m.total]), ["합계", "", c.total]]));
  });
  r.post("/import/items", need("register"), express.raw({ type: () => true, limit: "20mb" }), (req, res) => {
    if (!req.caps.has("edit")) throw forbidden("CSV 가져오기는 담당교사만 할 수 있습니다");
    const text = decodeCsv(req.body);
    const dry = req.query.dry !== "0";
    res.json(importItemsCsv(ctx, req.actor, text, { dry, createLocations: req.query.create_locations !== "0" }));
  });

  // ---------------------------------------------------------------- 알림·관리
  r.post("/telegram/test", need("system"), async (req, res) => {
    const ids = await sendTelegram(ctx, `[inni · ${schoolName(ctx)}] 연결 시험 메시지입니다. 이 채팅으로 연체·재고 부족·고장 신고를 알려 드립니다.`, {
      token: str(req.body && req.body.token, 200) || undefined,
      chatIds: req.body && req.body.chat_ids ? String(req.body.chat_ids).split(/[,\s]+/).filter(Boolean) : undefined,
    });
    res.json({ ok: true, sent: ids });
  });
  r.get("/maintenance/orphans", need("system"), (req, res) => {
    const list = orphanUploads(ctx);
    res.json({ count: list.length, bytes: list.reduce((s, x) => s + x.bytes, 0) });
  });
  r.post("/maintenance/orphans", need("system"), (req, res) => {
    const list = orphanUploads(ctx);
    for (const f of list) fs.rmSync(f.abs, { force: true });
    res.json({ removed: list.length });
  });
  r.post("/maintenance/prune-backups", need("system"), (req, res) => {
    res.json({ removed: pruneBackups(ctx, section(ctx.db, "backup").keep) });
  });

  return r;
}

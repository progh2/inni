// 백업·복원. 백업 파일 하나(zip)에 DB 스냅숏·사진·설명(manifest)이 모두 들어간다.
// 다른 NAS·PC 로 옮길 때도 이 파일 하나를 새 inni 에서 복원하면 된다.
import fs from "node:fs";
import fsp from "node:fs/promises";
import path from "node:path";
import { pipeline } from "node:stream/promises";
import Database from "better-sqlite3";
import yazl from "yazl";
import yauzl from "yauzl";
import { newId, badRequest, notFound, conflict, seoulParts, fmtBytes } from "../lib/util.js";
import { schemaVersion, openDb } from "../lib/db.js";
import { SCHEMA_VERSION } from "../lib/schema.js";
import { logEvent } from "../lib/events.js";
import { schoolName } from "../lib/settings.js";

const NAME_RE = /^inni-backup-[\w-]+\.zip$/;

function stamp(d = new Date()) {
  const p = seoulParts(d);
  const s = String(d.getSeconds()).padStart(2, "0");
  return `${p.ymd.replace(/-/g, "")}-${String(p.hh).padStart(2, "0")}${String(p.mm).padStart(2, "0")}${s}`;
}

function walkFiles(root) {
  const out = [];
  const walk = (dir) => {
    let list = [];
    try { list = fs.readdirSync(dir, { withFileTypes: true }); } catch { return; }
    for (const e of list) {
      const p = path.join(dir, e.name);
      if (e.isDirectory()) walk(p);
      else if (e.isFile()) out.push(p);
    }
  };
  walk(root);
  return out;
}

export function counts(db) {
  const n = (sql) => db.prepare(sql).get().n;
  return {
    items: n("SELECT COUNT(*) n FROM items"), units: n("SELECT COUNT(*) n FROM assets"), stocks: n("SELECT COUNT(*) n FROM stocks"),
    locations: n("SELECT COUNT(*) n FROM locations"), users: n("SELECT COUNT(*) n FROM users"), events: n("SELECT COUNT(*) n FROM events"),
  };
}

/**
 * @returns {Promise<{name, bytes, path}>}
 */
export async function createBackup(ctx, { reason = "manual", includeSecrets = true, actor = null } = {}) {
  const dir = ctx.cfg.backupsDir;
  fs.mkdirSync(dir, { recursive: true });
  const tag = reason === "auto" ? "-auto" : reason === "before-restore" ? "-before-restore" : "";
  const name = `inni-backup-${stamp()}${tag}.zip`;
  const tmpDb = path.join(dir, `.tmp-${newId()}.db`);
  const tmpZip = path.join(dir, `.tmp-${newId()}.zip`);
  try {
    await ctx.db.backup(tmpDb);
    const snap = new Database(tmpDb);
    snap.prepare("DELETE FROM sessions").run();
    if (!includeSecrets) snap.prepare("DELETE FROM secrets").run();
    const manifest = {
      app: "inni", format: 1, version: ctx.cfg.version, schema_version: schemaVersion(snap), created_at: new Date().toISOString(),
      reason, school: schoolName(ctx), includes_secrets: includeSecrets, counts: counts(snap),
    };
    snap.pragma("journal_mode = DELETE");
    snap.exec("VACUUM");
    snap.close();
    const zip = new yazl.ZipFile();
    const uploads = walkFiles(ctx.cfg.uploadsDir);
    manifest.photos = uploads.length;
    zip.addBuffer(Buffer.from(JSON.stringify(manifest, null, 2)), "manifest.json");
    zip.addFile(tmpDb, "inni.db");
    for (const abs of uploads) {
      const rel = path.relative(ctx.cfg.uploadsDir, abs).split(path.sep).join("/");
      zip.addFile(abs, `uploads/${rel}`, { compress: false });
    }
    zip.addBuffer(Buffer.from(README), "읽어보세요.txt");
    zip.end();
    await pipeline(zip.outputStream, fs.createWriteStream(tmpZip));
    const final = path.join(dir, name);
    fs.renameSync(tmpZip, final);
    const bytes = fs.statSync(final).size;
    if (reason !== "before-restore") logEvent(ctx.db, actor, { action: "backup", summary: `백업 만들기 (${reason === "auto" ? "자동" : "직접"}) · ${name} · ${fmtBytes(bytes)}` });
    return { name, bytes, path: final, manifest };
  } finally {
    for (const f of [tmpDb, tmpZip]) { try { fs.unlinkSync(f); } catch { /* 없음 */ } }
  }
}

const README = `inni 백업 파일입니다.
- inni.db : 데이터베이스(SQLite). 물품·장소·대여·기록·설정이 들어 있습니다.
- uploads/ : 사진
- manifest.json : 백업 정보(만든 시각·개수·버전)

복원: inni 화면 → 시스템 → 백업·이전 → [백업 파일로 복원]에서 이 zip 을 고르세요.
처음 설치한 새 inni(다른 NAS·PC)에서도 관리자로 로그인한 뒤 같은 방법으로 복원하면 그대로 옮겨집니다.
주의: 설정의 API 키(AI·검색·텔레그램)가 들어 있을 수 있으니 안전한 곳에 보관하세요.
`;

export function listBackups(ctx) {
  const dir = ctx.cfg.backupsDir;
  let files = [];
  try { files = fs.readdirSync(dir); } catch { return []; }
  return files.filter((f) => NAME_RE.test(f)).map((f) => {
    const st = fs.statSync(path.join(dir, f));
    return { name: f, bytes: st.size, created_at: st.mtime.toISOString(), auto: f.includes("-auto"), before_restore: f.includes("-before-restore") };
  }).sort((a, b) => b.created_at.localeCompare(a.created_at));
}

export function backupPath(ctx, name) {
  if (!NAME_RE.test(String(name || ""))) throw notFound("백업이 없습니다");
  const p = path.join(ctx.cfg.backupsDir, name);
  if (!fs.existsSync(p)) throw notFound("백업이 없습니다");
  return p;
}

export function deleteBackup(ctx, name) {
  fs.unlinkSync(backupPath(ctx, name));
}

// 자동 백업은 keep 개만 남긴다(직접 만든 것·복원 전 백업은 지우지 않는다. 복원 전 백업은 5개까지)
export function pruneBackups(ctx, keep = 14) {
  const all = listBackups(ctx);
  const autos = all.filter((b) => b.auto);
  const befores = all.filter((b) => b.before_restore);
  const drop = [...autos.slice(keep), ...befores.slice(5)];
  for (const b of drop) { try { fs.unlinkSync(path.join(ctx.cfg.backupsDir, b.name)); } catch { /* 이미 없음 */ } }
  return drop.length;
}

// ---------------------------------------------------------------- 복원
function openZip(file) {
  return new Promise((resolve, reject) => yauzl.open(file, { lazyEntries: true, autoClose: false }, (err, zf) => (err ? reject(err) : resolve(zf))));
}

function readEntries(zf, onEntry) {
  return new Promise((resolve, reject) => {
    zf.on("error", reject);
    zf.on("end", resolve);
    zf.on("entry", async (entry) => {
      try {
        await onEntry(entry);
        zf.readEntry();
      } catch (e) {
        reject(e);
      }
    });
    zf.readEntry();
  });
}

function entryStream(zf, entry) {
  return new Promise((resolve, reject) => zf.openReadStream(entry, (err, s) => (err ? reject(err) : resolve(s))));
}

async function streamToBuffer(s, max = 5 * 1048576) {
  const chunks = [];
  let size = 0;
  for await (const c of s) {
    size += c.length;
    if (size > max) throw badRequest("백업 안의 파일이 너무 큽니다");
    chunks.push(c);
  }
  return Buffer.concat(chunks);
}

/** zip 을 풀어 검사만 한다. 문제가 없으면 { manifest, dir } */
export async function inspectBackup(ctx, zipFile) {
  const work = path.join(ctx.cfg.dataDir, `.restore-${newId()}`);
  fs.mkdirSync(path.join(work, "uploads"), { recursive: true });
  let manifest = null;
  let hasDb = false;
  let zf;
  try {
    zf = await openZip(zipFile);
  } catch {
    fs.rmSync(work, { recursive: true, force: true });
    throw badRequest("zip 파일을 열지 못했습니다. inni 백업 파일이 맞는지 확인하세요.");
  }
  try {
    await readEntries(zf, async (entry) => {
      const name = entry.fileName;
      if (/\/$/.test(name)) return;
      if (name.includes("..") || path.isAbsolute(name)) throw badRequest("백업 파일 안의 경로가 이상합니다");
      if (name === "manifest.json") {
        manifest = JSON.parse((await streamToBuffer(await entryStream(zf, entry))).toString("utf8"));
      } else if (name === "inni.db") {
        await pipeline(await entryStream(zf, entry), fs.createWriteStream(path.join(work, "inni.db")));
        hasDb = true;
      } else if (name.startsWith("uploads/")) {
        const rel = name.slice("uploads/".length);
        if (!/^[\w\-/.]+$/.test(rel)) return;
        const out = path.join(work, "uploads", rel);
        fs.mkdirSync(path.dirname(out), { recursive: true });
        await pipeline(await entryStream(zf, entry), fs.createWriteStream(out));
      }
    });
  } catch (e) {
    zf.close();
    fs.rmSync(work, { recursive: true, force: true });
    throw e;
  }
  zf.close();
  const fail = (msg) => { fs.rmSync(work, { recursive: true, force: true }); throw badRequest(msg); };
  if (!manifest || manifest.app !== "inni") fail("inni 백업 파일이 아닙니다(manifest.json 없음)");
  if (!hasDb) fail("백업 안에 데이터베이스(inni.db)가 없습니다");
  if (Number(manifest.schema_version) > SCHEMA_VERSION) fail(`이 백업은 더 새 inni(스키마 ${manifest.schema_version})에서 만들었습니다. 먼저 프로그램을 업데이트하세요.`);
  let check;
  try {
    const d = new Database(path.join(work, "inni.db"), { readonly: true });
    check = d.pragma("integrity_check", { simple: true });
    d.prepare("SELECT COUNT(*) FROM items").get();
    d.close();
  } catch (e) {
    fail(`백업의 데이터베이스를 읽지 못했습니다: ${e.message}`);
  }
  if (check !== "ok") fail(`백업 데이터베이스가 손상되었습니다: ${check}`);
  return { manifest, dir: work };
}

let restoring = false;

export async function restoreBackup(ctx, zipFile, actor) {
  if (restoring) throw conflict("이미 복원 중입니다");
  restoring = true;
  try {
    const { manifest, dir } = await inspectBackup(ctx, zipFile);
    // 1) 지금 상태를 먼저 백업
    const safety = await createBackup(ctx, { reason: "before-restore", actor });
    // 2) DB 바꿔 끼우기
    const dbPath = ctx.cfg.dbPath;
    const t = stamp();
    try { ctx.db.pragma("wal_checkpoint(TRUNCATE)"); } catch { /* 무시 */ }
    ctx.db.close();
    for (const ext of ["", "-wal", "-shm"]) {
      if (fs.existsSync(dbPath + ext)) fs.renameSync(dbPath + ext, `${dbPath}.old-${t}${ext}`);
    }
    fs.renameSync(path.join(dir, "inni.db"), dbPath);
    // 3) 사진 바꿔 끼우기
    const up = ctx.cfg.uploadsDir;
    const oldUp = `${up}.old-${t}`;
    if (fs.existsSync(up)) fs.renameSync(up, oldUp);
    fs.renameSync(path.join(dir, "uploads"), up);
    const next = openDb(dbPath);
    ctx.swapDb(next, { closeOld: false });
    logEvent(next, actor, { action: "restore", summary: `백업 복원: ${manifest.school || ""} · ${manifest.created_at ? manifest.created_at.slice(0, 16).replace("T", " ") : ""} (복원 전 상태는 ${safety.name})` });
    // 4) 치우기
    fs.rmSync(dir, { recursive: true, force: true });
    for (const ext of ["", "-wal", "-shm"]) { try { fs.unlinkSync(`${dbPath}.old-${t}${ext}`); } catch { /* 없음 */ } }
    fs.rmSync(oldUp, { recursive: true, force: true });
    ctx.changed({ kind: "restore" });
    return { ok: true, manifest, safety: safety.name };
  } finally {
    restoring = false;
  }
}

export async function writeUploadToTemp(ctx, req, maxBytes = 4 * 1024 * 1048576) {
  const tmp = path.join(ctx.cfg.dataDir, `.upload-${newId()}.zip`);
  let size = 0;
  const out = fs.createWriteStream(tmp);
  try {
    await pipeline(req, async function* (source) {
      for await (const chunk of source) {
        size += chunk.length;
        if (size > maxBytes) throw badRequest("파일이 너무 큽니다");
        yield chunk;
      }
    }, out);
  } catch (e) {
    await fsp.rm(tmp, { force: true });
    throw e;
  }
  if (!size) {
    await fsp.rm(tmp, { force: true });
    throw badRequest("파일이 비어 있습니다");
  }
  return tmp;
}

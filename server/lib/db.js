// SQLite 열기·마이그레이션. 파일 하나(inni.db)라서 복사만으로 백업·이전이 된다.
import fs from "node:fs";
import path from "node:path";
import Database from "better-sqlite3";
import { MIGRATIONS, SCHEMA_VERSION } from "./schema.js";

export function openDb(file) {
  if (file !== ":memory:") fs.mkdirSync(path.dirname(file), { recursive: true });
  const db = new Database(file);
  db.pragma("journal_mode = WAL");
  db.pragma("foreign_keys = ON");
  db.pragma("busy_timeout = 5000");
  db.pragma("synchronous = NORMAL");
  migrate(db);
  return db;
}

export function schemaVersion(db) {
  const row = db.prepare("SELECT value FROM meta WHERE key = 'schema_version'").get();
  return Number(row ? row.value : 0);
}

export function migrate(db) {
  db.exec("CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
  const current = schemaVersion(db);
  if (current > SCHEMA_VERSION) {
    throw new Error(`데이터베이스가 이 프로그램보다 새 버전(${current})입니다. 프로그램을 먼저 업데이트하세요.`);
  }
  for (const m of MIGRATIONS) {
    if (m.version <= current) continue;
    db.transaction(() => {
      db.exec(m.sql);
      db.prepare("INSERT INTO meta(key, value) VALUES('schema_version', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value").run(String(m.version));
    })();
  }
  if (!db.prepare("SELECT 1 FROM meta WHERE key = 'created_at'").get()) {
    db.prepare("INSERT INTO meta(key, value) VALUES('created_at', ?)").run(new Date().toISOString());
  }
}

// 즉시 쓰기 잠금을 잡는 트랜잭션(동시에 두 명이 같은 장비를 빌리는 경우 등)
export function tx(db, fn) {
  return db.transaction(fn).immediate();
}

// ---------------------------------------------------------------- 설정
export function getSetting(db, key, fallback = null) {
  const row = db.prepare("SELECT value FROM settings WHERE key = ?").get(key);
  if (!row) return fallback;
  try {
    return JSON.parse(row.value);
  } catch {
    return fallback;
  }
}

export function setSetting(db, key, value) {
  db.prepare("INSERT INTO settings(key, value, updated_at) VALUES(?, ?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
    .run(key, JSON.stringify(value), new Date().toISOString());
}

export function getSecret(db, key) {
  const row = db.prepare("SELECT value FROM secrets WHERE key = ?").get(key);
  return row ? row.value : "";
}

export function setSecret(db, key, value) {
  if (!value) {
    db.prepare("DELETE FROM secrets WHERE key = ?").run(key);
    return;
  }
  db.prepare("INSERT INTO secrets(key, value, updated_at) VALUES(?, ?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
    .run(key, String(value), new Date().toISOString());
}

export function secretHint(value) {
  const s = String(value || "");
  if (!s) return "";
  return s.length <= 6 ? "••••" : `••••${s.slice(-4)}`;
}

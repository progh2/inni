// 환경 변수 → 설정. 값은 .env(도커 compose)나 셸에서 온다.
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { gitCommit } from "./update.js";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, "package.json"), "utf8"));

const list = (s) => String(s || "").split(/[,\s]+/).map((x) => x.trim().toLowerCase()).filter(Boolean);
const loginMethods = (s) => {
  const m = list(s || "google,email").filter((x) => x === "google" || x === "email");
  return m.length ? [...new Set(m)] : ["google", "email"];
};
const flag = (s) => /^(1|true|yes|on)$/i.test(String(s || "").trim());

export function loadConfig(overrides = {}, env = process.env) {
  const dataDir = path.resolve(ROOT, overrides.dataDir ?? env.INNI_DATA_DIR ?? "data");
  const projectId = overrides.firebaseProjectId ?? env.FIREBASE_PROJECT_ID ?? "";
  const cfg = {
    root: ROOT,
    webDir: path.join(ROOT, "web"),
    version: pkg.version,
    commit: String(env.INNI_COMMIT || gitCommit(ROOT) || "").slice(0, 12),
    port: Number(overrides.port ?? env.PORT ?? 3000),
    host: env.HOST || "0.0.0.0",
    dataDir,
    dbPath: path.join(dataDir, "inni.db"),
    uploadsDir: path.join(dataDir, "uploads"),
    backupsDir: path.join(dataDir, "backups"),
    publicUrl: String(env.PUBLIC_URL || "").trim().replace(/\/+$/, ""),
    authMode: String(overrides.authMode ?? env.AUTH_MODE ?? "firebase").toLowerCase() === "dev" ? "dev" : "firebase",
    firebase: {
      projectId,
      apiKey: env.FIREBASE_API_KEY || "",
      authDomain: env.FIREBASE_AUTH_DOMAIN || (projectId ? `${projectId}.firebaseapp.com` : ""),
    },
    allowedDomains: list(overrides.allowedDomains ?? env.ALLOWED_DOMAINS),
    // 로그인 방법: google(구글 계정) · email(학교 메일로 받은 링크). 비우면 둘 다
    loginMethods: loginMethods(overrides.loginMethods ?? env.LOGIN_METHODS),
    adminEmails: list(overrides.adminEmails ?? env.ADMIN_EMAILS),
    schoolName: env.SCHOOL_NAME || "",
    seedDemo: overrides.seedDemo ?? flag(env.SEED_DEMO),
    sessionDays: Math.max(1, Math.min(365, Number(env.SESSION_DAYS || 30))),
    trustProxy: env.TRUST_PROXY ?? "loopback, linklocal, uniquelocal",
    legacyDb: env.LEGACY_DB || path.join(dataDir, "inni.sqlite"),
    legacyUploads: env.LEGACY_UPLOADS || path.join(dataDir, "legacy-uploads"),
    // 테스트에서 바꿔 끼운다
    fetch: overrides.fetch || globalThis.fetch,
    jobs: overrides.jobs ?? true,
  };
  return cfg;
}

export function validateConfig(cfg) {
  const problems = [];
  if (cfg.authMode === "firebase") {
    if (!cfg.firebase.projectId) problems.push("FIREBASE_PROJECT_ID 가 비어 있습니다 (Firebase 콘솔의 프로젝트 ID)");
    if (!cfg.firebase.apiKey) problems.push("FIREBASE_API_KEY 가 비어 있습니다 (Firebase 웹 앱 설정의 apiKey)");
    if (!cfg.adminEmails.length) problems.push("ADMIN_EMAILS 가 비어 있습니다 (처음 관리자로 들어올 구글 계정)");
  }
  return problems;
}

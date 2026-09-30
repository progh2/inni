// 앱 조립. 테스트는 createApp() 으로 띄우고, 운영은 index.js 가 부른다.
import fs from "node:fs";
import crypto from "node:crypto";
import path from "node:path";
import express from "express";
import { loadConfig } from "./lib/config.js";
import { openDb } from "./lib/db.js";
import { createBus } from "./lib/bus.js";
import { createSnapshotCache, subtree } from "./lib/snapshot.js";
import { createCertStore } from "./lib/firebase.js";
import { attachUser, csrfGuard, errorHandler, requireUser } from "./lib/http.js";
import { uploadPath } from "./lib/uploads.js";
import { eventView } from "./lib/events.js";
import { apiRouter } from "./routes/api.js";
import { opsRouter } from "./routes/ops.js";
import { aiRouter } from "./routes/ai.js";
import { seedDemo } from "./lib/seed.js";
import { createAi } from "./ai/service.js";

export function createContext(overrides = {}) {
  const cfg = loadConfig(overrides);
  for (const d of [cfg.dataDir, cfg.uploadsDir, cfg.backupsDir]) fs.mkdirSync(d, { recursive: true });
  let db = openDb(overrides.dbPath || cfg.dbPath);
  const bus = createBus();
  const cache = createSnapshotCache(() => ctx.db);
  const ctx = {
    cfg,
    get db() { return db; },
    bus,
    certs: overrides.certs || createCertStore(cfg.fetch),
    snapshot: () => cache.get(),
    helpers: { subtree },
    invalidate: () => cache.invalidate(),
    // 쓰기 뒤에 부른다: 현황판을 새로 만들게 하고, 열려 있는 화면들에 알린다
    changed(change = {}) {
      cache.invalidate();
      const events = (change.events || []).filter(Boolean).map((e) => (e.summary !== undefined && e.label === undefined ? eventView(e) : e));
      bus.publish("change", (user) => {
        if (!user) return null;
        const staff = user.role !== "student";
        return { kind: change.kind || "data", id: change.id || null, events: staff ? events : [] };
      });
    },
    // 복원할 때 DB 파일을 바꿔 끼운다
    swapDb(next) {
      const old = db;
      db = next;
      cache.invalidate();
      try { old.close(); } catch { /* 이미 닫힘 */ }
    },
    reopenDb() {
      const next = openDb(cfg.dbPath);
      this.swapDb(next);
      return next;
    },
  };
  if (cfg.seedDemo) seedDemo(ctx);
  return ctx;
}

// index.html 의 import map(인라인 스크립트)은 해시로 허용한다
function importMapHash(webDir) {
  try {
    const html = fs.readFileSync(path.join(webDir, "index.html"), "utf8");
    const m = /<script type="importmap">([\s\S]*?)<\/script>/.exec(html);
    return m ? ` 'sha256-${crypto.createHash("sha256").update(m[1]).digest("base64")}'` : "";
  } catch { return ""; }
}

const CSP = (webDir) => [
  "default-src 'self'",
  `script-src 'self' https://www.gstatic.com https://apis.google.com${importMapHash(webDir)}`,
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data: blob: https:",
  "font-src 'self' data:",
  "connect-src 'self' https://*.googleapis.com https://www.gstatic.com https://*.firebaseapp.com",
  "frame-src 'self' https://*.firebaseapp.com https://accounts.google.com https://*.google.com",
  "worker-src 'self' blob:",
  "media-src 'self' blob:",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
].join("; ");

// 배경 지우기 방(web/bg-lab.html, 숨은 iframe) 전용 정책. 라이브러리가 new Function·blob 스크립트·wasm 을 쓴다.
// 앱 화면(위 CSP)에는 이런 허용을 두지 않고, 이 방과는 postMessage 로 사진만 주고받는다.
const BG_LAB_CSP = [
  "default-src 'none'",
  "script-src 'self' https://cdn.jsdelivr.net/npm/ blob: 'unsafe-eval' 'wasm-unsafe-eval'",
  "connect-src 'self' blob: data: https://cdn.jsdelivr.net https://staticimgly.com",
  "worker-src 'self' blob:",
  "img-src 'self' blob: data:",
  "frame-ancestors 'self'",
  "base-uri 'none'",
  "form-action 'none'",
].join("; ");

export function createApp(overrides = {}) {
  const ctx = createContext(overrides);
  ctx.ai = createAi(ctx);
  ctx.aiReady = () => ctx.ai.ready();
  const { cfg } = ctx;
  const app = express();
  const csp = CSP(cfg.webDir);
  app.disable("x-powered-by");
  app.set("trust proxy", cfg.trustProxy);
  app.use((req, res, next) => {
    res.setHeader("X-Content-Type-Options", "nosniff");
    res.setHeader("Referrer-Policy", "same-origin");
    res.setHeader("X-Frame-Options", "SAMEORIGIN");
    res.setHeader("Permissions-Policy", "camera=(self), microphone=(self), geolocation=()");
    if (process.env.CSP !== "off") res.setHeader("Content-Security-Policy", csp);
    next();
  });

  app.get("/health", (_req, res) => res.json({ status: "ok", version: cfg.version }));

  // 라벨 QR: 휴대폰 기본 카메라로 찍으면 이 주소가 열린다
  app.get("/q/:code", (req, res) => {
    res.redirect(302, `/#scan?code=${encodeURIComponent(String(req.params.code).slice(0, 40))}`);
  });

  const api = express.Router();
  api.use(express.json({ limit: "2mb" }));
  api.use(csrfGuard);
  api.use(attachUser(ctx));
  api.get("/stream", requireUser, (req, res) => {
    ctx.bus.add(req, res, req.user);
  });
  api.use(apiRouter(ctx));
  api.use(opsRouter(ctx));
  api.use(aiRouter(ctx));
  api.use((req, res) => res.status(404).json({ error: "없는 주소입니다" }));
  app.use("/api", api);

  // 사진: 로그인한 사람만
  app.get("/uploads/*path", attachUser(ctx), (req, res, next) => {
    if (!req.user || req.user.status !== "active") return res.status(401).end();
    let abs;
    try { abs = uploadPath(ctx, [].concat(req.params.path).join("/")); } catch { return res.status(404).end(); }
    res.setHeader("Cache-Control", "private, max-age=31536000, immutable");
    res.sendFile(abs, (err) => { if (err && !res.headersSent) res.status(404).end(); else if (err) next(); });
  });

  // 3D·글꼴·QR 라이브러리는 npm 으로 받은 파일을 그대로 준다(교실 망에서 CDN 에 기대지 않는다)
  const vendor = (rel) => path.join(cfg.root, "node_modules", rel);
  const long = { maxAge: "30d", immutable: false };
  app.use("/vendor/three", express.static(vendor("three/build"), long));
  app.use("/vendor/three-addons", express.static(vendor("three/examples/jsm"), long));
  app.use("/vendor/fonts/pretendard", express.static(vendor("pretendard/dist/web/variable"), long));
  app.use("/vendor/fonts/orbitron", express.static(vendor("@fontsource-variable/orbitron/files"), long));
  app.use("/vendor/fonts/jetbrains-mono", express.static(vendor("@fontsource-variable/jetbrains-mono/files"), long));
  app.use("/vendor/qrcode", express.static(vendor("qrcode-generator/dist"), long));
  app.use("/vendor/jsqr", express.static(vendor("jsqr/dist"), long));

  app.get("/bg-lab.html", (req, res, next) => {
    res.setHeader("Content-Security-Policy", BG_LAB_CSP);
    next();
  });
  app.use(express.static(cfg.webDir, {
    index: "index.html",
    setHeaders(res, file) {
      // 화면 파일은 늘 새로 확인(ETag), 업데이트가 바로 보이게
      res.setHeader("Cache-Control", /\.(woff2|png|svg|webp)$/.test(file) ? "public, max-age=86400" : "no-cache");
    },
  }));

  app.use(errorHandler);
  return {
    app,
    ctx,
    close() {
      ctx.bus.close();
      try { ctx.db.close(); } catch { /* 이미 닫힘 */ }
    },
  };
}

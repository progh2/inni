// 핵심 API. 화면(web/)은 이 주소들만 부른다.
import express from "express";
import { verifyIdToken, TokenError } from "../lib/firebase.js";
import { signIn, signOut, sessionCookie, COOKIE, accessPolicy, allowedDomains } from "../lib/auth.js";
import { requireUser, need, meView, clientIp, isSecure } from "../lib/http.js";
import { badRequest, notFound, str, reqStr, nowIso, forbidden } from "../lib/util.js";
import { publicSettings, adminSettings, updateSettings, schoolName, section } from "../lib/settings.js";
import * as inv from "../lib/inventory.js";
import * as act from "../lib/actions.js";
import { search, resolveCode } from "../lib/search.js";
import { listEvents } from "../lib/events.js";
import { computeAlerts, dashboardCounts } from "../lib/alerts.js";
import * as repairs from "../lib/repairs.js";
import * as audits from "../lib/audits.js";
import * as users from "../lib/users.js";
import { suggestUsefulLife } from "../lib/pps.js";
import { readUpdateStatus } from "../lib/update.js";
import { saveImage, sniffImage } from "../lib/uploads.js";
import { productSearch, readProductPage, proxyImage } from "../lib/product.js";
import { itemCard, loanBrief } from "../lib/inventory.js";

export function apiRouter(ctx) {
  const r = express.Router();

  // ---------------------------------------------------------------- 로그인
  r.get("/auth/config", (req, res) => {
    const fb = ctx.cfg.firebase;
    res.json({
      mode: ctx.cfg.authMode,
      firebase: ctx.cfg.authMode === "firebase" ? { apiKey: fb.apiKey, authDomain: fb.authDomain, projectId: fb.projectId } : null,
      domains: allowedDomains(ctx),
      methods: ctx.cfg.loginMethods,
      school: schoolName(ctx),
      assistant: section(ctx.db, "assistant").name || "이니",
      version: ctx.cfg.version,
      setup: !ctx.db.prepare("SELECT 1 FROM users LIMIT 1").get(),
    });
  });

  const finishLogin = (req, res, identity) => {
    const out = signIn(ctx, identity, { userAgent: req.get("user-agent"), ip: clientIp(req) });
    res.setHeader("Set-Cookie", sessionCookie(out.token, { maxAgeMs: out.maxAgeMs, secure: isSecure(req) }));
    req.user = out.user;
    req.caps = new Set();
    res.json({ me: meView(ctx, { user: out.user }) });
  };

  r.post("/auth/session", async (req, res) => {
    if (ctx.cfg.authMode !== "firebase") throw badRequest("Firebase 로그인이 꺼져 있습니다");
    const token = str(req.body && req.body.idToken, 5000);
    if (!token) throw badRequest("로그인 토큰이 없습니다");
    let claims;
    try {
      claims = await verifyIdToken(token, { projectId: ctx.cfg.firebase.projectId, certs: ctx.certs });
    } catch (e) {
      if (e instanceof TokenError) throw Object.assign(new Error(e.message), { status: 401, code: e.code });
      throw e;
    }
    if (!claims.email || claims.email_verified !== true) throw forbidden("이메일이 확인된 계정만 쓸 수 있습니다");
    // google.com = 구글 계정, password = 메일로 받은 로그인 링크(메일함 주인만 누를 수 있다)
    const provider = claims.firebase && claims.firebase.sign_in_provider;
    const method = provider === "google.com" ? "google" : provider === "password" ? "email" : "";
    if (!ctx.cfg.loginMethods.includes(method)) throw forbidden("이 로그인 방법은 쓰지 않습니다. 로그인 화면의 방법으로 다시 들어오세요.");
    // 링크 로그인에는 이름이 없다 → 처음 들어올 때 적은 이름을 쓴다(나중에 바꿀 수 있음)
    const name = claims.name || str(req.body.name, 40) || undefined;
    finishLogin(req, res, { email: claims.email, name, picture: claims.picture, uid: claims.sub });
  });

  r.post("/auth/dev-login", (req, res) => {
    if (ctx.cfg.authMode !== "dev") throw notFound();
    const email = str(req.body && req.body.email, 120).toLowerCase();
    if (!/^[^@\s]+@[^@\s]+$/.test(email)) throw badRequest("이메일을 넣으세요");
    finishLogin(req, res, { email, name: str(req.body.name, 40) || email.split("@")[0] });
  });

  r.post("/auth/logout", (req, res) => {
    signOut(ctx, req.sessionToken);
    res.setHeader("Set-Cookie", sessionCookie("", { maxAgeMs: 0, secure: isSecure(req) }));
    res.json({ ok: true });
  });

  r.get("/me", (req, res) => {
    if (!req.user) return res.status(401).json({ error: "로그인이 필요합니다" });
    res.json({ me: meView(ctx, req) });
  });
  // 내 이름 바꾸기(대여 기록 등에 보이는 이름)
  r.patch("/me", (req, res) => {
    if (!req.user) return res.status(401).json({ error: "로그인이 필요합니다" });
    const name = reqStr(req.body && req.body.name, "이름", 40);
    ctx.db.prepare("UPDATE users SET name = ?, updated_at = ? WHERE id = ?").run(name, nowIso(), req.user.id);
    req.user = { ...req.user, name };
    res.json({ me: meView(ctx, req) });
  });

  // 여기부터는 승인된 사용자만
  r.use(requireUser);

  // ---------------------------------------------------------------- 시작 묶음
  r.get("/bootstrap", need("view"), (req, res) => {
    const snap = ctx.snapshot();
    const pending = req.caps.has("users") ? ctx.db.prepare("SELECT COUNT(*) n FROM users WHERE status = 'pending'").get().n : 0;
    res.json({
      me: meView(ctx, req),
      settings: publicSettings(ctx),
      locations: inv.listLocations(ctx),
      categories: inv.listCategories(ctx),
      alerts: computeAlerts(snap, { user: req.user, caps: req.caps, pendingUsers: pending, update: req.caps.has("system") ? readUpdateStatus(ctx) : null }),
      counts: dashboardCounts(snap),
      server: { version: ctx.cfg.version, commit: ctx.cfg.commit, auth: ctx.cfg.authMode, time: nowIso(), ai: ctx.aiReady ? ctx.aiReady() : false, public_url: ctx.cfg.publicUrl || "" },
    });
  });

  r.get("/dashboard", need("view"), (req, res) => {
    const snap = ctx.snapshot();
    const pending = req.caps.has("users") ? ctx.db.prepare("SELECT COUNT(*) n FROM users WHERE status = 'pending'").get().n : 0;
    const mine = snap.loansActive.filter((l) => l.borrower_user_id === req.user.id).map((l) => loanRow(snap, l));
    const favorites = [...snap.items.values()].filter((it) => it.favorite && !it.archived_at).slice(0, 12).map((it) => itemCard(snap, it));
    const recentItems = [...snap.items.values()].filter((it) => !it.archived_at).sort((a, b) => String(b.created_at).localeCompare(String(a.created_at))).slice(0, 8).map((it) => itemCard(snap, it));
    const events = req.user.role === "student" ? listEvents(ctx.db, { actor_id: req.user.id, limit: 15 }) : listEvents(ctx.db, { limit: 25 });
    res.json({
      counts: dashboardCounts(snap),
      alerts: computeAlerts(snap, { user: req.user, caps: req.caps, pendingUsers: pending, update: req.caps.has("system") ? readUpdateStatus(ctx) : null }),
      my_loans: mine, favorites, recent_items: recentItems, events,
      active_audits: audits.listAudits(ctx).filter((a) => a.status === "active"),
    });
  });

  // ---------------------------------------------------------------- 찾기
  r.get("/search", need("view"), (req, res) => {
    const snap = ctx.snapshot();
    const q = str(req.query.q, 100);
    const out = search(snap, q, { limit: Math.min(60, Number(req.query.limit) || 12) });
    res.json({
      q,
      total: out.total || 0,
      exact: out.exact,
      items: out.items.map((x) => ({ ...itemCard(snap, snap.items.get(x.id)), match_unit_id: x.unit_id, fuzzy: x.fuzzy })),
      locations: out.locations.map((x) => inv.locationView(snap, snap.locations.get(x.id))),
    });
  });

  r.get("/scan/:code", need("view"), (req, res) => {
    const hit = resolveCode(ctx.snapshot(), req.params.code);
    if (!hit) return res.status(404).json({ error: "등록되지 않은 코드입니다", code: "unknown" });
    res.json(hit);
  });

  r.get("/items", need("view"), (req, res) => {
    res.json(inv.listItems(ctx, req.query, search));
  });

  r.get("/items/:id", need("view"), (req, res) => {
    const d = inv.itemDetail(ctx, req.params.id);
    if (req.user.role === "student") {
      d.loans = d.loans.filter((l) => l.borrower_user_id === req.user.id);
      d.events = [];
    }
    res.json(d);
  });

  r.post("/items", need("register"), (req, res) => {
    const out = inv.createItem(ctx, req.actor, req.body || {});
    res.status(201).json({ id: out.item.id, item: out.item, units: out.units, stock: out.stock });
  });

  r.patch("/items/:id", need("view"), (req, res) => {
    // 즐겨찾기만 바꿀 때는 등록 권한으로 충분
    const keys = Object.keys(req.body || {});
    const onlyFavorite = keys.length === 1 && keys[0] === "favorite";
    if (!req.caps.has("edit") && !(onlyFavorite && req.caps.has("register"))) {
      // 방금 자기가 등록한 품목은 1시간 동안 고칠 수 있다
      const it = ctx.db.prepare("SELECT created_by, created_at FROM items WHERE id = ?").get(req.params.id);
      if (!(it && it.created_by === req.user.id && Date.now() - Date.parse(it.created_at) < 3600000 && req.caps.has("register"))) {
        throw forbidden("정보를 고칠 권한이 없습니다(담당교사)");
      }
    }
    res.json({ item: inv.updateItem(ctx, req.actor, req.params.id, req.body || {}) });
  });

  r.post("/items/:id/archive", need("delete"), (req, res) => {
    inv.setArchived(ctx, req.actor, req.params.id, req.body && req.body.archived !== false);
    res.json({ ok: true });
  });

  r.post("/items/:id/units", need("register"), (req, res) => {
    res.status(201).json({ units: inv.addUnits(ctx, req.actor, req.params.id, req.body || {}) });
  });

  r.patch("/units/:id", need("edit"), (req, res) => {
    res.json({ unit: inv.updateUnit(ctx, req.actor, req.params.id, req.body || {}) });
  });

  r.get("/numbers/next", need("view"), (req, res) => {
    res.json({ numbers: inv.nextManagementNumbers(ctx.db, req.query.prefix, Math.min(50, Number(req.query.count) || 1)) });
  });

  r.get("/check-duplicate", need("view"), (req, res) => {
    const snap = ctx.snapshot();
    const q = str(req.query.name, 100);
    if (q.length < 2) return res.json({ items: [] });
    const out = search(snap, q, { limit: 5 });
    res.json({ items: out.items.filter((x) => x.score >= 90).map((x) => itemCard(snap, snap.items.get(x.id))) });
  });

  // ---------------------------------------------------------------- 장소·분류
  r.get("/locations", need("view"), (req, res) => res.json({ locations: inv.listLocations(ctx) }));
  r.get("/locations/:id", need("view"), (req, res) => {
    res.json(inv.locationContents(ctx, req.params.id, { deep: req.query.deep !== "0" }));
  });
  r.post("/locations", need("edit"), (req, res) => res.status(201).json({ location: inv.createLocation(ctx, req.actor, req.body || {}) }));
  r.patch("/locations/:id", need("edit"), (req, res) => res.json({ location: inv.updateLocation(ctx, req.actor, req.params.id, req.body || {}) }));
  r.delete("/locations/:id", need("edit"), (req, res) => {
    inv.deleteLocation(ctx, req.actor, req.params.id);
    res.json({ ok: true });
  });
  r.get("/categories", need("view"), (req, res) => res.json({ categories: inv.listCategories(ctx) }));
  r.post("/categories", need("register"), (req, res) => res.status(201).json({ category: inv.createCategory(ctx, req.actor, req.body || {}) }));
  r.patch("/categories/:id", need("edit"), (req, res) => res.json({ category: inv.updateCategory(ctx, req.actor, req.params.id, req.body || {}) }));
  r.delete("/categories/:id", need("edit"), (req, res) => {
    inv.deleteCategory(ctx, req.actor, req.params.id);
    res.json({ ok: true });
  });

  // ---------------------------------------------------------------- 현장 작업
  r.post("/actions/move", need("move"), (req, res) => res.json(act.move(ctx, req.actor, req.body || {})));
  r.post("/actions/loan", need("loan"), (req, res) => res.json(act.loan(ctx, req.actor, req.body || {})));
  r.post("/actions/return", (req, res) => {
    // 학생은 자기가 빌린 것만(검사는 returnLoans 안에서)
    if (!req.caps.has("loan") && req.user.role !== "student") throw forbidden("반납 권한이 없습니다");
    res.json(act.returnLoans(ctx, req.actor, req.body || {}));
  });
  r.post("/actions/use", need("stock"), (req, res) => res.json(act.useStock(ctx, req.actor, req.body || {})));
  r.post("/actions/restock", need("stock"), (req, res) => res.json(act.restock(ctx, req.actor, req.body || {})));
  r.post("/actions/adjust", need("edit"), (req, res) => res.json(act.adjustStock(ctx, req.actor, req.body || {})));
  r.post("/actions/status", need("edit"), (req, res) => res.json(act.setUnitStatus(ctx, req.actor, req.body || {})));
  r.post("/actions/retire", need("delete"), (req, res) => res.json(act.retireUnit(ctx, req.actor, req.body || {})));
  r.post("/undo", (req, res) => res.json(act.undo(ctx, req.actor, req.caps, (req.body || {}).event_ids)));

  // ---------------------------------------------------------------- 대여 목록·이력
  r.get("/loans", need("view"), (req, res) => {
    const snap = ctx.snapshot();
    const f = String(req.query.filter || "active");
    let list;
    if (f === "returned") {
      list = ctx.db.prepare("SELECT * FROM loans WHERE status = 'returned' ORDER BY returned_at DESC LIMIT 200").all();
    } else {
      list = snap.loansActive;
      if (f === "overdue") list = list.filter((l) => l.overdue);
      else if (f === "today") list = list.filter((l) => l.due_today || l.overdue);
    }
    if (f === "mine" || req.user.role === "student") list = list.filter((l) => l.borrower_user_id === req.user.id);
    const q = str(req.query.q, 60);
    let rows = list.map((l) => loanRow(snap, l));
    if (q) rows = rows.filter((x) => `${x.item_name} ${x.borrower_name} ${x.unit || ""} ${x.borrower_note || ""}`.includes(q));
    res.json({ loans: rows });
  });

  r.get("/borrowers", need("view"), (req, res) => res.json(users.borrowerSuggestions(ctx, req.query.q)));

  r.get("/events", need("view"), (req, res) => {
    const f = { limit: req.query.limit, before: req.query.before, item_id: req.query.item_id, asset_id: req.query.asset_id, q: str(req.query.q, 60) };
    if (req.query.location_id) {
      const snap = ctx.snapshot();
      const { subtree } = ctx.helpers;
      f.location_ids = [...subtree(snap, req.query.location_id)];
    }
    if (req.query.mine === "1" || req.user.role === "student") f.actor_id = req.user.id;
    if (req.query.actions) f.actions = String(req.query.actions).split(",");
    res.json({ events: listEvents(ctx.db, f) });
  });

  // ---------------------------------------------------------------- 고장·수리
  r.get("/repairs", need("view"), (req, res) => {
    res.json({ repairs: repairs.listRepairs(ctx, { status: req.query.status, target_id: req.query.target_id, mine: req.user.role === "student" || req.query.mine === "1", user_id: req.user.id }) });
  });
  r.post("/repairs", need("repair"), (req, res) => res.status(201).json({ repair: repairs.createRepair(ctx, req.actor, { ...(req.body || {}), mark_repair: req.caps.has("repair_manage") && req.body && req.body.mark_repair }) }));
  r.patch("/repairs/:id", need("repair_manage"), (req, res) => res.json({ repair: repairs.updateRepair(ctx, req.actor, req.params.id, req.body || {}) }));
  r.delete("/repairs/:id", need("repair_manage"), (req, res) => {
    repairs.deleteRepair(ctx, req.actor, req.params.id);
    res.json({ ok: true });
  });
  r.get("/repairs-costs", need("repair_manage"), (req, res) => res.json(repairs.repairCosts(ctx, { year: req.query.year })));

  // ---------------------------------------------------------------- 실사
  r.get("/audits", need("view"), (req, res) => res.json({ audits: audits.listAudits(ctx) }));
  r.post("/audits", need("audit"), (req, res) => res.status(201).json(audits.startAudit(ctx, req.actor, req.body || {})));
  r.get("/audits/:id", need("view"), (req, res) => res.json(audits.auditView(ctx, req.params.id)));
  r.post("/audits/:id/scan", need("audit"), (req, res) => res.json(audits.checkCode(ctx, req.actor, req.params.id, (req.body || {}).code)));
  r.patch("/audits/:id/lines/:line", need("audit"), (req, res) => res.json(audits.setLine(ctx, req.actor, req.params.id, req.params.line, req.body || {})));
  r.post("/audits/:id/finish", need("audit"), (req, res) => res.json(audits.finishAudit(ctx, req.actor, req.params.id, { cancel: Boolean(req.body && req.body.cancel) })));
  r.post("/audits/:id/lines/:line/resolve", need("audit", "edit"), (req, res) => res.json(audits.resolveLine(ctx, req.actor, req.params.id, req.params.line, req.body || {})));

  // ---------------------------------------------------------------- 사용자
  r.get("/users", need("users"), (req, res) => res.json({ users: users.listUsers(ctx) }));
  r.post("/users", need("users"), (req, res) => res.status(201).json({ user: users.preRegister(ctx, req.user, req.body || {}) }));
  r.patch("/users/:id", need("users"), (req, res) => res.json({ user: users.updateUser(ctx, req.user, req.params.id, req.body || {}) }));
  r.delete("/users/:id", need("users"), (req, res) => {
    users.deleteUser(ctx, req.user, req.params.id);
    res.json({ ok: true });
  });

  // ---------------------------------------------------------------- 설정
  r.get("/settings", need("settings"), (req, res) => res.json(adminSettings(ctx)));
  r.patch("/settings", need("settings"), (req, res) => res.json(updateSettings(ctx, req.actor, req.caps, req.body || {})));

  // ---------------------------------------------------------------- 도우미
  r.get("/pps", need("view"), (req, res) => res.json(suggestUsefulLife(req.query.name, req.query.class_number, Number(req.query.limit) || 6)));

  r.post("/uploads", need("view"), express.raw({ type: () => true, limit: "13mb" }), (req, res) => {
    if (!(req.caps.has("register") || req.caps.has("edit") || req.caps.has("repair"))) throw forbidden("사진을 올릴 권한이 없습니다");
    res.status(201).json(saveImage(ctx, req.body));
  });

  r.get("/product-search", need("register"), async (req, res) => {
    res.json(await productSearch(ctx, req.query.q, req.query.source));
  });
  r.post("/url-import", need("register"), async (req, res) => {
    const url = str(req.body && req.body.url, 2000);
    if (!url) throw badRequest("제품 링크를 넣으세요");
    const page = await readProductPage(ctx, url);
    // AI 가 연결되어 있으면 본문으로 빈칸을 더 채운다
    if (ctx.ai && ctx.ai.extractProduct && req.body.ai !== false) {
      try {
        const extra = await ctx.ai.extractProduct({ url: page.url, fields: page.fields, text: page.text });
        if (extra) page.ai = extra;
      } catch (e) {
        page.ai_error = e.message;
      }
    }
    delete page.text;
    res.json(page);
  });
  r.get("/image-proxy", need("register"), async (req, res) => {
    const out = await proxyImage(ctx, str(req.query.url, 2000));
    res.setHeader("Content-Type", out.type);
    res.setHeader("Cache-Control", "private, max-age=3600");
    res.setHeader("Content-Security-Policy", "default-src 'none'; sandbox");
    res.send(out.buffer);
  });

  // 배경 제거 서버(rembg) 대신 부르기. 설정 → 제품 검색·이미지에서 주소를 넣으면 켜진다
  r.post("/rembg", need("register"), express.raw({ type: () => true, limit: "13mb" }), async (req, res) => {
    const cfg = section(ctx.db, "images");
    if (cfg.bg_removal !== "rembg" || !cfg.rembg_url) throw badRequest("배경 제거 서버가 설정되지 않았습니다");
    const base = cfg.rembg_url.replace(/\/+$/, "");
    const url = /\/api\/remove$/.test(base) ? base : `${base}/api/remove`;
    const form = new FormData();
    form.append("file", new Blob([req.body], { type: req.get("content-type") || "image/png" }), "image.png");
    let resp;
    try {
      resp = await ctx.cfg.fetch(url, { method: "POST", body: form, signal: AbortSignal.timeout(120000) });
    } catch (e) {
      throw Object.assign(new Error(`배경 제거 서버(${base})에 연결하지 못했습니다: ${e.cause ? e.cause.code || e.message : e.message}`), { status: 502 });
    }
    if (!resp.ok) throw Object.assign(new Error(`배경 제거 서버 오류 (HTTP ${resp.status})`), { status: 502 });
    const buf = Buffer.from(await resp.arrayBuffer());
    const kind = sniffImage(buf);
    if (!kind) throw Object.assign(new Error("배경 제거 서버가 사진이 아닌 것을 돌려줬습니다"), { status: 502 });
    res.setHeader("Content-Type", kind.mime);
    res.setHeader("Content-Security-Policy", "default-src 'none'; sandbox");
    res.send(buf);
  });

  return r;
}

export function loanRow(snap, l) {
  const it = snap.items.get(l.item_id) || {};
  const a = l.asset_id && snap.assets.get(l.asset_id);
  const from = l.from_location_id && snap.locations.get(l.from_location_id);
  return {
    ...loanBrief(l), status: l.status, item_id: l.item_id, item_name: it.name || "(지운 품목)", thumb: it.thumb || null, unit_label: it.unit,
    asset_id: l.asset_id, unit: a ? a.management_number || a.label : null, from_location_id: l.from_location_id, from_path: from ? from.path : "",
    returned_at: l.returned_at, return_condition: l.return_condition, return_note: l.return_note,
  };
}

export { accessPolicy, COOKIE };

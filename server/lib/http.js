// Express 도우미: 로그인 확인·권한·CSRF·오류 응답.
import { COOKIE, parseCookies, userFromToken, accessPolicy, publicUser } from "./auth.js";
import { capsFor } from "./permissions.js";
import { HttpError, unauthorized, forbidden } from "./util.js";

export function attachUser(ctx) {
  return (req, _res, next) => {
    const token = parseCookies(req.headers.cookie)[COOKIE];
    req.sessionToken = token || null;
    const user = token ? userFromToken(ctx, token) : null;
    req.user = user;
    req.caps = capsFor(user, accessPolicy(ctx.db));
    req.actor = user ? { id: user.id, name: user.name, role: user.role, via: viaOf(req) } : null;
    next();
  };
}

function viaOf(req) {
  const v = String(req.get("x-inni-via") || "").toLowerCase();
  return ["scan", "ai", "desk", "voice", "audit"].includes(v) ? v : "app";
}

// 로그인 + 승인된 사용자
export function requireUser(req, _res, next) {
  if (!req.user) return next(unauthorized());
  if (req.user.status === "pending") return next(new HttpError(403, "관리자 승인을 기다리고 있습니다", { code: "pending" }));
  if (req.user.status !== "active") return next(forbidden("사용이 중지된 계정입니다"));
  next();
}

export function need(...caps) {
  return (req, _res, next) => {
    if (!req.user) return next(unauthorized());
    for (const c of caps) if (!req.caps.has(c)) return next(forbidden(CAP_MESSAGE[c] || "권한이 없습니다"));
    next();
  };
}

const CAP_MESSAGE = {
  loan: "대여·반납 권한이 없습니다", move: "위치를 옮길 권한이 없습니다", stock: "입고·사용 권한이 없습니다", register: "새 물품을 등록할 권한이 없습니다",
  edit: "정보를 고칠 권한이 없습니다(담당교사)", delete: "지우거나 폐기할 권한이 없습니다", audit: "실사 권한이 없습니다(담당교사)",
  repair_manage: "수리를 처리할 권한이 없습니다", users: "사용자를 관리할 권한이 없습니다", settings: "설정을 바꿀 권한이 없습니다",
  system: "관리자만 할 수 있습니다", ai: "AI 도우미를 쓸 권한이 없습니다",
};

// 바꾸는 요청은 우리 화면만 보낼 수 있는 머리글이 있어야 한다(CSRF 방지).
// 다른 사이트는 이 머리글을 붙이면 브라우저가 사전 확인(CORS)에서 막는다.
export function csrfGuard(req, _res, next) {
  if (["GET", "HEAD", "OPTIONS"].includes(req.method)) return next();
  if (req.get("x-inni") !== "1") return next(forbidden("잘못된 요청입니다(화면을 새로고침하세요)"));
  const origin = req.get("origin");
  if (origin) {
    let host = "";
    try { host = new URL(origin).host; } catch { host = ""; }
    if (host !== req.get("host")) return next(forbidden("다른 사이트에서 온 요청입니다"));
  }
  next();
}

export function meView(ctx, req) {
  return publicUser(req.user, accessPolicy(ctx.db));
}

export function errorHandler(err, req, res, _next) {
  const status = err.status && err.status >= 400 && err.status < 600 ? err.status : err.type === "entity.too.large" ? 413 : 500;
  if (status >= 500) console.error(`[오류] ${req.method} ${req.originalUrl}:`, err.external ? err.message : err.stack || err.message);
  if (res.headersSent) return;
  const body = { error: status >= 500 && !err.status ? "서버 오류가 났습니다. 잠시 뒤 다시 해 보세요." : err.message || "오류" };
  if (err.code) body.code = err.code;
  if (status === 413) body.error = "보낸 내용이 너무 큽니다";
  res.status(status).json(body);
}

export function clientIp(req) {
  return req.ip || req.socket.remoteAddress || "";
}

export function isSecure(req) {
  return req.secure || req.get("x-forwarded-proto") === "https";
}

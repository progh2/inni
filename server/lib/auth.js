// 로그인·세션. Firebase(구글) 로그인으로 신원을 확인한 뒤, 이 서버의 세션 쿠키를 준다.
// 세션 값은 DB 에 해시로만 남는다. 로그아웃·사용 중지 즉시 끊긴다.
import { newId, randomToken, sha256, nowIso, forbidden, badRequest } from "./util.js";
import { getSetting } from "./db.js";
import { DEFAULT_ACCESS, capsFor } from "./permissions.js";
import { emailAllowed } from "./firebase.js";

export const COOKIE = "inni_sid";

export function accessPolicy(db) {
  return { ...DEFAULT_ACCESS, ...(getSetting(db, "access", {}) || {}) };
}

export function allowedDomains(ctx) {
  const extra = getSetting(ctx.db, "allowed_domains", []) || [];
  return [...new Set([...(ctx.cfg.allowedDomains || []), ...extra.map((d) => String(d).toLowerCase())])];
}

export function isAdminEmail(ctx, email) {
  return (ctx.cfg.adminEmails || []).includes(String(email || "").toLowerCase());
}

/**
 * 확인된 신원으로 사용자를 만들거나 갱신하고 세션을 연다.
 * @param identity { email, name, picture, uid }
 */
export function signIn(ctx, identity, { userAgent = "", ip = "" } = {}) {
  const db = ctx.db;
  const email = String(identity.email || "").trim().toLowerCase();
  if (!email || !email.includes("@")) throw badRequest("이메일이 없는 계정입니다");
  const admin = isAdminEmail(ctx, email);
  if (!admin && !emailAllowed(email, allowedDomains(ctx))) {
    const list = allowedDomains(ctx);
    throw forbidden(`학교 계정(${list.map((d) => `@${d}`).join(", ")})으로 로그인하세요. 지금 계정: ${email}`);
  }
  const access = accessPolicy(db);
  const t = nowIso();
  let user = db.prepare("SELECT * FROM users WHERE email = ?").get(email);
  if (!user) {
    const firstUser = !db.prepare("SELECT 1 FROM users WHERE role = 'owner' AND status = 'active' LIMIT 1").get();
    // 관리자 이메일이 없고 첫 사용자라면(개발 모드 등) 첫 사람을 관리자로 둔다
    const bootstrapOwner = admin || (firstUser && ctx.cfg.authMode === "dev" && !(ctx.cfg.adminEmails || []).length);
    const role = bootstrapOwner ? "owner" : (["manager", "teacher", "student"].includes(access.default_role) ? access.default_role : "teacher");
    const status = bootstrapOwner || access.auto_approve ? "active" : "pending";
    user = {
      id: newId(), email, name: String(identity.name || email.split("@")[0]).slice(0, 80), photo_url: identity.picture || null,
      role, status, firebase_uid: identity.uid || null, created_at: t, updated_at: t,
    };
    db.prepare(`INSERT INTO users(id, email, name, photo_url, role, status, firebase_uid, last_login_at, created_at, updated_at)
      VALUES(@id, @email, @name, @photo_url, @role, @status, @firebase_uid, @created_at, @created_at, @updated_at)`).run(user);
  } else {
    if (user.status === "disabled") throw forbidden("사용이 중지된 계정입니다. 담당 선생님께 문의하세요.");
    const patch = { id: user.id, name: user.name || String(identity.name || "").slice(0, 80), photo_url: identity.picture || user.photo_url, firebase_uid: identity.uid || user.firebase_uid, t };
    if (admin && (user.role !== "owner" || user.status !== "active")) {
      db.prepare("UPDATE users SET role = 'owner', status = 'active' WHERE id = ?").run(user.id);
    }
    db.prepare("UPDATE users SET name = @name, photo_url = @photo_url, firebase_uid = COALESCE(@firebase_uid, firebase_uid), last_login_at = @t, updated_at = @t WHERE id = @id").run(patch);
    user = db.prepare("SELECT * FROM users WHERE id = ?").get(user.id);
  }
  if (user.role === "student" && !access.student_login && !admin) {
    throw forbidden("학생 로그인이 꺼져 있습니다. 선생님께 문의하세요.");
  }
  const token = randomToken();
  const days = ctx.cfg.sessionDays || 30;
  db.prepare("INSERT INTO sessions(id, user_id, created_at, expires_at, last_seen_at, user_agent, ip) VALUES(?, ?, ?, ?, ?, ?, ?)")
    .run(sha256(token), user.id, t, new Date(Date.now() + days * 86400000).toISOString(), t, String(userAgent).slice(0, 200), String(ip).slice(0, 60));
  return { token, user, maxAgeMs: days * 86400000 };
}

export function userFromToken(ctx, token) {
  if (!token) return null;
  const db = ctx.db;
  const s = db.prepare("SELECT * FROM sessions WHERE id = ?").get(sha256(token));
  if (!s) return null;
  if (Date.parse(s.expires_at) < Date.now()) {
    db.prepare("DELETE FROM sessions WHERE id = ?").run(s.id);
    return null;
  }
  const user = db.prepare("SELECT * FROM users WHERE id = ?").get(s.user_id);
  if (!user || user.status === "disabled") return null;
  // 마지막 접속 시각은 5분에 한 번만 쓴다
  if (Date.now() - Date.parse(s.last_seen_at) > 300000) {
    db.prepare("UPDATE sessions SET last_seen_at = ? WHERE id = ?").run(nowIso(), s.id);
  }
  return user;
}

export function signOut(ctx, token) {
  if (token) ctx.db.prepare("DELETE FROM sessions WHERE id = ?").run(sha256(token));
}

export function purgeSessions(ctx) {
  return ctx.db.prepare("DELETE FROM sessions WHERE expires_at < ?").run(nowIso()).changes;
}

export function publicUser(user, access) {
  if (!user) return null;
  return {
    id: user.id, email: user.email, name: user.name, photo_url: user.photo_url, role: user.role, status: user.status,
    caps: [...capsFor(user, access)],
  };
}

export function parseCookies(header) {
  const out = {};
  for (const part of String(header || "").split(";")) {
    const i = part.indexOf("=");
    if (i < 0) continue;
    const k = part.slice(0, i).trim();
    if (!k) continue;
    try {
      out[k] = decodeURIComponent(part.slice(i + 1).trim());
    } catch {
      out[k] = part.slice(i + 1).trim();
    }
  }
  return out;
}

export function sessionCookie(token, { maxAgeMs, secure }) {
  const parts = [`${COOKIE}=${encodeURIComponent(token)}`, "Path=/", "HttpOnly", "SameSite=Lax"];
  if (maxAgeMs !== undefined) parts.push(`Max-Age=${Math.floor(maxAgeMs / 1000)}`);
  if (secure) parts.push("Secure");
  return parts.join("; ");
}

// 승무원(사용자) 관리: 승인·역할·사용 중지·미리 등록.
import { newId, nowIso, str, reqStr, badRequest, forbidden, notFound, conflict } from "./util.js";
import { ROLES, canAssignRole, ROLE_LABEL, STATUS_LABEL } from "./permissions.js";
import { logEvent } from "./events.js";

export function listUsers(ctx) {
  const rows = ctx.db.prepare(`SELECT u.*, (SELECT COUNT(*) FROM loans l WHERE l.borrower_user_id = u.id AND l.status = 'active') loans,
      (SELECT MAX(last_seen_at) FROM sessions s WHERE s.user_id = u.id) last_seen_at
    FROM users u ORDER BY u.status = 'pending' DESC, CASE u.role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 WHEN 'teacher' THEN 2 ELSE 3 END, u.name`).all();
  return rows.map((u) => ({
    id: u.id, email: u.email, name: u.name, photo_url: u.photo_url, role: u.role, role_label: ROLE_LABEL[u.role], status: u.status,
    status_label: STATUS_LABEL[u.status], note: u.note, last_login_at: u.last_login_at, last_seen_at: u.last_seen_at, loans: u.loans, created_at: u.created_at,
    admin_email: (ctx.cfg.adminEmails || []).includes(u.email.toLowerCase()),
  }));
}

export function updateUser(ctx, actor, id, input) {
  const db = ctx.db;
  const cur = db.prepare("SELECT * FROM users WHERE id = ?").get(id);
  if (!cur) throw notFound("사용자가 없습니다");
  const next = { ...cur };
  if (input.role !== undefined) {
    if (!ROLES.includes(input.role)) throw badRequest("역할이 올바르지 않습니다");
    if (!canAssignRole(actor, cur, input.role)) throw forbidden("이 역할을 줄 권한이 없습니다");
    next.role = input.role;
  }
  if (input.status !== undefined) {
    if (!["pending", "active", "disabled"].includes(input.status)) throw badRequest("상태가 올바르지 않습니다");
    if (cur.role === "owner" && actor.role !== "owner") throw forbidden("관리자 계정은 관리자만 바꿀 수 있습니다");
    next.status = input.status;
  }
  if (input.name !== undefined) next.name = reqStr(input.name, "이름", 60);
  if (input.note !== undefined) next.note = str(input.note, 200) || null;
  if (id === actor.id && (next.role !== cur.role || next.status !== "active")) throw badRequest("자기 자신의 역할·상태는 바꿀 수 없습니다");
  // 마지막 관리자를 잃지 않게
  if (cur.role === "owner" && (next.role !== "owner" || next.status !== "active")) {
    const owners = db.prepare("SELECT COUNT(*) n FROM users WHERE role = 'owner' AND status = 'active'").get().n;
    if (owners <= 1) throw conflict("관리자가 한 명뿐이라 바꿀 수 없습니다");
  }
  next.updated_at = nowIso();
  db.prepare("UPDATE users SET role = @role, status = @status, name = @name, note = @note, updated_at = @updated_at WHERE id = @id").run(next);
  if (next.status === "disabled") db.prepare("DELETE FROM sessions WHERE user_id = ?").run(id);
  const what = [];
  if (next.role !== cur.role) what.push(`${ROLE_LABEL[cur.role]} → ${ROLE_LABEL[next.role]}`);
  if (next.status !== cur.status) what.push(`${STATUS_LABEL[cur.status]} → ${STATUS_LABEL[next.status]}`);
  if (what.length) logEvent(db, actor, { action: "user", summary: `${next.name}(${next.email}) ${what.join(", ")}` });
  ctx.changed({ kind: "user", id });
  return next;
}

// 첫 로그인 전에 미리 등록(바로 사용 가능)
export function preRegister(ctx, actor, input) {
  const db = ctx.db;
  const email = reqStr(input.email, "이메일", 120).toLowerCase();
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) throw badRequest("이메일 형식이 올바르지 않습니다");
  const role = ROLES.includes(input.role) ? input.role : "teacher";
  if (!canAssignRole(actor, null, role)) throw forbidden("이 역할을 줄 권한이 없습니다");
  if (db.prepare("SELECT 1 FROM users WHERE email = ?").get(email)) throw conflict("이미 있는 계정입니다");
  const t = nowIso();
  const row = { id: newId(), email, name: str(input.name, 60) || email.split("@")[0], role, status: "active", note: str(input.note, 200) || null, t };
  db.prepare("INSERT INTO users(id, email, name, role, status, note, created_at, updated_at) VALUES(@id, @email, @name, @role, @status, @note, @t, @t)").run(row);
  logEvent(db, actor, { action: "user", summary: `${row.name}(${email}) 미리 등록 · ${ROLE_LABEL[role]}` });
  ctx.changed({ kind: "user", id: row.id });
  return row;
}

export function deleteUser(ctx, actor, id) {
  const db = ctx.db;
  const cur = db.prepare("SELECT * FROM users WHERE id = ?").get(id);
  if (!cur) throw notFound("사용자가 없습니다");
  if (id === actor.id) throw badRequest("자기 자신은 지울 수 없습니다");
  if (cur.role === "owner" && actor.role !== "owner") throw forbidden("관리자 계정은 관리자만 지울 수 있습니다");
  if (db.prepare("SELECT 1 FROM loans WHERE borrower_user_id = ? AND status = 'active'").get(id)) throw conflict("빌려 간 것이 있어 지울 수 없습니다. 사용 중지를 쓰세요.");
  db.prepare("DELETE FROM users WHERE id = ?").run(id);
  logEvent(db, actor, { action: "user", summary: `${cur.name}(${cur.email}) 삭제` });
  ctx.changed({ kind: "user", id });
}

// 빌리는 사람 고르기: 사용자 + 예전에 적은 이름
export function borrowerSuggestions(ctx, q = "") {
  const db = ctx.db;
  const like = `%${String(q).trim()}%`;
  const users = db.prepare("SELECT id, name, email, role FROM users WHERE status = 'active' AND (name LIKE ? OR email LIKE ?) ORDER BY name LIMIT 12").all(like, like);
  const recent = db.prepare(`SELECT borrower_name name, borrower_note note, MAX(created_at) at FROM loans WHERE borrower_user_id IS NULL AND borrower_name LIKE ?
    GROUP BY borrower_name, borrower_note ORDER BY at DESC LIMIT 12`).all(like);
  return { users: users.map((u) => ({ ...u, role_label: ROLE_LABEL[u.role] })), recent };
}

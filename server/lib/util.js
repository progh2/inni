// 서버 공용 도우미: 아이디·시각·입력 검사·HTTP 오류.
import crypto from "node:crypto";

// 사람이 읽고 입력하기 쉬운 코드 글자(0/O, 1/I/L 없음)
const CODE_ALPHABET = "23456789ABCDEFGHJKMNPQRSTUVWXYZ";

export function newId() {
  return crypto.randomBytes(9).toString("base64url");
}

export function randomCode(len = 7) {
  const bytes = crypto.randomBytes(len);
  let s = "";
  for (let i = 0; i < len; i++) s += CODE_ALPHABET[bytes[i] % CODE_ALPHABET.length];
  return s;
}

export function randomToken(bytes = 32) {
  return crypto.randomBytes(bytes).toString("base64url");
}

export const sha256 = (s) => crypto.createHash("sha256").update(String(s)).digest("hex");

export const nowIso = () => new Date().toISOString();

export class HttpError extends Error {
  constructor(status, message, extra = {}) {
    super(message);
    this.status = status;
    Object.assign(this, extra);
  }
}
export const badRequest = (m, extra) => new HttpError(400, m, extra);
export const unauthorized = (m = "로그인이 필요합니다") => new HttpError(401, m);
export const forbidden = (m = "권한이 없습니다") => new HttpError(403, m);
export const notFound = (m = "찾을 수 없습니다") => new HttpError(404, m);
export const conflict = (m, extra) => new HttpError(409, m, extra);

// ---------------------------------------------------------------- 입력 검사
export function str(v, max = 200) {
  if (v === undefined || v === null) return "";
  return String(v).normalize("NFC").trim().slice(0, max);
}

export function optStr(v, max = 200) {
  const s = str(v, max);
  return s ? s : null;
}

export function reqStr(v, name, max = 200) {
  const s = str(v, max);
  if (!s) throw badRequest(`${name}을(를) 입력하세요`);
  return s;
}

export function optNum(v, name, { min = -Infinity, max = Infinity, integer = false } = {}) {
  if (v === undefined || v === null || v === "") return null;
  const n = Number(String(v).replace(/,/g, ""));
  if (!Number.isFinite(n)) throw badRequest(`${name}은(는) 숫자여야 합니다`);
  if (integer && !Number.isInteger(n)) throw badRequest(`${name}은(는) 정수여야 합니다`);
  if (n < min || n > max) throw badRequest(`${name}은(는) ${min}~${max} 사이여야 합니다`);
  return n;
}

export function reqNum(v, name, opts) {
  const n = optNum(v, name, opts);
  if (n === null) throw badRequest(`${name}을(를) 입력하세요`);
  return n;
}

// 수량: 소수 둘째 자리까지
export function qty(v, name = "수량", { allowZero = false } = {}) {
  const n = reqNum(v, name, { min: 0, max: 1e9 });
  if (!allowZero && n <= 0) throw badRequest(`${name}은(는) 0보다 커야 합니다`);
  return Math.round(n * 100) / 100;
}

export function optDate(v, name = "날짜") {
  const s = str(v, 40);
  if (!s) return null;
  const m = /^(\d{4})[-./](\d{1,2})[-./](\d{1,2})/.exec(s);
  if (!m) throw badRequest(`${name} 형식은 YYYY-MM-DD 입니다`);
  const d = `${m[1]}-${m[2].padStart(2, "0")}-${m[3].padStart(2, "0")}`;
  const t = Date.parse(`${d}T00:00:00Z`);
  if (!Number.isFinite(t)) throw badRequest(`${name}이(가) 올바른 날짜가 아닙니다`);
  return d;
}

export function optDateTime(v, name = "시각") {
  const s = str(v, 40);
  if (!s) return null;
  const t = Date.parse(s);
  if (!Number.isFinite(t)) throw badRequest(`${name}이(가) 올바르지 않습니다`);
  return new Date(t).toISOString();
}

export function optYear(v, name = "연도") {
  return optNum(v, name, { min: 1950, max: 2200, integer: true });
}

export function oneOf(v, list, name) {
  if (!list.includes(v)) throw badRequest(`${name}은(는) ${list.join(", ")} 중 하나입니다`);
  return v;
}

export function tagsFrom(v) {
  const list = Array.isArray(v) ? v : String(v || "").split(/[,#\n]/);
  return [...new Set(list.map((t) => str(t, 40)).filter(Boolean))].slice(0, 30);
}

export function jsonParse(s, fallback) {
  if (s === null || s === undefined || s === "") return fallback;
  try {
    return JSON.parse(s);
  } catch {
    return fallback;
  }
}

export function pick(obj, keys) {
  const out = {};
  for (const k of keys) if (obj[k] !== undefined) out[k] = obj[k];
  return out;
}

// 숫자를 사람이 읽는 수량으로 (3 → "3", 2.5 → "2.5")
export function fmtBytes(n) {
  const b = Number(n) || 0;
  if (b < 1024) return `${b}B`;
  if (b < 1048576) return `${Math.round(b / 1024)}KB`;
  return `${(b / 1048576).toFixed(b < 10485760 ? 1 : 0)}MB`;
}

export function fmtQty(n) {
  const v = Math.round(Number(n || 0) * 100) / 100;
  return Number.isInteger(v) ? String(v) : String(v);
}

// 서울 기준 날짜 조각
const seoulFmt = new Intl.DateTimeFormat("en-CA", {
  timeZone: "Asia/Seoul", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hourCycle: "h23",
});
export function seoulParts(date = new Date()) {
  const p = Object.fromEntries(seoulFmt.formatToParts(date).map((x) => [x.type, x.value]));
  return { y: Number(p.year), m: Number(p.month), d: Number(p.day), hh: Number(p.hour), mm: Number(p.minute), ymd: `${p.year}-${p.month}-${p.day}` };
}

export function fmtSeoul(iso) {
  if (!iso) return "";
  const p = seoulParts(new Date(iso));
  return `${p.m}/${p.d} ${String(p.hh).padStart(2, "0")}:${String(p.mm).padStart(2, "0")}`;
}

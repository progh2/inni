// 학교 밖 AI(OpenAI 등)로 보낼 때 사람 이름을 가린다. "박학생" → "대여자07".
// 서버가 답과 도구 인자를 되돌려(unmask) 화면에는 원래 이름이 보인다.
const NAME_RE = /^[가-힣]{2,4}$/;
const STOP = new Set(["선생님", "교사", "학생", "담당", "관리자", "동아리", "시스템", "방과후", "수업", "실습", "프로젝트", "대회", "교무실"]);
const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

export function namesFrom({ users = [], loans = [] } = {}) {
  const out = new Set();
  for (const u of users) {
    const n = String((u && u.name) || "").trim();
    if (NAME_RE.test(n) && !STOP.has(n)) out.add(n);
  }
  for (const l of loans) {
    const n = String((l && l.borrower_name) || "").trim();
    if (NAME_RE.test(n) && !STOP.has(n)) out.add(n);
  }
  return [...out];
}

export class NameMask {
  constructor(names = []) {
    const list = [...new Set(names)].sort((a, b) => a.localeCompare(b, "ko"));
    const width = list.length >= 100 ? 3 : 2;
    this.toCode = new Map(list.map((n, i) => [n, `대여자${String(i + 1).padStart(width, "0")}`]));
    this.toName = new Map([...this.toCode].map(([n, c]) => [c, n]));
    const byLen = (a, b) => b.length - a.length;
    const names2 = [...this.toCode.keys()].sort(byLen);
    const codes = [...this.toName.keys()].sort(byLen);
    this.nameRe = names2.length ? new RegExp(names2.map(escapeRe).join("|"), "g") : null;
    this.codeRe = codes.length ? new RegExp(codes.map(escapeRe).join("|"), "g") : null;
  }

  static none() { return new NameMask([]); }

  get size() { return this.toCode.size; }

  mask(text) {
    if (text == null) return text;
    return this.nameRe ? String(text).replace(this.nameRe, (m) => this.toCode.get(m)) : String(text);
  }

  unmask(text) {
    if (text == null) return text;
    return this.codeRe ? String(text).replace(this.codeRe, (m) => this.toName.get(m)) : String(text);
  }

  unmaskDeep(value) { return walk(value, (s) => this.unmask(s)); }

  maskDeep(value) { return walk(value, (s) => this.mask(s)); }
}

function walk(value, fn, depth = 0) {
  if (typeof value === "string") return fn(value);
  if (depth > 6 || value == null || typeof value !== "object") return value;
  if (Array.isArray(value)) return value.map((v) => walk(v, fn, depth + 1));
  const out = {};
  for (const [k, v] of Object.entries(value)) out[k] = walk(v, fn, depth + 1);
  return out;
}

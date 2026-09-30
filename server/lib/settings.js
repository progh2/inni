// 학교 설정. 값은 settings 표에, 비밀 값(API 키·봇 토큰)은 secrets 표에 둔다.
// 화면에는 비밀 값을 다시 보내지 않고 끝 네 글자만 보여 준다.
import { getSetting, setSetting, getSecret, setSecret, secretHint } from "./db.js";
import { str, optStr, badRequest, forbidden } from "./util.js";
import { DEFAULT_ACCESS } from "./permissions.js";
import { logEvent } from "./events.js";

export const LABEL_PAPERS = {
  "a4-24": { name: "A4 24칸 (3×8, 64×34mm)", cols: 3, rows: 8, w: 64, h: 33.9 },
  "a4-40": { name: "A4 40칸 (4×10, 48×25mm)", cols: 4, rows: 10, w: 48.5, h: 25.4 },
  "a4-65": { name: "A4 65칸 (5×13, 38×21mm)", cols: 5, rows: 13, w: 38.1, h: 21.2 },
  "a4-12": { name: "A4 12칸 (2×6, 크게)", cols: 2, rows: 6, w: 99, h: 46 },
  "roll-50x30": { name: "라벨 프린터 50×30mm", cols: 1, rows: 1, w: 50, h: 30, roll: true },
  "roll-62x29": { name: "라벨 프린터 62×29mm (브라더 DK)", cols: 1, rows: 1, w: 62, h: 29, roll: true },
  "roll-40x20": { name: "라벨 프린터 40×20mm (작게)", cols: 1, rows: 1, w: 40, h: 20, roll: true },
};

const DEFAULTS = {
  school: { name: "", short_name: "" },
  allowed_domains: [],
  access: DEFAULT_ACCESS,
  numbering: { default_prefix: "" },
  labels: { paper: "a4-24", show_school: true, show_location: true, show_name: true, show_number: true },
  loan: { day_end: "17:00", default_due: "today" },
  assistant: { name: "이니", proactive: true },
  product_search: { provider: "auto" },
  images: { bg_removal: "browser", rembg_url: "" },
  url_import: { enabled: true, allow_private: false },
  telegram: { enabled: false, chat_ids: [], overdue: true, low: true, repair: true, backup: false },
  backup: { auto: true, hour: 3, keep: 14 },
};

const SECRET_KEYS = ["naver_client_id", "naver_client_secret", "kakao_rest_key", "telegram_token"];

export function section(db, key) {
  const base = DEFAULTS[key];
  const cur = getSetting(db, key, null);
  if (Array.isArray(base)) return Array.isArray(cur) ? cur : base;
  return { ...(base || {}), ...(cur || {}) };
}

export function schoolName(ctx) {
  return section(ctx.db, "school").name || ctx.cfg.schoolName || "우리 학교";
}

// 로그인한 누구에게나 보내도 되는 값
export function publicSettings(ctx) {
  const db = ctx.db;
  const labels = section(db, "labels");
  return {
    school: { ...section(db, "school"), name: schoolName(ctx) },
    labels: { ...labels, papers: LABEL_PAPERS },
    loan: section(db, "loan"),
    assistant: section(db, "assistant"),
    numbering: section(db, "numbering"),
    images: { bg_removal: section(db, "images").bg_removal, rembg: Boolean(section(db, "images").rembg_url) },
    product_search: { available: productSearchSources(db) },
    url_import: { enabled: section(db, "url_import").enabled },
    access: section(db, "access"),
  };
}

export function productSearchSources(db) {
  const out = [];
  if (getSecret(db, "naver_client_id") && getSecret(db, "naver_client_secret")) out.push("naver_shop", "naver_image");
  if (getSecret(db, "kakao_rest_key")) out.push("kakao_image");
  return out;
}

// 관리 화면용(비밀 값은 힌트만)
export function adminSettings(ctx) {
  const db = ctx.db;
  const out = {};
  for (const k of Object.keys(DEFAULTS)) out[k] = section(db, k);
  out.school.name = schoolName(ctx);
  out.secrets = Object.fromEntries(SECRET_KEYS.map((k) => [k, secretHint(getSecret(db, k))]));
  out.env = {
    allowed_domains: ctx.cfg.allowedDomains, admin_emails: ctx.cfg.adminEmails, auth_mode: ctx.cfg.authMode,
    firebase_project: ctx.cfg.firebase.projectId, public_url: ctx.cfg.publicUrl, data_dir: ctx.cfg.dataDir, version: ctx.cfg.version,
  };
  out.label_papers = LABEL_PAPERS;
  return out;
}

const bool = (v) => v === true || v === "true" || v === 1 || v === "1" || v === "on";

// 섹션마다 검사. system=true 면 관리자(owner) 전용 섹션
const VALIDATORS = {
  school: { system: false, fn: (v) => ({ name: str(v.name, 60), short_name: str(v.short_name, 20) }) },
  numbering: { system: false, fn: (v) => ({ default_prefix: str(v.default_prefix, 30) }) },
  labels: {
    system: false,
    fn: (v) => {
      if (v.paper && !LABEL_PAPERS[v.paper]) throw badRequest("모르는 라벨 용지입니다");
      return { paper: v.paper || "a4-24", show_school: bool(v.show_school), show_location: bool(v.show_location), show_name: v.show_name === undefined ? true : bool(v.show_name), show_number: v.show_number === undefined ? true : bool(v.show_number) };
    },
  },
  loan: {
    system: false,
    fn: (v) => {
      const end = str(v.day_end, 5) || "17:00";
      if (!/^\d{2}:\d{2}$/.test(end)) throw badRequest("수업 끝 시각은 17:00 처럼 적습니다");
      return { day_end: end, default_due: ["today", "tomorrow", "week", "none"].includes(v.default_due) ? v.default_due : "today" };
    },
  },
  assistant: { system: false, fn: (v) => ({ name: str(v.name, 12) || "이니", proactive: v.proactive === undefined ? true : bool(v.proactive) }) },
  access: {
    system: true,
    fn: (v) => ({
      auto_approve: bool(v.auto_approve),
      default_role: ["manager", "teacher", "student"].includes(v.default_role) ? v.default_role : "teacher",
      teacher_can_register: v.teacher_can_register === undefined ? true : bool(v.teacher_can_register),
      teacher_can_move: v.teacher_can_move === undefined ? true : bool(v.teacher_can_move),
      student_login: bool(v.student_login),
      student_ai: bool(v.student_ai),
    }),
  },
  allowed_domains: {
    system: true,
    fn: (v) => {
      const list = (Array.isArray(v) ? v : String(v || "").split(/[,\s]+/)).map((d) => String(d).trim().toLowerCase().replace(/^@/, "")).filter(Boolean);
      for (const d of list) if (!/^[a-z0-9.-]+\.[a-z]{2,}$/.test(d)) throw badRequest(`도메인 형식이 이상합니다: ${d}`);
      return [...new Set(list)].slice(0, 20);
    },
  },
  product_search: { system: true, fn: (v) => ({ provider: ["auto", "naver_shop", "naver_image", "kakao_image"].includes(v.provider) ? v.provider : "auto" }) },
  images: {
    system: true,
    fn: (v) => {
      const url = str(v.rembg_url, 300).replace(/\/+$/, "");
      if (url && !/^https?:\/\//i.test(url)) throw badRequest("배경 제거 서버 주소는 http:// 로 시작해야 합니다");
      return { bg_removal: ["browser", "rembg", "off"].includes(v.bg_removal) ? v.bg_removal : "browser", rembg_url: url };
    },
  },
  url_import: { system: true, fn: (v) => ({ enabled: v.enabled === undefined ? true : bool(v.enabled), allow_private: bool(v.allow_private) }) },
  telegram: {
    system: true,
    fn: (v) => ({
      enabled: bool(v.enabled),
      chat_ids: (Array.isArray(v.chat_ids) ? v.chat_ids : String(v.chat_ids || "").split(/[,\s]+/)).map((x) => String(x).trim()).filter((x) => /^-?\d+$|^@\w+$/.test(x)).slice(0, 10),
      overdue: bool(v.overdue), low: bool(v.low), repair: bool(v.repair), backup: bool(v.backup),
    }),
  },
  backup: {
    system: true,
    fn: (v) => {
      const hour = Math.round(Number(v.hour));
      const keep = Math.round(Number(v.keep));
      if (!(hour >= 0 && hour <= 23)) throw badRequest("자동 백업 시각은 0~23시입니다");
      if (!(keep >= 1 && keep <= 365)) throw badRequest("보관 개수는 1~365개입니다");
      return { auto: bool(v.auto), hour, keep };
    },
  },
};

export function updateSettings(ctx, actor, caps, patch = {}) {
  const db = ctx.db;
  const changed = [];
  for (const [key, value] of Object.entries(patch)) {
    if (key === "secrets") continue;
    const v = VALIDATORS[key];
    if (!v) throw badRequest(`모르는 설정입니다: ${key}`);
    if (v.system && !caps.has("system")) throw forbidden("관리자만 바꿀 수 있는 설정입니다");
    if (!v.system && !caps.has("settings")) throw forbidden("설정을 바꿀 권한이 없습니다");
    setSetting(db, key, v.fn(value || {}));
    changed.push(key);
  }
  if (patch.secrets && typeof patch.secrets === "object") {
    if (!caps.has("system")) throw forbidden("관리자만 바꿀 수 있는 설정입니다");
    for (const [k, val] of Object.entries(patch.secrets)) {
      if (!SECRET_KEYS.includes(k)) throw badRequest(`모르는 비밀 값입니다: ${k}`);
      if (val === null || val === "") { setSecret(db, k, ""); changed.push(`${k}(지움)`); } else if (typeof val === "string" && val.trim()) { setSecret(db, k, val.trim()); changed.push(`${k}(새 값)`); }
    }
  }
  if (changed.length) {
    logEvent(db, actor, { action: "settings", summary: `설정 변경: ${changed.join(", ")}` });
    ctx.changed({ kind: "settings" });
  }
  return adminSettings(ctx);
}

export { SECRET_KEYS, optStr };

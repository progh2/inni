// 공용 도우미. 화면에 넣는 값은 모두 esc() 를 거친다.
export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
export const DAY = 86400000;

export const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
export const icon = (name, cls = "") => `<svg class="ic ${cls}" aria-hidden="true"><use href="#i-${name}"/></svg>`;

export const num = (n) => (Number(n) || 0).toLocaleString("ko-KR");
export const qty = (n) => {
  const v = Math.round(Number(n || 0) * 100) / 100;
  return v.toLocaleString("ko-KR", { maximumFractionDigits: 2 });
};
export const bytes = (n) => {
  const b = Number(n) || 0;
  if (b < 1024) return `${b}B`;
  if (b < 1048576) return `${Math.round(b / 1024)}KB`;
  return `${(b / 1048576).toFixed(b < 10485760 ? 1 : 0)}MB`;
};
export const won = (n) => (n === null || n === undefined || n === "" ? "—" : `${Math.round(Number(n)).toLocaleString("ko-KR")}원`);
export const clamp = (v, a, b) => Math.min(b, Math.max(a, v));

const seoulFmt = new Intl.DateTimeFormat("ko-KR", {
  timeZone: "Asia/Seoul", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23", weekday: "short",
});
export function seoulParts(date = new Date()) {
  const p = Object.fromEntries(seoulFmt.formatToParts(date).map((x) => [x.type, x.value]));
  return { y: Number(p.year), m: Number(p.month), d: Number(p.day), hh: Number(p.hour) % 24, mm: Number(p.minute), ss: Number(p.second), weekday: p.weekday };
}
export function todayYmd(d = new Date()) {
  const p = seoulParts(d);
  return `${p.y}-${String(p.m).padStart(2, "0")}-${String(p.d).padStart(2, "0")}`;
}
export function fmtDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso.length === 10 ? `${iso}T00:00:00+09:00` : iso);
  if (Number.isNaN(d.getTime())) return "—";
  const p = seoulParts(d);
  return `${p.y}. ${p.m}. ${p.d}.`;
}
export function fmtDateTime(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  const p = seoulParts(d);
  return `${p.m}/${p.d}(${p.weekday}) ${String(p.hh).padStart(2, "0")}:${String(p.mm).padStart(2, "0")}`;
}
export function fmtTime(iso) {
  if (!iso) return "—";
  const p = seoulParts(new Date(iso));
  return `${String(p.hh).padStart(2, "0")}:${String(p.mm).padStart(2, "0")}`;
}
export function relTime(iso, now = Date.now()) {
  if (!iso) return "—";
  const t = new Date(iso).getTime();
  if (!Number.isFinite(t)) return "—";
  const s = Math.round((now - t) / 1000);
  if (s < 0) {
    const f = -s;
    if (f < 3600) return `${Math.max(1, Math.ceil(f / 60))}분 뒤`;
    if (f < 86400) return `${Math.round(f / 3600)}시간 뒤`;
    return `${Math.round(f / 86400)}일 뒤`;
  }
  if (s < 45) return "방금";
  if (s < 3600) return `${Math.round(s / 60)}분 전`;
  if (s < 86400) return `${Math.round(s / 3600)}시간 전`;
  if (s < 86400 * 2) return "어제";
  return `${Math.round(s / 86400)}일 전`;
}
// 반납 예정 설명: "오늘 17:00", "내일 17:00", "3일 늦음"
export function dueLabel(iso, now = Date.now()) {
  if (!iso) return "기한 없음";
  const t = new Date(iso).getTime();
  if (t < now) {
    const days = Math.ceil((now - t) / DAY);
    return days <= 1 ? `${fmtTime(iso)} 지남` : `${days - 1}일 늦음`;
  }
  const today = todayYmd(new Date(now));
  const tomorrow = todayYmd(new Date(now + DAY));
  const day = todayYmd(new Date(t));
  if (day === today) return `오늘 ${fmtTime(iso)}`;
  if (day === tomorrow) return `내일 ${fmtTime(iso)}`;
  return fmtDateTime(iso);
}

// 반납 예정 시각 만들기(서울 기준, 수업 끝 시각)
export function dueAt(preset, dayEnd = "17:00", now = new Date()) {
  if (!preset || preset === "none") return null;
  const [hh, mm] = String(dayEnd || "17:00").split(":").map(Number);
  const k = new Date(now.getTime() + 9 * 3600000);
  const add = { today: 0, tomorrow: 1, "3d": 3, week: 7, "2w": 14 }[preset];
  if (add === undefined) return null;
  let d = new Date(Date.UTC(k.getUTCFullYear(), k.getUTCMonth(), k.getUTCDate() + add, hh - 9, mm));
  // 오늘 수업이 이미 끝났으면 오늘 자정까지
  if (add === 0 && d.getTime() < now.getTime()) d = new Date(Date.UTC(k.getUTCFullYear(), k.getUTCMonth(), k.getUTCDate(), 23 - 9, 59));
  return d.toISOString();
}

export function debounce(fn, ms = 200) {
  let t;
  const f = (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
  f.cancel = () => clearTimeout(t);
  return f;
}

export function downloadBlob(filename, blob) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 5000);
}

export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    const ta = document.createElement("textarea");
    ta.value = text;
    ta.style.position = "fixed";
    ta.style.opacity = "0";
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try { ok = document.execCommand("copy"); } catch { ok = false; }
    ta.remove();
    return ok;
  }
}

export const prefersReducedMotion = () => window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
export const isMobile = () => window.matchMedia("(max-width: 760px)").matches;
export const isTouch = () => window.matchMedia("(pointer: coarse)").matches;

// 로컬 저장(사생활 보호 모드에서도 죽지 않게)
export const local = {
  get(key, fallback = null) {
    try {
      const v = localStorage.getItem(`inni.${key}`);
      return v === null ? fallback : JSON.parse(v);
    } catch { return fallback; }
  },
  set(key, value) {
    try { localStorage.setItem(`inni.${key}`, JSON.stringify(value)); } catch { /* 저장 공간 없음 */ }
  },
};

// 최근 고른 것 기억(장소·빌린 사람 등). 앞에 넣고 중복 제거
export function remember(key, value, max = 8) {
  const list = local.get(key, []).filter((x) => JSON.stringify(x) !== JSON.stringify(value));
  list.unshift(value);
  local.set(key, list.slice(0, max));
}

export const KIND_LABEL = { equipment: "장비", fixture: "비품", consumable: "소모품", part: "부품" };
export const KIND_HINT = { equipment: "한 대씩 관리(관리번호)", fixture: "의자·책상처럼 개수로", consumable: "쓰면 줄어드는 것", part: "부품·재료" };
export const KIND_ICON = { equipment: "tool", fixture: "chair", consumable: "drop", part: "chip" };
export const STATUS_TONE = { available: "good", partial: "info", on_loan: "warn", repair: "serious", lost: "crit", empty: "muted", low: "warn", archived: "muted", retired: "muted" };
export const UNIT_TONE = { available: "good", on_loan: "warn", repair: "serious", lost: "crit", retired: "muted" };

export function statusChip(status, label) {
  return `<span class="chip-s ${STATUS_TONE[status] || "info"}"><i></i>${esc(label)}</span>`;
}

// 사진 자리(사진이 없으면 종류 아이콘을 홀로그램처럼)
export function thumb(item, size = "") {
  const src = item && (item.thumb || item.image);
  if (src) return `<span class="thumb ${size}"><img src="/uploads/${esc(src)}" alt="" loading="lazy" decoding="async"></span>`;
  return `<span class="thumb ${size} blank">${icon(KIND_ICON[item && item.kind] || "box")}</span>`;
}

export function wait(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

export function vibrate(pattern = 20) {
  try { if (navigator.vibrate) navigator.vibrate(pattern); } catch { /* 지원 안 함 */ }
}

export function loadScript(src) {
  return new Promise((resolve, reject) => {
    if (document.querySelector(`script[src="${src}"]`)) return resolve();
    const s = document.createElement("script");
    s.src = src;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error(`${src} 를 불러오지 못했습니다`));
    document.head.appendChild(s);
  });
}

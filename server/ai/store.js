// AI 연결 설정. 여러 연결(OpenAI·Ollama·호환 서버·aiapi 프록시)을 등록해 두고
// "대화(이니)"와 "사진 읽기(비전)"에 각각 어떤 연결·모델을 쓸지 고른다. API 키는 secrets 표에만 둔다.
import { getSetting, setSetting, getSecret, setSecret, secretHint } from "../lib/db.js";
import { newId, str, badRequest, notFound } from "../lib/util.js";

export const PROVIDERS = {
  openai: { label: "ChatGPT (OpenAI API)", needsKey: true, needsUrl: false },
  ollama: { label: "Ollama (학교 PC 로컬)", needsKey: false, needsUrl: true, defaultUrl: "http://192.168.0.20:11434" },
  aiapi: { label: "AIAPI 관제 함교 (학교 프록시)", needsKey: true, needsUrl: true, defaultUrl: "http://host.docker.internal:4000" },
  compatible: { label: "OpenAI 호환 서버", needsKey: false, needsUrl: true, defaultUrl: "http://192.168.0.20:1234/v1" },
};

const DEFAULTS = {
  enabled: false,
  connections: [],
  chat: { connection_id: "", model: "" },
  vision: { connection_id: "", model: "" },
  mask_names: true,
  monthly_token_cap: 3000000,
  num_ctx: 8192,
  max_output_tokens: 800,
  fast_mode: true,
  usage: { month: "", prompt_tokens: 0, completion_tokens: 0, requests: 0 },
};

export function seoulMonth(date = new Date()) {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Seoul", year: "numeric", month: "2-digit" }).format(date);
}

export function isPrivateHost(url) {
  const m = /^https?:\/\/([^/:]+)/i.exec(String(url || ""));
  if (!m) return false;
  const h = m[1].toLowerCase();
  return h === "localhost" || h === "host.docker.internal" || /^127\./.test(h) || /^10\./.test(h) || /^192\.168\./.test(h)
    || /^172\.(1[6-9]|2\d|3[01])\./.test(h) || h.endsWith(".local") || !h.includes(".");
}

function cleanUrl(value) {
  const s = String(value || "").trim().replace(/\/+$/, "");
  if (!s) return "";
  if (!/^https?:\/\/[^\s/]+/i.test(s)) throw badRequest("주소는 http:// 또는 https:// 로 시작해야 합니다");
  return s;
}

export function loadAi(db) {
  const cur = getSetting(db, "ai", null) || {};
  return {
    ...DEFAULTS, ...cur,
    chat: { ...DEFAULTS.chat, ...(cur.chat || {}) },
    vision: { ...DEFAULTS.vision, ...(cur.vision || {}) },
    usage: { ...DEFAULTS.usage, ...(cur.usage || {}) },
    connections: Array.isArray(cur.connections) ? cur.connections : [],
  };
}

function saveAi(db, cfg) {
  setSetting(db, "ai", cfg);
}

export function connectionWithKey(db, cfg, id) {
  const c = cfg.connections.find((x) => x.id === id);
  if (!c) return null;
  return { ...c, api_key: getSecret(db, `ai_key_${c.id}`) };
}

// 외부(학교 밖)로 나가는 연결인지. 이름 가리기에 쓴다.
export function isExternal(c) {
  if (!c) return true;
  if (c.provider === "openai") return true;
  return !isPrivateHost(c.base_url);
}

export function chatReady(db, cfg = loadAi(db)) {
  if (!cfg.enabled) return false;
  const c = connectionWithKey(db, cfg, cfg.chat.connection_id);
  if (!c || !cfg.chat.model) return false;
  if (PROVIDERS[c.provider].needsKey && !c.api_key) return false;
  if (PROVIDERS[c.provider].needsUrl && !c.base_url) return false;
  return true;
}

export function visionReady(db, cfg = loadAi(db)) {
  if (!cfg.enabled) return false;
  const id = cfg.vision.connection_id || cfg.chat.connection_id;
  const model = cfg.vision.model || (cfg.vision.connection_id ? "" : cfg.chat.model);
  const c = connectionWithKey(db, cfg, id);
  return Boolean(c && model && (!PROVIDERS[c.provider].needsKey || c.api_key));
}

export function publicAi(db) {
  const cfg = loadAi(db);
  const month = seoulMonth();
  return {
    ...cfg,
    connections: cfg.connections.map((c) => {
      const key = getSecret(db, `ai_key_${c.id}`);
      return { ...c, provider_label: PROVIDERS[c.provider] ? PROVIDERS[c.provider].label : c.provider, key_hint: secretHint(key), has_key: Boolean(key), external: isExternal(c) };
    }),
    usage: cfg.usage.month === month ? cfg.usage : { month, prompt_tokens: 0, completion_tokens: 0, requests: 0 },
    ready: chatReady(db, cfg),
    vision_ready: visionReady(db, cfg),
    providers: PROVIDERS,
  };
}

export function upsertConnection(db, input) {
  const cfg = loadAi(db);
  const provider = String(input.provider || "");
  if (!PROVIDERS[provider]) throw badRequest("연결 종류는 openai, ollama, aiapi, compatible 중 하나입니다");
  const base = PROVIDERS[provider].needsUrl ? cleanUrl(input.base_url) : "";
  if (PROVIDERS[provider].needsUrl && !base) throw badRequest("서버 주소를 넣으세요");
  let conn = input.id ? cfg.connections.find((c) => c.id === input.id) : null;
  if (input.id && !conn) throw notFound("연결이 없습니다");
  if (!conn) {
    conn = { id: newId(), created_at: new Date().toISOString() };
    cfg.connections.push(conn);
  }
  if (conn.provider && conn.provider !== provider) setSecret(db, `ai_key_${conn.id}`, "");
  conn.provider = provider;
  conn.name = str(input.name, 40) || PROVIDERS[provider].label;
  conn.base_url = base;
  if (typeof input.api_key === "string" && input.api_key.trim()) setSecret(db, `ai_key_${conn.id}`, input.api_key.trim());
  if (input.clear_api_key) setSecret(db, `ai_key_${conn.id}`, "");
  // 첫 연결이면 대화용으로 바로 잡는다
  if (!cfg.chat.connection_id) cfg.chat.connection_id = conn.id;
  saveAi(db, cfg);
  return conn;
}

export function deleteConnection(db, id) {
  const cfg = loadAi(db);
  cfg.connections = cfg.connections.filter((c) => c.id !== id);
  if (cfg.chat.connection_id === id) cfg.chat = { connection_id: cfg.connections[0] ? cfg.connections[0].id : "", model: "" };
  if (cfg.vision.connection_id === id) cfg.vision = { connection_id: "", model: "" };
  setSecret(db, `ai_key_${id}`, "");
  saveAi(db, cfg);
}

const num = (v, min, max, name) => {
  const n = Number(v);
  if (!Number.isFinite(n) || n < min || n > max) throw badRequest(`${name}은(는) ${min}~${max} 사이여야 합니다`);
  return Math.round(n);
};

export function updateAi(db, patch = {}) {
  const cfg = loadAi(db);
  if (patch.enabled !== undefined) cfg.enabled = Boolean(patch.enabled);
  for (const role of ["chat", "vision"]) {
    if (!patch[role]) continue;
    const cid = str(patch[role].connection_id, 40);
    if (cid && !cfg.connections.find((c) => c.id === cid)) throw badRequest("없는 연결입니다");
    cfg[role] = { connection_id: cid, model: str(patch[role].model, 200) };
  }
  if (patch.mask_names !== undefined) cfg.mask_names = Boolean(patch.mask_names);
  if (patch.monthly_token_cap !== undefined) cfg.monthly_token_cap = num(patch.monthly_token_cap, 0, 1e9, "월 토큰 상한");
  if (patch.num_ctx !== undefined) cfg.num_ctx = num(patch.num_ctx, 2048, 131072, "문맥 길이");
  if (patch.max_output_tokens !== undefined) cfg.max_output_tokens = num(patch.max_output_tokens, 200, 4000, "답변 길이");
  if (patch.fast_mode !== undefined) cfg.fast_mode = Boolean(patch.fast_mode);
  if (cfg.enabled && !cfg.chat.connection_id) throw badRequest("먼저 AI 연결을 하나 등록하세요");
  saveAi(db, cfg);
  return cfg;
}

export function setLastTest(db, connectionId, result) {
  const cfg = loadAi(db);
  const c = cfg.connections.find((x) => x.id === connectionId);
  if (!c) return;
  c.last_test = { ...result, at: new Date().toISOString() };
  saveAi(db, cfg);
}

export function addUsage(db, { prompt_tokens = 0, completion_tokens = 0 } = {}) {
  const cfg = loadAi(db);
  const month = seoulMonth();
  if (cfg.usage.month !== month) cfg.usage = { month, prompt_tokens: 0, completion_tokens: 0, requests: 0 };
  cfg.usage.prompt_tokens += Number(prompt_tokens) || 0;
  cfg.usage.completion_tokens += Number(completion_tokens) || 0;
  cfg.usage.requests += 1;
  saveAi(db, cfg);
}

export function capReached(db) {
  const cfg = loadAi(db);
  const cap = Number(cfg.monthly_token_cap) || 0;
  if (!cap || cfg.usage.month !== seoulMonth()) return false;
  return cfg.usage.prompt_tokens + cfg.usage.completion_tokens >= cap;
}

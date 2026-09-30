// 화면 전체가 같이 보는 상태: 나·설정·장소·분류·경보. 서버 알림(SSE)이 오면 다시 불러온다.
import { api } from "./api.js";
import { local } from "./util.js";

export const state = {
  me: null,
  settings: null,
  locations: [],
  locMap: new Map(),
  categories: [],
  alerts: { level: "green", alerts: [] },
  counts: {},
  server: {},
  ai: { available: false, vision: false, name: "이니" },
  authConfig: null,
};

const listeners = new Map();
export function on(event, fn) {
  if (!listeners.has(event)) listeners.set(event, new Set());
  listeners.get(event).add(fn);
  return () => listeners.get(event).delete(fn);
}
export function emit(event, data) {
  for (const fn of listeners.get(event) || []) {
    try { fn(data); } catch (e) { console.error(e); }
  }
}

export const can = (cap) => Boolean(state.me && state.me.caps && state.me.caps.includes(cap));

function setLocations(list) {
  state.locations = list;
  state.locMap = new Map(list.map((l) => [l.id, l]));
}

export async function loadBootstrap() {
  const b = await api.get("/api/bootstrap");
  state.me = b.me;
  state.settings = b.settings;
  setLocations(b.locations);
  state.categories = b.categories;
  state.alerts = b.alerts;
  state.counts = b.counts;
  state.server = b.server;
  emit("bootstrap", b);
  return b;
}

export async function refreshCore() {
  const b = await api.get("/api/bootstrap");
  state.me = b.me;
  state.settings = b.settings;
  setLocations(b.locations);
  state.categories = b.categories;
  state.alerts = b.alerts;
  state.counts = b.counts;
  state.server = b.server;
  emit("core", b);
  return b;
}

export async function loadAiConfig() {
  try {
    const c = await api.get("/api/ai/config");
    state.ai = c.connections ? { available: c.ready && can("ai"), vision: c.vision_ready && can("register"), name: (state.settings && state.settings.assistant.name) || "이니", admin: c } : c;
  } catch {
    state.ai = { available: false, vision: false, name: "이니" };
  }
  emit("ai", state.ai);
  return state.ai;
}

export const locPath = (id) => (state.locMap.get(id) || {}).path || "";
export const locName = (id) => (state.locMap.get(id) || {}).name || "";

export function rooms() {
  return state.locations.filter((l) => l.kind === "room");
}

// 장소를 나무 모양 순서로(들여쓰기용 depth 포함)
export function locationTree() {
  const out = [];
  const kids = new Map();
  for (const l of state.locations) {
    const p = l.parent_id && state.locMap.has(l.parent_id) ? l.parent_id : "";
    if (!kids.has(p)) kids.set(p, []);
    kids.get(p).push(l);
  }
  const walk = (pid, depth) => {
    for (const l of (kids.get(pid) || []).sort((a, b) => (a.sort - b.sort) || a.name.localeCompare(b.name, "ko"))) {
      out.push({ ...l, depth });
      walk(l.id, depth + 1);
    }
  };
  walk("", 0);
  return out;
}

// 최근에 옮기거나 둔 곳
export function recentLocations(limit = 6) {
  return local.get("recentLocations", []).filter((id) => state.locMap.has(id)).slice(0, limit);
}
export function rememberLocation(id) {
  if (!id) return;
  const list = local.get("recentLocations", []).filter((x) => x !== id);
  list.unshift(id);
  local.set("recentLocations", list.slice(0, 12));
}

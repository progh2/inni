// 여러 모듈이 함께 쓰는 앱 손잡이. main.js 가 채워 넣는다(서로 import 가 꼬이지 않게).
import { local } from "./lib/util.js";

const PREF_KEY = "prefs";
export const PREF_DEFAULTS = {
  scene: "auto", // auto | on | off
  quality: "auto",
  labels: true,
  reduceMotion: false,
  contrast: false,
  bigText: false,
  sound: true,
  volume: 0.5,
  voice: false,
  voiceName: "",
  proactive: true,
  autoInterval: 20,
  autoStations: [],
  view: "grid",
};

export const prefs = { ...PREF_DEFAULTS, ...(local.get(PREF_KEY, {}) || {}) };
export function savePrefs() {
  local.set(PREF_KEY, prefs);
}

export const app = {
  // 화면 이동
  go: (station, params = {}) => {},
  back: () => {},
  openItem: (id, unitId) => {},
  openLocation: (id) => {},
  // 작업
  scan: async () => null,
  scanAndOpen: async () => {},
  addItem: (draft) => {},
  actions: {},
  // 이니·지도
  inni: null,
  scene: { highlight: () => {}, pulse: () => {}, focus: () => {} },
  refresh: async () => {},
  get current() { return null; },
};

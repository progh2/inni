// 조달청 내용연수 제안(조달청고시 제2024-30호, 2025-01-01 시행). 후보만 보여 주고, 고르면 그때 채운다.
// 고시가 바뀌면 server/data/pps_useful_life.json 의 notice·items 만 바꾼다.
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { normalize } from "../../web/js/shared/hangul.js";

const FILE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../data/pps_useful_life.json");
let catalog = null;

function load() {
  if (catalog) return catalog;
  const data = JSON.parse(fs.readFileSync(FILE, "utf8"));
  const items = (data.items || [])
    .filter((x) => x && x.name && Number(x.years) > 0)
    .map((x) => ({ class_number: String(x.class_number || "").replace(/\D+/g, ""), name: String(x.name), years: Number(x.years), n: normalize(x.name) }));
  catalog = { notice: data.notice || {}, items };
  return catalog;
}

export function ppsNotice() {
  const n = load().notice;
  return n.label || n.title || "조달청고시";
}

export function suggestUsefulLife(name, classNumber = "", limit = 6) {
  const { items, notice } = load();
  const cls = String(classNumber || "").replace(/\D+/g, "");
  const q = normalize(name);
  const scored = [];
  for (const it of items) {
    let score = 0;
    let match = "";
    if (cls) {
      if (it.class_number === cls) { score = 100; match = "class"; }
      else if (cls.length >= 4 && it.class_number.startsWith(cls)) { score = 70; match = "class"; }
    }
    if (!score && q.length >= 2) {
      if (it.n === q) { score = 95; match = "name"; }
      else if (q.includes(it.n) && it.n.length >= 2) { score = 60 + Math.min(30, it.n.length * 3); match = "name"; }
      else if (it.n.includes(q)) { score = 50 + Math.min(20, q.length * 2); match = "name"; }
    }
    if (score) scored.push({ class_number: it.class_number, name: it.name, years: it.years, score, match });
  }
  scored.sort((a, b) => b.score - a.score || a.name.length - b.name.length);
  return { notice: notice.label || notice.title || "", items: scored.slice(0, Math.max(1, Math.min(20, limit))) };
}

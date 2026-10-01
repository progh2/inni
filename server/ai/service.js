// AI 서비스: 설정을 읽어 연결을 만들고, 대화·사진 읽기·제품 정보 정리를 한다.
import * as llm from "./llm.js";
import { loadAi, connectionWithKey, chatReady, visionReady, isExternal, addUsage } from "./store.js";
import { VISION_PROMPT, EXTRACT_PROMPT } from "./prompt.js";
import { KINDS } from "../lib/inventory.js";

export function parseJsonLoose(text) {
  const s = String(text || "");
  const start = s.indexOf("{");
  const end = s.lastIndexOf("}");
  if (start < 0 || end <= start) return null;
  try {
    return JSON.parse(s.slice(start, end + 1));
  } catch {
    return null;
  }
}

function cleanDraft(d) {
  if (!d || typeof d !== "object") return null;
  const s = (v, n = 120) => (typeof v === "string" ? v.trim().slice(0, n) : "");
  const out = {
    name: s(d.name, 80), kind: KINDS.includes(d.kind) ? d.kind : "", category: s(d.category, 30), manufacturer: s(d.manufacturer, 60),
    model: s(d.model, 60), spec: s(d.spec, 200), aliases: s(d.aliases, 120), unit: s(d.unit, 8), search_query: s(d.search_query, 80),
  };
  const price = Number(String(d.price ?? "").replace(/[^\d.]/g, ""));
  if (price > 0) out.price = Math.round(price);
  const conf = Number(d.confidence);
  if (Number.isFinite(conf)) out.confidence = Math.max(0, Math.min(1, conf));
  return out;
}

export function createAi(ctx) {
  const fetchImpl = ctx.cfg.fetch;
  function conn(role) {
    const cfg = loadAi(ctx.db);
    const r = role === "vision" && cfg.vision.connection_id ? cfg.vision : role === "vision" && cfg.vision.model ? { connection_id: cfg.chat.connection_id, model: cfg.vision.model } : cfg.chat;
    const c = connectionWithKey(ctx.db, cfg, r.connection_id);
    if (!c) return null;
    return { conn: llm.connectionFrom(c, r.model), raw: c, cfg };
  }
  return {
    ready: () => chatReady(ctx.db),
    visionReady: () => visionReady(ctx.db),
    conn,
    external: (role = "chat") => { const x = conn(role); return x ? isExternal(x.raw) : true; },

    async visionDraft({ data, mime = "image/jpeg" }) {
      if (!visionReady(ctx.db)) throw Object.assign(new Error("사진 읽기용 AI 가 연결되지 않았습니다. 시스템 → AI 코어에서 비전 모델을 고르세요."), { status: 409 });
      const x = conn("vision");
      const res = await llm.chat(x.conn, {
        messages: [{ role: "user", content: VISION_PROMPT, images: [{ mime, data }] }],
        maxTokens: 400, fast: true, json: true, numCtx: x.cfg.num_ctx, timeoutMs: 120000, fetchImpl,
      });
      addUsage(ctx.db, res.usage);
      const draft = cleanDraft(parseJsonLoose(res.content));
      if (!draft || !draft.name) throw Object.assign(new Error("사진에서 물건을 알아보지 못했어요. 더 가까이, 밝게 찍어 보세요."), { status: 422 });
      return { ...draft, model: res.model };
    },

    async extractProduct({ url, fields, text }) {
      if (!chatReady(ctx.db)) return null;
      const x = conn("chat");
      const body = `주소: ${url}\n읽은 값: ${JSON.stringify(fields)}\n본문(앞부분): ${String(text || "").slice(0, 3500)}`;
      const res = await llm.chat(x.conn, {
        messages: [{ role: "system", content: EXTRACT_PROMPT }, { role: "user", content: body }],
        maxTokens: 400, fast: true, json: true, numCtx: x.cfg.num_ctx, timeoutMs: 90000, fetchImpl,
      });
      addUsage(ctx.db, res.usage);
      return cleanDraft(parseJsonLoose(res.content));
    },
  };
}

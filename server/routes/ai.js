// AI(이니) API.
//   GET  /api/ai/config            설정(관리자) / 쓸 수 있는지(그 외)
//   POST /api/ai/config            설정 저장(관리자)
//   POST /api/ai/connections       연결 등록·수정(관리자)   DELETE /api/ai/connections/:id
//   POST /api/ai/models            모델 불러오기(관리자, 저장 전 값으로도)
//   POST /api/ai/test              연결 시험(관리자)
//   POST /api/ai/chat              대화. text/event-stream 으로 진행 상황과 답을 보낸다
//   POST /api/ai/vision            사진 → 등록 초안
import express from "express";
import * as llm from "../ai/llm.js";
import { publicAi, updateAi, upsertConnection, deleteConnection, connectionWithKey, loadAi, setLastTest, addUsage, capReached, isExternal, PROVIDERS } from "../ai/store.js";
import { runAssistant, probe } from "../ai/engine.js";
import { toolsFor, snapshotText } from "../ai/tools.js";
import { systemPrompt } from "../ai/prompt.js";
import { NameMask, namesFrom } from "../ai/privacy.js";
import { need, requireUser } from "../lib/http.js";
import { section } from "../lib/settings.js";
import { badRequest, str } from "../lib/util.js";
import { logEvent } from "../lib/events.js";

const HOUR = 3600000;

function limiter() {
  const hits = new Map();
  return (id, max) => {
    const now = Date.now();
    const recent = (hits.get(id) || []).filter((t) => now - t < HOUR);
    if (recent.length >= max) { hits.set(id, recent); return false; }
    recent.push(now);
    hits.set(id, recent);
    return true;
  };
}

function seoulNow(d = new Date()) {
  return d.toLocaleString("ko-KR", { timeZone: "Asia/Seoul", year: "numeric", month: "long", day: "numeric", weekday: "short", hour: "2-digit", minute: "2-digit" });
}

export function aiRouter(ctx) {
  const r = express.Router();
  const take = limiter();
  let running = 0;
  r.use("/ai", requireUser);

  r.get("/ai/config", (req, res) => {
    if (req.caps.has("system")) return res.json(publicAi(ctx.db));
    const ready = ctx.ai.ready() && req.caps.has("ai");
    res.json({ available: ready, vision: ctx.ai.visionReady() && req.caps.has("register"), name: section(ctx.db, "assistant").name || "이니" });
  });

  r.post("/ai/config", need("system"), (req, res) => {
    const cfg = updateAi(ctx.db, req.body || {});
    logEvent(ctx.db, req.actor, { action: "settings", summary: `AI 설정: ${cfg.enabled ? "켜짐" : "꺼짐"} · 대화 ${cfg.chat.model || "-"}${cfg.vision.model ? ` · 사진 ${cfg.vision.model}` : ""}` });
    ctx.changed({ kind: "settings" });
    res.json(publicAi(ctx.db));
  });

  r.post("/ai/connections", need("system"), (req, res) => {
    const c = upsertConnection(ctx.db, req.body || {});
    logEvent(ctx.db, req.actor, { action: "settings", summary: `AI 연결 ${req.body && req.body.id ? "수정" : "추가"}: ${c.name} (${PROVIDERS[c.provider].label})` });
    ctx.changed({ kind: "settings" });
    res.json(publicAi(ctx.db));
  });

  r.delete("/ai/connections/:id", need("system"), (req, res) => {
    deleteConnection(ctx.db, req.params.id);
    ctx.changed({ kind: "settings" });
    res.json(publicAi(ctx.db));
  });

  // 저장된 연결(id) 또는 화면에 적은 값(draft)으로 연결을 만든다
  function connFrom(body = {}, model = "") {
    const cfg = loadAi(ctx.db);
    let c = body.connection_id ? connectionWithKey(ctx.db, cfg, body.connection_id) : null;
    if (body.draft) {
      const d = body.draft;
      if (!PROVIDERS[d.provider]) throw badRequest("연결 종류를 고르세요");
      c = { ...(c || {}), provider: d.provider, base_url: str(d.base_url, 300).replace(/\/+$/, "") || (c && c.base_url) || "", api_key: str(d.api_key, 300) || (c && c.api_key) || "" };
    }
    if (!c) throw badRequest("연결을 고르세요");
    if (PROVIDERS[c.provider].needsUrl && !c.base_url) throw badRequest("서버 주소를 넣으세요");
    if (PROVIDERS[c.provider].needsKey && !c.api_key) throw badRequest("API 키를 넣으세요");
    return { c, conn: llm.connectionFrom(c, model) };
  }

  r.post("/ai/models", need("system"), async (req, res) => {
    const { c, conn } = connFrom(req.body);
    const models = await llm.listModels(conn, { fetchImpl: ctx.cfg.fetch });
    res.json({ models: llm.gradeModels(models, c.provider === "aiapi" ? "compatible" : c.provider), provider: c.provider });
  });

  r.post("/ai/test", need("system"), async (req, res) => {
    const model = str(req.body && req.body.model, 200);
    if (!model) throw badRequest("모델을 고르세요");
    const { conn } = connFrom(req.body, model);
    const cfg = loadAi(ctx.db);
    try {
      const out = await probe(conn, { fast: cfg.fast_mode !== false, numCtx: cfg.num_ctx, vision: Boolean(req.body.vision), fetchImpl: ctx.cfg.fetch });
      addUsage(ctx.db, out.usage);
      if (req.body.connection_id) setLastTest(ctx.db, req.body.connection_id, { ok: true, model, tools_ok: out.tools_ok, vision_ok: out.vision_ok, latency_ms: out.latency_ms });
      res.json(out);
    } catch (e) {
      if (req.body.connection_id) setLastTest(ctx.db, req.body.connection_id, { ok: false, model, error: e.message });
      throw e;
    }
  });

  r.post("/ai/vision", need("register"), async (req, res) => {
    const body = req.body || {};
    const m = /^data:(image\/[\w.+-]+);base64,(.+)$/.exec(String(body.image || ""));
    if (!m) throw badRequest("사진이 없습니다");
    if (m[2].length > 4 * 1024 * 1024) throw badRequest("사진이 너무 큽니다");
    if (!take(`v:${req.user.id}`, 60)) return res.status(429).json({ error: "사진 읽기를 너무 자주 했어요. 잠시 뒤에 해 보세요." });
    res.json(await ctx.ai.visionDraft({ mime: m[1], data: m[2] }));
  });

  r.post("/ai/chat", need("ai"), async (req, res) => {
    const aiCfg = loadAi(ctx.db);
    const name = section(ctx.db, "assistant").name || "이니";
    if (!ctx.ai.ready()) return res.status(409).json({ error: req.caps.has("system") ? `${name}의 두뇌(AI 모델)가 아직 연결되지 않았습니다. 시스템 → AI 코어에서 연결하세요.` : `${name}를 지금은 쓸 수 없어요` });
    if (capReached(ctx.db)) return res.status(429).json({ error: "이번 달 AI 사용 한도(토큰)를 다 썼습니다. 관리자가 AI 코어에서 늘릴 수 있습니다." });
    if (!take(req.user.id, req.user.role === "student" ? 20 : 150)) return res.status(429).json({ error: "질문이 너무 잦아요. 잠시 뒤에 다시 물어봐 주세요." });
    if (running >= 3) return res.status(429).json({ error: `${name}가 다른 질문에 답하는 중이에요. 조금 뒤에 다시 물어봐 주세요.` });
    const question = str(req.body && req.body.question, 1500);
    if (!question) throw badRequest("질문을 적어 주세요");

    running += 1;
    const ac = new AbortController();
    res.on("close", () => { if (!res.writableFinished) ac.abort(); });
    res.writeHead(200, { "Content-Type": "text/event-stream; charset=utf-8", "Cache-Control": "no-cache, no-transform", Connection: "keep-alive", "X-Accel-Buffering": "no" });
    res.flushHeaders();
    const send = (event, data) => { if (!res.writableEnded) res.write(`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`); };
    const beat = setInterval(() => { if (!res.writableEnded) res.write(": ping\n\n"); }, 15000);
    try {
      const x = ctx.ai.conn("chat");
      const snap = ctx.snapshot();
      const external = isExternal(x.raw);
      const mask = aiCfg.mask_names && external ? new NameMask(namesFrom({ users: [...snap.users.values()], loans: snap.loansActive })) : NameMask.none();
      const loanCfg = section(ctx.db, "loan");
      const role = req.user.role;
      const toolCtx = {
        ...ctx, user: req.user, dayEnd: loanCfg.day_end, defaultDue: loanCfg.default_due === "none" ? "" : loanCfg.default_due === "week" ? "다음주" : loanCfg.default_due === "tomorrow" ? "내일" : "오늘",
      };
      const tools = toolsFor({ caps: req.caps, role });
      const snapshot = role === "student" ? "학생에게는 현황표를 보여 주지 않는다." : snapshotText(ctx, req.user, req.caps);
      const c = (req.body && req.body.context) || {};
      let viewing = "";
      if (c.item_id && snap.items.get(c.item_id)) viewing = `물건 "${snap.items.get(c.item_id).name}"`;
      else if (c.location_id && snap.locations.get(c.location_id)) viewing = `장소 "${snap.locations.get(c.location_id).path}"`;
      const out = await runAssistant({
        conn: x.conn, question, history: req.body.history, tools, ctx: toolCtx, mask, emit: send, signal: ac.signal, fetchImpl: ctx.cfg.fetch,
        options: { maxTokens: aiCfg.max_output_tokens, numCtx: aiCfg.num_ctx, fast: aiCfg.fast_mode !== false },
        system: (withTools) => systemPrompt({ name, now: seoulNow(), station: String(req.body.station || "bridge"), snapshot, user: `${req.user.name}(${role})`, role, masked: mask.size > 0, tools: withTools, viewing }),
      });
      addUsage(ctx.db, out.usage);
      console.log(`[이니] ${req.user.email} · ${out.usage.steps}단계 · ${out.elapsed_ms}ms · 토큰 ${out.usage.prompt_tokens}+${out.usage.completion_tokens}${mask.size ? ` · 이름 ${mask.size}개 가림` : ""}`);
      send("done", { ok: true });
    } catch (e) {
      if (e.code !== "aborted") console.error(`[이니] ${req.user.email} 실패:`, e.message);
      send("error", { message: req.user.role === "student" ? "지금은 대답할 수 없어요. 잠시 뒤에 다시 물어봐 주세요." : e.message, code: e.code || null });
    } finally {
      running -= 1;
      clearInterval(beat);
      if (!res.writableEnded) res.end();
    }
  });

  return r;
}

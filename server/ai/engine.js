// 이니 대화 한 번: 질문 → (도구 호출 ↔ 결과)* → 답. aiapi-manager 엘피 엔진을 바탕으로 했다.
// 화면에는 진행 상황·제안 카드·화면 이동·답을 이벤트로 보낸다.
import * as llm from "./llm.js";

const MAX_STEPS = 5;
const MAX_CALLS_PER_STEP = 4;
const MAX_PROPOSALS = 3;
const MAX_HISTORY = 12;
const MAX_HISTORY_CHARS = 2000;
const MAX_QUESTION_CHARS = 1500;
const MAX_TOOL_RESULT_CHARS = 3500;

export function cleanHistory(history) {
  if (!Array.isArray(history)) return [];
  return history
    .filter((m) => m && (m.role === "user" || m.role === "assistant") && typeof m.content === "string" && m.content.trim())
    .slice(-MAX_HISTORY)
    .map((m) => ({ role: m.role, content: m.content.slice(0, MAX_HISTORY_CHARS) }));
}

const clip = (text, max) => { const s = String(text); return s.length > max ? `${s.slice(0, max)}…(잘림)` : s; };

/**
 * o: { conn, question, history, system(withTools)→string, tools, ctx, mask, options, emit, signal, chat? }
 */
export async function runAssistant(o) {
  const chat = o.chat || llm.chat;
  const emit = o.emit || (() => {});
  const mask = o.mask;
  const tools = o.tools || [];
  const opts = o.options || {};
  const started = Date.now();
  const usage = { prompt_tokens: 0, completion_tokens: 0, steps: 0 };
  const proposals = [];
  const cache = new Map();
  let useTools = tools.length > 0;
  const question = String(o.question || "").trim().slice(0, MAX_QUESTION_CHARS);
  if (!question) throw Object.assign(new Error("질문이 비어 있습니다"), { status: 400 });

  const messages = [
    { role: "system", content: mask.mask(o.system(useTools)) },
    ...cleanHistory(o.history).map((m) => ({ role: m.role, content: mask.mask(m.content) })),
    { role: "user", content: mask.mask(question) },
  ];

  const ask = async (withTools) => {
    usage.steps += 1;
    const res = await chat(o.conn, {
      messages, tools: withTools ? tools : [], maxTokens: opts.maxTokens || 800, numCtx: opts.numCtx || 8192, fast: opts.fast !== false, signal: o.signal, fetchImpl: o.fetchImpl,
    });
    usage.prompt_tokens += res.usage.prompt_tokens;
    usage.completion_tokens += res.usage.completion_tokens;
    return res;
  };

  let final = "";
  let answered = false;
  for (let step = 0; step < MAX_STEPS && !answered; step++) {
    emit("status", { phase: "thinking", label: step === 0 ? "질문을 읽는 중" : "알아낸 것을 정리하는 중" });
    let res;
    try {
      res = await ask(useTools);
    } catch (e) {
      if (e.code !== "tools_unsupported" || !useTools) throw e;
      useTools = false;
      messages[0] = { role: "system", content: mask.mask(o.system(false)) };
      emit("notice", { text: "이 모델은 도구 호출을 지원하지 않아 현황표만으로 답합니다. 찾기·제안 카드를 쓰려면 도구를 지원하는 모델을 고르세요." });
      res = await ask(false);
    }
    if (!useTools || !res.toolCalls.length) {
      final = res.content;
      answered = true;
      break;
    }
    const calls = res.toolCalls.slice(0, MAX_CALLS_PER_STEP);
    messages.push({ role: "assistant", content: res.content || "", tool_calls: calls, reasoning: res.reasoning });
    for (const call of calls) {
      const tool = tools.find((t) => t.name === call.name);
      const sig = `${call.name}:${JSON.stringify(call.arguments || {})}`;
      let out;
      if (!tool) out = { result: { error: `없는 도구입니다: ${call.name}` }, summary: "알 수 없는 도구" };
      else if (call.arguments && call.arguments.__invalid) out = { result: { error: "도구 인자가 올바른 JSON 이 아닙니다. 다시 시도하세요." }, summary: "인자 오류" };
      else if (cache.has(sig) && tool.kind === "read") out = { result: { note: "같은 조회를 이미 했습니다. 앞의 결과로 답하세요.", ...cache.get(sig) }, summary: "이미 확인함" };
      else if (tool.kind === "propose" && proposals.length >= MAX_PROPOSALS) out = { result: { error: `제안 카드는 한 번에 ${MAX_PROPOSALS}개까지입니다.` }, summary: "제안 한도" };
      else {
        emit("status", { phase: "tool", tool: tool.name, label: tool.label });
        try {
          out = await tool.run(mask.unmaskDeep(call.arguments || {}), o.ctx);
        } catch (e) {
          if (!e.tool) console.error(`[이니] 도구 ${tool.name} 실패:`, e.message);
          out = { result: { error: e.message }, summary: e.tool ? "확인 필요" : "조회 실패", failed: true };
        }
        if (tool.kind === "read" && !out.failed) cache.set(sig, out.result);
      }
      if (out.proposal) {
        proposals.push(out.proposal);
        emit("proposal", out.proposal);
      }
      if (out.navigate) emit("navigate", out.navigate);
      if (out.action) emit("action", out.action);
      if (out.found && out.found.length) emit("found", { item_ids: out.found });
      if (out.location_id) emit("found", { location_ids: [out.location_id] });
      emit("tool", { name: call.name, label: tool ? tool.label : call.name, summary: out.summary || "", ok: !out.failed && !(out.result && out.result.error) });
      messages.push({ role: "tool", tool_call_id: call.id, name: call.name, content: clip(mask.mask(JSON.stringify(out.result ?? {})), MAX_TOOL_RESULT_CHARS) });
    }
  }
  if (!answered) {
    emit("status", { phase: "thinking", label: "답을 쓰는 중" });
    messages.push({ role: "user", content: "지금까지 확인한 내용으로 짧게 답해 주세요. 도구는 더 부르지 마세요." });
    const res = await ask(false);
    final = res.content;
  }
  let text = mask.unmask(String(final || "").trim());
  if (!text) text = proposals.length ? "제안 카드를 띄웠어요. 내용을 확인하고 버튼을 눌러 주세요." : "음… 답을 만들지 못했어요. 조금 다르게 말씀해 주시겠어요?";
  const elapsed = Date.now() - started;
  emit("message", { text });
  emit("usage", { ...usage, elapsed_ms: elapsed, model: o.conn && o.conn.model, tools: useTools });
  return { text, usage, proposals, elapsed_ms: elapsed, tools: useTools };
}

// 연결 시험: 도구 호출을 시켜 보고, 안 되면 대화만 확인한다. vision 이면 작은 그림도 읽혀 본다.
export async function probe(conn, { chat = llm.chat, fast = true, numCtx = 8192, vision = false, fetchImpl } = {}) {
  const started = Date.now();
  const clock = { name: "get_time", description: "지금 서울 시각을 알려 준다.", parameters: { type: "object", properties: { city: { type: "string" } } } };
  let toolsOk = false;
  let reply = "";
  let note = "";
  let usage = { prompt_tokens: 0, completion_tokens: 0 };
  try {
    const res = await chat(conn, {
      messages: [{ role: "system", content: "너는 도구를 쓸 줄 아는 도우미다. 시각을 물으면 반드시 get_time 도구를 호출한다." }, { role: "user", content: "서울은 지금 몇 시야? get_time 도구로 확인해 줘." }],
      tools: [clock], maxTokens: 200, fast, numCtx, timeoutMs: 120000, fetchImpl,
    });
    usage = res.usage;
    toolsOk = res.toolCalls.some((c) => c.name === "get_time");
    reply = res.content;
    if (!toolsOk) note = "모델이 도구를 부르지 않았습니다. 찾기·제안이 불안정할 수 있습니다.";
  } catch (e) {
    if (e.code !== "tools_unsupported") throw e;
    note = "이 모델은 도구 호출을 지원하지 않습니다. 대화만 됩니다.";
  }
  if (!reply) {
    const res = await chat(conn, { messages: [{ role: "user", content: "한 문장으로 반갑게 인사해 줘. 한국어로. 너는 학교 물품 관리 도우미 이니야." }], maxTokens: 120, fast, numCtx, timeoutMs: 120000, fetchImpl });
    reply = res.content;
    usage = { prompt_tokens: usage.prompt_tokens + res.usage.prompt_tokens, completion_tokens: usage.completion_tokens + res.usage.completion_tokens };
  }
  let visionOk = null;
  if (vision) {
    // 빨간 사각형 PNG(8×8)
    const red = "iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAEklEQVR4nGP4z8CAFWEXHbQSACj/P8Fu7N9hAAAAAElFTkSuQmCC";
    try {
      const res = await chat(conn, { messages: [{ role: "user", content: "이 그림은 무슨 색이야? 한 낱말로.", images: [{ mime: "image/png", data: red }] }], maxTokens: 50, fast, numCtx, timeoutMs: 120000, fetchImpl });
      visionOk = /빨|red|적색|붉/i.test(res.content);
      if (!visionOk) note = `${note} 사진 읽기 결과가 이상합니다("${String(res.content).slice(0, 20)}").`.trim();
    } catch (e) {
      visionOk = false;
      note = `${note} 사진 읽기 실패: ${e.message}`.trim();
    }
  }
  return { ok: true, tools_ok: toolsOk, vision_ok: visionOk, reply: String(reply || "").slice(0, 300), note, latency_ms: Date.now() - started, usage, model: conn.model };
}

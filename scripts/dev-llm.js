// 개발·시연용 흉내 LLM(OpenAI 호환). 낱말 규칙으로 이니의 도구를 불러 제안 카드까지 시연한다.
// 실행: node scripts/dev-llm.js  → 시스템 → AI 코어 → 연결 추가 → "OpenAI 호환 서버" 주소 http://127.0.0.1:4466
// 운영에는 쓰지 않는다.
import http from "node:http";

const PORT = Number(process.env.PORT || 4466);

function lastUser(messages) {
  for (let i = messages.length - 1; i >= 0; i--) if (messages[i].role === "user") return messages[i];
  return null;
}
const textOf = (m) => (Array.isArray(m.content) ? m.content.map((p) => p.text || "").join(" ") : String(m.content || ""));

function plan(messages, tools) {
  const names = new Set((tools || []).map((t) => t.function.name));
  const last = messages[messages.length - 1];
  const u = lastUser(messages);
  const q = u ? textOf(u) : "";
  // 연결 시험 질문
  if (names.has("get_time")) return { tool: { name: "get_time", args: { city: "서울" } } };
  if (/무슨 색/.test(q)) return { content: "빨강" };
  // 사진이면 등록 초안(JSON)
  if (u && Array.isArray(u.content) && u.content.some((p) => p.type === "image_url")) {
    return { content: JSON.stringify({ name: "디지털 멀티미터", kind: "equipment", category: "계측기", manufacturer: "Fluke", model: "87V", spec: "True-RMS", aliases: "멀티테스터, 테스터기", search_query: "Fluke 87V 멀티미터", confidence: 0.82 }) };
  }
  if (last.role === "tool") {
    const r = JSON.parse(last.content || "{}");
    if (r.error) return { content: `확인이 필요해요: ${r.error}` };
    if (r.proposal_shown) return { content: `**${r.title}** 카드를 띄웠어요. 카드의 버튼을 누르시면 진행돼요.` };
    if (r.items) {
      if (!r.items.length) return { content: "맞는 물건을 찾지 못했어요. 다른 이름으로 말씀해 주세요." };
      const x = r.items[0];
      return { content: `**${x.name}**: ${(x.where || []).join(", ") || "위치 정보 없음"} (${x.status})${r.count > 1 ? ` 외 ${r.count - 1}가지가 더 있어요.` : ""}` };
    }
    if (r.location) return { content: `${r.location}에는 ${r.count}가지가 있어요:\n${(r.items || []).slice(0, 6).map((s) => `- ${s}`).join("\n")}` };
    if (r.loans) return { content: r.loans.length ? `대여 ${r.count}건:\n${r.loans.slice(0, 5).map((l) => `- ${l.what} · ${l.borrower} (${l.due}${l.overdue ? ", 연체" : ""})`).join("\n")}` : "빌려 간 것이 없어요." };
    if (r.alerts) return { content: r.alerts.length ? `챙길 일 ${r.alerts.length}건:\n${r.alerts.slice(0, 5).map((a) => `- ${a.title}`).join("\n")}` : "지금은 챙길 일이 없어요!" };
    if (r.opened) return { content: "화면을 열었어요." };
    return { content: "확인했어요." };
  }
  const call = (name, args) => (names.has(name) ? { tool: { name, args } } : null);
  let m;
  if ((m = /(.+?)\s*(?:을|를)?\s+(\S+?)(?:으로|로)\s*옮겨/.exec(q))) {
    const cnt = /(\d+)\s*대/.exec(m[1]);
    return call("propose_move", { items: [{ item: m[1].replace(/\s*\d+\s*대/, "").trim(), count: cnt ? Number(cnt[1]) : undefined }], to: m[2] }) || { content: "옮기기 권한이 없어요." };
  }
  if ((m = /(.+?)\s*(?:을|를)?\s+(\S+?)(?:에게|한테)\s*(오늘|내일|\d+일)?.*빌려/.exec(q))) return call("propose_loan", { item: m[1].trim(), borrower: m[2], due: m[3] || "오늘" });
  if ((m = /(.+?)\s*(\d+)\s*\S*\s*(?:썼|사용)/.exec(q))) return call("propose_use", { item: m[1].trim(), quantity: Number(m[2]) });
  if ((m = /(.+?)\s*(\d+)\s*\S*\s*(?:들어왔|입고)/.exec(q))) return call("propose_restock", { item: m[1].trim(), quantity: Number(m[2]) });
  if ((m = /(.+?)\s*(?:반납)/.exec(q))) return call("propose_return", { item: m[1].replace(/(을|를)$/, "").trim() });
  if ((m = /(.+?)(?:에|엔)\s*뭐/.exec(q))) return call("location_contents", { location: m[1].trim() });
  if (/연체|빌려\s*간/.test(q)) return call("list_loans", { filter: /연체/.test(q) ? "overdue" : "active" });
  if (/챙길|경보|브리핑|상황/.test(q)) return call("list_alerts", {});
  if ((m = /(.+?)\s*(?:어디|어딨|찾아|있어)/.exec(q))) return call("search_items", { query: m[1].replace(/^(이니야|이니)\s*/, "").trim() });
  if ((m = /(.+?)\s*등록/.exec(q))) return call("draft_new_item", { name: m[1].replace(/\d+\s*개/, "").trim() });
  return { content: "안녕하세요, 보급관 이니예요! (흉내 모델) \"멀티미터 어디 있어?\"처럼 물어봐 주세요." };
}

let n = 0;
http.createServer((req, res) => {
  const send = (code, obj) => { res.writeHead(code, { "Content-Type": "application/json" }); res.end(JSON.stringify(obj)); };
  if (req.method === "GET" && /\/v1\/models$/.test(req.url)) return send(200, { data: [{ id: "inni-mock" }, { id: "inni-mock-vision" }] });
  if (req.method !== "POST" || !/\/v1\/chat\/completions$/.test(req.url)) return send(404, { error: { message: "not found" } });
  let body = "";
  req.on("data", (c) => { body += c; });
  req.on("end", () => {
    const j = JSON.parse(body || "{}");
    const p = plan(j.messages || [], j.tools || []);
    n += 1;
    const message = p.tool
      ? { role: "assistant", content: null, tool_calls: [{ id: `call_${n}`, type: "function", function: { name: p.tool.name, arguments: JSON.stringify(p.tool.args) } }] }
      : { role: "assistant", content: p.content };
    setTimeout(() => send(200, { id: `mock-${n}`, model: j.model || "inni-mock", choices: [{ index: 0, message, finish_reason: p.tool ? "tool_calls" : "stop" }], usage: { prompt_tokens: 420, completion_tokens: 36 } }), 350);
  });
}).listen(PORT, "0.0.0.0", () => console.log(`흉내 LLM: http://127.0.0.1:${PORT}/v1 (OpenAI 호환)`));

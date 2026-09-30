// 이니 시험: 가짜 모델로 도구 호출 → 제안 카드 → 실행, 그리고 Firebase 토큰 검증
import { test } from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import { withSchool } from "./helpers.js";
import { verifyIdToken, TokenError } from "../server/lib/firebase.js";
import { runAssistant, probe } from "../server/ai/engine.js";
import { NameMask } from "../server/ai/privacy.js";
import { toolsFor } from "../server/ai/tools.js";
import { capsFor } from "../server/lib/permissions.js";

test("Firebase ID 토큰 검증(서명·aud·iss·만료)", async () => {
  const { publicKey, privateKey } = crypto.generateKeyPairSync("rsa", { modulusLength: 2048 });
  const pem = publicKey.export({ type: "spki", format: "pem" });
  const certs = { get: async () => ({ k1: pem }) };
  const now = Date.now();
  const sign = (payload, { kid = "k1", key = privateKey, alg = "RS256" } = {}) => {
    const h = Buffer.from(JSON.stringify({ alg, kid, typ: "JWT" })).toString("base64url");
    const p = Buffer.from(JSON.stringify(payload)).toString("base64url");
    const s = crypto.sign("RSA-SHA256", Buffer.from(`${h}.${p}`), key).toString("base64url");
    return `${h}.${p}.${s}`;
  };
  const base = { aud: "school-proj", iss: "https://securetoken.google.com/school-proj", sub: "uid1", iat: Math.floor(now / 1000) - 10, exp: Math.floor(now / 1000) + 3600, email: "t@school.kr", email_verified: true };
  const ok = await verifyIdToken(sign(base), { projectId: "school-proj", certs, now });
  assert.equal(ok.email, "t@school.kr");
  await assert.rejects(verifyIdToken(sign({ ...base, aud: "other" }), { projectId: "school-proj", certs, now }), TokenError);
  await assert.rejects(verifyIdToken(sign({ ...base, iss: "https://evil" }), { projectId: "school-proj", certs, now }), TokenError);
  await assert.rejects(verifyIdToken(sign({ ...base, exp: Math.floor(now / 1000) - 3600 }), { projectId: "school-proj", certs, now }), /만료/);
  const other = crypto.generateKeyPairSync("rsa", { modulusLength: 2048 }).privateKey;
  await assert.rejects(verifyIdToken(sign(base, { key: other }), { projectId: "school-proj", certs, now }), /서명/);
  await assert.rejects(verifyIdToken(sign(base, { kid: "nope" }), { projectId: "school-proj", certs, now }), TokenError);
  await assert.rejects(verifyIdToken("a.b", { projectId: "school-proj", certs, now }), TokenError);
});

// 가짜 모델: 질문을 보고 도구를 부른 뒤, 결과를 받으면 답한다
function fakeChat(script) {
  let step = 0;
  return async (_conn, { messages, tools }) => {
    const s = script[step++] || { content: "끝" };
    const usage = { prompt_tokens: 10, completion_tokens: 5 };
    if (s.call && tools.length) return { content: "", toolCalls: [{ id: `c${step}`, name: s.call, arguments: s.args }], usage, model: "fake" };
    return { content: typeof s.content === "function" ? s.content(messages) : s.content, toolCalls: [], usage, model: "fake" };
  };
}

test("이니: 찾기 → 옮기기 제안 카드 → 카드대로 실행", async (t) => {
  const { env, admin, loc } = await withSchool(t);
  const eq = await admin.ok("POST", "/api/items", { name: "오실로스코프", kind: "equipment", location_id: loc.elec.id, unit_count: 2, number_prefix: "전장-" });
  const ctx = { ...env.ctx, user: { id: "x", name: "김담당" }, dayEnd: "17:00", defaultDue: "오늘" };
  const caps = capsFor({ role: "owner", status: "active" });
  const events = [];
  const out = await runAssistant({
    conn: { model: "fake" },
    question: "오실로스코프 2대 공구실로 옮겨 줘",
    history: [],
    system: () => "sys",
    tools: toolsFor({ caps, role: "owner" }),
    ctx,
    mask: NameMask.none(),
    emit: (e, d) => events.push([e, d]),
    chat: fakeChat([
      { call: "search_items", args: { query: "오실로스코프" } },
      { call: "propose_move", args: { items: [{ item: "오실로스코프", count: 2 }], to: "공구실" } },
      { content: (msgs) => (msgs.some((m) => m.role === "tool" && m.content.includes("proposal_shown")) ? "카드를 띄웠어요." : "?") },
    ]),
  });
  assert.equal(out.text, "카드를 띄웠어요.");
  const proposal = events.find(([e]) => e === "proposal")[1];
  assert.equal(proposal.request.path, "/api/actions/move");
  assert.equal(proposal.request.body.targets.length, 2);
  assert.equal(proposal.request.body.to_location_id, loc.tool.id);
  assert.ok(events.some(([e, d]) => e === "tool" && d.name === "search_items" && d.ok));
  // 카드의 요청을 그대로 실행하면 옮겨진다(권한·검증은 기존 API)
  const r = await admin.ok("POST", proposal.request.path, proposal.request.body, { headers: { "x-inni-via": "ai" } });
  assert.equal(r.moved, 2);
  const d = await admin.ok("GET", `/api/items/${eq.id}`);
  assert.ok(d.units.every((u) => u.location_id === loc.tool.id));
  const ev = await admin.ok("GET", "/api/events?limit=3");
  assert.equal(ev.events[0].via, "ai");
});

test("이니: 헷갈리면 되묻고, 권한 없는 도구는 없다", async (t) => {
  const { env, admin, loc } = await withSchool(t);
  await admin.ok("POST", "/api/items", { name: "충전드릴", kind: "equipment", location_id: loc.elec.id, unit_count: 1 });
  await admin.ok("POST", "/api/items", { name: "충전드라이버", kind: "equipment", location_id: loc.elec.id, unit_count: 1 });
  const ctx = { ...env.ctx, user: { id: "x", name: "김담당" }, dayEnd: "17:00" };
  const tools = toolsFor({ caps: capsFor({ role: "owner", status: "active" }), role: "owner" });
  const loanTool = tools.find((x) => x.name === "propose_loan");
  await assert.rejects(Promise.resolve().then(() => loanTool.run({ item: "충전" }, ctx)), /여러 개/);
  const student = toolsFor({ caps: capsFor({ role: "student", status: "active" }), role: "student" }).map((x) => x.name);
  assert.ok(!student.includes("propose_move") && !student.includes("propose_loan") && !student.includes("list_alerts"));
  assert.ok(student.includes("search_items"));
});

test("연결 시험: 도구 호출·사진 읽기 판정", async () => {
  const chat = async (_c, { tools, messages }) => {
    if (tools && tools.length) return { content: "", toolCalls: [{ id: "1", name: "get_time", arguments: {} }], usage: { prompt_tokens: 1, completion_tokens: 1 } };
    if (messages[0].images) return { content: "빨강", toolCalls: [], usage: { prompt_tokens: 1, completion_tokens: 1 } };
    return { content: "안녕하세요!", toolCalls: [], usage: { prompt_tokens: 1, completion_tokens: 1 } };
  };
  const r = await probe({ model: "m" }, { chat, vision: true });
  assert.ok(r.tools_ok);
  assert.equal(r.vision_ok, true);
  assert.equal(r.reply, "안녕하세요!");
});

test("로그인: 구글·메일 링크 토큰 → 세션, 방법 끄기·도메인·이름", async (t) => {
  const { startApp } = await import("./helpers.js");
  const { publicKey, privateKey } = crypto.generateKeyPairSync("rsa", { modulusLength: 2048 });
  const certs = { get: async () => ({ k1: publicKey.export({ type: "spki", format: "pem" }) }) };
  const now = Math.floor(Date.now() / 1000);
  const token = (claims) => {
    const h = Buffer.from(JSON.stringify({ alg: "RS256", kid: "k1", typ: "JWT" })).toString("base64url");
    const p = Buffer.from(JSON.stringify({ aud: "sch", iss: "https://securetoken.google.com/sch", sub: `u-${claims.email}`, iat: now - 5, exp: now + 3600, email_verified: true, ...claims })).toString("base64url");
    return `${h}.${p}.${crypto.sign("RSA-SHA256", Buffer.from(`${h}.${p}`), privateKey).toString("base64url")}`;
  };
  const boot = async (loginMethods) => {
    const env = await startApp({ authMode: "firebase", firebaseProjectId: "sch", certs, adminEmails: ["boss@school.kr"], allowedDomains: ["school.kr"], loginMethods });
    t.after(() => env.close());
    return env;
  };
  const env = await boot(undefined);
  const cfg = await env.client().ok("GET", "/api/auth/config");
  assert.deepEqual(cfg.methods, ["google", "email"]);
  // 구글 계정
  const g = env.client("g");
  const r1 = await g.post("/api/auth/session", { idToken: token({ email: "boss@school.kr", name: "김담당", firebase: { sign_in_provider: "google.com" } }) });
  assert.equal(r1.status, 200);
  assert.equal(r1.data.me.name, "김담당");
  // 메일 링크(provider=password): 이름은 처음 적은 것
  const m = env.client("m");
  const r2 = await m.post("/api/auth/session", { idToken: token({ email: "t1@school.kr", firebase: { sign_in_provider: "password" } }), name: "이수업" });
  assert.equal(r2.status, 200);
  assert.equal(r2.data.me.name, "이수업");
  assert.equal(r2.data.me.status, "pending");
  // 확인 안 된 메일·다른 도메인·다른 방법은 막는다
  const x = env.client("x");
  assert.equal((await x.post("/api/auth/session", { idToken: token({ email: "t2@school.kr", email_verified: false, firebase: { sign_in_provider: "password" } }) })).status, 403);
  assert.equal((await x.post("/api/auth/session", { idToken: token({ email: "a@gmail.com", firebase: { sign_in_provider: "password" } }) })).status, 403);
  assert.equal((await x.post("/api/auth/session", { idToken: token({ email: "t3@school.kr", firebase: { sign_in_provider: "anonymous" } }) })).status, 403);
  // 내 이름 바꾸기(승인 대기 중에도)
  const r3 = await m.ok("PATCH", "/api/me", { name: "이수업 선생님" });
  assert.equal(r3.me.name, "이수업 선생님");
  assert.equal((await m.patch("/api/me", { name: "" })).status, 400);
  // 메일 링크를 끈 학교
  const env2 = await boot(["google"]);
  assert.equal((await env2.client().post("/api/auth/session", { idToken: token({ email: "t1@school.kr", firebase: { sign_in_provider: "password" } }) })).status, 403);
});

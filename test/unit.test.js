// 규칙 단위 시험: 한글 검색·명령 해석·CSV·내부망 차단·AI 도우미
import { test } from "node:test";
import assert from "node:assert/strict";
import { normalize, choseong, isChoseong, editDistance, josa, tokens } from "../web/js/shared/hangul.js";
import { parseIntent, looksLikeCommand } from "../web/js/shared/intents.js";
import { parseCsv, toCsv, decodeCsv } from "../server/io/csv.js";
import { isPrivateIp, safeFetch } from "../server/lib/safe-fetch.js";
import { NameMask, namesFrom } from "../server/ai/privacy.js";
import { inlineToolCalls, isReasoningModel, openaiBase, toResponsesInput } from "../server/ai/llm.js";
import { parseJsonLoose } from "../server/ai/service.js";
import { dueFrom } from "../server/ai/tools.js";
import { capsFor, canAssignRole } from "../server/lib/permissions.js";
import { emailAllowed } from "../server/lib/firebase.js";
import { suggestUsefulLife } from "../server/lib/pps.js";

test("한글: 정규화·초성·오타", () => {
  assert.equal(normalize("디지털 멀티-미터"), "디지털멀티미터");
  assert.equal(choseong("오실로스코프"), "ㅇㅅㄹㅅㅋㅍ");
  assert.equal(choseong("3D 프린터"), "3dㅍㄹㅌ");
  assert.ok(isChoseong("ㅁㅌㅁㅌ"));
  assert.ok(!isChoseong("멀티"));
  assert.equal(editDistance("멀티미터", "멀티미타"), 1);
  assert.ok(editDistance("abcdef", "zzzzzz", 2) > 2);
  assert.equal(josa("드릴", "을", "를"), "드릴을");
  assert.equal(josa("노트북", "을", "를"), "노트북을");
  assert.equal(josa("프린터", "을", "를"), "프린터를");
  assert.deepEqual(tokens("  용접실  드릴 "), ["용접실", "드릴"]);
});

test("명령 해석: 찾기·옮기기·대여·사용·입고·화면", () => {
  assert.deepEqual(parseIntent("멀티미터 어디 있어?"), { type: "find", query: "멀티미터" });
  assert.deepEqual(parseIntent("공구실에 뭐 있어"), { type: "contents", place: "공구실" });
  assert.deepEqual(parseIntent("드릴 2대 전자실습실로 옮겨 줘"), { type: "move", item: "드릴", count: 2, to: "전자실습실" });
  assert.deepEqual(parseIntent("3D 프린터 전자실습실로 옮겨"), { type: "move", item: "3D 프린터", count: null, to: "전자실습실" });
  const loan = parseIntent("충전드릴을 박학생한테 내일까지 빌려줘");
  assert.equal(loan.type, "loan");
  assert.equal(loan.item, "충전드릴");
  assert.equal(loan.borrower, "박학생");
  assert.equal(loan.due, "내일");
  assert.deepEqual(parseIntent("실납 세 롤 썼어"), { type: "use", item: "실납", qty: 3 });
  assert.deepEqual(parseIntent("용접봉 20kg 들어왔어"), { type: "restock", item: "용접봉", qty: 20 });
  assert.equal(parseIntent("재고 부족 보여줘").type, "nav");
  assert.deepEqual(parseIntent("인두기 5개 등록해 줘"), { type: "add", name: "인두기", qty: 5 });
  assert.equal(parseIntent("박학생이 빌린 거 반납").borrower, "박학생");
  assert.equal(parseIntent("전장-2024-017").type, "search");
  assert.ok(looksLikeCommand("오실로스코프 어딨어"));
  assert.ok(!looksLikeCommand("멀티"));
});

test("CSV: 따옴표·줄바꿈·CP949", () => {
  const rows = parseCsv('﻿품명,메모\n"가, 나","줄\n바꿈"\n"따옴표 ""안""",x\n');
  assert.deepEqual(rows, [["품명", "메모"], ["가, 나", "줄\n바꿈"], ['따옴표 "안"', "x"]]);
  assert.ok(toCsv([["a", "b,c"]]).startsWith("﻿a,\"b,c\""));
  const cp949 = Buffer.from([0xc7, 0xb0, 0xb8, 0xed, 0x2c, 0x41]); // "품명,A"
  assert.equal(decodeCsv(cp949), "품명,A");
});

test("내부망 주소는 가져오지 않는다(SSRF)", async () => {
  for (const ip of ["127.0.0.1", "10.1.2.3", "192.168.0.10", "172.20.1.1", "169.254.169.254", "::1", "fd00::1", "::ffff:192.168.1.1", "0.0.0.0"]) {
    assert.ok(isPrivateIp(ip), ip);
  }
  for (const ip of ["8.8.8.8", "142.250.1.1", "2001:4860:4860::8888"]) assert.ok(!isPrivateIp(ip), ip);
  await assert.rejects(safeFetch("http://127.0.0.1:1/x"), /내부망/);
  await assert.rejects(safeFetch("file:///etc/passwd"), /http/);
});

test("이름 가리기와 되돌리기", () => {
  const names = namesFrom({ users: [{ name: "김담당" }], loans: [{ borrower_name: "박학생" }, { borrower_name: "로봇동아리" }] });
  assert.ok(names.includes("김담당") && names.includes("박학생"));
  const m = new NameMask(names);
  const masked = m.mask("박학생이 드릴을 빌렸고 김담당이 확인");
  assert.ok(!masked.includes("박학생") && !masked.includes("김담당"));
  assert.equal(m.unmask(masked), "박학생이 드릴을 빌렸고 김담당이 확인");
  assert.deepEqual(m.unmaskDeep({ a: [m.mask("박학생")] }), { a: ["박학생"] });
});

test("AI 연결 도우미", () => {
  const calls = inlineToolCalls('<tool_call>{"name":"search_items","arguments":{"query":"드릴"}}</tool_call>', ["search_items"]);
  assert.equal(calls[0].name, "search_items");
  assert.deepEqual(calls[0].arguments, { query: "드릴" });
  assert.equal(inlineToolCalls("search_items(query='드릴')", ["search_items"])[0].arguments.query, "드릴");
  assert.ok(isReasoningModel("gpt-5-mini"));
  assert.ok(!isReasoningModel("gpt-4.1-mini"));
  assert.equal(openaiBase("http://nas:4000"), "http://nas:4000/v1");
  assert.equal(openaiBase("http://nas:1234/v1"), "http://nas:1234/v1");
  const r = toResponsesInput([{ role: "system", content: "sys" }, { role: "user", content: "사진", images: [{ mime: "image/png", data: "AAA" }] }]);
  assert.equal(r.instructions, "sys");
  assert.equal(r.input[0].content[1].type, "input_image");
  assert.deepEqual(parseJsonLoose('설명 {"name":"멀티미터","kind":"equipment"} 끝'), { name: "멀티미터", kind: "equipment" });
  assert.equal(parseJsonLoose("없음"), null);
});

test("반납 예정 말 → 시각(서울, 수업 끝)", () => {
  const now = new Date("2026-10-05T01:00:00Z"); // 서울 10:00
  assert.equal(dueFrom("오늘", "17:00", now), "2026-10-05T08:00:00.000Z");
  assert.equal(dueFrom("내일", "17:00", now), "2026-10-06T08:00:00.000Z");
  assert.equal(dueFrom("3일", "17:00", now), "2026-10-08T08:00:00.000Z");
  assert.equal(dueFrom("10/9", "16:30", now), "2026-10-09T07:30:00.000Z");
  assert.equal(dueFrom("기한 없음"), null);
});

test("권한 표", () => {
  const u = (role, status = "active") => ({ role, status });
  assert.ok(capsFor(u("owner")).has("system"));
  assert.ok(!capsFor(u("manager")).has("system"));
  assert.ok(capsFor(u("manager")).has("users"));
  assert.ok(capsFor(u("teacher")).has("loan") && capsFor(u("teacher")).has("register"));
  assert.ok(!capsFor(u("teacher"), { teacher_can_register: false }).has("register"));
  assert.ok(!capsFor(u("student")).has("loan"));
  assert.equal(capsFor(u("teacher", "pending")).size, 0);
  assert.ok(canAssignRole({ role: "owner" }, null, "owner"));
  assert.ok(!canAssignRole({ role: "manager" }, null, "owner"));
  assert.ok(!canAssignRole({ role: "manager" }, { role: "owner" }, "teacher"));
});

test("학교 도메인", () => {
  assert.ok(emailAllowed("a@hanbit.hs.kr", ["hanbit.hs.kr"]));
  assert.ok(!emailAllowed("a@mail.hanbit.hs.kr", ["hanbit.hs.kr"]));
  assert.ok(!emailAllowed("a@gmail.com", ["hanbit.hs.kr"]));
  assert.ok(emailAllowed("a@gmail.com", []));
  assert.ok(!emailAllowed("nope", []));
});

test("조달청 내용연수 제안", () => {
  const r = suggestUsefulLife("디지털 멀티미터");
  assert.ok(r.items.length > 0);
  assert.ok(r.items.some((x) => x.name.includes("멀티미터")));
  assert.ok(r.notice.includes("조달청"));
  assert.equal(suggestUsefulLife("ㄱ").items.length, 0);
});

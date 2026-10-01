// API 통합 시험: 로그인·권한·등록·찾기·이동/대여/반납/사용/입고·되돌리기·실사·백업/복원·CSV·예전 데이터
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import Database from "better-sqlite3";
import { startApp, withSchool } from "./helpers.js";

test("로그인·승인·CSRF", async (t) => {
  const env = await startApp({ adminEmails: ["boss@school.kr"], allowedDomains: ["school.kr"] });
  t.after(() => env.close());
  const a = env.client("a");
  assert.equal((await a.get("/api/me")).status, 401);
  // 다른 도메인은 거부
  const bad = await a.call("POST", "/api/auth/dev-login", { email: "x@gmail.com" });
  assert.equal(bad.status, 403);
  // 관리자 이메일은 바로 관리자
  const me = await a.login("boss@school.kr");
  assert.equal(me.role, "owner");
  assert.equal(me.status, "active");
  // 학교 도메인 새 사용자는 승인 대기
  const b = env.client("b");
  const t1 = await b.login("teacher1@school.kr");
  assert.equal(t1.status, "pending");
  assert.equal((await b.get("/api/bootstrap")).status, 403);
  // 승인
  const users = (await a.ok("GET", "/api/users")).users;
  const pending = users.find((u) => u.email === "teacher1@school.kr");
  await a.ok("PATCH", `/api/users/${pending.id}`, { status: "active", role: "teacher" });
  assert.equal((await b.get("/api/bootstrap")).status, 200);
  // CSRF: 머리글 없는 변경 요청은 거부
  const noCsrf = await a.call("POST", "/api/locations", { name: "x" }, { csrf: false });
  assert.equal(noCsrf.status, 403);
  // 다른 사이트 Origin 거부
  const cross = await a.call("POST", "/api/locations", { name: "x" }, { headers: { origin: "https://evil.example" } });
  assert.equal(cross.status, 403);
  // 사용 중지하면 세션이 끊긴다
  await a.ok("PATCH", `/api/users/${pending.id}`, { status: "disabled" });
  assert.equal((await b.get("/api/bootstrap")).status, 401);
  // 마지막 관리자는 스스로 못 바꾼다
  const self = (await a.ok("GET", "/api/users")).users.find((u) => u.email === "boss@school.kr");
  assert.equal((await a.patch(`/api/users/${self.id}`, { role: "teacher" })).status, 400);
});

test("등록·찾기(초성·별칭·관리번호·장소)·관리번호 자동", async (t) => {
  const { env, admin, loc } = await withSchool(t);
  const r = await admin.ok("POST", "/api/items", { name: "디지털 멀티미터", kind: "equipment", location_id: loc.elec.id, unit_count: 3, number_prefix: "전장-2026-", aliases: "멀티테스터", manufacturer: "Fluke" });
  assert.deepEqual(r.units.map((u) => u.management_number), ["전장-2026-001", "전장-2026-002", "전장-2026-003"]);
  const more = await admin.ok("POST", `/api/items/${r.id}/units`, { location_id: loc.tool.id, unit_count: 2, number_prefix: "전장-2026-" });
  assert.deepEqual(more.units.map((u) => u.management_number), ["전장-2026-004", "전장-2026-005"]);
  await admin.ok("POST", "/api/items", { name: "납땜 실납", kind: "consumable", location_id: loc.elec.id, quantity: 12, unit: "롤", min_stock: 20 });
  const q = async (s) => (await admin.ok("GET", `/api/search?q=${encodeURIComponent(s)}`));
  assert.equal((await q("ㄷㅈㅌ")).items[0].name, "디지털 멀티미터");
  assert.equal((await q("멀티테스터")).items[0].name, "디지털 멀티미터");
  const code = await q("전장-2026-004");
  assert.equal(code.exact.type, "item");
  assert.ok(code.items[0].match_unit_id);
  assert.equal((await q("공구실 멀티")).items[0].name, "디지털 멀티미터");
  assert.equal((await q("전자실습실")).locations[0].name, "전자실습실");
  // 재고 부족 경보
  const boot = await admin.ok("GET", "/api/bootstrap");
  assert.ok(boot.alerts.alerts.some((a) => a.kind === "low"));
  const low = await admin.ok("GET", "/api/items?status=low");
  assert.equal(low.items[0].name, "납땜 실납");
  // 스캔 코드
  const unit = r.units[0];
  const scan = await admin.ok("GET", `/api/scan/${encodeURIComponent(`http://nas:8080/q/${unit.qr}`)}`);
  assert.equal(scan.type, "asset");
  assert.equal(scan.item_id, r.id);
  // 라벨 QR 주소로 들어오면 앱으로 넘긴다
  const q2 = await admin.call("GET", `/q/${unit.qr}`);
  assert.equal(q2.status, 302);
  assert.match(q2.headers.get("location"), /#scan\?code=/);
});

test("이동·대여·반납(이상→수리)·사용·입고·보정과 되돌리기", async (t) => {
  const { admin, loc } = await withSchool(t);
  const eq = await admin.ok("POST", "/api/items", { name: "충전드릴", kind: "equipment", location_id: loc.shelf.id, unit_count: 2 });
  const [u1, u2] = eq.units;
  // 이동 + 되돌리기
  const mv = await admin.ok("POST", "/api/actions/move", { targets: [{ asset_id: u1.id }], to_location_id: loc.elec.id });
  assert.equal(mv.moved, 1);
  assert.ok(mv.events[0].undoable);
  await admin.ok("POST", "/api/undo", { event_ids: [mv.events[0].id] });
  let d = await admin.ok("GET", `/api/items/${eq.id}`);
  assert.equal(d.units.find((u) => u.id === u1.id).location_id, loc.shelf.id);
  // 같은 작업을 두 번 되돌릴 수 없다
  assert.equal((await admin.post("/api/undo", { event_ids: [mv.events[0].id] })).status, 409);
  // 대여 → 이중 대여 막힘 → 반납(이상) → 수리 신고 생김
  const ln = await admin.ok("POST", "/api/actions/loan", { asset_ids: [u1.id], borrower_name: "박학생", borrower_note: "3-2", due_at: new Date(Date.now() - 3600000).toISOString() });
  assert.equal((await admin.post("/api/actions/loan", { asset_ids: [u1.id], borrower_name: "누구" })).status, 409);
  const boot = await admin.ok("GET", "/api/bootstrap");
  assert.equal(boot.counts.overdue, 1);
  assert.equal(boot.alerts.level, "red");
  // 대여 중인 장비는 옮길 수 없다
  assert.equal((await admin.post("/api/actions/move", { targets: [{ asset_id: u1.id }], to_location_id: loc.tool.id })).status, 409);
  const rt = await admin.ok("POST", "/api/actions/return", { loan_ids: [ln.loans[0].id], condition: "issue", note: "척 헐거움" });
  assert.match(rt.events[0].summary, /이상/);
  d = await admin.ok("GET", `/api/items/${eq.id}`);
  assert.equal(d.units.find((u) => u.id === u1.id).status, "repair");
  assert.equal(d.repairs.length, 1);
  // 반납 되돌리기 → 다시 대여 중, 자동 신고도 사라짐
  await admin.ok("POST", "/api/undo", { event_ids: [rt.events[0].id] });
  d = await admin.ok("GET", `/api/items/${eq.id}`);
  assert.equal(d.units.find((u) => u.id === u1.id).status, "on_loan");
  assert.equal(d.repairs.length, 0);
  // 수량 품목: 사용·입고·보정·부분 이동
  const cons = await admin.ok("POST", "/api/items", { name: "전선 1.5sq", kind: "consumable", location_id: loc.elec.id, quantity: 30, unit: "m" });
  const sid = cons.stock.id;
  const use = await admin.ok("POST", "/api/actions/use", { stock_id: sid, qty: 7.5, purpose: "수업" });
  assert.match(use.event.summary, /7.5m 사용/);
  assert.equal((await admin.post("/api/actions/use", { stock_id: sid, qty: 1000 })).status, 409);
  await admin.ok("POST", "/api/actions/restock", { item_id: cons.item.id, location_id: loc.elec.id, qty: 10 });
  const mv2 = await admin.ok("POST", "/api/actions/move", { targets: [{ stock_id: sid, qty: 12.5 }], to_location_id: loc.tool.id });
  d = await admin.ok("GET", `/api/items/${cons.item.id}`);
  const byLoc = Object.fromEntries(d.stocks.map((s) => [s.location_id, s.quantity]));
  assert.equal(byLoc[loc.elec.id], 20);
  assert.equal(byLoc[loc.tool.id], 12.5);
  await admin.ok("POST", "/api/undo", { event_ids: [mv2.events[0].id] });
  d = await admin.ok("GET", `/api/items/${cons.item.id}`);
  assert.equal(d.stocks.find((s) => s.location_id === loc.elec.id).quantity, 32.5);
  const adj = await admin.ok("POST", "/api/actions/adjust", { stock_id: sid, quantity: 31, reason: "세어 봄" });
  assert.ok(adj.event.undoable);
  // 폐기 + 되돌리기
  const rtm = await admin.ok("POST", "/api/actions/retire", { asset_id: u2.id, kind: "unrepairable", reason: "모터 고장" });
  d = await admin.ok("GET", `/api/items/${eq.id}`);
  assert.equal(d.retired_units.length, 1);
  await admin.ok("POST", "/api/undo", { event_ids: [rtm.event.id] });
  d = await admin.ok("GET", `/api/items/${eq.id}`);
  assert.equal(d.retired_units.length, 0);
});

test("역할별 권한: 교사·학생", async (t) => {
  const { env, admin, loc } = await withSchool(t);
  const eq = await admin.ok("POST", "/api/items", { name: "노트북", kind: "equipment", location_id: loc.elec.id, unit_count: 1 });
  await admin.ok("POST", "/api/users", { email: "t@school.kr", name: "이수업", role: "teacher" });
  await admin.ok("POST", "/api/users", { email: "s@school.kr", name: "최학생", role: "student" });
  const teacher = env.client("t");
  await teacher.login("t@school.kr");
  // 교사: 대여·이동·등록 됨, 정보 수정·폐기·설정은 안 됨
  const ln = await teacher.ok("POST", "/api/actions/loan", { asset_ids: [eq.units[0].id] });
  assert.equal(ln.loans[0].borrower_name, "이수업");
  assert.equal((await teacher.patch(`/api/items/${eq.id}`, { name: "새 이름" })).status, 403);
  assert.equal((await teacher.post("/api/actions/retire", { asset_id: eq.units[0].id, reason: "x" })).status, 403);
  assert.equal((await teacher.get("/api/settings")).status, 403);
  // 교사가 방금 등록한 것은 1시간 동안 고칠 수 있다
  const mine = await teacher.ok("POST", "/api/items", { name: "인두기", kind: "fixture", location_id: loc.tool.id, quantity: 5 });
  await teacher.ok("PATCH", `/api/items/${mine.id}`, { spec: "60W" });
  // 학생: 기본은 로그인 막힘 → 켜면 조회·신고만
  const student = env.client("s");
  assert.equal((await student.call("POST", "/api/auth/dev-login", { email: "s@school.kr" })).status, 403);
  await admin.ok("PATCH", "/api/settings", { access: { student_login: true } });
  await student.login("s@school.kr");
  assert.equal((await student.get(`/api/items/${eq.id}`)).status, 200);
  assert.equal((await student.post("/api/actions/move", { targets: [{ asset_id: eq.units[0].id }], to_location_id: loc.tool.id })).status, 403);
  assert.equal((await student.post("/api/repairs", { target_type: "item", target_id: mine.id, title: "안 켜져요" })).status, 201);
  // 학생은 남의 대여 이름을 못 본다
  const loans = await student.ok("GET", "/api/loans");
  assert.equal(loans.loans.length, 0);
});

test("실사: 스캔 확인·발견·끝낸 뒤 보정", async (t) => {
  const { admin, loc } = await withSchool(t);
  const a = await admin.ok("POST", "/api/items", { name: "오실로스코프", kind: "equipment", location_id: loc.tool.id, unit_count: 2 });
  const b = await admin.ok("POST", "/api/items", { name: "토크렌치", kind: "equipment", location_id: loc.elec.id, unit_count: 1 });
  const c = await admin.ok("POST", "/api/items", { name: "볼트 M6", kind: "part", location_id: loc.shelf.id, quantity: 100 });
  const au = await admin.ok("POST", "/api/audits", { location_id: loc.tool.id });
  assert.equal(au.progress.total, 3);
  assert.equal((await admin.post("/api/audits", { location_id: loc.tool.id })).status, 409);
  const s1 = await admin.ok("POST", `/api/audits/${au.id}/scan`, { code: a.units[0].qr });
  assert.equal(s1.result, "ok");
  assert.equal((await admin.ok("POST", `/api/audits/${au.id}/scan`, { code: a.units[0].qr })).result, "again");
  const ex = await admin.ok("POST", `/api/audits/${au.id}/scan`, { code: b.units[0].qr });
  assert.equal(ex.result, "extra");
  const stockLine = au.lines.find((l) => l.kind === "stock");
  await admin.ok("PATCH", `/api/audits/${au.id}/lines/${stockLine.id}`, { counted_qty: 96 });
  const done = await admin.ok("POST", `/api/audits/${au.id}/finish`, {});
  assert.equal(done.status, "done");
  const missing = done.lines.find((l) => l.kind === "asset" && !l.checked_at && !l.extra);
  await admin.ok("POST", `/api/audits/${au.id}/lines/${missing.id}/resolve`, { action: "lost" });
  await admin.ok("POST", `/api/audits/${au.id}/lines/${stockLine.id}/resolve`, { action: "adjust" });
  const extraLine = done.lines.find((l) => l.extra);
  await admin.ok("POST", `/api/audits/${au.id}/lines/${extraLine.id}/resolve`, { action: "move_here" });
  const da = await admin.ok("GET", `/api/items/${a.id}`);
  assert.equal(da.units.filter((u) => u.status === "lost").length, 1);
  const dc = await admin.ok("GET", `/api/items/${c.id}`);
  assert.equal(dc.stocks[0].quantity, 96);
  const db = await admin.ok("GET", `/api/items/${b.id}`);
  assert.equal(db.units[0].location_id, loc.tool.id);
});

test("백업 → 바꾸기 → 복원(되돌아옴)·업로드 복원", async (t) => {
  const { env, admin, loc } = await withSchool(t);
  await admin.ok("POST", "/api/items", { name: "현미경", kind: "equipment", location_id: loc.elec.id, unit_count: 1 });
  const bk = await admin.ok("POST", "/api/backups", {});
  assert.match(bk.name, /^inni-backup-\d{8}-\d{6}\.zip$/);
  assert.equal(bk.manifest.counts.items, 1);
  await admin.ok("POST", "/api/items", { name: "나중에 생긴 것", kind: "consumable", location_id: loc.elec.id, quantity: 1 });
  assert.equal((await admin.post(`/api/backups/${bk.name}/restore`, { confirm: "아니" })).status, 400);
  const rs = await admin.ok("POST", `/api/backups/${bk.name}/restore`, { confirm: "복원" });
  assert.ok(rs.safety.includes("before-restore"));
  // 복원하면 세션이 비워진다 → 다시 로그인
  assert.equal((await admin.get("/api/bootstrap")).status, 401);
  await admin.login("boss@school.kr");
  const items = await admin.ok("GET", "/api/items");
  assert.deepEqual(items.items.map((x) => x.name), ["현미경"]);
  // 다른 곳(새 inni)으로 옮기기: zip 을 올려 복원
  const zip = fs.readFileSync(path.join(env.ctx.cfg.backupsDir, bk.name));
  const env2 = await (await import("./helpers.js")).startApp();
  t.after(() => env2.close());
  const other = env2.client("x");
  await other.login("new@school.kr");
  const bad = await other.call("POST", "/api/restore-upload", undefined, { raw: Buffer.from("not a zip"), headers: { "content-type": "application/zip", "x-inni-confirm": encodeURIComponent("복원") } });
  assert.equal(bad.status, 400);
  const up = await other.call("POST", "/api/restore-upload", undefined, { raw: zip, headers: { "content-type": "application/zip", "x-inni-confirm": encodeURIComponent("복원") } });
  assert.equal(up.status, 200, JSON.stringify(up.data));
  await other.login("boss@school.kr");
  assert.equal((await other.ok("GET", "/api/items")).items[0].name, "현미경");
});

test("CSV 미리보기·가져오기·내보내기", async (t) => {
  const { admin, loc } = await withSchool(t);
  const csv = "품명,종류,위치,수량,관리번호,제조사\n오실로스코프,장비,실습동 > 전자실습실,1,전장-2021-001,RIGOL\n오실로스코프,장비,전자실습실,1,전장-2021-002,RIGOL\n실납,소모품,공구실,12,,\n없는종류,로켓,공구실,1,,\n저항,부품,새 창고,50,,\n";
  const dry = await admin.ok("POST", "/api/import/items?dry=1", undefined, { raw: Buffer.from(csv), headers: { "content-type": "text/csv" } });
  assert.equal(dry.new_items, 3);
  assert.equal(dry.new_units, 2);
  assert.equal(dry.errors.length, 1);
  assert.deepEqual(dry.new_locations, ["새 창고"]);
  assert.equal((await admin.ok("GET", "/api/items")).total, 0);
  const done = await admin.ok("POST", "/api/import/items?dry=0", undefined, { raw: Buffer.from(csv), headers: { "content-type": "text/csv" } });
  assert.ok(done.done);
  const list = await admin.ok("GET", "/api/items");
  assert.equal(list.total, 3);
  const scope = await admin.ok("GET", `/api/items?q=${encodeURIComponent("오실로")}`);
  assert.equal(scope.items[0].units, 2);
  const out = await admin.call("GET", "/api/export/items.csv");
  assert.match(out.data, /전장-2021-002/);
  assert.match(out.headers.get("content-disposition"), /attachment/);
  assert.ok(loc.elec.id);
});

test("예전 inni(PHP) 데이터 가져오기 — 아이디·QR 유지", async (t) => {
  const env = await startApp();
  t.after(() => env.close());
  const legacy = path.join(env.dir, "inni.sqlite");
  const old = new Database(legacy);
  old.exec(fs.readFileSync(new URL("./fixtures/legacy_schema.sql", import.meta.url), "utf8"));
  const ts = "2026-09-10T00:00:00Z";
  old.prepare("INSERT INTO settings(key,value) VALUES('school_name','한빛공고')").run();
  old.prepare("INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES('u1','kim@hanbit.hs.kr','김담당','owner','active',?,?)").run(ts, ts);
  old.prepare("INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES('demo-owner','owner@demo.inni','데모','owner','active',?,?)").run(ts, ts);
  old.prepare("INSERT INTO locations(id,name,kind,parent_id,code,sort_order,qr_code,created_at,updated_at) VALUES('b1','실습동','building',NULL,NULL,0,'LOC-b1',?,?)").run(ts, ts);
  old.prepare("INSERT INTO locations(id,name,kind,parent_id,code,sort_order,qr_code,created_at,updated_at) VALUES('r1','전자실','room','b1','E-1',1,'LOC-r1',?,?)").run(ts, ts);
  old.prepare("INSERT INTO catalog_items(id,name,type,tags,unit,qr_code,created_at,updated_at) VALUES('ci1','멀티미터','equipment','[\"계측\"]','ea','CAT-ci1',?,?)").run(ts, ts);
  old.prepare("INSERT INTO catalog_items(id,name,type,tags,unit,min_stock,qr_code,created_at,updated_at) VALUES('ci2','실납','consumable','[]','m',5,'CAT-ci2',?,?)").run(ts, ts);
  old.prepare("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,tags,qr_code,created_at,updated_at) VALUES('a1','ci1','멀티미터 #1','전장-001','on_loan','r1','[]','AST-a1',?,?)").run(ts, ts);
  old.prepare("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,tags,qr_code,created_at,updated_at) VALUES('a2','ci1','멀티미터 #2','전장-002','moving','r1','[]','AST-a2',?,?)").run(ts, ts);
  old.prepare("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('s1','ci2','r1',3,?)").run(ts);
  old.prepare("INSERT INTO loans(id,kind,asset_id,quantity,borrower_user_id,borrower_name,from_location_id,due_at,status,created_at,created_by) VALUES('l1','asset','a1',1,NULL,'박학생','r1','2026-09-11T00:00:00Z','overdue',?,'u1')").run(ts);
  old.prepare("INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,created_at) VALUES('g1','loan','asset','a1','u1','김담당','멀티미터 #1 대여',?)").run(ts);
  old.close();
  const a = env.client("a");
  await a.login("boss@school.kr");
  const st = await a.ok("GET", "/api/setup/status");
  assert.ok(st.empty && st.legacy.found);
  const r = await a.ok("POST", "/api/setup/legacy", {});
  assert.equal(r.items, 2);
  assert.equal(r.units, 2);
  assert.equal(r.users, 1); // 데모 계정은 빼고
  // 예전 라벨(QR) 이 그대로 통한다
  const hit = await a.ok("GET", "/api/scan/AST-a1");
  assert.equal(hit.id, "a1");
  const d = await a.ok("GET", "/api/items/ci1");
  assert.equal(d.units.find((u) => u.id === "a2").status, "available");
  assert.equal(d.units.find((u) => u.id === "a1").loan.borrower_name, "박학생");
  const boot = await a.ok("GET", "/api/bootstrap");
  assert.equal(boot.settings.school.name, "한빛공고");
  assert.equal(boot.counts.low, 1);
  // 두 번은 안 된다
  assert.equal((await a.post("/api/setup/legacy", {})).status, 409);
});

test("사진 올리기: 형식 확인·로그인한 사람만 보기", async (t) => {
  const { env, admin } = await withSchool(t);
  const png = Buffer.from("89504E470D0A1A0A0000000D4948445200000001000000010806000000", "hex");
  const up = await admin.call("POST", "/api/uploads", undefined, { raw: Buffer.concat([png, Buffer.alloc(20)]), headers: { "content-type": "image/png" } });
  assert.equal(up.status, 201);
  const bad = await admin.call("POST", "/api/uploads", undefined, { raw: Buffer.from("<svg onload=alert(1)>"), headers: { "content-type": "image/svg+xml" } });
  assert.equal(bad.status, 400);
  assert.equal((await admin.call("GET", up.data.url)).status, 200);
  const anon = env.client("anon");
  assert.equal((await anon.call("GET", up.data.url)).status, 401);
  assert.equal((await admin.call("GET", "/uploads/../inni.db")).status, 404);
});

test("설정: 비밀 값은 힌트만, 관리자 전용 구분", async (t) => {
  const { env, admin } = await withSchool(t);
  const s = await admin.ok("PATCH", "/api/settings", { secrets: { naver_client_id: "abcdefghij1234" }, school: { name: "한빛공고", short_name: "한빛" } });
  assert.equal(s.secrets.naver_client_id, "••••1234");
  assert.ok(!JSON.stringify(s).includes("abcdefghij1234"));
  const boot = await admin.ok("GET", "/api/bootstrap");
  assert.equal(boot.settings.school.name, "한빛공고");
  await admin.ok("POST", "/api/users", { email: "m@school.kr", name: "박실무", role: "manager" });
  const m = env.client("m");
  await m.login("m@school.kr");
  assert.equal((await m.patch("/api/settings", { labels: { paper: "a4-40" } })).status, 200);
  assert.equal((await m.patch("/api/settings", { secrets: { kakao_rest_key: "x" } })).status, 403);
  assert.equal((await m.get("/api/backups")).status, 403);
});

test("제품 링크로 채우기·사진 대신 받기(내부망 차단·SVG 거절)", async (t) => {
  const http = await import("node:http");
  const { admin } = await withSchool(t);
  const png = Buffer.from("89504E470D0A1A0A0000000D49484452000000010000000108060000001F15C4890000000D49444154789C6360000002000154A24F5D0000000049454E44AE426082", "hex");
  const page = `<!doctype html><html><head><meta charset="utf-8"><title>무시되는 제목</title>
    <meta property="og:site_name" content="학교장터"><meta property="og:image" content="/img/p.png">
    <script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"디지털 멀티미터 87V","brand":{"@type":"Brand","name":"Fluke"},"model":"87V","gtin13":"0095969000000","offers":{"@type":"Offer","price":"512000","priceCurrency":"KRW"}}</script>
    </head><body><h1>디지털 멀티미터</h1><p>True-RMS 측정</p></body></html>`;
  const shop = http.createServer((req, res) => {
    if (req.url === "/p/1") { res.writeHead(200, { "content-type": "text/html; charset=utf-8" }); res.end(page); return; }
    if (req.url === "/img/p.png") { res.writeHead(200, { "content-type": "image/png" }); res.end(png); return; }
    // 이미지라고 속이는 SVG
    if (req.url === "/img/x.svg") { res.writeHead(200, { "content-type": "image/png" }); res.end('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'); return; }
    res.writeHead(404).end();
  });
  await new Promise((r) => shop.listen(0, "127.0.0.1", r));
  t.after(() => shop.close());
  const base = `http://127.0.0.1:${shop.address().port}`;
  // 기본은 학교 내부망 주소를 읽지 않는다
  const blocked = await admin.post("/api/url-import", { url: `${base}/p/1`, ai: false });
  assert.equal(blocked.status, 400);
  assert.match(blocked.data.error, /내부망/);
  await admin.ok("PATCH", "/api/settings", { url_import: { enabled: true, allow_private: true } });
  const r = await admin.ok("POST", "/api/url-import", { url: `${base}/p/1`, ai: false });
  assert.equal(r.fields.name, "디지털 멀티미터 87V");
  assert.equal(r.fields.manufacturer, "Fluke");
  assert.equal(r.fields.model, "87V");
  assert.equal(r.fields.price, 512000);
  assert.equal(r.fields.barcode, "0095969000000");
  assert.equal(r.fields.vendor, "학교장터");
  assert.equal(r.images[0], `${base}/img/p.png`);
  assert.equal(r.source, "json-ld");
  // 사진 대신 받기: 진짜 PNG 만, 보안 머리글과 함께
  const img = await admin.get(`/api/image-proxy?url=${encodeURIComponent(`${base}/img/p.png`)}`);
  assert.equal(img.status, 200);
  assert.equal(img.headers.get("content-type"), "image/png");
  assert.match(img.headers.get("content-security-policy"), /sandbox/);
  const svg = await admin.get(`/api/image-proxy?url=${encodeURIComponent(`${base}/img/x.svg`)}`);
  assert.ok(svg.status >= 400, "SVG 는 사진으로 내보내지 않는다");
});

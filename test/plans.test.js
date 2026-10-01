// 도면: 층에 그림을 붙이고 실을 그려 장소와 잇는다(3D 지도·위치 안내)
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { withSchool } from "./helpers.js";
import { guessLevel } from "../server/lib/plans.js";

const PNG = Buffer.concat([Buffer.from("89504E470D0A1A0A0000000D4948445200000001000000010806000000", "hex"), Buffer.alloc(20)]);
const rect = (x, y, w, h) => [[x, y], [x + w, y], [x + w, y + h], [x, y + h]];

test("층 이름으로 층 번호 짐작", () => {
  assert.equal(guessLevel("지하1층"), -1);
  assert.equal(guessLevel("B2"), -2);
  assert.equal(guessLevel("3층"), 3);
  assert.equal(guessLevel("본관 2F"), 2);
  assert.equal(guessLevel("본관"), 1);
});

test("도면: 올리기·실 그리기·표시·권한·사진 정리", async (t) => {
  const { env, admin, loc } = await withSchool(t);
  const floor = (await admin.ok("POST", "/api/locations", { name: "2층", kind: "floor", parent_id: loc.building.id })).location;
  const up = await admin.call("POST", "/api/uploads", undefined, { raw: PNG, headers: { "content-type": "image/png" } });
  assert.equal(up.status, 201);

  // 층에 도면 붙이기(층 번호는 이름에서)
  const { plan } = await admin.ok("POST", "/api/plans", { location_id: floor.id, image: up.data.path, width: 800, height: 600 });
  assert.equal(plan.level, 2);
  assert.equal(plan.image_url, `/uploads/${up.data.path}`);
  assert.equal((await admin.post("/api/plans", { location_id: floor.id, image: up.data.path, width: 800, height: 600 })).status, 409, "한 층에 도면 하나");
  assert.equal((await admin.post("/api/plans", { location_id: loc.elec.id })).status, 400, "실에는 도면을 붙이지 않는다");
  assert.equal((await admin.post("/api/plans", { location_id: floor.id, image: "../../etc/passwd" })).status, 409);

  // 실·보관함 그리기
  const r = await admin.ok("PATCH", `/api/plans/${plan.id}`, { shapes: [{ location_id: loc.elec.id, pts: rect(10, 10, 300, 200) }, { location_id: loc.shelf.id, pts: [[400, 50], [480, 50], [9999, 9999]] }] });
  assert.equal(r.plan.shapes.length, 2);
  assert.deepEqual(r.plan.shapes[1].pts[2], [800, 600], "그림 밖 좌표는 가장자리로");
  const bad = async (shapes, why) => assert.equal((await admin.patch(`/api/plans/${plan.id}`, { shapes })).status, 400, why);
  await bad([{ location_id: loc.building.id, pts: rect(0, 0, 10, 10) }], "건물은 실로 그리지 않는다");
  await bad([{ location_id: loc.elec.id, pts: rect(0, 0, 10, 10) }, { location_id: loc.elec.id, pts: rect(20, 0, 10, 10) }], "같은 실을 두 번");
  await bad([{ location_id: loc.tool.id, pts: [[0, 0], [5, 5]] }], "점이 둘뿐");
  await bad([{ location_id: "nope", pts: rect(0, 0, 10, 10) }], "없는 장소");

  // 표시(현관·계단)
  const m = await admin.ok("PATCH", `/api/plans/${plan.id}`, { marks: [{ type: "entrance", x: 5, y: 590, label: "정문" }, { type: "stairs", x: 780, y: 20 }] });
  assert.equal(m.plan.marks.length, 2);
  assert.equal((await admin.patch(`/api/plans/${plan.id}`, { marks: [{ type: "door", x: 1, y: 1 }] })).status, 400);

  // 다른 도면(단층 건물)에 같은 실을 또 그리면 거절
  const b2 = (await admin.ok("POST", "/api/locations", { name: "본관", kind: "building" })).location;
  const { plan: p2 } = await admin.ok("POST", "/api/plans", { location_id: b2.id, width: 400, height: 300 });
  assert.equal(p2.level, 1);
  assert.equal((await admin.patch(`/api/plans/${p2.id}`, { shapes: [{ location_id: loc.elec.id, pts: rect(0, 0, 50, 50) }] })).status, 409);

  // 모두 볼 수 있고, 고치기는 담당교사 이상
  const boot = await admin.ok("GET", "/api/bootstrap");
  assert.equal(boot.plans.length, 2);
  await admin.ok("POST", "/api/users", { email: "t@school.kr", name: "이수업", role: "teacher" });
  const teacher = env.client("t");
  await teacher.login("t@school.kr");
  assert.equal((await teacher.get("/api/plans")).data.plans.length, 2);
  assert.equal((await teacher.patch(`/api/plans/${plan.id}`, { level: 3 })).status, 403);

  // 쓰지 않는 사진 정리는 도면 그림을 지우지 않는다(하루 지난 파일만 보므로 날짜를 돌려 둔다)
  const old = (Date.now() - 2 * 86400000) / 1000;
  fs.utimesSync(path.join(env.ctx.cfg.uploadsDir, up.data.path), old, old);
  const before = (await admin.ok("GET", "/api/maintenance/orphans")).count;
  await admin.ok("DELETE", `/api/plans/${plan.id}`);
  const after = (await admin.ok("GET", "/api/maintenance/orphans")).count;
  assert.equal(after, before + 1, "도면을 지우면 그 그림이 정리 대상이 된다");
  assert.equal((await admin.ok("GET", "/api/plans")).plans.length, 1);
});

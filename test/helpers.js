// 시험용: 임시 폴더에 앱을 띄우고 쿠키를 들고 API 를 부른다.
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { createApp } from "../server/app.js";

export async function startApp(overrides = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), "inni-test-"));
  const { app, ctx, close } = createApp({ dataDir: dir, authMode: "dev", seedDemo: false, jobs: false, ...overrides });
  const server = await new Promise((resolve) => { const s = app.listen(0, "127.0.0.1", () => resolve(s)); });
  const base = `http://127.0.0.1:${server.address().port}`;
  const clients = new Map();

  function client(name = "default") {
    if (clients.has(name)) return clients.get(name);
    let cookie = "";
    async function call(method, p, body, { raw, headers = {}, csrf = true } = {}) {
      const h = { ...(csrf ? { "x-inni": "1" } : {}), ...headers };
      if (cookie) h.cookie = cookie;
      let payload;
      if (raw !== undefined) payload = raw;
      else if (body !== undefined) { h["content-type"] = "application/json"; payload = JSON.stringify(body); }
      const r = await fetch(base + p, { method, headers: h, body: payload, redirect: "manual" });
      const sc = r.headers.get("set-cookie");
      if (sc) cookie = sc.split(";")[0];
      const type = r.headers.get("content-type") || "";
      const data = type.includes("json") ? await r.json() : await r.text();
      return { status: r.status, data, headers: r.headers };
    }
    const c = {
      call,
      get: (p, o) => call("GET", p, undefined, o),
      post: (p, b, o) => call("POST", p, b ?? {}, o),
      patch: (p, b, o) => call("PATCH", p, b, o),
      del: (p, o) => call("DELETE", p, undefined, o),
      async login(email, name) {
        const r = await call("POST", "/api/auth/dev-login", { email, name });
        if (r.status !== 200) throw new Error(`login ${email}: ${r.status} ${JSON.stringify(r.data)}`);
        return r.data.me;
      },
      async ok(method, p, body, o) {
        const r = await call(method, p, body, o);
        if (r.status >= 400) throw new Error(`${method} ${p} → ${r.status} ${JSON.stringify(r.data)}`);
        return r.data;
      },
    };
    clients.set(name, c);
    return c;
  }

  return {
    base, ctx, dir, client,
    async close() {
      await new Promise((r) => server.close(r));
      close();
      fs.rmSync(dir, { recursive: true, force: true });
    },
  };
}

// 관리자 + 기본 장소 몇 개
export async function withSchool(t) {
  const env = await startApp();
  const admin = env.client("admin");
  await admin.login("boss@school.kr", "김담당");
  const b = await admin.ok("POST", "/api/locations", { name: "실습동", kind: "building" });
  const elec = await admin.ok("POST", "/api/locations", { name: "전자실습실", kind: "room", parent_id: b.location.id, code: "E-201" });
  const tool = await admin.ok("POST", "/api/locations", { name: "공구실", kind: "room", parent_id: b.location.id });
  const shelf = await admin.ok("POST", "/api/locations", { name: "선반 A", kind: "storage", parent_id: tool.location.id });
  if (t) t.after(() => env.close());
  return { env, admin, loc: { building: b.location, elec: elec.location, tool: tool.location, shelf: shelf.location } };
}

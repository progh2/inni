// 실시간 알림(Server-Sent Events). 누가 무엇을 옮기면 다른 화면(데스크 PC·휴대폰)에 바로 뜬다.
export function createBus() {
  const clients = new Set();
  let seq = 0;
  const beat = setInterval(() => {
    for (const c of clients) c.res.write(": ping\n\n");
  }, 25000);
  beat.unref();
  return {
    add(req, res, user) {
      res.writeHead(200, {
        "Content-Type": "text/event-stream; charset=utf-8",
        "Cache-Control": "no-cache, no-transform",
        Connection: "keep-alive",
        "X-Accel-Buffering": "no",
      });
      res.write(`retry: 4000\n\n`);
      const client = { res, user };
      clients.add(client);
      req.on("close", () => clients.delete(client));
      return client;
    },
    publish(type, data, { filter } = {}) {
      const id = ++seq;
      for (const c of clients) {
        if (filter && !filter(c.user)) continue;
        const payload = typeof data === "function" ? data(c.user) : data;
        if (payload === null || payload === undefined) continue;
        c.res.write(`id: ${id}\nevent: ${type}\ndata: ${JSON.stringify(payload)}\n\n`);
      }
    },
    get size() { return clients.size; },
    close() {
      clearInterval(beat);
      for (const c of clients) { try { c.res.end(); } catch { /* 이미 닫힘 */ } }
      clients.clear();
    },
  };
}

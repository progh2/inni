// 서버 부르기. 로그인은 쿠키로, 바꾸는 요청에는 x-inni 머리글을 붙인다(CSRF 방지).
export class ApiError extends Error {
  constructor(message, status, code) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

let onUnauthorized = null;
export function setUnauthorizedHandler(fn) {
  onUnauthorized = fn;
}

export async function api(path, { method = "GET", body, raw, headers = {}, signal, via } = {}) {
  const h = { "x-inni": "1", ...headers };
  if (via) h["x-inni-via"] = via;
  let payload;
  if (raw !== undefined) payload = raw;
  else if (body !== undefined) {
    h["Content-Type"] = "application/json";
    payload = JSON.stringify(body);
  }
  let resp;
  try {
    resp = await fetch(path, { method, headers: h, body: payload, signal, credentials: "same-origin" });
  } catch (e) {
    if (e.name === "AbortError") throw e;
    throw new ApiError("서버에 연결하지 못했습니다. 네트워크(와이파이)를 확인하세요.", 0, "network");
  }
  const type = resp.headers.get("content-type") || "";
  let data = null;
  if (type.includes("application/json")) {
    try { data = await resp.json(); } catch { data = null; }
  }
  if (!resp.ok) {
    if (resp.status === 401 && onUnauthorized && !path.startsWith("/api/auth")) onUnauthorized();
    throw new ApiError((data && data.error) || `요청 실패 (HTTP ${resp.status})`, resp.status, data && data.code);
  }
  if (data !== null) return data;
  return resp;
}

api.get = (path, opts) => api(path, { ...opts, method: "GET" });
api.post = (path, body, opts) => api(path, { ...opts, method: "POST", body });
api.patch = (path, body, opts) => api(path, { ...opts, method: "PATCH", body });
api.del = (path, opts) => api(path, { ...opts, method: "DELETE" });

// POST 로 받는 text/event-stream(이니 대화). 이벤트마다 onEvent(event, data)
api.stream = async function stream(path, body, onEvent, { signal } = {}) {
  let resp;
  try {
    resp = await fetch(path, {
      method: "POST", credentials: "same-origin", signal,
      headers: { "x-inni": "1", "Content-Type": "application/json", Accept: "text/event-stream" },
      body: JSON.stringify(body),
    });
  } catch (e) {
    if (e.name === "AbortError") throw e;
    throw new ApiError("서버에 연결하지 못했습니다", 0, "network");
  }
  if (!resp.ok || !resp.body) {
    let data = null;
    try { data = await resp.json(); } catch { data = null; }
    throw new ApiError((data && data.error) || `요청 실패 (HTTP ${resp.status})`, resp.status, data && data.code);
  }
  const reader = resp.body.getReader();
  const decoder = new TextDecoder();
  let buf = "";
  for (;;) {
    const { value, done } = await reader.read();
    if (done) break;
    buf += decoder.decode(value, { stream: true });
    let cut;
    while ((cut = buf.indexOf("\n\n")) >= 0) {
      const chunk = buf.slice(0, cut);
      buf = buf.slice(cut + 2);
      let event = "message";
      const lines = [];
      for (const line of chunk.split("\n")) {
        if (line.startsWith("event:")) event = line.slice(6).trim();
        else if (line.startsWith("data:")) lines.push(line.slice(5).trimStart());
      }
      if (!lines.length) continue;
      let data = lines.join("\n");
      try { data = JSON.parse(data); } catch { /* 글자 그대로 */ }
      onEvent(event, data);
    }
  }
};

// 사진 올리기(Blob) → { path }
export async function uploadImage(blob) {
  return api("/api/uploads", { method: "POST", raw: blob, headers: { "Content-Type": blob.type || "application/octet-stream" } });
}

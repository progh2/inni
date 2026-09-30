// 배경 지우기 전용 방(사진 편집기가 숨겨서 띄우는 iframe).
// @imgly/background-removal 은 속에서 new Function·blob 스크립트를 써서 앱 화면의 엄격한 보안 정책(CSP)으로는 못 돈다.
// 그래서 이 방에만 따로 허용하고(server/app.js 의 BG_LAB_CSP), 앱과는 사진 데이터만 주고받는다.
const IMGLY = "https://cdn.jsdelivr.net/npm/@imgly/background-removal@1.7.0/+esm";
let mod = null;
const post = (msg, transfer = []) => parent.postMessage(msg, location.origin, transfer);

window.addEventListener("message", async (ev) => {
  if (ev.source !== parent || ev.origin !== location.origin) return;
  const { id, buffer, type } = ev.data || {};
  if (!id || !(buffer instanceof ArrayBuffer)) return;
  try {
    if (!mod) mod = await import(IMGLY);
    post({ id, type: "progress", text: "배경을 지우는 중…" });
    const out = await mod.removeBackground(new Blob([buffer], { type: type || "image/png" }), {
      model: "isnet_quint8",
      output: { format: "image/png" },
      progress: (key, cur, total) => {
        if (total) post({ id, type: "progress", text: key.includes("fetch") ? `AI 모델 받는 중 ${Math.round((cur / total) * 100)}%` : "배경을 지우는 중…" });
      },
    });
    const res = await out.arrayBuffer();
    post({ id, type: "done", buffer: res }, [res]);
  } catch (e) {
    post({ id, type: "error", error: (e && e.message) || String(e) });
  }
});
post({ type: "ready" });

// AI 보급관 "이니". 치비(2.5등신) 보급관 견습생 그림 — ChatGPT 이미지 모델로 그렸다(scripts/gen-character.mjs).
// 표정(data-mood)마다 그림이 바뀐다: idle · think · talk · happy · alert · error · listen · scan · sleep
// 작은 자리(sm)나 face:true 는 얼굴만 자른 둥근 그림. 움직임(둥실·통통·흔들림)은 CSS(character.css).
export const MOODS = ["idle", "happy", "think", "talk", "alert", "error", "listen", "scan", "sleep"];

function inner({ cone, face }) {
  return `${cone && !face ? '<i class="i-cone"></i>' : ""}${face ? "" : '<i class="i-base"></i>'}<span class="i-art"></span><i class="i-fx"></i>`;
}

export function characterEl({ size = "md", mood = "idle", cone = false, face } = {}) {
  const el = document.createElement("span");
  const f = face ?? size === "sm";
  el.className = `inni ${size}${f ? " face" : ""}`;
  el.dataset.mood = mood;
  el.setAttribute("aria-hidden", "true");
  el.innerHTML = inner({ cone, face: f });
  return el;
}

export function characterHtml({ size = "md", mood = "idle", cone = false, face } = {}) {
  const f = face ?? size === "sm";
  return `<span class="inni ${size}${f ? " face" : ""}" data-mood="${mood}" aria-hidden="true">${inner({ cone, face: f })}</span>`;
}

// 모든 표정 그림을 미리 받아 둔다(표정이 처음 바뀔 때 깜빡이지 않게)
let preloaded = false;
export function preloadCharacter() {
  if (preloaded) return;
  preloaded = true;
  const go = () => {
    for (const m of MOODS) {
      new Image().src = `/img/inni/${m}.webp`;
      new Image().src = `/img/inni/face-${m}.webp`;
    }
  };
  if ("requestIdleCallback" in window) window.requestIdleCallback(go, { timeout: 4000 });
  else setTimeout(go, 1500);
}

// 모든 이니의 표정을 함께 바꾼다. ms 가 있으면 그 뒤 원래 표정으로
let base = "idle";
let timer = null;
export function setBaseMood(m) {
  base = m;
  if (!timer) applyAll(m);
}
export function mood(m, ms = 0) {
  clearTimeout(timer);
  timer = null;
  applyAll(m);
  if (ms) timer = setTimeout(() => { timer = null; applyAll(base); }, ms);
}
function applyAll(m) {
  for (const el of document.querySelectorAll(".inni[data-live]")) {
    if (el.dataset.mood === m) continue;
    el.dataset.mood = m;
    // 그림이 바뀔 때 살짝 튀어 오르게
    el.classList.remove("swap");
    void el.offsetWidth;
    el.classList.add("swap");
  }
}

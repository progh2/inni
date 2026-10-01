// AI 보급관 "이니". 그림 파일 없이 SVG 로 그린 홀로그램 화물 로봇이다.
// 표정(data-mood): idle · think · talk · happy · alert · error · listen · scan · sleep
const DEFS = `
<svg width="0" height="0" style="position:absolute" aria-hidden="true" id="inni-defs">
  <defs>
    <radialGradient id="ig-base" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#5fdcff" stop-opacity=".75"/><stop offset="1" stop-color="#5fdcff" stop-opacity="0"/></radialGradient>
    <linearGradient id="ig-shell" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f4fdff"/><stop offset=".55" stop-color="#a9e7ff"/><stop offset="1" stop-color="#4aaedb"/></linearGradient>
    <linearGradient id="ig-visor" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#0d2b46"/><stop offset="1" stop-color="#030f1c"/></linearGradient>
    <linearGradient id="ig-body" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#dcf6ff"/><stop offset="1" stop-color="#58b6e0"/></linearGradient>
    <radialGradient id="ig-core"><stop offset="0" stop-color="#fff6de"/><stop offset=".55" stop-color="#ffc857"/><stop offset="1" stop-color="#ff9d2e"/></radialGradient>
    <linearGradient id="ig-thrust" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#d6fbff"/><stop offset="1" stop-color="#5fdcff" stop-opacity="0"/></linearGradient>
    <linearGradient id="ig-cone" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#5fdcff" stop-opacity=".32"/><stop offset="1" stop-color="#5fdcff" stop-opacity="0"/></linearGradient>
    <filter id="ig-glow" x="-60%" y="-60%" width="220%" height="220%"><feGaussianBlur stdDeviation="1.5" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
    <filter id="ig-soft" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="0.6" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
    <clipPath id="ig-visor-clip"><rect x="30" y="34" width="60" height="40" rx="18"/></clipPath>
  </defs>
</svg>`;

function ensureDefs() {
  if (document.getElementById("inni-defs")) return;
  document.body.insertAdjacentHTML("afterbegin", DEFS);
}

export function characterSvg({ cone = false } = {}) {
  ensureDefs();
  return `<svg viewBox="0 0 120 150" aria-hidden="true">
  ${cone ? '<path d="M28 146 L50 96 H70 L92 146 Z" fill="url(#ig-cone)"/>' : ""}
  <ellipse class="i-base" cx="60" cy="142" rx="30" ry="5" fill="url(#ig-base)"/>
  <g class="i-flicker"><g class="i-float">
    <path class="i-thrust" d="M51 117 Q60 140 69 117 Z" fill="url(#ig-thrust)"/>
    <rect x="39" y="87" width="42" height="33" rx="15" fill="url(#ig-body)" stroke="#5fdcff" stroke-width="1.2"/>
    <path d="M45 95 Q60 89 75 95" stroke="#fff" stroke-opacity=".65" stroke-width="1.6" fill="none" stroke-linecap="round"/>
    <path d="M60 96.5 l6.2 3.6 v7.2 l-6.2 3.6 l-6.2 -3.6 v-7.2z" fill="url(#ig-core)" stroke="#ffe3a3" stroke-width=".9" filter="url(#ig-glow)"/>
    <circle cx="60" cy="103.7" r="1.7" fill="#fff8e6"/>
    <rect x="54" y="79" width="12" height="10" rx="3.5" fill="#3a97c4"/>
    <circle class="i-hand l" cx="29.5" cy="101" r="6.6" fill="url(#ig-body)" stroke="#5fdcff" stroke-width="1.1"/>
    <circle class="i-hand r" cx="90.5" cy="101" r="6.6" fill="url(#ig-body)" stroke="#5fdcff" stroke-width="1.1"/>
    <path d="M60 22 V10.5" stroke="#8fdcff" stroke-width="2.4" stroke-linecap="round"/>
    <circle class="i-tip" cx="60" cy="8" r="4.3" fill="#ffc857" filter="url(#ig-glow)"/>
    <g class="i-ear"><rect x="14.5" y="41" width="11.5" height="23" rx="5.75" fill="url(#ig-body)" stroke="#5fdcff" stroke-width="1.1"/><circle cx="20.25" cy="52.5" r="2.7" fill="#5fdcff" filter="url(#ig-soft)"/></g>
    <g class="i-ear"><rect x="94" y="41" width="11.5" height="23" rx="5.75" fill="url(#ig-body)" stroke="#5fdcff" stroke-width="1.1"/><circle cx="99.75" cy="52.5" r="2.7" fill="#5fdcff" filter="url(#ig-soft)"/></g>
    <rect x="22" y="19" width="76" height="65" rx="28" fill="url(#ig-shell)" stroke="#5fdcff" stroke-width="1.4"/>
    <path d="M35 27.5 Q48 21.5 62 22.5" stroke="#fff" stroke-width="3.2" stroke-linecap="round" fill="none" opacity=".8"/>
    <rect x="30" y="34" width="60" height="40" rx="18" fill="url(#ig-visor)" stroke="#2bb8ec" stroke-width="1"/>
    <path d="M36 40 Q44 36.5 52 37" stroke="#5fdcff" stroke-opacity=".35" stroke-width="1.6" stroke-linecap="round" fill="none"/>
    <ellipse cx="38.5" cy="63.5" rx="4.4" ry="2.5" fill="#ff8fb0" opacity=".5"/>
    <ellipse cx="81.5" cy="63.5" rx="4.4" ry="2.5" fill="#ff8fb0" opacity=".5"/>
    <g class="i-eyes" filter="url(#ig-glow)">
      <g class="i-eye l"><ellipse cx="47" cy="52" rx="5.5" ry="7.3" fill="var(--eye)"/><circle cx="49" cy="48.6" r="1.7" fill="#fff"/></g>
      <g class="i-eye r"><ellipse cx="73" cy="52" rx="5.5" ry="7.3" fill="var(--eye)"/><circle cx="75" cy="48.6" r="1.7" fill="#fff"/></g>
      <g class="i-happy" stroke="var(--eye)" stroke-width="3.2" stroke-linecap="round" fill="none"><path d="M41.5 54.5 Q47 45.5 52.5 54.5"/><path d="M67.5 54.5 Q73 45.5 78.5 54.5"/></g>
      <g class="i-closed" stroke="var(--eye)" stroke-width="2.6" stroke-linecap="round" fill="none"><path d="M41.5 53.5 Q47 56.5 52.5 53.5"/><path d="M67.5 53.5 Q73 56.5 78.5 53.5"/></g>
      <g class="i-x" stroke="#ff8a8a" stroke-width="2.8" stroke-linecap="round"><path d="M42.5 47.5 l9 9 M51.5 47.5 l-9 9 M68.5 47.5 l9 9 M77.5 47.5 l-9 9"/></g>
    </g>
    <path class="i-mouth" d="M55 64.5 Q60 69 65 64.5" stroke="var(--eye)" stroke-width="2.2" stroke-linecap="round" fill="none"/>
    <g class="i-eq" fill="var(--eye)"><rect x="52" y="62" width="2.6" height="6" rx="1.3"/><rect x="56.4" y="60.5" width="2.6" height="9" rx="1.3"/><rect x="60.8" y="61.5" width="2.6" height="7" rx="1.3"/><rect x="65.2" y="62.5" width="2.6" height="5" rx="1.3"/></g>
    <g class="i-think" fill="var(--eye)"><circle cx="54" cy="66" r="1.7"/><circle cx="60" cy="66" r="1.7"/><circle cx="66" cy="66" r="1.7"/></g>
    <g class="i-scan" clip-path="url(#ig-visor-clip)"><rect x="30" y="52" width="60" height="2.4" fill="#8fffe0" opacity=".85"/></g>
    <g class="i-zz" fill="#9fdcff" font-family="Orbitron, sans-serif" font-weight="700"><text x="88" y="28" font-size="10">z</text><text x="96" y="18" font-size="7.5">z</text></g>
  </g></g>
</svg>`;
}

export function characterEl({ size = "md", mood = "idle", cone = false } = {}) {
  const el = document.createElement("span");
  el.className = `inni ${size}`;
  el.dataset.mood = mood;
  el.innerHTML = characterSvg({ cone });
  return el;
}

export function characterHtml({ size = "md", mood = "idle", cone = false } = {}) {
  return `<span class="inni ${size}" data-mood="${mood}">${characterSvg({ cone })}</span>`;
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
  for (const el of document.querySelectorAll(".inni[data-live]")) el.dataset.mood = m;
}

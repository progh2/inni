// 말로 묻기(음성 인식)·소리 내어 읽기(TTS). 브라우저 기능을 쓴다.
// 음성 인식은 https 나 localhost 에서만 된다(NAS 를 http 로 열면 꺼진다 → README 의 HTTPS 안내).
import { toast } from "../lib/ui.js";
import { prefs } from "../app.js";
import { mood } from "./character.js";

const SR = window.SpeechRecognition || window.webkitSpeechRecognition;

export function canListen() {
  return Boolean(SR) && window.isSecureContext;
}

let active = null;

/** 한 번 듣고 글자로 돌려준다. onInterim 으로 중간 글자를 받는다. */
export function listen({ onInterim } = {}) {
  if (!canListen()) {
    toast(window.isSecureContext ? "이 브라우저는 음성 인식을 지원하지 않아요(크롬·엣지·사파리 권장)" : "음성 인식은 https 주소에서만 됩니다(시스템 → 도움말의 HTTPS 안내 참고)", { tone: "warn" });
    return Promise.resolve("");
  }
  if (active) { active.stop(); return Promise.resolve(""); }
  return new Promise((resolve) => {
    const rec = new SR();
    active = rec;
    rec.lang = "ko-KR";
    rec.interimResults = true;
    rec.maxAlternatives = 1;
    let finalText = "";
    mood("listen");
    rec.onresult = (ev) => {
      let interim = "";
      for (const r of ev.results) {
        if (r.isFinal) finalText += r[0].transcript;
        else interim += r[0].transcript;
      }
      if (onInterim) onInterim(finalText || interim);
    };
    rec.onerror = (ev) => {
      if (ev.error === "not-allowed") toast("마이크 권한이 막혀 있어요. 주소창의 자물쇠 아이콘에서 마이크를 허용하세요.", { tone: "warn" });
      else if (ev.error !== "no-speech" && ev.error !== "aborted") toast(`음성 인식 실패: ${ev.error}`, { tone: "warn" });
    };
    rec.onend = () => {
      active = null;
      mood("idle");
      resolve(finalText.trim());
    };
    try { rec.start(); } catch { active = null; mood("idle"); resolve(""); }
  });
}

export function stopListening() {
  if (active) active.stop();
}

const plain = (t) => String(t || "").replace(/\*\*(.+?)\*\*/g, "$1").replace(/`([^`]+)`/g, "$1").replace(/^\s*[-•*]\s+/gm, "").replace(/›/g, ",");

export function speak(text, { force = false } = {}) {
  if ((!prefs.voice && !force) || !("speechSynthesis" in window)) return;
  try {
    speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(plain(text).slice(0, 500));
    u.lang = "ko-KR";
    u.rate = 1.05;
    u.pitch = 1.15;
    const voices = speechSynthesis.getVoices();
    const v = voices.find((x) => x.name === prefs.voiceName) || voices.find((x) => /^ko/i.test(x.lang));
    if (v) u.voice = v;
    u.onstart = () => mood("talk");
    u.onend = () => mood("idle");
    speechSynthesis.speak(u);
  } catch { /* 목소리 기능 없음 */ }
}

export function koreanVoices() {
  if (!("speechSynthesis" in window)) return [];
  return speechSynthesis.getVoices().filter((v) => /^ko/i.test(v.lang));
}

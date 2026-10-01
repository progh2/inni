// AI 보급관 "이니" 대화창. 아래 오른쪽의 이니를 누르거나 E 를 누르면 열린다.
// AI 가 연결되어 있으면 서버의 모델이 찾기·제안 카드·화면 이동을 하고, 없으면 규칙 명령으로 돕는다.
// 무엇을 바꾸는 일은 제안 카드의 버튼을 눌러야만 진행된다(기존 API·권한 그대로).
import { $, esc, icon, prefersReducedMotion, thumb, statusChip, local } from "../lib/util.js";
import { api } from "../lib/api.js";
import { toast, confirmDialog } from "../lib/ui.js";
import { state, can, on as onStore } from "../lib/store.js";
import { app, prefs, savePrefs } from "../app.js";
import * as sfx from "../lib/sfx.js";
import { characterHtml, mood, setBaseMood, preloadCharacter } from "./character.js";
import { listen, canListen, speak, stopListening } from "./voice.js";
import { runLocalCommand } from "../features/commands.js";
import { undoToast } from "../features/actions.js";

const HISTORY_KEY = "inni.chat.history";
const SEEN_KEY = "inni.chat.seen";
const ALLOWED = new Set(["/api/actions/move", "/api/actions/loan", "/api/actions/return", "/api/actions/use", "/api/actions/restock", "/api/repairs"]);

const CHIPS = {
  bridge: ["오늘 챙길 일 알려줘", "멀티미터 어디 있어?", "재고 부족한 거 보여줘"],
  search: ["연체된 거 보여줘", "노후 장비 보여줘", "초성으로 찾는 법 알려줘"],
  decks: ["공구실에 뭐 있어?", "전자실습실 지도에서 보여줘"],
  dock: ["연체된 대여 누구야?", "노트북 반납 받았어", "드릴 박학생한테 내일까지 빌려줘"],
  item: ["이거 누가 빌려 갔어?", "이거 공구실로 옮겨 줘", "이거 고장 신고해 줘"],
  audit: ["실사는 어떻게 해?", "공구실 실사 시작하려면?"],
  repair: ["급한 고장 신고 뭐야?", "수리비 합계 알려줘"],
  log: ["오늘 누가 뭘 빌려 갔어?", "최근에 옮긴 것 알려줘"],
  crew: ["승인 기다리는 사람 있어?"],
  systems: ["AI 연결은 어떻게 해?", "백업은 어떻게 해?"],
};

const TIPS = {
  search: "이름·초성(ㅁㅌㅁㅌ)·관리번호·장소로 찾을 수 있어요. 위의 칩으로 재고 부족·연체만 볼 수도 있어요.",
  decks: "장소를 누르면 그곳의 물건이 보여요. 실·선반 라벨을 인쇄해 붙이면 스캔으로 바로 열려요.",
  dock: "대여 데스크에서는 빌리는 사람을 고르고 스캔만 하면 대여·반납이 번갈아 처리돼요.",
  item: "큰 버튼 한 번이면 옮기기·빌려주기·사용이 끝나요. 실수하면 알림의 [되돌리기]를 누르세요.",
  audit: "장소를 골라 실사를 시작하고, 라벨을 찍거나 눌러서 확인하세요. 끝나면 없는 것만 모아 보여 드려요.",
  systems: () => (state.ai.available
    ? "백업·이전 탭의 백업 파일(zip) 하나에 모든 게 들어 있어요. 다른 NAS로 옮길 때도 그 파일만 복원하면 돼요."
    : "AI 코어에서 제 두뇌(모델)를 연결하면 사진으로 이름 맞히기와 말로 시키기가 돼요."),
};

function inline(s) {
  return s.replace(/\*\*(.+?)\*\*/g, "<b>$1</b>").replace(/`([^`]+)`/g, "<code>$1</code>");
}
export function renderMarkdown(text) {
  let html = "";
  let list = false;
  for (const raw of esc(text).split("\n")) {
    const item = /^\s*[-•*]\s+(.*)$/.exec(raw);
    if (item) {
      if (!list) { html += "<ul>"; list = true; }
      html += `<li>${inline(item[1])}</li>`;
      continue;
    }
    if (list) { html += "</ul>"; list = false; }
    if (raw.trim()) html += `<p>${inline(raw)}</p>`;
  }
  if (list) html += "</ul>";
  return html;
}
const plain = (t) => String(t || "").replace(/\*\*(.+?)\*\*/g, "$1").replace(/`([^`]+)`/g, "$1");

function loadHistory() {
  try {
    const list = JSON.parse(sessionStorage.getItem(HISTORY_KEY) || "[]");
    return Array.isArray(list) ? list.filter((m) => m && (m.role === "user" || m.role === "assistant") && typeof m.content === "string") : [];
  } catch { return []; }
}

export function createInni({ getStation }) {
  const name = () => state.ai.name || "이니";
  let history = loadHistory();
  let busy = false;
  let openState = false;
  let controller = null;
  let pending = "";
  let unread = 0;
  let bubbleTimer = null;
  let tipTimer = null;
  let lastPeek = 0;
  const seen = new Set(local.get("seenTips", []));

  // ---- 런처
  const launch = document.createElement("button");
  launch.type = "button";
  launch.className = "inni-launch";
  launch.setAttribute("aria-haspopup", "dialog");
  launch.setAttribute("aria-expanded", "false");
  launch.innerHTML = `${characterHtml({ size: "sm" })}<span class="lb"><b>${esc(name())}</b><small>보급관 AI · 묻기 E</small></span><span class="badge" hidden>0</span>`;
  launch.querySelector(".inni").dataset.live = "1";
  document.body.appendChild(launch);
  preloadCharacter();
  // 휴대폰: 아래로 훑어 내리는 동안은 런처가 비켜 준다(아래쪽 버튼을 가리지 않게). 멈추거나 올리면 돌아온다
  {
    const last = new WeakMap();
    let back = null;
    document.addEventListener("scroll", (ev) => {
      if (!window.matchMedia("(max-width: 760px)").matches) return;
      const t = ev.target === document ? document.scrollingElement : ev.target;
      if (!t || typeof t.scrollTop !== "number") return;
      const prev = last.get(t) ?? t.scrollTop;
      last.set(t, t.scrollTop);
      if (t.scrollTop - prev > 4) launch.classList.add("tuck");
      else if (prev - t.scrollTop > 4) launch.classList.remove("tuck");
      clearTimeout(back);
      back = setTimeout(() => launch.classList.remove("tuck"), 900);
    }, { capture: true, passive: true });
  }
  const badge = launch.querySelector(".badge");

  // ---- 말풍선
  const bubble = document.createElement("div");
  bubble.className = "inni-bubble";
  bubble.hidden = true;
  bubble.setAttribute("role", "status");
  document.body.appendChild(bubble);

  // ---- 대화창
  const panel = document.createElement("section");
  panel.className = "inni-panel";
  panel.hidden = true;
  panel.setAttribute("role", "dialog");
  panel.setAttribute("aria-label", `${name()}와 대화`);
  panel.innerHTML = `
    <header class="inni-h">
      ${characterHtml({ size: "md", face: true })}
      <div class="inni-title"><span class="code">QUARTERMASTER AI · INNI</span><b>AI 보급관 ${esc(name())}</b><span class="inni-status" id="inni-status"></span></div>
      <div class="inni-tools">
        <button class="icon-btn" type="button" data-b="voice" aria-pressed="false" title="답을 소리 내어 읽기">${icon("volume")}</button>
        <button class="icon-btn" type="button" data-b="new" title="새 대화">${icon("refresh")}</button>
        <button class="icon-btn" type="button" data-b="close" title="닫기 (Esc)">${icon("x")}</button>
      </div>
    </header>
    <div class="inni-log" aria-live="polite"></div>
    <div class="inni-chips"></div>
    <form class="inni-input">
      <textarea rows="1" placeholder="예: 멀티미터 어디 있어? · 드릴 박학생한테 내일까지 빌려줘" aria-label="${esc(name())}에게 보낼 말"></textarea>
      ${canListen() ? `<button class="icon-btn" type="button" data-b="mic" title="말로 하기">${icon("mic")}</button>` : ""}
      <button class="btn primary" type="submit" title="보내기 (Enter)">${icon("send")}</button>
    </form>
    <div class="inni-foot"></div>`;
  document.body.appendChild(panel);
  panel.querySelector(".inni").dataset.live = "1";
  const log = panel.querySelector(".inni-log");
  const input = panel.querySelector("textarea");
  const sendBtn = panel.querySelector('button[type="submit"]');
  const statusEl = panel.querySelector("#inni-status");

  function paintStatus() {
    const ai = state.ai;
    let s;
    if (ai.available) {
      const cfg = ai.admin;
      s = cfg ? (() => {
        const c = cfg.connections.find((x) => x.id === cfg.chat.connection_id);
        return `${c ? c.name : "AI"} · ${cfg.chat.model}${c && c.external && cfg.mask_names ? " · 이름 가림" : ""}`;
      })() : "AI 연결됨";
      setBaseMood("idle");
    } else {
      s = "기본 명령 모드 (AI 미연결)";
      setBaseMood("idle");
    }
    statusEl.textContent = s;
    panel.querySelector(".inni-foot").textContent = ai.available
      ? "무엇을 바꾸는 일은 카드의 버튼을 눌러야 진행돼요."
      : `AI 가 없어도 "찾기·옮기기·빌려주기·사용·입고·화면 열기" 명령은 알아들어요.${can("system") ? " 시스템 → AI 코어에서 연결하면 더 똑똑해져요." : ""}`;
    launch.querySelector("small").textContent = ai.available ? "보급관 AI · 묻기 E" : "명령하기 · E";
  }
  onStore("ai", paintStatus);
  paintStatus();

  // ---------------------------------------------------------------- 기록
  const saveHistory = () => { try { sessionStorage.setItem(HISTORY_KEY, JSON.stringify(history.slice(-40))); } catch { /* 없음 */ } };
  const scrollDown = () => { log.scrollTop = log.scrollHeight; };
  function addNode(cls, html) {
    const el = document.createElement("div");
    el.className = cls;
    el.innerHTML = html;
    log.appendChild(el);
    scrollDown();
    return el;
  }
  const addUser = (t) => addNode("msg user", `<div class="bub">${esc(t).replace(/\n/g, "<br>")}</div>`);
  function addBot(text, { typing = false } = {}) {
    const el = addNode("msg bot", `<div class="bub"></div>`);
    const bub = el.querySelector(".bub");
    const html = renderMarkdown(text);
    if (!typing || prefersReducedMotion() || document.body.classList.contains("reduce-motion") || text.length > 700) {
      bub.innerHTML = html;
      scrollDown();
      return el;
    }
    const flat = plain(text);
    let i = 0;
    bub.classList.add("typing");
    mood("talk");
    const step = () => {
      i = Math.min(flat.length, i + 3);
      bub.textContent = flat.slice(0, i);
      if (i % 6 === 0) sfx.play("type");
      scrollDown();
      if (i < flat.length) requestAnimationFrame(step);
      else { bub.classList.remove("typing"); bub.innerHTML = html; scrollDown(); mood("idle"); }
    };
    requestAnimationFrame(step);
    return el;
  }
  const addNote = (t, tone = "info") => addNode(`note ${tone}`, `${icon(tone === "crit" ? "warn" : "bolt")}<span>${esc(t)}</span>`);

  function addFound(items) {
    if (!items || !items.length) return;
    const el = addNode("found", items.slice(0, 4).map((x, i) => `<button class="irow" type="button" data-i="${i}">${thumb(x, "sm")}<span class="tx"><span class="nm">${esc(x.name)}</span><span class="sub">${esc(x.where && x.where[0] ? x.where[0].path : x.status_label)}</span></span>${statusChip(x.status, x.status_label)}</button>`).join(""));
    el.querySelectorAll("[data-i]").forEach((b) => { b.onclick = () => app.openItem(items[Number(b.dataset.i)].id, items[Number(b.dataset.i)].match_unit_id); });
  }

  function welcome() {
    log.innerHTML = "";
    if (history.length) {
      for (const m of history.slice(-20)) {
        if (m.role === "user") addUser(m.content);
        else if (/^\((실행|취소)/.test(m.content)) addNote(m.content);
        else addBot(m.content);
      }
      return;
    }
    const me = state.me ? state.me.name : "";
    addBot(state.ai.available
      ? `안녕하세요${me ? `, ${me} 선생님` : ""}! 보급관 **${name()}**예요. 물건을 찾아 드리고, 옮기기·빌려주기·반납·입출고를 카드로 준비해 드릴게요. 말로 하셔도 돼요.`
      : `안녕하세요${me ? `, ${me} 선생님` : ""}! 보급관 **${name()}**예요. 지금은 AI 두뇌 없이 기본 명령만 알아들어요.\n- "멀티미터 어디 있어?"\n- "공구실에 뭐 있어?"\n- "드릴 2대 전자실습실로 옮겨"\n- "실납 3롤 썼어" · "재고 부족 보여줘"`);
  }

  function renderChips() {
    const box = panel.querySelector(".inni-chips");
    const list = CHIPS[getStation()] || CHIPS.bridge;
    box.innerHTML = list.map((q) => `<button class="chip" type="button" data-q="${esc(q)}">${esc(q)}</button>`).join("");
  }
  panel.querySelector(".inni-chips").addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-q]");
    if (b) ask(b.dataset.q);
  });

  // ---------------------------------------------------------------- 제안 카드
  function addProposal(p) {
    const tone = p.tone === "warn" ? "warn" : "primary";
    const el = addNode(`proposal ${tone}`, `
      <div class="p-h"><span class="code">PROPOSAL · 확인해 주세요</span><b>${esc(p.title)}</b></div>
      ${p.summary ? `<div class="p-sum">${esc(p.summary)}</div>` : ""}
      ${(p.lines || []).length ? `<ul class="p-lines">${p.lines.map((l) => `<li>${esc(l)}</li>`).join("")}</ul>` : ""}
      <div class="p-actions"><button class="btn ghost sm" type="button" data-act="cancel">취소</button>
        <button class="btn ${p.kind === "loan" || p.kind === "use" ? "amber" : "primary"} sm" type="button" data-act="run">${esc(p.confirm || "실행")}</button></div>
      <div class="p-result" hidden></div>`);
    const run = el.querySelector('[data-act="run"]');
    const result = el.querySelector(".p-result");
    const finish = (cls, html) => {
      el.classList.add(cls);
      el.querySelectorAll(".p-actions button").forEach((b) => { b.disabled = true; });
      result.hidden = false;
      result.innerHTML = html;
      scrollDown();
    };
    el.querySelector('[data-act="cancel"]').onclick = () => {
      finish("cancelled", "취소했어요. 아무것도 바뀌지 않았어요.");
      history.push({ role: "assistant", content: `(취소함) ${p.title}` });
      saveHistory();
      sfx.play("close");
    };
    run.onclick = async () => {
      if (!p.request || !ALLOWED.has(p.request.path)) { finish("failed", "알 수 없는 작업이라 실행하지 않았어요."); return; }
      if (p.tone === "danger") {
        const ok = await confirmDialog({ title: p.title, message: p.summary || "진행할까요?", confirmLabel: p.confirm || "실행", tone: "danger" });
        if (!ok) return;
      }
      run.classList.add("busy");
      run.disabled = true;
      try {
        const out = await api(p.request.path, { method: "POST", body: p.request.body, via: "ai" });
        const events = out.events || (out.event ? [out.event] : []);
        const text = events.length ? (events.length === 1 ? events[0].summary : `${events.length}건 처리`) : "처리했어요";
        finish("done", `${icon("check")} ${esc(text)}${p.after ? ' <button class="btn xs" type="button" data-go>화면에서 보기</button>' : ""}`);
        const gb = result.querySelector("[data-go]");
        if (gb) gb.onclick = () => app.go(p.after.station, p.after.params || {});
        history.push({ role: "assistant", content: `(실행 완료) ${p.title} — ${text}` });
        saveHistory();
        if (events.length) undoToast(text, events, { title: `${name()} 제안 실행`, sound: p.kind === "move" ? "lock" : p.kind === "loan" ? "out" : p.kind === "return" ? "in" : "ok" });
        else { sfx.play("ok"); mood("happy", 1800); }
      } catch (e) {
        finish("failed", `${icon("warn")} ${esc(e.message)}`);
        history.push({ role: "assistant", content: `(실행 실패) ${p.title} — ${e.message}` });
        saveHistory();
        sfx.play("error");
        mood("error", 2500);
      } finally { run.classList.remove("busy"); }
    };
  }

  // ---------------------------------------------------------------- 묻기
  function setBusy(on) {
    busy = on;
    sendBtn.innerHTML = on ? icon("stop") : icon("send");
    sendBtn.classList.toggle("danger", on);
    sendBtn.classList.toggle("primary", !on);
    mood(on ? "think" : "idle");
  }

  function context() {
    const st = getStation();
    const ctx = {};
    if (st === "item" && app.currentItem) ctx.item_id = app.currentItem.id;
    if (st === "decks" && app.currentLocation) ctx.location_id = app.currentLocation;
    return ctx;
  }

  async function ask(question) {
    const q = String(question || "").trim();
    if (!q) return;
    if (busy) {
      pending = q;
      input.value = "";
      addNote(`"${q.slice(0, 40)}" — 지금 답이 끝나면 이어서 볼게요.`);
      return;
    }
    if (!openState) open();
    if (log.querySelector(".msg.bot:only-child") && !history.length) log.innerHTML = "";
    input.value = "";
    autosize();
    addUser(q);
    const past = history.slice(-12);
    history.push({ role: "user", content: q });
    saveHistory();
    sfx.play("send");

    if (!state.ai.available) {
      setBusy(true);
      try {
        const out = await runLocalCommand(q);
        addBot(out.reply, { typing: true });
        addFound(out.items);
        history.push({ role: "assistant", content: out.reply });
        saveHistory();
        sfx.play("inni");
        speak(out.reply);
      } catch (e) {
        addNote(e.message, "crit");
      } finally { setBusy(false); }
      return;
    }

    setBusy(true);
    const think = addNode("thinking", `<span class="dots"><i></i><i></i><i></i></span><span class="lbl">질문을 읽는 중…</span>`);
    const trace = addNode("trace", "");
    trace.hidden = true;
    controller = new AbortController();
    let proposals = 0;
    const started = performance.now();
    try {
      await api.stream("/api/ai/chat", { question: q, history: past, station: getStation(), context: context() }, (ev, data) => {
        if (ev === "status") {
          think.querySelector(".lbl").textContent = `${data.label}…`;
          if (data.phase === "thinking") sfx.play("think");
          if (data.phase === "tool") mood("scan");
        } else if (ev === "tool") {
          trace.hidden = false;
          trace.insertAdjacentHTML("beforeend", `<div><span class="led ${data.ok ? "info" : "warn"}"></span>${esc(data.label)}${data.summary ? ` — ${esc(data.summary)}` : ""}</div>`);
          scrollDown();
        } else if (ev === "notice") addNote(data.text, "warn");
        else if (ev === "proposal") {
          proposals += 1;
          addProposal(data);
          log.appendChild(think);
          sfx.play("proposal");
          mood("alert", 1200);
        } else if (ev === "navigate") {
          app.go(data.station, data.params || {});
        } else if (ev === "action") {
          if (data.type === "open_add") app.addItem(data.draft || {});
          else if (data.type === "highlight" && app.scene) app.scene.highlight(data.location_ids || []);
        } else if (ev === "found") {
          if (data.location_ids && app.scene) app.scene.highlight(data.location_ids);
        } else if (ev === "message") {
          think.remove();
          addBot(data.text, { typing: true });
          history.push({ role: "assistant", content: data.text });
          saveHistory();
          sfx.play("inni");
          speak(data.text);
          if (!proposals) mood("happy", 1500);
        } else if (ev === "usage") {
          const secs = ((data.elapsed_ms || performance.now() - started) / 1000).toFixed(1);
          if (can("system")) addNode("meta", `${esc(data.model || "")} · ${secs}초 · 토큰 ${Number(data.prompt_tokens || 0).toLocaleString("ko-KR")}+${Number(data.completion_tokens || 0).toLocaleString("ko-KR")}`);
        } else if (ev === "error") {
          think.remove();
          addNote(data.message || "답하지 못했어요", "crit");
          sfx.play("error");
          mood("error", 2500);
        }
      }, { signal: controller.signal });
    } catch (e) {
      if (e.name === "AbortError") addNote("질문을 멈췄어요.");
      else {
        // AI 가 안 되면 기본 명령으로라도 돕는다
        addNote(`${e.message} — 기본 명령으로 해 볼게요.`, "crit");
        try {
          const out = await runLocalCommand(q);
          addBot(out.reply);
          addFound(out.items);
        } catch { /* 무시 */ }
      }
    } finally {
      think.remove();
      if (!trace.childElementCount) trace.remove();
      controller = null;
      setBusy(false);
      if (!openState) { unread += 1; paintBadge(); }
    }
    if (pending) {
      const next = pending;
      pending = "";
      ask(next);
    }
  }

  // ---------------------------------------------------------------- 말하기·듣기
  const voiceBtn = panel.querySelector('[data-b="voice"]');
  const paintVoice = () => voiceBtn.setAttribute("aria-pressed", String(Boolean(prefs.voice)));
  voiceBtn.onclick = () => {
    prefs.voice = !prefs.voice;
    savePrefs();
    paintVoice();
    if (!prefs.voice && "speechSynthesis" in window) speechSynthesis.cancel();
    toast(prefs.voice ? `${name()}가 답을 소리 내어 읽어요` : "목소리를 껐어요", { timeout: 1800, sound: false });
  };
  const mic = panel.querySelector('[data-b="mic"]');
  if (mic) {
    mic.onclick = async () => {
      if (mic.classList.contains("listening")) { stopListening(); return; }
      mic.classList.add("listening");
      try {
        const text = await listen({ onInterim: (t) => { input.value = t; autosize(); } });
        if (text) ask(text);
      } finally { mic.classList.remove("listening"); }
    };
  }

  // ---------------------------------------------------------------- 열고 닫기
  function paintBadge() {
    badge.hidden = !unread;
    badge.textContent = String(unread);
  }
  function open(prefill) {
    if (!openState) {
      openState = true;
      panel.hidden = false;
      panel.classList.remove("closing");
      document.body.classList.add("inni-open");
      launch.setAttribute("aria-expanded", "true");
      hideBubble();
      unread = 0;
      paintBadge();
      if (!log.childElementCount) welcome();
      renderChips();
      paintVoice();
      sfx.play("open");
    }
    if (prefill) { input.value = prefill; autosize(); }
    setTimeout(() => input.focus(), 60);
  }
  function close() {
    if (!openState) return;
    openState = false;
    launch.setAttribute("aria-expanded", "false");
    document.body.classList.remove("inni-open");
    sfx.play("close");
    if (document.body.classList.contains("reduce-motion")) { panel.hidden = true; return; }
    panel.classList.add("closing");
    setTimeout(() => { if (!openState) { panel.hidden = true; panel.classList.remove("closing"); } }, 200);
  }
  launch.onclick = () => (openState ? close() : open());
  panel.querySelector('[data-b="close"]').onclick = () => { close(); launch.focus(); };
  panel.querySelector('[data-b="new"]').onclick = () => {
    pending = "";
    if (busy && controller) controller.abort();
    history = [];
    saveHistory();
    welcome();
    renderChips();
    input.focus();
  };
  panel.addEventListener("keydown", (ev) => { if (ev.key === "Escape") { ev.stopPropagation(); close(); launch.focus(); } });
  function autosize() {
    input.style.height = "auto";
    input.style.height = `${Math.min(140, input.scrollHeight)}px`;
  }
  input.addEventListener("input", autosize);
  input.addEventListener("keydown", (ev) => {
    if (ev.key === "Enter" && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); ask(input.value); }
  });
  panel.querySelector("form").addEventListener("submit", (ev) => {
    ev.preventDefault();
    if (busy && controller) controller.abort();
    else ask(input.value);
  });

  // ---------------------------------------------------------------- 먼저 알려 주기
  function hideBubble() {
    clearTimeout(bubbleTimer);
    bubble.hidden = true;
  }
  function notify({ level = "info", text, ask: q = "", go = null, timeout = 11000, force = false, kind = "" } = {}) {
    if (!prefs.proactive && !force) return;
    if (openState) return;
    clearTimeout(bubbleTimer);
    bubble.className = `inni-bubble ${level}`;
    bubble.dataset.kind = kind;
    bubble.innerHTML = `<div class="who">AI 보급관 ${esc(name())}</div><div class="txt">${renderMarkdown(text)}</div>
      <div class="acts">${q ? `<button class="btn xs primary" type="button" data-b="ask">${esc(name())}에게 맡기기</button>` : ""}
      ${go ? `<button class="btn xs" type="button" data-b="go">보러 가기</button>` : ""}
      <button class="btn xs ghost" type="button" data-b="x">닫기</button></div>`;
    bubble.hidden = false;
    bubble.querySelectorAll("[data-b]").forEach((b) => {
      b.onclick = () => {
        hideBubble();
        if (b.dataset.b === "ask") ask(q);
        if (b.dataset.b === "go" && go) app.go(go.station, go.params || {});
      };
    });
    if (level === "crit" || level === "warn") mood("alert", 3000);
    else mood("happy", 1200);
    sfx.play("inni");
    if (timeout) bubbleTimer = setTimeout(hideBubble, timeout);
  }

  // 시작할 때 브리핑(규칙만, AI 부르지 않음).
  // 함교에는 같은 브리핑 카드가 있으니 말풍선은 띄우지 않는다(두 번 말하지 않게)
  function greet() {
    if (getStation() === "bridge") return;
    const today = new Date().toISOString().slice(0, 10);
    if (window.matchMedia("(max-width: 760px)").matches && local.get("greeted") === today) return;
    local.set("greeted", today);
    const a = state.alerts.alerts;
    const me = state.me.name;
    const hour = new Date().getHours();
    const hi = hour < 11 ? "좋은 아침이에요" : hour < 17 ? "안녕하세요" : "오늘도 수고 많으셨어요";
    if (!a.length) { notify({ text: `${hi}, ${me} 선생님! 모든 화물이 제자리에 있어요. 찾을 물건이 있으면 말씀만 하세요.`, timeout: 7000 }); return; }
    const top = a[0];
    notify({
      level: top.level === "crit" ? "crit" : top.level === "warn" ? "warn" : "info",
      text: `${hi}, ${me} 선생님! **${top.title}**${a.length > 1 ? ` 외 ${a.length - 1}건` : ""}이 있어요.${top.detail ? `\n${top.detail}` : ""}`,
      go: top.go, ask: state.ai.available ? "오늘 챙길 일 알려줘" : "", timeout: 14000,
    });
  }

  function setStation(id) {
    if (openState) renderChips();
    // 앞 화면의 도움말은 새 화면에 남기지 않는다
    clearTimeout(tipTimer);
    if (!bubble.hidden && bubble.dataset.kind === "tip") hideBubble();
    if (busy) return;
    launch.classList.remove("hop");
    void launch.offsetWidth;
    launch.classList.add("hop");
    if (TIPS[id] && !seen.has(id) && prefs.proactive && !window.matchMedia("(max-width: 760px)").matches) {
      seen.add(id);
      local.set("seenTips", [...seen]);
      const text = typeof TIPS[id] === "function" ? TIPS[id]() : TIPS[id];
      tipTimer = setTimeout(() => notify({ text, timeout: 7000, kind: "tip" }), 700);
    }
  }

  // 다른 사람이 한 일을 작게 알린다(1분에 한 번까지)
  function peek(text) {
    if (openState || !bubble.hidden || Date.now() - lastPeek < 60000) return;
    lastPeek = Date.now();
    notify({ text, timeout: 4500 });
  }

  // 화면 알림에 표정으로 반응
  window.addEventListener("inni:toast", (ev) => {
    if (busy) return;
    const tone = ev.detail && ev.detail.tone;
    if (tone === "good") mood("happy", 1500);
    else if (tone === "crit") mood("error", 2500);
  });

  return {
    open, close, ask, notify, greet, setStation, peek,
    toggle: () => (openState ? close() : open()),
    say(text, { items } = {}) {
      open();
      addBot(text, { typing: true });
      addFound(items);
      history.push({ role: "assistant", content: text });
      saveHistory();
      sfx.play("inni");
      speak(text);
    },
    get isOpen() { return openState; },
  };
}

export { $ };

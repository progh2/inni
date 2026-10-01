// 통합 검색창: 글자·초성·관리번호·장소를 치면 바로 결과가 뜨고, 마이크·스캔·이니로 이어진다.
import { $, esc, icon, debounce, thumb, statusChip, isMobile } from "../lib/util.js";
import { api } from "../lib/api.js";
import { app } from "../app.js";
import { state } from "../lib/store.js";
import { looksLikeCommand } from "../shared/intents.js";
import { runLocalCommand } from "./commands.js";
import { listen, canListen } from "../ai/voice.js";
import * as sfx from "../lib/sfx.js";

export function createOmnibox({ big = false, placeholder = "", onSearch = null } = {}) {
  const el = document.createElement("div");
  el.className = `omni${big ? " big" : ""}`;
  const mic = canListen();
  el.innerHTML = `
    <div class="omni-box">
      ${icon("search")}
      <input type="search" enterkeyhint="search" autocomplete="off" spellcheck="false" aria-label="찾기"
        placeholder="${esc(placeholder || "찾기 — 이름·초성·번호·장소, 말로 물어도 돼요")}">
      ${mic ? `<button class="icon-btn" type="button" data-b="mic" title="말로 묻기">${icon("mic")}</button>` : ""}
      <button class="icon-btn" type="button" data-b="scan" title="스캔">${icon("scan")}</button>
    </div>
    <div class="omni-drop" hidden role="listbox"></div>`;
  const input = el.querySelector("input");
  const drop = el.querySelector(".omni-drop");
  let rows = [];
  let sel = -1;
  let lastQ = "";
  let ctrl = null;

  function close() {
    drop.hidden = true;
    sel = -1;
  }

  function render(q, r) {
    const ask = looksLikeCommand(q);
    rows = [];
    let html = "";
    if (ask) {
      rows.push({ type: "ask", q });
      html += `<div class="omni-row ask" data-i="0">${icon("magic")}<div class="tx"><div class="nm">${esc(state.ai.name || "이니")}에게 맡기기</div><div class="wh">"${esc(q)}"</div></div><span class="kbd">Enter</span></div>`;
    }
    if (r && r.items.length) {
      html += `<div class="grp">CARGO · 물건 ${r.total > r.items.length ? `(${r.total})` : ""}</div>`;
      for (const it of r.items) {
        rows.push({ type: "item", it });
        const w = it.where[0];
        html += `<div class="omni-row" data-i="${rows.length - 1}">${thumb(it, "sm")}<div class="tx"><div class="nm">${esc(it.name)}${it.fuzzy ? ' <span class="tag muted">비슷</span>' : ""}</div>
          <div class="wh">${icon("pin")} ${esc(w ? w.path : it.status_label)}${it.where_more ? ` 외 ${it.where_more}곳` : ""}</div></div>${statusChip(it.status, it.status_label)}</div>`;
      }
    }
    if (r && r.locations.length) {
      html += `<div class="grp">DECK · 장소</div>`;
      for (const l of r.locations) {
        rows.push({ type: "loc", l });
        html += `<div class="omni-row" data-i="${rows.length - 1}"><span class="thumb sm blank">${icon("decks")}</span><div class="tx"><div class="nm">${esc(l.name)}${l.code ? ` <span class="tag amber">${esc(l.code)}</span>` : ""}</div><div class="wh">${esc(l.path)} · ${l.items}종</div></div></div>`;
      }
    }
    if (r && !r.items.length && !r.locations.length && !ask) {
      html += `<div class="empty" style="padding:14px">"${esc(q)}"에 맞는 것이 없어요.<br><span class="muted">초성(ㅁㅌㅁㅌ)이나 다른 이름으로 찾아 보세요.</span></div>`;
    }
    html += `<div class="omni-foot"><span>↑↓ 고르기 · Enter 열기</span><span>전체 결과: Enter 두 번</span></div>`;
    drop.innerHTML = html;
    drop.hidden = false;
    sel = rows.length ? 0 : -1;
    paintSel();
  }

  function paintSel() {
    drop.querySelectorAll(".omni-row").forEach((r) => r.setAttribute("aria-selected", String(Number(r.dataset.i) === sel)));
  }

  const lookup = debounce(async (q) => {
    if (ctrl) ctrl.abort();
    ctrl = new AbortController();
    try {
      const r = await api.get(`/api/search?q=${encodeURIComponent(q)}&limit=7`, { signal: ctrl.signal });
      if (input.value.trim() !== q) return;
      render(q, r);
    } catch (e) {
      if (e.name !== "AbortError") close();
    }
  }, 130);

  input.addEventListener("input", () => {
    const q = input.value.trim();
    lastQ = q;
    if (!q) { close(); return; }
    if (looksLikeCommand(q)) render(q, null);
    lookup(q);
  });
  input.addEventListener("focus", () => { if (input.value.trim() && drop.innerHTML) drop.hidden = false; });
  input.addEventListener("blur", () => setTimeout(close, 180));
  input.addEventListener("keydown", (ev) => {
    if (ev.isComposing) return;
    if (ev.key === "ArrowDown") { sel = Math.min(rows.length - 1, sel + 1); paintSel(); ev.preventDefault(); }
    else if (ev.key === "ArrowUp") { sel = Math.max(0, sel - 1); paintSel(); ev.preventDefault(); }
    else if (ev.key === "Escape") { close(); input.blur(); }
    else if (ev.key === "Enter") {
      ev.preventDefault();
      const q = input.value.trim();
      if (!q) return;
      if (!drop.hidden && sel >= 0 && rows[sel]) pick(rows[sel]);
      else submit(q);
    }
  });
  drop.addEventListener("mousedown", (ev) => ev.preventDefault());
  drop.addEventListener("click", (ev) => {
    const r = ev.target.closest(".omni-row");
    if (r) pick(rows[Number(r.dataset.i)]);
  });

  function pick(row) {
    close();
    if (!row) return;
    sfx.play("click");
    if (row.type === "ask") return ask(row.q);
    if (row.type === "item") {
      app.openItem(row.it.id, row.it.match_unit_id);
      if (app.scene) app.scene.highlight(row.it.where.map((w) => w.id));
    } else if (row.type === "loc") app.openLocation(row.l.id);
    input.value = "";
    input.blur();
  }

  function submit(q) {
    close();
    if (looksLikeCommand(q)) return ask(q);
    if (onSearch) onSearch(q);
    else app.go("search", { q });
    input.blur();
  }

  async function ask(q) {
    input.value = "";
    input.blur();
    if (state.ai.available && app.inni) {
      app.inni.ask(q);
      return;
    }
    const out = await runLocalCommand(q).catch((e) => ({ reply: e.message }));
    if (app.inni) app.inni.say(out.reply, { items: out.items });
  }

  el.querySelector('[data-b="scan"]').onclick = () => app.scanAndOpen();
  const micBtn = el.querySelector('[data-b="mic"]');
  if (micBtn) {
    micBtn.onclick = async () => {
      if (micBtn.classList.contains("listening")) return;
      micBtn.classList.add("listening");
      try {
        const text = await listen({ onInterim: (t) => { input.value = t; } });
        if (text) {
          input.value = text;
          ask(text);
        }
      } finally {
        micBtn.classList.remove("listening");
      }
    };
  }

  return {
    el,
    focus() {
      if (isMobile() && !el.closest(".station")) { app.go("search", { focus: "1" }); return; }
      input.focus();
      input.select();
    },
    set(q) { input.value = q || ""; },
    get value() { return input.value; },
    input,
  };
}

// 02 찾기: 치는 대로 바로 결과. 상태·종류·장소로 좁히고, 여러 개 골라 한꺼번에 옮기거나 라벨을 뽑는다.
import { $, esc, icon, debounce, thumb, statusChip, qty as fmtQty, KIND_LABEL, local, isMobile } from "../lib/util.js";
import { api } from "../lib/api.js";
import { modal, toast, toastError } from "../lib/ui.js";
import { state, can, locPath } from "../lib/store.js";
import { app } from "../app.js";
import { locationPicker } from "../features/pickers.js";
import * as actions from "../features/actions.js";
import { printLabels, labelsForItems } from "../features/labels.js";
import { listen, canListen } from "../ai/voice.js";
import { looksLikeCommand } from "../shared/intents.js";
import { runLocalCommand } from "../features/commands.js";

const STATUS = [["", "전체"], ["available", "쓸 수 있음"], ["on_loan", "대여 중"], ["overdue", "연체"], ["low", "재고 부족"], ["repair", "수리"], ["aging", "노후"], ["expiring", "유통기한"], ["favorite", "즐겨찾기"], ["archived", "보관함"]];
let root;
let f = { q: "", status: "", kind: "", loc: "", cat: "", sort: "" };
let view = local.get("searchView", "grid");
let items = [];
let total = 0;
let limit = 60;
let selecting = false;
const picked = new Set();
let ctrl = null;

function shell() {
  root.innerHTML = `
    <div class="st-inner">
      <div class="st-head"><div class="ttl"><span class="code">STATION 02 · SCANNER</span><h1>찾기</h1><p>이름·초성(ㅁㅌㅁㅌ)·관리번호·바코드·장소 이름 모두 됩니다. 말로 물어봐도 돼요.</p></div>
        <div class="tools">
          <div class="seg" data-view><button type="button" data-v="grid" aria-pressed="${view === "grid"}" title="사진으로">${icon("grid")}</button><button type="button" data-v="list" aria-pressed="${view === "list"}" title="목록으로">${icon("list")}</button></div>
          <button class="btn" type="button" data-b="select" aria-pressed="false">${icon("check")}여러 개 고르기</button>
          <a class="btn hide-mobile" href="/api/export/items.csv" data-b="csv">${icon("download")}CSV</a>
          ${can("register") ? `<button class="btn amber" type="button" data-b="add">${icon("plus")}등록</button>` : ""}
        </div></div>
      <div class="omni big" style="width:100%;margin-bottom:12px"><div class="omni-box">${icon("search")}
        <input type="search" data-q placeholder="찾을 물건 — 예: 멀티미터, ㅇㅅㄹ, 전장-2024-017, 공구실" enterkeyhint="search" autocomplete="off">
        ${canListen() ? `<button class="icon-btn" type="button" data-b="mic" title="말로 찾기">${icon("mic")}</button>` : ""}
        <button class="icon-btn" type="button" data-b="scan" title="스캔">${icon("scan")}</button></div></div>
      <div class="filterbar"><div class="scroll" data-status>${STATUS.map(([v, l]) => `<button class="chip ${v === "overdue" || v === "low" ? "warn" : ""}" type="button" data-v="${v}" aria-pressed="${f.status === v}">${l}</button>`).join("")}</div></div>
      <div class="filterbar">
        <div class="scroll" data-kind>${[["", "모든 종류"], ...Object.entries(KIND_LABEL)].map(([v, l]) => `<button class="chip" type="button" data-v="${v}" aria-pressed="${f.kind === v}">${l}</button>`).join("")}</div>
        <button class="chip" type="button" data-b="loc">${icon("pin")}<span data-locname>${f.loc ? esc(locPath(f.loc)) : "모든 장소"}</span></button>
        <select data-cat style="width:auto;min-height:34px"><option value="">모든 분류</option>${state.categories.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join("")}</select>
        <select data-sort style="width:auto;min-height:34px"><option value="">정렬: 알맞은 순</option><option value="name">이름순</option><option value="recent">최근 바뀐 순</option><option value="qty">적은 것부터</option></select>
      </div>
      <p class="count-line" data-count></p>
      <div data-results></div>
      <div data-more style="text-align:center;margin-top:12px"></div>
      <div data-cart></div>
    </div>`;
}

function card(x) {
  const w = x.where[0];
  const q = x.kind === "equipment" ? `${x.available}<small>/${x.units}대</small>` : `${fmtQty(x.qty)}<small>${esc(x.unit)}</small>`;
  const img = x.thumb || x.image;
  return `<button class="icard ${picked.has(x.id) ? "picked" : ""}" type="button" data-id="${x.id}" data-unit="${x.match_unit_id || ""}">
    <span class="ph">${img ? `<img src="/uploads/${esc(img)}" alt="" loading="lazy">` : `<span class="noimg">${icon(x.kind === "equipment" ? "tool" : x.kind === "fixture" ? "chair" : x.kind === "consumable" ? "drop" : "chip")}</span>`}
      <span class="kind tag">${esc(x.kind_label)}</span>${x.favorite ? `<svg class="fav"><use href="#i-star-fill"/></svg>` : ""}${selecting ? `<span class="sel">${picked.has(x.id) ? icon("check") : ""}</span>` : ""}</span>
    <span class="bd"><span class="nm">${esc(x.name)}</span>${x.manufacturer || x.model ? `<span class="mk">${esc([x.manufacturer, x.model].filter(Boolean).join(" · "))}</span>` : ""}
      <span class="wh">${icon("pin")}<span>${esc(w ? w.path : "위치 없음")}${x.where_more ? ` 외 ${x.where_more}` : ""}</span></span>
      <span class="ft">${statusChip(x.status, x.status_label)}<span class="q">${q}</span></span></span></button>`;
}

function row(x) {
  const w = x.where[0];
  return `<button class="irow ${picked.has(x.id) ? "sel" : ""}" type="button" data-id="${x.id}" data-unit="${x.match_unit_id || ""}" ${picked.has(x.id) ? 'style="border-color:var(--amber)"' : ""}>
    ${selecting ? `<input type="checkbox" ${picked.has(x.id) ? "checked" : ""} tabindex="-1">` : ""}${thumb(x, "sm")}
    <span class="tx"><span class="nm">${esc(x.name)} <span class="tag muted">${esc(x.kind_label)}</span></span><span class="sub">${icon("pin")} ${esc(w ? w.path : "위치 없음")}${x.where_more ? ` 외 ${x.where_more}` : ""}</span></span>
    <span class="end"><span class="num">${x.kind === "equipment" ? `${x.available}/${x.units}대` : `${fmtQty(x.qty)}${esc(x.unit)}`}</span>${statusChip(x.status, x.status_label)}</span></button>`;
}

function paint() {
  const box = root.querySelector("[data-results]");
  if (!items.length) {
    box.innerHTML = `<div class="empty">${f.q ? `"${esc(f.q)}"에 맞는 물건이 없어요.<br><span class="muted">초성(ㅁㅌㅁㅌ)이나 다른 이름으로 찾거나, 필터를 풀어 보세요.</span>` : "조건에 맞는 물건이 없어요."}
      ${can("register") && f.q ? `<div style="margin-top:12px"><button class="btn amber" type="button" data-b="add-q">${icon("plus")}"${esc(f.q)}" 새로 등록</button></div>` : ""}</div>`;
  } else {
    box.innerHTML = view === "grid" ? `<div class="item-grid">${items.map(card).join("")}</div>` : `<div class="list">${items.map(row).join("")}</div>`;
  }
  root.querySelector("[data-count]").innerHTML = `<b>${total}</b>개${f.q ? ` · "${esc(f.q)}"` : ""}${f.status ? ` · ${STATUS.find((s) => s[0] === f.status)[1]}` : ""}${f.loc ? ` · ${esc(locPath(f.loc))}` : ""}`;
  root.querySelector("[data-more]").innerHTML = items.length < total ? `<button class="btn" type="button" data-b="more">더 보기 (${total - items.length})</button>` : "";
  paintCart();
}

function paintCart() {
  const box = root.querySelector("[data-cart]");
  if (!selecting || !picked.size) { box.innerHTML = ""; return; }
  box.innerHTML = `<div class="cartbar"><span class="cnt">${picked.size}개 고름</span>
    ${can("move") ? `<button class="btn sm" type="button" data-c="move">${icon("move")}한꺼번에 옮기기</button>` : ""}
    <button class="btn sm" type="button" data-c="labels">${icon("print")}라벨 인쇄</button>
    <button class="btn sm ghost" type="button" data-c="all">모두 고르기</button>
    <button class="btn sm ghost" type="button" data-c="clear">${icon("x")}고르기 끝</button></div>`;
}

async function load({ append = false } = {}) {
  if (ctrl) ctrl.abort();
  ctrl = new AbortController();
  const qs = new URLSearchParams();
  if (f.q) qs.set("q", f.q);
  if (f.status) qs.set("status", f.status);
  if (f.kind) qs.set("kind", f.kind);
  if (f.loc) qs.set("location_id", f.loc);
  if (f.cat) qs.set("category_id", f.cat);
  if (f.sort) qs.set("sort", f.sort);
  qs.set("limit", String(limit));
  try {
    const r = await api.get(`/api/items?${qs}`, { signal: ctrl.signal });
    items = r.items;
    total = r.total;
    paint();
    if (app.scene && f.q && items.length && items.length <= 6) app.scene.highlight(items.flatMap((x) => x.where.map((w) => w.id)));
  } catch (e) { if (e.name !== "AbortError") toastError(e); }
}
const loadSoon = debounce(() => { limit = 60; load(); }, 160);

function syncHash() {
  const p = {};
  for (const [k, v] of Object.entries(f)) if (v) p[k] = v;
  history.replaceState(null, "", `#search${Object.keys(p).length ? `?${new URLSearchParams(p)}` : ""}`);
}

export default {
  mount(el) {
    root = el;
    shell();
    el.addEventListener("input", (ev) => {
      if (ev.target.matches("[data-q]")) { f.q = ev.target.value.trim(); syncHash(); loadSoon(); }
    });
    el.addEventListener("keydown", async (ev) => {
      if (ev.target.matches("[data-q]") && ev.key === "Enter" && !ev.isComposing) {
        const q = ev.target.value.trim();
        if (q && looksLikeCommand(q) && !items.length) {
          ev.preventDefault();
          if (state.ai.available) app.inni.ask(q);
          else { const out = await runLocalCommand(q); app.inni.say(out.reply, { items: out.items }); }
        } else if (items.length === 1) app.openItem(items[0].id, items[0].match_unit_id);
      }
    });
    el.addEventListener("change", (ev) => {
      if (ev.target.matches("[data-cat]")) { f.cat = ev.target.value; syncHash(); load(); }
      if (ev.target.matches("[data-sort]")) { f.sort = ev.target.value; syncHash(); load(); }
    });
    el.addEventListener("click", async (ev) => {
      const chip = ev.target.closest("[data-status] [data-v], [data-kind] [data-v]");
      if (chip) {
        const grp = chip.parentElement.hasAttribute("data-status") ? "status" : "kind";
        f[grp] = f[grp] === chip.dataset.v ? "" : chip.dataset.v;
        for (const b of chip.parentElement.querySelectorAll("[data-v]")) b.setAttribute("aria-pressed", String(b.dataset.v === f[grp]));
        syncHash();
        load();
        return;
      }
      const v = ev.target.closest("[data-view] [data-v]");
      if (v) {
        view = v.dataset.v;
        local.set("searchView", view);
        for (const b of root.querySelectorAll("[data-view] [data-v]")) b.setAttribute("aria-pressed", String(b.dataset.v === view));
        paint();
        return;
      }
      const b = ev.target.closest("[data-b]");
      if (b) {
        const k = b.dataset.b;
        if (k === "select") {
          selecting = !selecting;
          b.setAttribute("aria-pressed", String(selecting));
          if (!selecting) picked.clear();
          paint();
        } else if (k === "add") app.addItem({});
        else if (k === "add-q") app.addItem({ name: f.q });
        else if (k === "scan") app.scanAndOpen();
        else if (k === "more") { limit += 60; load(); }
        else if (k === "loc") {
          const body = document.createElement("div");
          const h = modal({ title: "장소로 좁히기", code: "DECK FILTER", size: "wide", body, actions: [{ label: "모든 장소", tone: "ghost", onClick: () => { f.loc = ""; root.querySelector("[data-locname]").textContent = "모든 장소"; syncHash(); load(); } }] });
          locationPicker(body, { onPick: (id) => { f.loc = id; root.querySelector("[data-locname]").textContent = locPath(id); h.close(); syncHash(); load(); } });
        } else if (k === "mic") {
          b.classList.add("listening");
          const input = root.querySelector("[data-q]");
          const text = await listen({ onInterim: (t) => { input.value = t; } });
          b.classList.remove("listening");
          if (!text) return;
          if (looksLikeCommand(text)) {
            if (state.ai.available) app.inni.ask(text);
            else { const out = await runLocalCommand(text); app.inni.say(out.reply, { items: out.items }); }
          } else { f.q = text; input.value = text; load(); }
        }
        return;
      }
      const c = ev.target.closest("[data-c]");
      if (c) {
        const k = c.dataset.c;
        if (k === "clear") { selecting = false; picked.clear(); root.querySelector('[data-b="select"]').setAttribute("aria-pressed", "false"); paint(); }
        else if (k === "all") { for (const x of items) picked.add(x.id); paint(); }
        else if (k === "labels") printLabels(await labelsForItems([...picked]));
        else if (k === "move") {
          const targets = [];
          for (const id of picked) {
            const d = await api.get(`/api/items/${id}`);
            for (const u of d.units) if (u.status !== "on_loan") targets.push({ asset_id: u.id });
            for (const s of d.stocks) if (s.quantity > 0) targets.push({ stock_id: s.id });
          }
          actions.moveMany({ targets, label: `고른 ${picked.size}가지(${targets.length}건)` });
        }
        return;
      }
      const it = ev.target.closest("[data-id]");
      if (it) {
        if (selecting) {
          if (picked.has(it.dataset.id)) picked.delete(it.dataset.id); else picked.add(it.dataset.id);
          paint();
          return;
        }
        app.openItem(it.dataset.id, it.dataset.unit || undefined);
      }
    });
  },
  enter(params = {}) {
    f = { q: params.q || "", status: params.status || "", kind: params.kind || "", loc: params.loc || params.location_id || "", cat: params.cat || "", sort: params.sort || "" };
    if (params.select === "1") selecting = true;
    shell();
    root.querySelector("[data-q]").value = f.q;
    root.querySelector("[data-cat]").value = f.cat;
    root.querySelector("[data-sort]").value = f.sort;
    root.querySelector('[data-b="select"]').setAttribute("aria-pressed", String(selecting));
    if (params.focus === "1" || (!isMobile() && !f.q)) setTimeout(() => root.querySelector("[data-q]").focus(), 350);
    limit = 60;
    return load();
  },
  refresh() { return load(); },
};

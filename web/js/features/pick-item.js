// "무엇을?" 고르기: 스캔하거나 이름으로 찾아 물건 하나를 고른다(빠른 작업 버튼용).
import { esc, icon, debounce, thumb, statusChip } from "../lib/util.js";
import { api } from "../lib/api.js";
import { modal } from "../lib/ui.js";
import { openScanner } from "./scanner.js";
import * as sfx from "../lib/sfx.js";

/**
 * @returns {Promise<{ id, unit_id, card } | null>}
 */
export function pickItem({ title = "무엇을?", hint = "라벨을 스캔하거나 이름으로 찾으세요", filter = null } = {}) {
  return new Promise((resolve) => {
    let out = null;
    const body = document.createElement("div");
    body.innerHTML = `
      <div class="row nw" style="margin-bottom:10px">
        <div class="search" style="flex:1">${icon("search")}<input type="search" placeholder="이름·초성·관리번호" autofocus></div>
        <button class="btn primary" type="button" data-b="scan">${icon("scan")}스캔</button></div>
      <p class="help">${esc(hint)}</p>
      <div class="list" data-out></div>`;
    const input = body.querySelector("input");
    const list = body.querySelector("[data-out]");
    let rows = [];
    const render = () => {
      list.innerHTML = rows.length ? rows.map((x, i) => `<button class="irow" type="button" data-i="${i}">${thumb(x, "sm")}<span class="tx"><span class="nm">${esc(x.name)}</span><span class="sub">${esc(x.where[0] ? x.where[0].path : "")}${x.kind === "equipment" ? ` · ${x.available}/${x.units}대 가능` : ` · ${x.qty}${esc(x.unit)}`}</span></span>${statusChip(x.status, x.status_label)}</button>`).join("")
        : input.value.trim() ? '<div class="empty">맞는 물건이 없어요</div>' : "";
    };
    const run = debounce(async () => {
      const q = input.value.trim();
      if (!q) { rows = []; render(); return; }
      try {
        const r = await api.get(`/api/search?q=${encodeURIComponent(q)}&limit=12`);
        rows = filter ? r.items.filter(filter) : r.items;
        render();
      } catch { /* 무시 */ }
    }, 150);
    input.addEventListener("input", run);
    list.addEventListener("click", (ev) => {
      const b = ev.target.closest("[data-i]");
      if (!b) return;
      const x = rows[Number(b.dataset.i)];
      sfx.play("click");
      out = { id: x.id, unit_id: x.match_unit_id || null, card: x };
      h.close();
    });
    const h = modal({ title, code: "SELECT CARGO", body, onClose: () => resolve(out) });
    body.querySelector('[data-b="scan"]').onclick = async () => {
      const code = await openScanner({ title, hint });
      if (!code) return;
      try {
        const hit = await api.get(`/api/scan/${encodeURIComponent(code)}`);
        if (hit.type === "location") { input.value = ""; return; }
        out = { id: hit.item_id || hit.id, unit_id: hit.type === "asset" ? hit.id : null, card: null };
        h.close();
      } catch (e) { list.innerHTML = `<div class="callout crit">${esc(e.message)}</div>`; }
    };
  });
}

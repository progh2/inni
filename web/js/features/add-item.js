// 새 물건 등록(과 정보 고치기). 사진 → 이름 → 장소 → 저장, 나머지는 "더 적기" 안에.
// 사진을 넣으면 이니(AI)가 이름·종류·제조사를 맞히고, 이름으로 제품 사진·정보를 찾거나 링크로 채울 수 있다.
import { $, esc, icon, debounce, local, KIND_LABEL, KIND_HINT, KIND_ICON, qty as fmtQty, thumb } from "../lib/util.js";
import { api } from "../lib/api.js";
import { modal, toast, toastError, busy } from "../lib/ui.js";
import { state, can, rememberLocation, recentLocations, locPath } from "../lib/store.js";
import { app } from "../app.js";
import * as sfx from "../lib/sfx.js";
import { mood } from "../ai/character.js";
import { locationPicker, stepperHtml, bindStepper } from "./pickers.js";
import { loadToCanvas, fetchRemote, uploadCanvas, toDataUrl, imageFromPaste, imageFromClipboard } from "./images.js";
import { editImage } from "./image-editor.js";
import { findProduct, importUrl } from "./product-finder.js";
import { openScanner } from "./scanner.js";
import { printLabels } from "./labels.js";

const UNITS = ["개", "대", "세트", "롤", "m", "kg", "박스", "장", "권"];
const MEMORY = "addMemory";

/**
 * draft: { name, kind, location_id, quantity, manufacturer, model, spec, aliases, barcode, category_id, image(주소) }
 * edit: itemDetail(고치기 모드)
 */
export function openAddItem(draft = {}, { edit = null } = {}) {
  if (!edit && !can("register")) { toast("새 물건을 등록할 권한이 없어요", { tone: "warn" }); return null; }
  const mem = local.get(MEMORY, {});
  const it = edit ? edit.item : null;
  const v = {
    name: draft.name ?? (it ? it.name : ""),
    kind: draft.kind || (it ? it.kind : mem.kind || "equipment"),
    location_id: draft.location_id || (it ? null : mem.location_id && state.locMap.has(mem.location_id) ? mem.location_id : (recentLocations(1)[0] || null)),
    quantity: draft.quantity ?? 1,
    category_id: draft.category_id ?? (it ? it.category_id : mem.category_id || ""),
    prefix: mem.prefix || (state.settings.numbering.default_prefix || ""),
  };
  const f = (k) => esc(draft[k] ?? (it ? it[k] ?? "" : ""));
  let photo = null; // 새 사진 캔버스
  let photoPath = it ? { image: it.image, thumb: it.thumb } : null;
  let aiFilled = null;

  const body = document.createElement("div");
  body.className = "add-grid";
  body.innerHTML = `
    <div>
      <div class="photo-drop" data-drop>${it && it.image ? `<img src="/uploads/${esc(it.image)}" alt="">` : `<div class="ph-empty">${icon("camera")}<b>사진을 찍거나 끌어다 놓으세요</b><span class="muted">PC 에서는 복사한 사진을 Ctrl+V 로 붙여넣어도 돼요</span></div>`}</div>
      <div class="photo-tools">
        <label class="btn">${icon("camera")}촬영<input type="file" accept="image/*" capture="environment" hidden data-file></label>
        <label class="btn">${icon("image")}사진<input type="file" accept="image/*" hidden data-file></label>
        <button class="btn" type="button" data-b="find">${icon("search")}제품 찾기</button>
        <button class="btn" type="button" data-b="link">${icon("link")}링크</button>
        <button class="btn" type="button" data-b="paste">${icon("paste")}붙여넣기</button>
        <button class="btn" type="button" data-b="edit" disabled>${icon("crop")}다듬기</button>
      </div>
      <div data-ai></div>
    </div>
    <div class="stack" style="gap:12px">
      <label class="field"><span>이름 <span class="req">*</span></span><input type="text" data-k="name" value="${esc(v.name)}" placeholder="예: 디지털 멀티미터" autocomplete="off" style="font-size:17px;font-weight:600" ${edit ? "" : "autofocus"}></label>
      <div data-dup></div>
      ${edit ? "" : `<div><div class="pick-sec" style="margin-top:0">종류</div><div class="kind-seg" data-kind>${Object.keys(KIND_LABEL).map((k) => `<button type="button" data-v="${k}" aria-pressed="${k === v.kind}">${icon(KIND_ICON[k])}${KIND_LABEL[k]}<small>${KIND_HINT[k]}</small></button>`).join("")}</div></div>
      <div><div class="pick-sec">둘 곳 <span class="req">*</span></div><div class="chips" data-locs></div><div data-picker hidden style="margin-top:8px"></div></div>
      <div><div class="pick-sec" data-qlabel>몇 대</div>${stepperHtml({ value: v.quantity, unit: "대", min: v.kind === "equipment" ? 1 : 0, id: "add-qty" })}
        <div data-numbering style="margin-top:8px"><label class="field"><span>관리번호 앞머리 <span class="hint">비우면 올해-순번</span></span><input type="text" data-prefix value="${esc(v.prefix)}" placeholder="예: 전장-2026-"></label><div class="num-preview" data-numprev></div></div></div>`}
      <details class="more" ${edit ? "open" : ""}><summary>더 적기 (제조사·규격·예산·내용연수…)</summary><div class="stack" style="gap:12px">
        <div><div class="pick-sec" style="margin-top:4px">분류</div><div class="chips" data-cats></div></div>
        <div class="form-grid">
          <label class="field"><span>제조사</span><input type="text" data-k="manufacturer" value="${f("manufacturer")}"></label>
          <label class="field"><span>모델</span><input type="text" data-k="model" value="${f("model")}"></label>
          <label class="field wide"><span>규격·설명</span><input type="text" data-k="spec" value="${f("spec")}" placeholder="예: 50MHz 4채널"></label>
          <label class="field"><span>단위</span><input type="text" data-k="unit" value="${esc(it ? it.unit : "")}" list="unit-list" placeholder="개"></label>
          <label class="field" data-minwrap><span>최소 재고 <span class="hint">밑돌면 알림</span></span><input type="number" data-k="min_stock" value="${f("min_stock")}" inputmode="decimal" min="0"></label>
          <label class="field wide"><span>다른 이름 <span class="hint">검색에 걸리게, 쉼표로</span></span><input type="text" data-k="aliases" value="${f("aliases")}" placeholder="예: 멀티테스터, 테스터기"></label>
          <label class="field"><span>가격(원)</span><input type="number" data-k="price" value="${f("price")}" inputmode="numeric" min="0"></label>
          <label class="field"><span>구입처</span><input type="text" data-k="vendor" value="${f("vendor")}"></label>
          <label class="field wide"><span>제품 링크</span><input type="url" data-k="product_url" value="${f("product_url")}" placeholder="https://"></label>
          <label class="field"><span>바코드 <button class="btn xs" type="button" data-b="barcode">${icon("scan")}스캔</button></span><input type="text" data-k="barcode" value="${f("barcode")}" inputmode="numeric"></label>
          <label class="field"><span>에듀파인 번호</span><input type="text" data-k="edufine_number" value="${f("edufine_number")}"></label>
          <label class="field"><span>물품분류번호</span><input type="text" data-k="class_number" value="${f("class_number")}" inputmode="numeric" placeholder="8자리"></label>
          <label class="field"><span>사업명(예산)</span><input type="text" data-k="budget_program" value="${f("budget_program")}" list="budget-list"></label>
          <label class="field"><span>예산 연도</span><input type="number" data-k="budget_year" value="${f("budget_year")}" inputmode="numeric" placeholder="${new Date().getFullYear()}"></label>
          ${edit ? "" : `<label class="field" data-eqonly><span>도입일</span><input type="date" data-purchase></label>`}
          <label class="field"><span>내용연수(년)</span><input type="number" data-k="useful_life_years" value="${f("useful_life_years")}" inputmode="numeric" min="1" max="100"></label>
          <div class="field wide" data-pps></div>
          <label class="field wide"><span>메모</span><textarea data-k="notes" rows="2">${f("notes")}</textarea></label>
        </div></div></details>
      ${edit ? "" : `<label class="check">${""}<input type="checkbox" data-print ${mem.print ? "checked" : ""}> 저장한 뒤 라벨 인쇄</label>`}
    </div>
    <datalist id="unit-list">${UNITS.map((u) => `<option value="${u}">`).join("")}</datalist>
    <datalist id="budget-list"></datalist>`;

  const $b = (sel) => body.querySelector(sel);
  const field = (k) => body.querySelector(`[data-k="${k}"]`);
  const drop = $b("[data-drop]");

  // ---------------------------------------------------------------- 사진
  async function setPhoto(src, { analyze = true } = {}) {
    try {
      photo = src instanceof HTMLCanvasElement ? src : await loadToCanvas(src);
    } catch (e) { toastError(e); return; }
    drop.innerHTML = "";
    const img = document.createElement("img");
    img.src = photo.toDataURL("image/png");
    drop.appendChild(img);
    $b('[data-b="edit"]').disabled = false;
    sfx.play("scan");
    if (analyze && state.ai.vision) analyzePhoto();
  }

  async function analyzePhoto() {
    const box = $b("[data-ai]");
    box.innerHTML = `<div class="ai-suggest">${icon("magic")}<span>${esc(state.ai.name)}가 사진을 보는 중…</span><span class="dots"><i></i><i></i><i></i></span></div>`;
    drop.classList.add("scanning");
    mood("scan");
    try {
      const g = await api.post("/api/ai/vision", { image: toDataUrl(photo) });
      mood("happy", 1500);
      const filled = [];
      const setIf = (k, val) => {
        const el = field(k);
        if (el && val && !el.value.trim()) { el.value = val; filled.push(k); }
      };
      setIf("name", g.name);
      setIf("manufacturer", g.manufacturer);
      setIf("model", g.model);
      setIf("spec", g.spec);
      setIf("aliases", g.aliases);
      if (g.kind && !edit && filled.includes("name")) setKind(g.kind);
      if (g.category) pickCategoryByName(g.category, { onlyIfEmpty: true });
      aiFilled = filled;
      box.innerHTML = `<div class="ai-suggest">${icon("magic")}<span><b>${esc(state.ai.name)} 생각:</b> ${esc(g.name)}${g.kind ? ` · ${KIND_LABEL[g.kind]}` : ""}${g.manufacturer ? ` · ${esc(g.manufacturer)}` : ""}${g.confidence !== undefined ? ` <span class="muted">(${Math.round(g.confidence * 100)}%)</span>` : ""}</span>
        ${filled.length ? `<span class="muted">빈칸 ${filled.length}개를 채웠어요.</span>` : ""}
        <button class="btn xs" type="button" data-b="ai-search">${icon("search")}이 이름으로 제품 찾기</button></div>`;
      box.querySelector('[data-b="ai-search"]').onclick = () => openFinder(g.search_query || g.name);
      checkDup();
      suggestPps();
    } catch (e) {
      mood("idle");
      box.innerHTML = `<div class="ai-suggest">${icon("info")}<span class="muted">사진 읽기: ${esc(e.message)}</span></div>`;
    } finally {
      drop.classList.remove("scanning");
    }
  }

  for (const input of body.querySelectorAll("[data-file]")) {
    input.onchange = () => {
      const file = input.files[0];
      input.value = "";
      if (file) setPhoto(file);
    };
  }
  drop.addEventListener("dragover", (ev) => { ev.preventDefault(); drop.classList.add("drag"); });
  drop.addEventListener("dragleave", () => drop.classList.remove("drag"));
  drop.addEventListener("drop", (ev) => {
    ev.preventDefault();
    drop.classList.remove("drag");
    const file = [...(ev.dataTransfer.files || [])].find((x) => x.type.startsWith("image/"));
    if (file) setPhoto(file);
    else {
      const url = ev.dataTransfer.getData("text/uri-list") || ev.dataTransfer.getData("text/plain");
      if (/^https?:\/\//.test(url)) fetchRemote(url).then((b) => setPhoto(b)).catch(toastError);
    }
  });
  const onPaste = (ev) => {
    const file = imageFromPaste(ev);
    if (file) { ev.preventDefault(); setPhoto(file); return; }
    const text = ev.clipboardData && ev.clipboardData.getData("text");
    if (text && /^https?:\/\/\S+$/.test(text.trim()) && document.activeElement && !document.activeElement.matches("input, textarea")) {
      ev.preventDefault();
      fillFromUrl(text.trim());
    }
  };
  document.addEventListener("paste", onPaste);
  $b('[data-b="paste"]').onclick = async () => {
    const blob = await imageFromClipboard();
    if (blob) setPhoto(blob);
    else toast("클립보드에 사진이 없어요. 사진을 복사한 뒤 Ctrl+V(휴대폰은 길게 눌러 붙여넣기)를 써 보세요.", { tone: "warn" });
  };
  $b('[data-b="edit"]').onclick = async () => {
    const out = await editImage(photo || (it && it.image ? await fetch(`/uploads/${it.image}`).then((r) => r.blob()) : null));
    if (out) setPhoto(out, { analyze: false });
  };
  if (it && it.image) $b('[data-b="edit"]').disabled = false;

  function applyFields(fields) {
    const map = { name: "name", manufacturer: "manufacturer", model: "model", spec: "spec", price: "price", product_url: "product_url", vendor: "vendor", barcode: "barcode", aliases: "aliases", unit: "unit" };
    let n = 0;
    for (const [k, target] of Object.entries(map)) {
      const val = fields[k];
      const el = field(target);
      if (el && val && (!el.value.trim() || (k === "name" && fields.__replaceName))) { el.value = val; n++; }
    }
    if (fields.kind && !edit) setKind(fields.kind);
    if (fields.category_hint) pickCategoryByName(String(fields.category_hint).split(">").pop().trim(), { onlyIfEmpty: true });
    if (n) {
      body.querySelector("details.more").open = true;
      checkDup();
      suggestPps();
    }
    return n;
  }

  function openFinder(q) {
    findProduct({
      query: q || field("name").value.trim(),
      onPick: async (p) => {
        if (p.image) {
          try { await setPhoto(await fetchRemote(p.image), { analyze: false }); } catch (e) { toast(`사진을 가져오지 못했어요: ${e.message}`, { tone: "warn" }); }
        }
        const n = applyFields(p.fields || {});
        toast(`제품 정보로 ${n}칸을 채웠어요${p.image ? " · 사진도 넣었어요" : ""}`, { tone: "good" });
        if (p.image) {
          // 쇼핑몰 사진은 배경이 흰색인 경우가 많다 → 바로 다듬기 제안
          toast("사진 배경을 지우려면 [다듬기]를 누르세요", { timeout: 4000, sound: false });
        }
      },
    });
  }
  $b('[data-b="find"]').onclick = () => openFinder();

  async function fillFromUrl(url) {
    const t = toast("제품 페이지를 읽는 중…", { timeout: 0, sound: false });
    try {
      const p = await importUrl(url);
      t();
      if (p.image) await setPhoto(await fetchRemote(p.image), { analyze: false }).catch(() => {});
      const n = applyFields(p.fields);
      toast(`링크에서 ${n}칸을 채웠어요`, { tone: "good" });
    } catch (e) { t(); toastError(e); }
  }
  $b('[data-b="link"]').onclick = () => {
    const h = modal({
      title: "제품 링크로 채우기", code: "LINK", size: "narrow",
      body: `<label class="field"><span>쇼핑몰·제조사 제품 페이지 주소</span><input type="url" id="lk-url" placeholder="https://" autofocus data-mobile-focus></label>
        <p class="help" style="margin-top:8px">이름·제조사·가격·사진을 가져와요. 쿠팡처럼 자동 읽기를 막는 곳은 제품 찾기(이름)를 쓰세요.</p>`,
      actions: [{ label: "취소", tone: "ghost" }, { label: "읽기", tone: "primary", onClick: (hh) => { const u = hh.el.querySelector("#lk-url").value.trim(); if (u) fillFromUrl(u); } }],
    });
    return h;
  };
  $b('[data-b="barcode"]').onclick = async () => {
    const code = await openScanner({ title: "제품 바코드 스캔", hint: "포장의 바코드(EAN)를 비춰 주세요" });
    if (code) field("barcode").value = code;
  };

  // ---------------------------------------------------------------- 이름·중복
  const checkDup = debounce(async () => {
    const box = $b("[data-dup]");
    const name = field("name").value.trim();
    if (edit || name.length < 2) { box.innerHTML = ""; return; }
    try {
      const r = await api.get(`/api/check-duplicate?name=${encodeURIComponent(name)}`);
      if (!r.items.length) { box.innerHTML = ""; return; }
      const x = r.items[0];
      box.innerHTML = `<div class="dup-card">${thumb(x, "sm")}<div style="flex:1;min-width:0"><b>이미 있어요: ${esc(x.name)}</b><div class="help" style="margin:0">${esc(x.where.map((w) => w.path).join(", ") || x.status_label)} · ${x.kind === "equipment" ? `${x.units}대` : `${fmtQty(x.qty)}${esc(x.unit)}`}</div></div>
        <button class="btn sm amber" type="button" data-b="addto">${icon("plus")}여기에 더하기</button><button class="btn sm ghost" type="button" data-b="open">열기</button></div>`;
      box.querySelector('[data-b="open"]').onclick = () => { h.close(); app.openItem(x.id); };
      box.querySelector('[data-b="addto"]').onclick = () => addToExisting(x);
    } catch { box.innerHTML = ""; }
  }, 350);
  field("name").addEventListener("input", () => { checkDup(); suggestPps(); });

  async function addToExisting(x) {
    const q = getQty ? getQty() : 1;
    if (!v.location_id) { toast("둘 곳을 먼저 고르세요", { tone: "warn" }); return; }
    try {
      if (x.kind === "equipment") {
        const r = await api.post(`/api/items/${x.id}/units`, { location_id: v.location_id, unit_count: Math.max(1, Math.round(q)), number_prefix: $b("[data-prefix]") ? $b("[data-prefix]").value : "" });
        toast(`${x.name} ${r.units.length}대를 더했어요`, { tone: "good", action: { label: "열기", run: () => app.openItem(x.id) } });
      } else {
        const r = await api.post("/api/actions/restock", { item_id: x.id, location_id: v.location_id, qty: q });
        toast(r.event.summary, { tone: "good", action: { label: "열기", run: () => app.openItem(x.id) } });
      }
      h.close();
    } catch (e) { toastError(e); }
  }

  // ---------------------------------------------------------------- 종류·장소·수량
  let getQty = null;
  function setKind(k) {
    v.kind = k;
    const box = $b("[data-kind]");
    if (!box) return;
    for (const b of box.querySelectorAll("[data-v]")) b.setAttribute("aria-pressed", String(b.dataset.v === k));
    const eq = k === "equipment";
    $b("[data-qlabel]").textContent = eq ? "몇 대" : "수량";
    $b("[data-numbering]").hidden = !eq;
    const u = $b('[data-step="add-qty"] .u');
    if (u) u.textContent = eq ? "대" : (field("unit").value || "개");
    $b("[data-minwrap]").hidden = eq;
    const eqOnly = $b("[data-eqonly]");
    if (eqOnly) eqOnly.hidden = !eq;
    if (!field("unit").value) field("unit").placeholder = eq ? "대" : "개";
    previewNumbers();
  }
  const kbox = $b("[data-kind]");
  if (kbox) kbox.addEventListener("click", (ev) => { const b = ev.target.closest("[data-v]"); if (b) { sfx.play("tick"); setKind(b.dataset.v); } });

  function paintLocs() {
    const box = $b("[data-locs]");
    if (!box) return;
    const ids = [...new Set([v.location_id, ...recentLocations(5)].filter(Boolean))];
    box.innerHTML = ids.map((id) => `<button class="chip" type="button" data-l="${id}" aria-pressed="${id === v.location_id}">${icon("pin")}${esc(state.locMap.get(id).name)}</button>`).join("")
      + `<button class="chip" type="button" data-other>${icon("decks")}${ids.length ? "다른 곳" : "고르기"}…</button>`;
  }
  if (!edit) {
    paintLocs();
    $b("[data-locs]").addEventListener("click", (ev) => {
      const b = ev.target.closest("[data-l]");
      const pick = $b("[data-picker]");
      if (b) { v.location_id = b.dataset.l; paintLocs(); pick.hidden = true; sfx.play("tick"); return; }
      if (ev.target.closest("[data-other]")) {
        pick.hidden = !pick.hidden;
        if (!pick.hidden) locationPicker(pick, { compact: true, onPick: (id) => { v.location_id = id; pick.hidden = true; paintLocs(); } });
      }
    });
    getQty = bindStepper(body, "add-qty", { onChange: previewNumbers });
    const pf = $b("[data-prefix]");
    pf.addEventListener("input", debounce(previewNumbers, 250));
  }
  async function previewNumbers() {
    const box = $b("[data-numprev]");
    if (!box || v.kind !== "equipment") return;
    const n = Math.max(1, Math.min(50, Math.round(getQty ? getQty() : 1)));
    try {
      const r = await api.get(`/api/numbers/next?prefix=${encodeURIComponent($b("[data-prefix]").value)}&count=${n}`);
      box.textContent = `관리번호: ${r.numbers[0]}${n > 1 ? ` ~ ${r.numbers[r.numbers.length - 1]}` : ""}`;
    } catch { box.textContent = ""; }
  }

  // ---------------------------------------------------------------- 분류
  let newCat = "";
  function paintCats() {
    const box = $b("[data-cats]");
    box.innerHTML = state.categories.map((c) => `<button class="chip" type="button" data-c="${c.id}" aria-pressed="${c.id === v.category_id}">${esc(c.name)}</button>`).join("")
      + (newCat ? `<button class="chip" type="button" data-new aria-pressed="true">${esc(newCat)} <small>새 분류</small></button>` : "")
      + `<button class="chip" type="button" data-add>${icon("plus")}새 분류</button>`;
  }
  function pickCategoryByName(name, { onlyIfEmpty = false } = {}) {
    if (!name || (onlyIfEmpty && (v.category_id || newCat))) return;
    const hit = state.categories.find((c) => c.name === name || name.includes(c.name) || c.name.includes(name));
    if (hit) { v.category_id = hit.id; newCat = ""; } else { v.category_id = ""; newCat = name.slice(0, 20); }
    paintCats();
  }
  paintCats();
  $b("[data-cats]").addEventListener("click", async (ev) => {
    const b = ev.target.closest("button");
    if (!b) return;
    sfx.play("tick");
    if (b.hasAttribute("data-c")) { v.category_id = v.category_id === b.dataset.c ? "" : b.dataset.c; newCat = ""; }
    else if (b.hasAttribute("data-new")) newCat = "";
    else if (b.hasAttribute("data-add")) {
      const name = prompt("새 분류 이름");
      if (name && name.trim()) { newCat = name.trim().slice(0, 20); v.category_id = ""; }
    }
    paintCats();
  });

  // ---------------------------------------------------------------- 조달청 내용연수 제안
  const suggestPps = debounce(async () => {
    const box = $b("[data-pps]");
    const name = field("name").value.trim();
    if (name.length < 2 || field("useful_life_years").value) { box.innerHTML = ""; return; }
    try {
      const r = await api.get(`/api/pps?name=${encodeURIComponent(name)}&class_number=${encodeURIComponent(field("class_number").value)}&limit=4`);
      if (!r.items.length) { box.innerHTML = ""; return; }
      box.innerHTML = `<span class="hint">${icon("info")} ${esc(r.notice)} 내용연수 후보 — 누르면 채워요</span><div class="chips" style="margin-top:4px">${r.items.map((x) => `<button class="chip" type="button" data-y="${x.years}" data-cls="${esc(x.class_number)}">${esc(x.name)} <small>${x.years}년</small></button>`).join("")}</div>`;
      box.querySelectorAll("[data-y]").forEach((b) => {
        b.onclick = () => {
          field("useful_life_years").value = b.dataset.y;
          if (!field("class_number").value) field("class_number").value = b.dataset.cls;
          box.innerHTML = `<span class="hint">${icon("check")} ${esc(b.textContent.trim())} 로 채웠어요</span>`;
          sfx.play("tick");
        };
      });
    } catch { box.innerHTML = ""; }
  }, 500);
  api.get("/api/items?limit=1").catch(() => {});

  // ---------------------------------------------------------------- 저장
  function collect() {
    const out = {};
    for (const el of body.querySelectorAll("[data-k]")) out[el.dataset.k] = el.value.trim();
    for (const k of ["min_stock", "price", "budget_year", "useful_life_years"]) if (out[k] === "") out[k] = null;
    if (!out.unit) out.unit = v.kind === "equipment" ? "대" : "개";
    return out;
  }

  async function save({ again = false } = {}) {
    const data = collect();
    if (!data.name) { toast("이름을 넣으세요", { tone: "warn" }); field("name").focus(); return false; }
    if (!edit && !v.location_id) { toast("둘 곳을 고르세요", { tone: "warn" }); return false; }
    try {
      if (photo) Object.assign(data, await uploadCanvas(photo));
      if (newCat) {
        const c = await api.post("/api/categories", { name: newCat });
        data.category_id = c.category.id;
        state.categories.push(c.category);
      } else data.category_id = v.category_id || null;
      if (edit) {
        await api.patch(`/api/items/${it.id}`, data);
        toast("정보를 고쳤어요", { tone: "good" });
        return true;
      }
      const qn = getQty();
      const payload = { ...data, kind: v.kind, location_id: v.location_id };
      if (v.kind === "equipment") {
        payload.unit_count = Math.max(1, Math.round(qn));
        payload.number_prefix = $b("[data-prefix]").value;
        const pd = $b("[data-purchase]").value;
        payload.unit_defaults = { purchase_date: pd || null, budget_program: data.budget_program || null, budget_year: data.budget_year || null };
      } else payload.quantity = qn;
      const r = await api.post("/api/items", payload);
      rememberLocation(v.location_id);
      local.set(MEMORY, { kind: v.kind, location_id: v.location_id, category_id: data.category_id || "", prefix: v.kind === "equipment" ? payload.number_prefix : mem.prefix || "", print: $b("[data-print]").checked });
      sfx.play("lock");
      mood("happy", 1600);
      toast(`${data.name} 등록 완료${r.units && r.units.length ? ` · ${r.units.length}대 (${r.units[0].management_number || ""}${r.units.length > 1 ? "…" : ""})` : ""}`, {
        tone: "good", sound: false, action: { label: "열기", run: () => app.openItem(r.id) },
      });
      if ($b("[data-print]").checked) {
        const d = await api.get(`/api/items/${r.id}`);
        printLabels(d.units.length ? d.units.map((u) => ({ kind: "unit", item: d.item, unit: u })) : [{ kind: "item", item: d.item, where: d.stocks[0] && d.stocks[0].location_path }]);
      }
      if (again) {
        // 종류·장소·분류는 그대로 두고 이름·사진만 비운다
        photo = null;
        drop.innerHTML = `<div class="ph-empty">${icon("camera")}<b>다음 물건 사진</b></div>`;
        field("name").value = "";
        $b("[data-ai]").innerHTML = "";
        $b("[data-dup]").innerHTML = "";
        for (const k of ["model", "spec", "aliases", "barcode", "product_url", "price", "notes"]) if (field(k)) field(k).value = "";
        field("name").focus();
        previewNumbers();
        return false;
      }
      return true;
    } catch (e) { toastError(e); return false; }
  }

  const h = modal({
    title: edit ? `${it.name} 고치기` : "새 물건 등록", code: edit ? "EDIT CARGO" : "NEW CARGO", size: "xwide", body, className: "add-modal",
    actions: edit
      ? [{ label: "취소", tone: "ghost" }, { label: "저장", tone: "primary", icon: "check", onClick: () => save() }]
      : [{ label: "취소", tone: "ghost" }, { label: "저장하고 하나 더", tone: "", icon: "plus", onClick: () => save({ again: true }) }, { label: "저장", tone: "primary", icon: "check", onClick: () => save() }],
    onClose: () => document.removeEventListener("paste", onPaste),
    onOpen: () => {
      if (!edit) setKind(v.kind);
      if (draft.image) fetchRemote(draft.image).then((b) => setPhoto(b)).catch(() => {});
      if (v.name) { checkDup(); suggestPps(); }
      api.get("/api/items?limit=400").then((r) => {
        const progs = [...new Set(r.items.map((x) => x.budget_program).filter(Boolean))];
        $b("#budget-list").innerHTML = progs.map((p) => `<option value="${esc(p)}">`).join("");
      }).catch(() => {});
    },
  });
  return h;
}

export function openEditItem(detail) {
  return openAddItem({}, { edit: detail });
}

export { locPath };

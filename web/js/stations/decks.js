// 03 장소: 건물 › 실 › 선반 나무와 그 장소의 물건. 장소 라벨·실사·여기에 등록을 여기서.
import { $, esc, icon, qty as fmtQty, thumb, statusChip, isMobile } from "../lib/util.js";
import { api } from "../lib/api.js";
import { modal, toast, toastError, confirmDialog } from "../lib/ui.js";
import { state, can, locationTree, locPath, rememberLocation } from "../lib/store.js";
import { app } from "../app.js";
import { printLabels } from "../features/labels.js";
import { quickRooms } from "../features/setup.js";
import * as actions from "../features/actions.js";

const KIND = { building: "건물", floor: "층", room: "실", zone: "구역", storage: "보관함", bin: "칸" };
const KIND_ICON = { building: "decks", floor: "layers", room: "box", zone: "grid", storage: "layers", bin: "grid" };
let root;
let sel = null;
let contents = null;

function treeHtml() {
  const tree = locationTree();
  if (!tree.length) return `<div class="empty">아직 장소가 없어요.${can("edit") ? `<br><button class="btn primary" type="button" data-b="quick" style="margin-top:10px">${icon("plus")}장소 한꺼번에 만들기</button>` : ""}</div>`;
  return tree.map((l) => `<button class="tree-row ${l.kind}" type="button" data-loc="${l.id}" style="padding-left:${8 + l.depth * 16}px" ${sel === l.id ? "aria-current" : ""}>
    ${icon(KIND_ICON[l.kind] || "box")}<span class="nm">${esc(l.name)}${l.code ? ` <span class="muted mono" style="font-size:11px">${esc(l.code)}</span>` : ""}</span>
    ${l.alerts ? '<i class="led warn"></i>' : ""}<span class="cnt">${l.items || ""}</span></button>`).join("");
}

function overviewHtml() {
  const rooms = state.locations.filter((l) => l.kind === "room");
  return `<div class="panel-h"><span class="code">DECK OVERVIEW</span><h2>모든 실</h2><span class="sub">${rooms.length}곳</span></div>
    <div class="pick-grid">${rooms.map((l) => `<button class="pick" type="button" data-loc="${l.id}"><span class="n">${esc(l.name)}${l.code ? ` <small class="muted mono">${esc(l.code)}</small>` : ""}</span><span class="p">${esc(l.path)}</span><span class="p">${l.items}종${l.units ? ` · 장비 ${l.units}대` : ""}${l.alerts ? ` · <span style="color:var(--amber)">경보 ${l.alerts}</span>` : ""}</span></button>`).join("") || '<div class="empty">실이 없어요</div>'}</div>`;
}

// 이 장소가 그려진 도면(자기 자신 또는 위 장소). 3D 지도에서 그 층·그 자리가 빛난다
function planSpot(l) {
  const ids = [l.id, ...[...(l.path_ids || [])].reverse()];
  for (const id of ids) {
    const p = state.plans.find((pl) => pl.shapes.some((s) => s.location_id === id));
    if (p) return { plan: p, host: state.locMap.get(p.location_id), at: state.locMap.get(id) };
  }
  return null;
}
function whereHtml(l) {
  const spot = planSpot(l);
  const lv = (n) => (n < 0 ? `지하 ${-n}층` : `${n}층`);
  if (spot) {
    const where = spot.host ? (spot.host.kind === "floor" ? spot.host.path || spot.host.name : `${spot.host.name} ${lv(spot.plan.level)}`) : lv(spot.plan.level);
    return `<div class="where-line">${icon("plan")}<span><b>${esc(where)}</b> 도면${spot.at && spot.at.id !== l.id ? ` · ${esc(spot.at.name)} 안` : ""}</span>
      ${app.scene ? `<button class="btn xs" type="button" data-b="map">${icon("decks")}3D에서 보기</button>` : ""}
      ${can("settings") ? `<button class="btn xs ghost" type="button" data-b="plan" data-host="${spot.plan.location_id}">도면 고치기</button>` : ""}</div>`;
  }
  if (can("settings") && ["room", "zone", "storage", "bin"].includes(l.kind)) {
    return `<div class="where-line muted">${icon("plan")}<span>아직 도면에 그리지 않았어요. 도면(04)에서 그리면 3D 지도에서 그 자리를 비춰요.</span><button class="btn xs ghost" type="button" data-b="plan">도면으로</button></div>`;
  }
  return "";
}

function detailHtml() {
  const l = contents.location;
  const kids = (l.children || []).map((id) => state.locMap.get(id)).filter(Boolean);
  const items = contents.items;
  return `
    ${isMobile() ? `<button class="btn ghost sm" type="button" data-b="list" style="margin-bottom:8px">${icon("left")}장소 목록</button>` : ""}
    <div class="loc-head"><div style="flex:1;min-width:0"><span class="code" style="font:600 10px/1 var(--font-display);letter-spacing:.26em;color:var(--hud-dim)">${esc(KIND[l.kind] || "장소")} · ${esc(l.qr)}</span>
      <h2 style="margin:4px 0 2px;font-size:22px">${esc(l.name)}</h2><div class="help" style="margin:0">${esc(l.path)}${l.manager ? ` · 담당 ${esc(l.manager.name)}` : ""}</div></div>
      ${l.code ? `<span class="code-badge">${esc(l.code)}</span>` : ""}</div>
    ${l.description ? `<p class="help" style="margin-top:8px">${esc(l.description)}</p>` : ""}
    ${whereHtml(state.locMap.get(l.id) || l)}
    <div class="row" style="margin:12px 0">
      ${can("register") ? `<button class="btn amber sm" type="button" data-b="add">${icon("plus")}여기에 등록</button>` : ""}
      <button class="btn sm" type="button" data-b="label">${icon("print")}장소 라벨</button>
      ${can("audit") ? `<button class="btn sm" type="button" data-b="audit">${icon("audit")}실사 시작</button>` : ""}
      ${app.scene ? `<button class="btn sm" type="button" data-b="map">${icon("decks")}지도</button>` : ""}
      ${can("edit") ? `<button class="btn sm" type="button" data-b="child">${icon("plus")}아래 장소</button><button class="btn sm ghost" type="button" data-b="edit">${icon("edit")}고치기</button>` : ""}
    </div>
    ${kids.length ? `<div class="loc-kids">${kids.map((k) => `<button class="chip" type="button" data-loc="${k.id}">${icon(KIND_ICON[k.kind] || "box")}${esc(k.name)} <small>${k.items}</small></button>`).join("")}</div>` : ""}
    <p class="count-line"><b>${items.length}</b>종${l.units ? ` · 장비 ${l.units}대` : ""} (아래 장소 포함)</p>
    ${items.length ? `<div class="list">${items.map((x) => `<button class="irow" type="button" data-item="${x.id}">${thumb(x, "sm")}<span class="tx"><span class="nm">${esc(x.name)} <span class="tag muted">${esc(x.kind_label)}</span></span>
        <span class="sub">${x.kind === "equipment" ? `여기 ${x.here.units}대 (사용 가능 ${x.here.available})${x.here.unit_list.filter((u) => u.location_id !== l.id).length ? " · 아래 장소 포함" : ""}` : `여기 ${fmtQty(x.here.qty)}${esc(x.unit)}${x.here.stock_list.length > 1 ? ` · ${x.here.stock_list.length}곳` : ""}`}</span></span>
        ${statusChip(x.status, x.status_label)}</button>`).join("")}</div>`
      : `<div class="empty">이 장소에는 아직 물건이 없어요.${can("register") ? `<br><button class="btn amber" type="button" data-b="add" style="margin-top:10px">${icon("plus")}여기에 등록</button>` : ""}</div>`}`;
}

function render() {
  root.innerHTML = `
    <div class="st-inner">
      <div class="st-head"><div class="ttl"><span class="code">STATION 03 · DECK MAP</span><h1>장소</h1><p>건물 › 실 › 선반 순서로 물건이 어디 있는지 봅니다. 실·선반에 라벨을 붙이면 스캔으로 바로 열려요.</p></div>
        <div class="tools">${can("edit") ? `<button class="btn" type="button" data-b="new">${icon("plus")}장소 추가</button><button class="btn" type="button" data-b="quick">${icon("layers")}한꺼번에 만들기</button>` : ""}
          <button class="btn" type="button" data-b="labels-all">${icon("print")}장소 라벨 모두</button></div></div>
      <div class="decks-grid">
        <section class="panel" data-treebox ${isMobile() && sel ? "hidden" : ""}><div class="panel-h"><span class="code">DECKS</span><h3>장소 나무</h3><span class="sub">${state.locations.length}곳</span></div><div class="tree">${treeHtml()}</div></section>
        <section class="panel" data-detail ${isMobile() && !sel ? "hidden" : ""}>${sel && contents ? detailHtml() : overviewHtml()}</section>
      </div>
    </div>`;
}

async function select(id, { push = true } = {}) {
  sel = id;
  if (!id) { contents = null; render(); return; }
  app.currentLocation = id;
  if (push) history.replaceState(null, "", `#decks?loc=${id}`);
  try {
    contents = await api.get(`/api/locations/${id}`);
    render();
    if (app.scene) app.scene.highlight([id]);
    const cur = root.querySelector(`.tree-row[data-loc="${id}"]`);
    if (cur) cur.scrollIntoView({ block: "nearest" });
  } catch (e) { toastError(e); sel = null; render(); }
}

function editLocation(loc = null, parentId = null) {
  const tree = locationTree();
  const cur = loc || { name: "", kind: parentId ? (state.locMap.get(parentId).kind === "building" ? "room" : "storage") : "room", code: "", description: "", parent_id: parentId };
  const h = modal({
    title: loc ? `${loc.name} 고치기` : "장소 추가", code: "DECK", size: "",
    body: `<div class="form-grid">
      <label class="field"><span>이름 <span class="req">*</span></span><input type="text" data-k="name" value="${esc(cur.name)}" autofocus placeholder="예: 전자실습실, 선반 A"></label>
      <label class="field"><span>종류</span><select data-k="kind">${Object.entries(KIND).map(([k, l]) => `<option value="${k}" ${k === cur.kind ? "selected" : ""}>${l}</option>`).join("")}</select></label>
      <label class="field"><span>번호(호실 등)</span><input type="text" data-k="code" value="${esc(cur.code || "")}" placeholder="예: E-201"></label>
      <label class="field"><span>위 장소</span><select data-k="parent_id"><option value="">(맨 위)</option>${tree.filter((t) => !loc || !t.path_ids.includes(loc.id)).map((t) => `<option value="${t.id}" ${t.id === cur.parent_id ? "selected" : ""}>${"　".repeat(t.depth)}${esc(t.name)}</option>`).join("")}</select></label>
      <label class="field wide"><span>메모</span><input type="text" data-k="description" value="${esc(cur.description || "")}" placeholder="예: 열쇠는 교무실"></label></div>`,
    actions: [
      ...(loc ? [{ label: "지우기", tone: "danger", icon: "trash", onClick: async () => {
        const ok = await confirmDialog({ title: "장소 지우기", message: `${loc.name}을(를) 지울까요? 물건이 있으면 지울 수 없어요.`, confirmLabel: "지우기", tone: "danger" });
        if (!ok) return false;
        try { await api.del(`/api/locations/${loc.id}`); toast("지웠어요", { tone: "good" }); sel = null; return true; } catch (e) { toastError(e); return false; }
      } }] : []),
      { label: "취소", tone: "ghost" },
      { label: loc ? "저장" : "추가", tone: "primary", onClick: async (hh) => {
        const body = {};
        for (const el of hh.el.querySelectorAll("[data-k]")) body[el.dataset.k] = el.value.trim();
        if (!body.name) { toast("이름을 넣으세요", { tone: "warn" }); return false; }
        body.parent_id = body.parent_id || null;
        try {
          const r = loc ? await api.patch(`/api/locations/${loc.id}`, body) : await api.post("/api/locations", body);
          toast(loc ? "고쳤어요" : `${body.name} 추가`, { tone: "good" });
          await app.refreshCore();
          select(r.location.id);
          return true;
        } catch (e) { toastError(e); return false; }
      } },
    ],
  });
  return h;
}

export default {
  mount(el) {
    root = el;
    el.addEventListener("click", async (ev) => {
      const l = ev.target.closest("[data-loc]");
      if (l) { select(l.dataset.loc); return; }
      const it = ev.target.closest("[data-item]");
      if (it) { app.openItem(it.dataset.item); return; }
      const b = ev.target.closest("[data-b]");
      if (!b) return;
      const k = b.dataset.b;
      const loc = contents && contents.location;
      if (k === "list") { sel = null; render(); }
      else if (k === "new") editLocation(null, sel);
      else if (k === "child" && loc) editLocation(null, loc.id);
      else if (k === "edit" && loc) editLocation(state.locMap.get(loc.id));
      else if (k === "quick") quickRooms();
      else if (k === "add" && loc) { rememberLocation(loc.id); app.addItem({ location_id: loc.id }); }
      else if (k === "label" && loc) printLabels([{ kind: "location", loc }]);
      else if (k === "labels-all") printLabels(locationTree().filter((x) => x.kind !== "building").map((x) => ({ kind: "location", loc: { ...x, kind_label: KIND[x.kind] } })));
      else if (k === "map" && loc && app.scene) app.scene.highlight([loc.id]);
      else if (k === "plan") app.go("plans", b.dataset.host ? { host: b.dataset.host } : {});
      else if (k === "audit" && loc) {
        try {
          const a = await api.post("/api/audits", { location_id: loc.id });
          app.go("audit", { id: a.id });
        } catch (e) {
          if (e.status === 409) app.go("audit", {});
          toastError(e);
        }
      }
    });
  },
  enter(params = {}) {
    if (params.loc) return select(params.loc, { push: false });
    sel = null;
    contents = null;
    render();
    return null;
  },
  async refresh() {
    if (sel) {
      try { contents = await api.get(`/api/locations/${sel}`); } catch { sel = null; contents = null; }
    }
    render();
  },
};

export { actions };

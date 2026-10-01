// QR 라벨 인쇄. QR 에는 "주소/q/코드"를 담는다 → 휴대폰 기본 카메라로 찍어도 그 물건 화면이 열린다.
// A4 라벨지(칸 나눔)와 라벨 프린터(롤) 둘 다 된다.
import { esc, icon } from "../lib/util.js";
import { modal, toast } from "../lib/ui.js";
import { state } from "../lib/store.js";
import { api } from "../lib/api.js";

let qrcodeMod = null;
async function qrSvg(text) {
  if (!qrcodeMod) qrcodeMod = (await import("/vendor/qrcode/qrcode.mjs")).default;
  const qr = qrcodeMod(0, "M");
  qr.addData(text, "Byte");
  qr.make();
  return qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
}

function base() {
  return (state.server && state.server.public_url) || location.origin;
}

// entries: [{ kind: "unit", item, unit }] | [{ kind: "item", item, where }] | [{ kind: "location", loc }]
function labelData(e) {
  if (e.kind === "unit") {
    return { code: e.unit.qr, title: e.item.name, big: e.unit.management_number || e.unit.label || "", sub: e.unit.location_path || "", tag: "장비" };
  }
  if (e.kind === "location") {
    return { code: e.loc.qr, title: e.loc.name, big: e.loc.code || "", sub: e.loc.path || "", tag: e.loc.kind_label || "장소", loc: true };
  }
  return { code: e.item.qr, title: e.item.name, big: [e.item.model, e.item.spec].filter(Boolean).join(" ").slice(0, 24), sub: e.where || "", tag: "품목" };
}

async function render(entries, paperKey, opts) {
  const papers = state.settings.labels.papers;
  const p = papers[paperKey] || papers["a4-24"];
  const school = state.settings.school.short_name || state.settings.school.name;
  const cells = [];
  for (const e of entries) {
    const d = labelData(e);
    const svg = await qrSvg(`${base()}/q/${d.code}`);
    cells.push(`<div class="lb ${d.loc ? "loc" : ""}"><div class="qr">${svg}</div><div class="tx">
      ${opts.show_name ? `<div class="t">${esc(d.title)}</div>` : ""}
      ${opts.show_number && d.big ? `<div class="b">${esc(d.big)}</div>` : ""}
      ${opts.show_location && d.sub ? `<div class="s">${esc(d.sub)}</div>` : ""}
      <div class="f">${opts.show_school ? esc(school) : ""}<span>${esc(d.code)}</span></div></div></div>`);
  }
  const w = p.w;
  const h = p.h;
  const qr = Math.min(h - 4, w * 0.42);
  const fs = Math.max(6.5, Math.min(11, h * 0.26));
  const css = `
    @page { size: ${p.roll ? `${w}mm ${h}mm` : "A4"}; margin: ${p.roll ? "0" : "8mm 6mm"}; }
    #print-root .lsheet { display: grid; grid-template-columns: repeat(${p.cols}, ${w}mm); grid-auto-rows: ${h}mm; gap: 0 ${p.roll ? 0 : 2.5}mm; justify-content: center; }
    #print-root .lb { box-sizing: border-box; width: ${w}mm; height: ${h}mm; padding: 1.6mm 1.8mm; display: flex; gap: 1.8mm; align-items: center; overflow: hidden; break-inside: avoid; ${p.roll ? "page-break-after: always;" : "outline: 0.2mm dashed #bbb;"} font-family: "Pretendard Variable", "Malgun Gothic", sans-serif; color: #000; }
    #print-root .lb .qr { width: ${qr}mm; height: ${qr}mm; flex: none; }
    #print-root .lb .qr svg { width: 100%; height: 100%; display: block; }
    #print-root .lb .tx { min-width: 0; flex: 1; display: flex; flex-direction: column; gap: 0.4mm; line-height: 1.15; }
    #print-root .lb .t { font-size: ${fs}pt; font-weight: 800; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    #print-root .lb .b { font: 700 ${fs * 0.95}pt/1.1 "JetBrains Mono", Consolas, monospace; }
    #print-root .lb .s { font-size: ${fs * 0.72}pt; color: #333; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    #print-root .lb .f { font-size: ${fs * 0.62}pt; color: #555; display: flex; justify-content: space-between; gap: 2mm; }
    #print-root .lb .f span { font-family: Consolas, monospace; }
    #print-root .lb.loc .t { font-size: ${fs * 1.35}pt; }
    @media print { #print-root .lsheet { break-after: page; } }`;
  const per = p.roll ? cells.length : p.cols * p.rows;
  let html = `<style>${css}</style>`;
  for (let i = 0; i < cells.length; i += per) html += `<div class="lsheet">${cells.slice(i, i + per).join("")}</div>`;
  return { html, sheets: Math.ceil(cells.length / per), paper: p };
}

export async function printLabels(entries, { paper } = {}) {
  if (!entries || !entries.length) { toast("인쇄할 라벨이 없어요", { tone: "warn" }); return; }
  const s = state.settings.labels;
  const papers = s.papers;
  let key = paper || s.paper;
  const opts = { show_school: s.show_school, show_location: s.show_location, show_name: s.show_name, show_number: s.show_number };
  const body = document.createElement("div");
  body.innerHTML = `
    <div class="grid g2" style="align-items:end">
      <label class="field"><span>용지</span><select data-paper>${Object.entries(papers).map(([k, p]) => `<option value="${k}" ${k === key ? "selected" : ""}>${esc(p.name)}</option>`).join("")}</select></label>
      <div class="chips">${[["show_name", "이름"], ["show_number", "번호"], ["show_location", "장소"], ["show_school", "학교"]].map(([k, l]) => `<button class="chip" type="button" data-o="${k}" aria-pressed="${opts[k]}">${l}</button>`).join("")}</div>
    </div>
    <p class="help" data-info style="margin-top:8px"></p>
    <div class="label-preview" style="background:#fff;border-radius:6px;padding:10px;max-height:52vh;overflow:auto;color:#000"></div>
    <p class="help" style="margin-top:8px">인쇄 창에서 <b>배율 100%(실제 크기)</b>, 여백 <b>기본/없음</b>으로 인쇄하세요. 라벨 프린터는 용지 크기를 라벨과 같게 맞춥니다.</p>`;
  const prev = body.querySelector(".label-preview");
  const info = body.querySelector("[data-info]");
  let out = null;
  const refresh = async () => {
    out = await render(entries, key, opts);
    prev.innerHTML = out.html.replace(/#print-root/g, ".label-preview");
    // 실제 크기(mm)라 휴대폰에서는 넘친다 → 미리보기만 화면 폭에 맞춰 줄인다
    const p = out.paper;
    const widthPx = (p.cols * p.w + (p.cols - 1) * (p.roll ? 0 : 2.5)) * (96 / 25.4);
    const room = prev.clientWidth - 20;
    for (const sh of prev.querySelectorAll(".lsheet")) {
      sh.style.width = `${widthPx}px`;
      sh.style.margin = "0 auto";
      sh.style.zoom = room > 0 && widthPx > room ? String(room / widthPx) : "";
    }
    info.textContent = `라벨 ${entries.length}장 · ${out.paper.roll ? "라벨 프린터" : `A4 ${out.sheets}장`}`;
  };
  body.querySelector("[data-paper]").onchange = (ev) => { key = ev.target.value; refresh(); };
  body.querySelectorAll("[data-o]").forEach((b) => {
    b.onclick = () => { opts[b.dataset.o] = !opts[b.dataset.o]; b.setAttribute("aria-pressed", String(opts[b.dataset.o])); refresh(); };
  });
  modal({
    title: "라벨 인쇄", code: "LABEL PRINTER", size: "wide", body,
    actions: [{ label: "닫기", tone: "ghost" }, {
      label: "인쇄", tone: "primary", icon: "print",
      onClick: () => {
        const root = document.getElementById("print-root");
        root.innerHTML = out.html;
        setTimeout(() => {
          window.print();
          setTimeout(() => { root.innerHTML = ""; }, 1500);
        }, 80);
        return false;
      },
    }],
    onOpen: refresh,
  });
}

// 물건 여러 개 → 라벨 목록(장비는 한 대씩)
export async function labelsForItems(itemIds) {
  const entries = [];
  for (const id of itemIds.slice(0, 200)) {
    const d = await api.get(`/api/items/${id}`);
    if (d.units.length) for (const u of d.units) entries.push({ kind: "unit", item: d.item, unit: u });
    else entries.push({ kind: "item", item: d.item, where: d.stocks[0] ? d.stocks[0].location_path : "" });
  }
  return entries;
}

export { icon };

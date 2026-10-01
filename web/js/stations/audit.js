// 05 실사: 장소를 고르면 장부 목록이 생기고, 라벨을 찍거나 눌러 확인한다. 끝나면 차이만 모아 장부에 반영.
import { $, esc, icon, qty as fmtQty, thumb, fmtDateTime, relTime } from "../lib/util.js";
import { api } from "../lib/api.js";
import { modal, toast, toastError, confirmDialog } from "../lib/ui.js";
import { state, can } from "../lib/store.js";
import { app } from "../app.js";
import * as sfx from "../lib/sfx.js";
import { locationPicker } from "../features/pickers.js";
import { openScanner } from "../features/scanner.js";
import { mood } from "../ai/character.js";

let root;
let list = [];
let cur = null;

function listHtml() {
  return `
    <div class="st-head"><div class="ttl"><span class="code">STATION 05 · INSPECTION</span><h1>실사</h1><p>장소를 고르면 장부의 물건 목록이 만들어져요. 라벨을 차례로 찍으면 끝. 없는 것만 모아 보여 드려요.</p></div>
      <div class="tools">${can("audit") ? `<button class="btn primary" type="button" data-b="start">${icon("audit")}실사 시작</button>` : ""}</div></div>
    <div class="list">${list.length ? list.map((a) => `<button class="irow" type="button" data-a="${a.id}"><span class="thumb sm blank">${icon("audit")}</span>
      <span class="tx"><span class="nm">${esc(a.title)} ${a.status === "active" ? '<span class="tag amber">진행 중</span>' : a.status === "done" ? '<span class="tag good">끝남</span>' : '<span class="tag muted">취소</span>'}</span>
      <span class="sub">${esc(a.location_path)} · ${esc(a.started_by_name || "")} · ${esc(fmtDateTime(a.started_at))} · 확인 ${a.checked}/${a.total}</span></span></button>`).join("")
      : `<div class="empty">아직 실사 기록이 없어요.${can("audit") ? `<br><button class="btn primary" type="button" data-b="start" style="margin-top:10px">${icon("audit")}첫 실사 시작</button>` : ""}</div>`}</div>`;
}

function lineHtml(l) {
  const done = Boolean(l.checked_at);
  const cls = l.extra ? "extra" : done ? "ok" : cur.status === "done" ? "missing" : "";
  const res = l.resolution ? `<span class="tag muted">${({ lost: "분실 처리", move_here: "이곳으로 옮김", adjust: "수량 보정", adjust_zero: "0으로 보정", ignore: "그대로 둠" })[l.resolution] || l.resolution}</span>` : "";
  let right = "";
  if (cur.status === "active" && can("audit")) {
    right = l.kind === "stock"
      ? `<input class="qin" type="number" inputmode="decimal" data-count="${l.id}" value="${l.counted_qty ?? ""}" placeholder="${fmtQty(l.expected_qty)}" aria-label="센 수량">`
      : "";
  } else if (cur.status === "done" && !l.resolution && can("edit")) {
    if (l.kind === "asset" && !done && !l.extra) right = `<button class="btn xs danger" type="button" data-res="lost" data-l="${l.id}">분실 처리</button>`;
    else if (l.extra) right = `<button class="btn xs" type="button" data-res="move_here" data-l="${l.id}">이곳으로 옮기기</button>`;
    else if (l.kind === "stock" && l.mismatch) right = `<button class="btn xs warn" type="button" data-res="adjust" data-l="${l.id}">${fmtQty(l.counted_qty)}로 보정</button>`;
    else if (l.kind === "stock" && !done) right = `<button class="btn xs warn" type="button" data-res="adjust_zero" data-l="${l.id}">0으로 보정</button>`;
    if (right) right += `<button class="btn xs ghost" type="button" data-res="ignore" data-l="${l.id}">그대로</button>`;
  }
  return `<div class="aline ${cls}" data-line="${l.id}">
    ${cur.status === "active" && can("audit") ? `<button class="ck" type="button" data-ck="${l.id}" aria-pressed="${done}" aria-label="확인">${icon("check")}</button>` : `<span class="ck">${done ? icon("check") : ""}</span>`}
    <span style="min-width:0"><b>${esc(l.name)}</b> ${l.extra ? '<span class="tag amber">여기서 발견</span>' : ""}${l.mismatch ? '<span class="tag warn">수량 다름</span>' : ""} ${res}
      <div class="help" style="margin:0">${esc(l.code || "")}${l.kind === "stock" ? ` · 장부 ${fmtQty(l.expected_qty)}` : ""}${l.location_path ? ` · ${esc(l.location_path.split(" › ").slice(-2).join(" › "))}` : ""}${l.checked_by_name ? ` · ${esc(l.checked_by_name)}` : ""}</div></span>
    <span class="row nw">${right}</span></div>`;
}

function auditHtml() {
  const p = cur.progress;
  const pct = p.total ? Math.round((p.checked / p.total) * 100) : 100;
  const missing = cur.status === "done" ? cur.lines.filter((l) => !l.checked_at && !l.extra) : [];
  const todo = cur.lines.filter((l) => !l.checked_at && !l.extra);
  const doneLines = cur.lines.filter((l) => l.checked_at || l.extra);
  return `
    <div class="st-head"><button class="btn ghost" type="button" data-b="back">${icon("left")}실사 목록</button>
      <div class="tools">${cur.status === "active" && can("audit") ? `<button class="btn primary lg" type="button" data-b="scan">${icon("scan")}계속 스캔</button><button class="btn" type="button" data-b="finish">${icon("check")}실사 끝내기</button>` : ""}
      ${cur.status === "done" ? `<button class="btn" type="button" data-b="report">${icon("download")}결과 CSV</button>` : ""}</div></div>
    <section class="panel" style="margin-bottom:14px"><div class="row nw" style="gap:16px">
      <div class="progress-ring" style="--p:${pct}"><b>${pct}%</b></div>
      <div style="min-width:0"><span class="code" style="font:600 10px/1 var(--font-display);letter-spacing:.26em;color:var(--hud-dim)">INSPECTION · ${cur.status === "active" ? "진행 중" : cur.status === "done" ? "끝남" : "취소"}</span>
        <h2 style="margin:4px 0">${esc(cur.title)}</h2><div class="help" style="margin:0">${esc(cur.location_path)} · 확인 ${p.checked}/${p.total}${p.extra ? ` · 여기서 발견 ${p.extra}` : ""}${p.mismatch ? ` · 수량 다름 ${p.mismatch}` : ""}</div></div></div>
      ${cur.status === "active" ? `<form class="desk-input" data-code style="margin-top:12px"><input type="text" placeholder="바코드 스캐너로 찍거나 관리번호 입력" autocomplete="off"><button class="btn" type="submit">확인</button></form><div class="help" data-msg style="margin-top:6px"></div>` : ""}
      ${cur.status === "done" && missing.length ? `<div class="callout warn" style="margin-top:12px">확인되지 않은 것 <b>${missing.length}건</b>이 있어요. 찾지 못했다면 [분실 처리], 수량이면 [보정]으로 장부에 반영하세요.</div>` : ""}
    </section>
    ${cur.status === "active" ? `<h3 style="margin:0 0 8px">아직 확인 안 한 것 · ${todo.length}</h3><div class="list">${todo.map(lineHtml).join("") || '<div class="empty">모두 확인했어요! [실사 끝내기]를 누르세요.</div>'}</div>
      <h3 style="margin:16px 0 8px">확인한 것 · ${doneLines.length}</h3><div class="list">${doneLines.map(lineHtml).join("")}</div>`
      : `<div class="list">${cur.lines.map(lineHtml).join("")}</div>`}`;
}

function render() {
  root.innerHTML = `<div class="st-inner">${cur ? auditHtml() : listHtml()}</div>`;
  if (cur && cur.status === "active") setTimeout(() => { const i = root.querySelector("[data-code] input"); if (i && !("ontouchstart" in window)) i.focus(); }, 200);
}

async function openAudit(id) {
  cur = await api.get(`/api/audits/${id}`);
  history.replaceState(null, "", `#audit?id=${id}`);
  render();
}

async function check(code) {
  const out = await api.post(`/api/audits/${cur.id}/scan`, { code }, { via: "audit" });
  const msg = root.querySelector("[data-msg]");
  if (msg) msg.textContent = out.message;
  if (out.result === "ok" || out.result === "extra") { sfx.play("scan"); mood("happy", 700); }
  else if (out.result === "again") sfx.play("tick");
  else sfx.play("warn");
  if (out.result === "count") {
    const first = out.lines[0];
    const input = root.querySelector(`[data-count="${first.id}"]`);
    if (input) { input.focus(); input.select(); }
  }
  cur = await api.get(`/api/audits/${cur.id}`);
  render();
  return { ok: ["ok", "extra", "again"].includes(out.result), text: out.message, short: code };
}

export default {
  mount(el) {
    root = el;
    el.addEventListener("submit", async (ev) => {
      if (!ev.target.matches("[data-code]")) return;
      ev.preventDefault();
      const input = ev.target.querySelector("input");
      const v = input.value.trim();
      input.value = "";
      if (v) check(v).catch(toastError);
    });
    el.addEventListener("change", async (ev) => {
      const c = ev.target.closest("[data-count]");
      if (!c) return;
      try {
        await api.patch(`/api/audits/${cur.id}/lines/${c.dataset.count}`, { counted_qty: c.value });
        sfx.play("tick");
        cur = await api.get(`/api/audits/${cur.id}`);
        render();
      } catch (e) { toastError(e); }
    });
    el.addEventListener("click", async (ev) => {
      const a = ev.target.closest("[data-a]");
      if (a) { openAudit(a.dataset.a).catch(toastError); return; }
      const ck = ev.target.closest("[data-ck]");
      if (ck) {
        try {
          await api.patch(`/api/audits/${cur.id}/lines/${ck.dataset.ck}`, { checked: ck.getAttribute("aria-pressed") !== "true" });
          sfx.play("tick");
          cur = await api.get(`/api/audits/${cur.id}`);
          render();
        } catch (e) { toastError(e); }
        return;
      }
      const res = ev.target.closest("[data-res]");
      if (res) {
        try {
          await api.post(`/api/audits/${cur.id}/lines/${res.dataset.l}/resolve`, { action: res.dataset.res });
          sfx.play("ok");
          cur = await api.get(`/api/audits/${cur.id}`);
          render();
        } catch (e) { toastError(e); }
        return;
      }
      const b = ev.target.closest("[data-b]");
      if (!b) return;
      const k = b.dataset.b;
      if (k === "back") { cur = null; history.replaceState(null, "", "#audit"); list = (await api.get("/api/audits")).audits; render(); }
      else if (k === "start") {
        const body = document.createElement("div");
        body.innerHTML = `<p class="help">어느 장소를 실사할까요? 아래 선반까지 모두 포함돼요.</p><div data-picker></div>`;
        const h = modal({ title: "실사 시작", code: "INSPECTION", size: "wide", body });
        locationPicker(body.querySelector("[data-picker]"), {
          onPick: async (id) => {
            try { const x = await api.post("/api/audits", { location_id: id }); h.close(); cur = x; history.replaceState(null, "", `#audit?id=${x.id}`); render(); sfx.play("ok"); } catch (e) { toastError(e); }
          },
        });
      } else if (k === "scan") {
        await openScanner({ title: cur.title, hint: "물건 라벨을 차례로 비추세요", continuous: true, onCode: check });
        render();
      } else if (k === "finish") {
        const left = cur.progress.total - cur.progress.checked;
        const ok = await confirmDialog({ title: "실사 끝내기", message: left ? `아직 ${left}건을 확인하지 않았어요. 끝내면 '확인 안 됨'으로 남고, 분실·보정 처리를 할 수 있어요.` : "모두 확인했어요. 실사를 끝낼까요?", confirmLabel: "끝내기", tone: left ? "warn" : "" });
        if (!ok) return;
        try { cur = await api.post(`/api/audits/${cur.id}/finish`, {}); sfx.play("ok"); render(); } catch (e) { toastError(e); }
      } else if (k === "report") {
        const rows = [["이름", "코드", "장소", "종류", "장부 수량", "센 수량", "확인", "처리"], ...cur.lines.map((l) => [l.name, l.code || "", l.location_path, l.kind === "asset" ? "장비" : "수량", l.expected_qty, l.counted_qty ?? "", l.checked_at ? "확인" : l.extra ? "발견" : "미확인", l.resolution || ""])];
        const csv = "﻿" + rows.map((r) => r.map((c) => (/[",\n]/.test(String(c)) ? `"${String(c).replace(/"/g, '""')}"` : c)).join(",")).join("\r\n");
        const a2 = document.createElement("a");
        a2.href = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
        a2.download = `inni-실사-${cur.title}.csv`;
        a2.click();
      }
    });
  },
  async enter(params = {}) {
    if (params.id) {
      try { await openAudit(params.id); return; } catch (e) { toastError(e); }
    }
    cur = null;
    try { list = (await api.get("/api/audits")).audits; } catch (e) { toastError(e); }
    render();
  },
  async refresh(change) {
    if (cur) { try { cur = await api.get(`/api/audits/${cur.id}`); } catch { cur = null; } }
    else { try { list = (await api.get("/api/audits")).audits; } catch { /* 다음에 */ } }
    const focused = document.activeElement && document.activeElement.matches("[data-code] input, [data-count]");
    if (!focused) render();
  },
};

export { relTime, thumb, state, app };

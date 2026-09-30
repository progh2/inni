// 08 승무원: 학교 구글 계정 승인·역할·사용 중지, 미리 등록, 로그인 허용 정책.
import { $, esc, icon, relTime } from "../lib/util.js";
import { api } from "../lib/api.js";
import { modal, toast, toastError, confirmDialog } from "../lib/ui.js";
import { state, can } from "../lib/store.js";

const ROLE = { owner: "관리자", manager: "담당교사", teacher: "교사", student: "학생" };
const ROLE_HINT = { owner: "모든 것 + AI·백업·외부 키", manager: "등록·수정·실사·수리·사용자 승인", teacher: "찾기·대여·이동·입출고·등록", student: "찾기·고장 신고(설정에 따라)" };
let root;
let users = [];
let policy = null;

function render() {
  const pending = users.filter((u) => u.status === "pending");
  root.innerHTML = `
    <div class="st-inner">
      <div class="st-head"><div class="ttl"><span class="code">STATION 08 · CREW</span><h1>승무원</h1><p>학교 구글 계정으로 처음 로그인하면 여기 "승인 대기"로 들어와요. 역할을 정하고 승인하세요.</p></div>
        <div class="tools"><button class="btn" type="button" data-b="add">${icon("plus")}미리 등록</button></div></div>
      ${pending.length ? `<section class="panel amber" style="margin-bottom:14px"><div class="panel-h"><span class="code">PENDING</span><h3>승인 대기 ${pending.length}명</h3></div>
        <div class="list">${pending.map((u) => `<div class="irow" data-u="${u.id}"><span class="avatar" style="width:36px;height:36px">${u.photo_url ? `<img src="${esc(u.photo_url)}" referrerpolicy="no-referrer" alt="">` : esc(u.name.slice(0, 1))}</span>
          <span class="tx"><span class="nm">${esc(u.name)}</span><span class="sub">${esc(u.email)} · ${esc(relTime(u.created_at))}</span></span>
          <span class="end"><select data-role style="width:auto">${Object.entries(ROLE).filter(([k]) => k !== "owner" || state.me.role === "owner").map(([k, l]) => `<option value="${k}" ${k === "teacher" ? "selected" : ""}>${l}</option>`).join("")}</select>
          <button class="btn sm good" type="button" data-approve>${icon("check")}승인</button><button class="btn sm ghost" type="button" data-reject>거절</button></span></div>`).join("")}</div></section>` : ""}
      <section class="panel"><div class="panel-h"><span class="code">ROSTER</span><h3>모든 승무원</h3><span class="sub">${users.length}명</span></div>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>이름</th><th>이메일</th><th>역할</th><th>상태</th><th>빌린 것</th><th>마지막 접속</th><th></th></tr></thead><tbody>
        ${users.map((u) => `<tr class="${u.status === "disabled" ? "dim" : ""}" data-u="${u.id}"><td><b>${esc(u.name)}</b>${u.admin_email ? ' <span class="tag amber">ADMIN_EMAILS</span>' : ""}</td><td class="mono">${esc(u.email)}</td>
          <td><select data-role style="width:auto" ${u.id === state.me.id ? "disabled" : ""}>${Object.entries(ROLE).filter(([k]) => k !== "owner" || state.me.role === "owner" || u.role === "owner").map(([k, l]) => `<option value="${k}" ${k === u.role ? "selected" : ""}>${l}</option>`).join("")}</select></td>
          <td><span class="tag ${u.status === "active" ? "good" : u.status === "pending" ? "warn" : "muted"}">${esc(u.status_label)}</span></td><td class="num">${u.loans || ""}</td>
          <td class="muted">${u.last_seen_at || u.last_login_at ? esc(relTime(u.last_seen_at || u.last_login_at)) : "—"}</td>
          <td class="act">${u.id !== state.me.id ? `${u.status === "active" ? `<button class="btn xs ghost" type="button" data-disable>사용 중지</button>` : `<button class="btn xs" type="button" data-enable>사용하게</button>`}<button class="btn xs ghost" type="button" data-del title="지우기">${icon("trash")}</button>` : '<span class="muted">나</span>'}</td></tr>`).join("")}</tbody></table></div>
        <div class="grid g4" style="margin-top:12px">${Object.entries(ROLE).map(([k, l]) => `<div class="tile"><div class="k">${l}</div><div class="s">${ROLE_HINT[k]}</div></div>`).join("")}</div></section>
      ${policy ? `<section class="panel" style="margin-top:14px"><div class="panel-h"><span class="code">ACCESS POLICY</span><h3>로그인·권한 정책</h3><span class="sub">${can("system") ? "" : "관리자만 바꿀 수 있어요"}</span></div>
        <div class="stack" style="gap:10px">
          <label class="switch"><input type="checkbox" data-p="auto_approve" ${policy.auto_approve ? "checked" : ""} ${can("system") ? "" : "disabled"}><i></i>허용 도메인 계정은 승인 없이 바로 쓰기(기본 역할로)</label>
          <label class="field" style="max-width:280px"><span>처음 들어온 사람의 역할</span><select data-p="default_role" ${can("system") ? "" : "disabled"}>${["teacher", "manager", "student"].map((k) => `<option value="${k}" ${policy.default_role === k ? "selected" : ""}>${ROLE[k]}</option>`).join("")}</select></label>
          <label class="switch"><input type="checkbox" data-p="teacher_can_register" ${policy.teacher_can_register ? "checked" : ""} ${can("system") ? "" : "disabled"}><i></i>교사도 새 물건 등록</label>
          <label class="switch"><input type="checkbox" data-p="teacher_can_move" ${policy.teacher_can_move ? "checked" : ""} ${can("system") ? "" : "disabled"}><i></i>교사도 위치 옮기기</label>
          <label class="switch"><input type="checkbox" data-p="student_login" ${policy.student_login ? "checked" : ""} ${can("system") ? "" : "disabled"}><i></i>학생 로그인 허용(찾기·고장 신고)</label>
          <label class="switch"><input type="checkbox" data-p="student_ai" ${policy.student_ai ? "checked" : ""} ${can("system") ? "" : "disabled"}><i></i>학생도 이니에게 묻기</label>
          <p class="help">로그인할 수 있는 학교 도메인: <b>${esc([...(state.authConfig.domains || [])].map((d) => `@${d}`).join(", ") || "제한 없음(모든 구글 계정, 대신 승인 필요)")}</b> — 시스템 → 학교에서 더할 수 있어요.</p></div></section>` : ""}
    </div>`;
}

async function load() {
  try {
    users = (await api.get("/api/users")).users;
    policy = state.settings.access;
  } catch (e) { toastError(e); }
  render();
}

async function update(id, patch) {
  try { await api.patch(`/api/users/${id}`, patch); toast("바꿨어요", { tone: "good" }); await load(); } catch (e) { toastError(e); await load(); }
}

export default {
  mount(el) {
    root = el;
    el.addEventListener("change", async (ev) => {
      const r = ev.target.closest("[data-role]");
      const row = ev.target.closest("[data-u]");
      if (r && row && !row.querySelector("[data-approve]")) update(row.dataset.u, { role: r.value });
      const p = ev.target.closest("[data-p]");
      if (p) {
        const next = { ...state.settings.access, [p.dataset.p]: p.type === "checkbox" ? p.checked : p.value };
        try { await api.patch("/api/settings", { access: next }); state.settings.access = next; policy = next; toast("정책을 바꿨어요", { tone: "good" }); } catch (e) { toastError(e); }
      }
    });
    el.addEventListener("click", async (ev) => {
      const row = ev.target.closest("[data-u]");
      const b = ev.target.closest("button");
      if (!b) return;
      if (b.dataset.b === "add") {
        modal({
          title: "미리 등록", code: "CREW",
          body: `<p class="help">첫 로그인 전에 역할을 정해 두면, 그 계정은 로그인하자마자 바로 쓸 수 있어요.</p>
            <div class="form-grid"><label class="field"><span>학교 구글 이메일</span><input type="email" data-k="email" autofocus></label>
            <label class="field"><span>이름</span><input type="text" data-k="name"></label>
            <label class="field"><span>역할</span><select data-k="role">${Object.entries(ROLE).filter(([k]) => k !== "owner" || state.me.role === "owner").map(([k, l]) => `<option value="${k}" ${k === "teacher" ? "selected" : ""}>${l}</option>`).join("")}</select></label></div>`,
          actions: [{ label: "취소", tone: "ghost" }, { label: "등록", tone: "primary", onClick: async (h) => {
            const body = {};
            for (const x of h.el.querySelectorAll("[data-k]")) body[x.dataset.k] = x.value.trim();
            try { await api.post("/api/users", body); toast("등록했어요", { tone: "good" }); await load(); return true; } catch (e) { toastError(e); return false; }
          } }],
        });
        return;
      }
      if (!row) return;
      const id = row.dataset.u;
      if (b.hasAttribute("data-approve")) update(id, { status: "active", role: row.querySelector("[data-role]").value });
      else if (b.hasAttribute("data-reject") || b.hasAttribute("data-disable")) update(id, { status: "disabled" });
      else if (b.hasAttribute("data-enable")) update(id, { status: "active" });
      else if (b.hasAttribute("data-del")) {
        if (await confirmDialog({ title: "승무원 지우기", message: "이 계정을 지울까요? 기록의 이름은 남아요.", confirmLabel: "지우기", tone: "danger" })) {
          try { await api.del(`/api/users/${id}`); await load(); } catch (e) { toastError(e); }
        }
      }
    });
  },
  enter() { return load(); },
  refresh() { return load(); },
};

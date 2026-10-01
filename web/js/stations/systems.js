// 10 시스템: 학교 · AI 코어(이니의 두뇌) · 제품 검색·이미지 · 알림 · 라벨 · 백업·이전 · 이 기기 · 정보
import { $, esc, icon, relTime, fmtDateTime, num, downloadBlob, bytes } from "../lib/util.js";
import { api } from "../lib/api.js";
import { modal, toast, toastError, confirmDialog, busy, promptDialog } from "../lib/ui.js";
import { state, can, loadAiConfig, refreshCore } from "../lib/store.js";
import { app, prefs, savePrefs, PREF_DEFAULTS } from "../app.js";
import * as sfx from "../lib/sfx.js";
import { characterHtml } from "../ai/character.js";
import { koreanVoices, speak } from "../ai/voice.js";
import { printLabels } from "../features/labels.js";

let root;
let tab = "school";
let S = null; // 관리 설정
let AI = null; // AI 설정
let models = {}; // 연결 id → 모델 목록
let backups = null;
let setupInfo = null;

const TABS = [
  ["school", "SCHOOL", "학교", "settings"],
  ["ai", "AI CORE", "AI 코어", "system"],
  ["product", "PRODUCT", "제품 검색·사진", "system"],
  ["alerts", "ALERTS", "알림", "system"],
  ["labels", "LABELS", "라벨", "settings"],
  ["backup", "BACKUP", "백업·이전", "system"],
  ["device", "DEVICE", "이 기기", null],
  ["about", "INFO", "정보·도움말", null],
];

async function patch(body, msg = "저장했어요") {
  try {
    S = await api.patch("/api/settings", body);
    toast(msg, { tone: "good" });
    await refreshCore();
    return true;
  } catch (e) { toastError(e); return false; }
}

const sw = (k, label, on, dis = false) => `<label class="switch"><input type="checkbox" data-sw="${k}" ${on ? "checked" : ""} ${dis ? "disabled" : ""}><i></i>${label}</label>`;
const secretField = (k, label, hint, placeholder = "") => `<label class="field"><span>${label} ${S.secrets[k] ? `<span class="tag good">${esc(S.secrets[k])}</span>` : '<span class="tag muted">없음</span>'}</span>
  <input type="password" data-secret="${k}" placeholder="${esc(S.secrets[k] ? "바꿀 때만 새로 입력" : placeholder)}" autocomplete="new-password"><span class="hint">${hint}</span></label>`;

// ---------------------------------------------------------------- 학교
function schoolHtml() {
  return `<div class="grid g2" style="align-items:start">
    <section class="panel"><div class="panel-h"><span class="code">SCHOOL</span><h3>학교</h3></div>
      <div class="form-grid"><label class="field"><span>학교 이름</span><input type="text" data-f="school.name" value="${esc(S.school.name)}"></label>
      <label class="field"><span>짧은 이름(라벨용)</span><input type="text" data-f="school.short_name" value="${esc(S.school.short_name)}" placeholder="예: 한빛공고"></label></div>
      <div class="row" style="margin-top:10px"><button class="btn primary" type="button" data-save="school">저장</button></div></section>
    <section class="panel"><div class="panel-h"><span class="code">LOGIN</span><h3>로그인 허용 학교 도메인</h3></div>
      <p class="help">서버 설정(.env ALLOWED_DOMAINS): <b>${esc(S.env.allowed_domains.join(", ") || "없음")}</b> · 관리자(ADMIN_EMAILS): <b>${esc(S.env.admin_emails.join(", ") || "없음")}</b></p>
      <label class="field"><span>여기서 더할 도메인 <span class="hint">쉼표로, 예: sen.go.kr, hanbit.hs.kr</span></span><input type="text" data-domains value="${esc((S.allowed_domains || []).join(", "))}" ${can("system") ? "" : "disabled"}></label>
      <p class="help" style="margin-top:6px">비워 두면(서버 설정도 없으면) 모든 구글 계정이 들어올 수 있지만 <b>승인 대기</b>로 들어옵니다.</p>
      ${can("system") ? `<button class="btn primary" type="button" data-save="domains">저장</button>` : ""}</section>
    <section class="panel"><div class="panel-h"><span class="code">NUMBERING</span><h3>관리번호·대여 기본값</h3></div>
      <div class="form-grid"><label class="field"><span>관리번호 기본 앞머리</span><input type="text" data-f="numbering.default_prefix" value="${esc(S.numbering.default_prefix)}" placeholder="예: 전자-2026-"></label>
      <label class="field"><span>수업 끝 시각(오늘 반납 기준)</span><input type="time" data-f="loan.day_end" value="${esc(S.loan.day_end)}"></label>
      <label class="field"><span>대여 기본 기한</span><select data-f="loan.default_due">${[["today", "오늘"], ["tomorrow", "내일"], ["week", "1주"], ["none", "기한 없음"]].map(([v, l]) => `<option value="${v}" ${S.loan.default_due === v ? "selected" : ""}>${l}</option>`).join("")}</select></label></div>
      <div class="row" style="margin-top:10px"><button class="btn primary" type="button" data-save="numbering">저장</button></div></section>
    <section class="panel"><div class="panel-h"><span class="code">ASSISTANT</span><h3>AI 보급관</h3></div>
      <div class="row nw" style="gap:14px">${characterHtml({ size: "md", mood: "happy" })}<div class="stack" style="gap:8px;flex:1">
        <label class="field"><span>이름</span><input type="text" data-f="assistant.name" value="${esc(S.assistant.name)}" maxlength="12"></label>
        ${sw("assistant.proactive", "먼저 알려 주기(경보·도움말 말풍선)", S.assistant.proactive)}</div></div>
      <div class="row" style="margin-top:10px"><button class="btn primary" type="button" data-save="assistant">저장</button></div></section>
  </div>`;
}

// ---------------------------------------------------------------- AI 코어
const PROVIDER_CARDS = [
  ["openai", "ChatGPT (OpenAI API)", "API 키 sk-… · 사진 읽기 가능(gpt-4.1-mini 등)"],
  ["ollama", "Ollama (학교 PC 로컬)", "무료 · 학교 밖으로 안 나감 · http://PC:11434"],
  ["aiapi", "AIAPI 관제 함교", "aiapi-manager 프록시 · 선생님이 발급한 가상 키"],
  ["compatible", "OpenAI 호환 서버", "LM Studio · vLLM · llama.cpp · Gemini 호환 주소"],
];

function aiHtml() {
  const a = AI;
  const conn = (id) => a.connections.find((c) => c.id === id);
  const chatC = conn(a.chat.connection_id);
  const visC = conn(a.vision.connection_id) || chatC;
  const opt = (sel) => `<option value="">(고르기)</option>${a.connections.map((c) => `<option value="${c.id}" ${c.id === sel ? "selected" : ""}>${esc(c.name)}</option>`).join("")}`;
  const modelList = (cid) => (models[cid] || []).map((m) => `<option value="${esc(m.id)}">${esc(m.badge || "")}${m.tools === false ? " · 대화만" : ""}${m.vision ? " · 사진" : ""}</option>`).join("");
  return `<div class="grid g3" style="align-items:start">
    <section class="panel"><div class="panel-h"><span class="code">CORE STATUS</span><h3>${esc(state.ai.name)} 상태</h3></div>
      <div style="text-align:center">${characterHtml({ size: "lg", mood: a.ready ? "happy" : "sleep", cone: true })}</div>
      <dl class="kv" style="margin-top:10px"><dt>상태</dt><dd>${a.enabled ? (a.ready ? '<span class="chip-s good"><i></i>가동 중</span>' : '<span class="chip-s warn"><i></i>연결 덜 됨</span>') : '<span class="chip-s muted"><i></i>꺼짐(기본 명령만)</span>'}</dd>
        <dt>대화</dt><dd>${chatC ? `${esc(chatC.name)} · <code>${esc(a.chat.model || "모델 없음")}</code>` : "—"}</dd>
        <dt>사진 읽기</dt><dd>${a.vision_ready ? `${esc(visC ? visC.name : "")} · <code>${esc(a.vision.model || a.chat.model)}</code>` : "—"}</dd>
        <dt>개인정보</dt><dd>${chatC && chatC.external ? (a.mask_names ? "학교 밖 · 이름 가림" : '<span style="color:#ffd779">학교 밖 · 이름 그대로</span>') : "학교 안에서 처리"}</dd>
        <dt>이번 달</dt><dd>질문 ${num(a.usage.requests)} · 토큰 ${num(a.usage.prompt_tokens + a.usage.completion_tokens)}${a.monthly_token_cap ? ` / ${num(a.monthly_token_cap)}` : ""}</dd></dl>
      <div class="row" style="margin-top:12px">${sw("ai.enabled", `${esc(state.ai.name)} 두뇌 켜기`, a.enabled)}</div></section>
    <section class="panel span2"><div class="panel-h"><span class="code">NEURAL LINKS</span><h3>AI 연결</h3><span class="end"><button class="btn sm primary" type="button" data-ai="add">${icon("plus")}연결 추가</button></span></div>
      ${a.connections.length ? `<div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">${a.connections.map((c) => `<div class="card" data-c="${c.id}">
        <div class="card-h"><div class="ttl">${esc(c.name)}<small>${esc(c.provider_label)}${c.base_url ? ` · ${esc(c.base_url)}` : ""}</small></div>
          <div class="end">${c.external ? '<span class="tag warn">학교 밖</span>' : '<span class="tag good">학교 안</span>'}</div></div>
        <div class="help" style="margin:0">키: ${c.has_key ? esc(c.key_hint) : "없음"}${c.last_test ? ` · 마지막 시험: ${c.last_test.ok ? `성공 ${esc(c.last_test.model)} (${(c.last_test.latency_ms / 1000).toFixed(1)}초${c.last_test.tools_ok ? ", 도구 ✓" : ""}${c.last_test.vision_ok ? ", 사진 ✓" : ""})` : `실패 ${esc(c.last_test.error || "")}`} · ${esc(relTime(c.last_test.at))}` : ""}</div>
        <div class="card-actions"><button class="btn sm" type="button" data-ai="models">${icon("refresh")}모델 보기</button><button class="btn sm" type="button" data-ai="edit">${icon("edit")}고치기</button><button class="btn sm ghost" type="button" data-ai="del">${icon("trash")}</button></div>
        <div data-models></div></div>`).join("")}</div>`
      : `<div class="empty">아직 연결이 없어요. [연결 추가]로 ChatGPT API 키나 학교 Ollama, AIAPI 프록시를 등록하세요.<br><span class="muted">연결이 없어도 이니는 "찾기·옮기기" 같은 기본 명령을 알아들어요.</span></div>`}</section>
    <section class="panel span2"><div class="panel-h"><span class="code">ROLES</span><h3>무엇에 어떤 모델을 쓸까</h3></div>
      <div class="form-grid">
        <label class="field"><span>대화(이니) 연결</span><select data-role="chat.connection_id">${opt(a.chat.connection_id)}</select></label>
        <label class="field"><span>대화 모델 <span class="hint">도구 호출 되는 모델</span></span><input type="text" data-role="chat.model" value="${esc(a.chat.model)}" list="ml-chat" placeholder="예: gpt-4.1-mini, qwen3:8b"><datalist id="ml-chat">${modelList(a.chat.connection_id)}</datalist></label>
        <label class="field"><span>사진 읽기 연결 <span class="hint">비우면 대화 연결</span></span><select data-role="vision.connection_id">${opt(a.vision.connection_id)}</select></label>
        <label class="field"><span>사진 읽기 모델 <span class="hint">비전 모델</span></span><input type="text" data-role="vision.model" value="${esc(a.vision.model)}" list="ml-vis" placeholder="예: gpt-4.1-mini, qwen2.5vl:7b, gemma3"><datalist id="ml-vis">${modelList(a.vision.connection_id || a.chat.connection_id)}</datalist></label>
      </div>
      <div class="row" style="margin-top:10px"><button class="btn primary" type="button" data-ai="roles">저장</button><button class="btn" type="button" data-ai="test-chat">${icon("bolt")}대화 시험</button><button class="btn" type="button" data-ai="test-vision">${icon("image")}사진 시험</button></div>
      <p class="help" style="margin-top:10px">추천: <b>OpenAI</b> gpt-4.1-mini·gpt-5-mini(대화+사진) · <b>Ollama</b> qwen3:8b(대화) + qwen2.5vl:7b 또는 gemma3(사진) · <b>AIAPI</b>는 aiapi-manager 에서 이니용 키를 발급해 넣으세요(사용량이 그쪽 통계에 잡힘).</p></section>
    <section class="panel"><div class="panel-h"><span class="code">PROTOCOLS</span><h3>행동 규칙</h3></div>
      <div class="stack" style="gap:10px">${sw("ai.mask_names", "학교 밖 AI 로 보낼 때 사람 이름 가리기", a.mask_names)}${sw("ai.fast_mode", "빠른 응답(생각 단계 줄이기)", a.fast_mode)}
        <label class="field"><span>월 토큰 상한 <span class="hint">0 = 제한 없음</span></span><input type="number" data-rule="monthly_token_cap" value="${a.monthly_token_cap}"></label>
        <label class="field"><span>문맥 길이(Ollama)</span><select data-rule="num_ctx">${[4096, 8192, 16384, 32768].map((n) => `<option ${a.num_ctx === n ? "selected" : ""}>${n}</option>`).join("")}</select></label>
        <label class="field"><span>답변 길이(토큰)</span><input type="number" data-rule="max_output_tokens" value="${a.max_output_tokens}"></label>
        <button class="btn primary" type="button" data-ai="rules">규칙 저장</button></div></section>
  </div>`;
}

function connectionDialog(existing = null) {
  let provider = existing ? existing.provider : "openai";
  const body = document.createElement("div");
  const paint = () => {
    const P = AI.providers[provider];
    body.innerHTML = `
      <div class="setup-opts" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr))">${PROVIDER_CARDS.map(([k, t, s]) => `<button class="setup-opt ${k === provider ? "found" : ""}" type="button" data-p="${k}" ${existing ? "disabled" : ""}><b>${t}</b><span>${s}</span></button>`).join("")}</div>
      <div class="form-grid" style="margin-top:12px">
        <label class="field"><span>이름</span><input type="text" data-k="name" value="${esc(existing ? existing.name : P.label)}"></label>
        ${P.needsUrl ? `<label class="field wide"><span>서버 주소 <span class="req">*</span></span><input type="url" data-k="base_url" value="${esc(existing ? existing.base_url : "")}" placeholder="${esc(P.defaultUrl || "")}"><span class="hint">${provider === "ollama" ? "Ollama 가 돌아가는 PC 주소. NAS 도커 안의 localhost 는 NAS 자신이에요." : provider === "aiapi" ? "같은 NAS 면 http://host.docker.internal:4000 (aiapi-manager LITELLM_PORT)" : "…/v1 까지 적어도 되고 안 적어도 돼요"}</span></label>` : ""}
        ${P.needsKey || provider === "compatible" ? `<label class="field wide"><span>API 키 ${P.needsKey ? '<span class="req">*</span>' : '<span class="hint">필요한 서버만</span>'} ${existing && existing.has_key ? `<span class="tag good">${esc(existing.key_hint)}</span>` : ""}</span><input type="password" data-k="api_key" placeholder="${existing && existing.has_key ? "바꿀 때만 입력" : "sk-…"}" autocomplete="new-password"></label>` : ""}
      </div>
      <div class="row" style="margin-top:10px"><button class="btn" type="button" data-t="models">${icon("refresh")}모델 불러오기</button><span class="help" data-status style="margin:0"></span></div>
      <div data-mlist style="margin-top:8px;max-height:260px;overflow:auto"></div>`;
  };
  paint();
  const draft = () => {
    const d = { provider };
    for (const el of body.querySelectorAll("[data-k]")) d[el.dataset.k] = el.value.trim();
    return d;
  };
  body.addEventListener("click", async (ev) => {
    const p = ev.target.closest("[data-p]");
    if (p && !existing) { provider = p.dataset.p; paint(); return; }
    const t = ev.target.closest('[data-t="models"]');
    if (t) {
      const st = body.querySelector("[data-status]");
      await busy(t, async () => {
        try {
          const r = await api.post("/api/ai/models", { connection_id: existing ? existing.id : undefined, draft: draft() });
          st.textContent = `${r.models.length}개 · 좋은 순`;
          body.querySelector("[data-mlist]").innerHTML = r.models.slice(0, 40).map((m) => `<div class="irow" style="cursor:default"><span class="tx"><span class="nm mono">${esc(m.id)} ${m.badge ? `<span class="tag ${m.grade >= 3 ? "good" : m.grade === 0 ? "muted" : "info"}">${esc(m.badge)}</span>` : ""} ${m.tools ? '<span class="tag info">도구 ✓</span>' : m.tools === false ? '<span class="tag muted">도구 ×</span>' : ""} ${m.vision ? '<span class="tag amber">사진</span>' : ""}</span><span class="sub">${esc([m.params, m.size_gb ? `${m.size_gb}GB` : "", m.note].filter(Boolean).join(" · "))}</span></span></div>`).join("");
        } catch (e) { st.textContent = e.message; sfx.play("error"); }
      });
    }
  });
  modal({
    title: existing ? `${existing.name} 고치기` : "AI 연결 추가", code: "NEURAL LINK", size: "wide", body,
    actions: [{ label: "취소", tone: "ghost" }, { label: "저장", tone: "primary", onClick: async () => {
      try {
        AI = await api.post("/api/ai/connections", { ...draft(), id: existing ? existing.id : undefined });
        toast("연결을 저장했어요. 아래에서 대화 모델을 고르세요.", { tone: "good" });
        await loadAiConfig();
        render();
        return true;
      } catch (e) { toastError(e); return false; }
    } }],
  });
}

// ---------------------------------------------------------------- 제품 검색·사진
function productHtml() {
  return `<div class="grid g2" style="align-items:start">
    <section class="panel"><div class="panel-h"><span class="code">PRODUCT SEARCH</span><h3>제품 이름으로 사진·정보 찾기</h3></div>
      <p class="help">등록 화면의 [제품 찾기]에 쓰입니다. 무료 검색 API 키를 넣으세요(없어도 검색 사이트 링크는 보여 줘요).</p>
      <div class="form-grid">
        ${secretField("naver_client_id", "네이버 Client ID", "developers.naver.com → 애플리케이션 등록 → 검색 API", "")}
        ${secretField("naver_client_secret", "네이버 Client Secret", "쇼핑·이미지 검색 하루 25,000번 무료")}
        ${secretField("kakao_rest_key", "카카오 REST API 키", "developers.kakao.com → 내 애플리케이션 → 앱 키")}
        <label class="field"><span>먼저 쓸 검색</span><select data-f="product_search.provider">${[["auto", "자동"], ["naver_shop", "네이버 쇼핑(가격·제조사)"], ["naver_image", "네이버 이미지"], ["kakao_image", "카카오 이미지"]].map(([v, l]) => `<option value="${v}" ${S.product_search.provider === v ? "selected" : ""}>${l}</option>`).join("")}</select></label>
      </div>
      <div class="row" style="margin-top:10px"><button class="btn primary" type="button" data-save="product">저장</button></div></section>
    <section class="panel"><div class="panel-h"><span class="code">IMAGE LAB</span><h3>사진 배경 지우기·링크 읽기</h3></div>
      <label class="field"><span>AI 배경 지우기 방식</span><select data-f="images.bg_removal">
        <option value="browser" ${S.images.bg_removal === "browser" ? "selected" : ""}>브라우저에서(처음 한 번 모델 약 40MB 받음, 인터넷 필요)</option>
        <option value="rembg" ${S.images.bg_removal === "rembg" ? "selected" : ""}>배경 제거 서버(rembg 컨테이너)</option>
        <option value="off" ${S.images.bg_removal === "off" ? "selected" : ""}>끄기(빠른 지우기·지우개만)</option></select></label>
      <label class="field" style="margin-top:8px"><span>rembg 서버 주소 <span class="hint">docker compose --profile rembg 로 켠 경우 http://rembg:7000</span></span><input type="url" data-f="images.rembg_url" value="${esc(S.images.rembg_url)}" placeholder="http://rembg:7000"></label>
      <div class="stack" style="gap:8px;margin-top:12px">${sw("url_import.enabled", "제품 링크로 정보 채우기 켜기", S.url_import.enabled)}${sw("url_import.allow_private", "학교 내부망 주소도 읽기(보통 끔)", S.url_import.allow_private)}</div>
      <div class="row" style="margin-top:10px"><button class="btn primary" type="button" data-save="images">저장</button></div></section></div>`;
}

// ---------------------------------------------------------------- 알림
function alertsHtml() {
  const t = S.telegram;
  return `<section class="panel" style="max-width:760px"><div class="panel-h"><span class="code">TELEGRAM</span><h3>텔레그램 알림</h3></div>
    <p class="help">연체·재고 부족·고장 신고를 담당 선생님 텔레그램으로 보냅니다. @BotFather 에서 봇을 만들고 토큰을 넣은 뒤, 봇과 대화를 시작한 채팅 ID(또는 그룹 ID)를 적으세요.</p>
    <div class="form-grid">${secretField("telegram_token", "봇 토큰", "예: 123456:ABC-…")}
      <label class="field"><span>채팅 ID <span class="hint">쉼표로 여러 개</span></span><input type="text" data-f="telegram.chat_ids" value="${esc((t.chat_ids || []).join(", "))}" placeholder="예: 12345678, -100987654321"></label></div>
    <div class="stack" style="gap:8px;margin-top:12px">${sw("telegram.enabled", "알림 켜기", t.enabled)}${sw("telegram.overdue", "연체", t.overdue)}${sw("telegram.low", "재고 부족", t.low)}${sw("telegram.repair", "고장 신고", t.repair)}${sw("telegram.backup", "자동 백업 결과", t.backup)}</div>
    <div class="row" style="margin-top:12px"><button class="btn primary" type="button" data-save="telegram">저장</button><button class="btn" type="button" data-b="tg-test">${icon("send")}시험 메시지</button></div></section>`;
}

// ---------------------------------------------------------------- 라벨
function labelsHtml() {
  const l = S.labels;
  return `<section class="panel" style="max-width:760px"><div class="panel-h"><span class="code">LABEL PRINTER</span><h3>라벨 기본값</h3></div>
    <label class="field"><span>용지</span><select data-f="labels.paper">${Object.entries(S.label_papers).map(([k, p]) => `<option value="${k}" ${l.paper === k ? "selected" : ""}>${esc(p.name)}</option>`).join("")}</select></label>
    <div class="stack" style="gap:8px;margin-top:12px">${sw("labels.show_name", "이름", l.show_name)}${sw("labels.show_number", "관리번호", l.show_number)}${sw("labels.show_location", "장소", l.show_location)}${sw("labels.show_school", "학교 이름", l.show_school)}</div>
    <p class="help" style="margin-top:10px">QR 에는 <code>${esc((state.server && state.server.public_url) || location.origin)}/q/코드</code> 가 들어가요. NAS 주소가 바뀔 예정이면 .env 의 PUBLIC_URL 을 정해 두세요(주소가 바뀌어도 앱 스캐너는 코드만 읽어서 괜찮아요).</p>
    <div class="row" style="margin-top:10px"><button class="btn primary" type="button" data-save="labels">저장</button><button class="btn" type="button" data-b="label-test">${icon("print")}시험 인쇄</button></div></section>`;
}

// ---------------------------------------------------------------- 백업·이전
function backupHtml() {
  const b = backups;
  const cfg = S.backup;
  return `<div class="grid g2" style="align-items:start">
    <section class="panel"><div class="panel-h"><span class="code">BACKUP</span><h3>백업</h3><span class="end"><button class="btn primary sm" type="button" data-b="backup-now">${icon("database")}지금 백업</button></span></div>
      <p class="help">백업 파일(zip) 하나에 데이터베이스·사진·설정이 모두 들어갑니다. 다른 NAS·PC 로 옮길 때도 이 파일 하나면 돼요.</p>
      <div class="stack" style="gap:8px">${sw("backup.auto", "매일 자동 백업", cfg.auto)}
        <div class="row"><label class="field" style="width:130px"><span>시각</span><select data-f="backup.hour">${Array.from({ length: 24 }, (_, h) => `<option value="${h}" ${cfg.hour === h ? "selected" : ""}>${h}시</option>`).join("")}</select></label>
        <label class="field" style="width:130px"><span>남길 개수</span><input type="number" data-f="backup.keep" value="${cfg.keep}" min="1" max="365"></label>
        <button class="btn sm" type="button" data-save="backup" style="align-self:flex-end">저장</button></div></div>
      <p class="help" style="margin-top:8px">저장 위치: <code>${esc(b ? b.dir : "")}</code> — NAS 의 <b>Hyper Backup</b> 으로 이 폴더(또는 inni 폴더 전체)를 다른 곳에 한 번 더 복사해 두면 가장 안전해요.</p>
      <div class="list" style="margin-top:10px">${b && b.backups.length ? b.backups.map((x) => `<div class="irow" data-bk="${esc(x.name)}"><span class="thumb sm blank">${icon("database")}</span><span class="tx"><span class="nm mono" style="font-size:12.5px">${esc(x.name)}</span><span class="sub">${esc(fmtDateTime(x.created_at))} · ${bytes(x.bytes)}${x.auto ? " · 자동" : x.before_restore ? " · 복원 전 자동 보관" : x.before_update ? " · 업데이트 전 자동 보관" : ""}</span></span>
        <span class="end"><a class="btn xs" href="/api/backups/${encodeURIComponent(x.name)}" download>${icon("download")}</a><button class="btn xs warn" type="button" data-restore>복원</button><button class="btn xs ghost" type="button" data-bkdel>${icon("trash")}</button></span></div>`).join("") : '<div class="empty">아직 백업이 없어요</div>'}</div></section>
    <div class="stack">
      <section class="panel"><div class="panel-h"><span class="code">MIGRATION</span><h3>이전(다른 NAS·PC 로 옮기기)</h3></div>
        <ol class="guide-steps"><li>여기서 <b>지금 백업</b> → 파일 받기(${icon("download")})</li><li>새 NAS 에 inni 를 설치하고 관리자로 로그인</li><li>새 inni 의 이 화면에서 <b>백업 파일로 복원</b> → 받은 zip 고르기</li></ol>
        <p class="help" style="margin-top:10px">또는 NAS 에서 <code>docker/inni</code> 폴더를 통째로 복사해도 됩니다(컨테이너를 멈춘 뒤).</p>
        <button class="btn warn" type="button" data-b="restore-file">${icon("upload")}백업 파일로 복원…</button></section>
      ${setupInfo && setupInfo.legacy && setupInfo.legacy.found ? `<section class="panel amber"><div class="panel-h"><span class="code">LEGACY</span><h3>예전 inni(PHP) 데이터</h3></div>
        <p class="help">${esc(setupInfo.legacy.path)} · 품목 ${setupInfo.legacy.counts.items} · 장비 ${setupInfo.legacy.counts.assets} · 장소 ${setupInfo.legacy.counts.locations}</p>
        <button class="btn primary" type="button" data-b="legacy">${icon("download")}가져오기</button></section>` : ""}
      <section class="panel"><div class="panel-h"><span class="code">CSV</span><h3>엑셀(CSV)로 한꺼번에</h3></div>
        <p class="help">엑셀 대장을 옮길 때: 양식을 받아 채운 뒤 <b>CSV UTF-8</b>(또는 CSV)로 저장해 올리세요. 먼저 미리보기로 무엇이 들어갈지 보여 드려요.</p>
        <div class="row"><a class="btn" href="/api/export/template.csv">${icon("download")}양식</a><a class="btn" href="/api/export/items.csv">${icon("download")}지금 물품 내보내기</a>
          <label class="btn primary">${icon("upload")}CSV 가져오기<input type="file" accept=".csv,text/csv" hidden data-csv></label></div></section>
      <section class="panel"><div class="panel-h"><span class="code">MAINTENANCE</span><h3>정리</h3></div>
        <button class="btn" type="button" data-b="orphans">${icon("image")}쓰지 않는 사진 정리</button></section>
    </div></div>`;
}

export function restoreFromFile() {
  const body = `<p class="lead">백업 파일(inni-backup-….zip)을 고르세요. 지금 데이터는 <b>먼저 자동으로 백업</b>한 뒤 바뀝니다. 복원 후 모두 다시 로그인해야 해요.</p>
    <input type="file" accept=".zip,application/zip" id="rs-file">
    <label class="field" style="margin-top:12px"><span>확인을 위해 <b>복원</b> 을 입력하세요</span><input type="text" id="rs-typed" autocomplete="off"></label>`;
  modal({
    title: "백업 파일로 복원", code: "RESTORE", tone: "crit", body,
    actions: [{ label: "취소", tone: "ghost" }, { label: "복원", tone: "danger", icon: "upload", onClick: async (h) => {
      const f = h.el.querySelector("#rs-file").files[0];
      if (!f) { toast("파일을 고르세요", { tone: "warn" }); return false; }
      if (h.el.querySelector("#rs-typed").value.trim() !== "복원") { toast("'복원'을 입력하세요", { tone: "warn" }); return false; }
      try {
        const r = await api("/api/restore-upload", { method: "POST", raw: f, headers: { "Content-Type": "application/zip", "x-inni-confirm": encodeURIComponent("복원") } });
        toast(`복원했어요: ${r.manifest.school || ""} (${fmtDateTime(r.manifest.created_at)})`, { tone: "good", timeout: 0 });
        setTimeout(() => location.reload(), 2500);
        return true;
      } catch (e) { toastError(e); return false; }
    } }],
  });
}

// ---------------------------------------------------------------- 이 기기
function deviceHtml() {
  const voices = koreanVoices();
  return `<section class="panel" style="max-width:760px"><div class="panel-h"><span class="code">DEVICE</span><h3>이 기기(브라우저) 화면 설정</h3><span class="sub">이 기기에만 저장돼요</span></div>
    <div class="stack" style="gap:10px">
      <label class="field" style="max-width:320px"><span>3D 선내 지도</span><select data-pref="scene"><option value="auto" ${prefs.scene === "auto" ? "selected" : ""}>자동(PC 켜기·휴대폰 끄기)</option><option value="on" ${prefs.scene === "on" ? "selected" : ""}>켜기</option><option value="off" ${prefs.scene === "off" ? "selected" : ""}>끄기</option></select></label>
      ${[["reduceMotion", "움직임 줄이기(전환·깜빡임 끄기)"], ["contrast", "대비 강화(밝은 교실·프로젝터)"], ["bigText", "글자 크게"], ["sound", "효과음"], ["voice", `${esc(state.ai.name)} 목소리(답을 읽어 줌)`], ["proactive", "먼저 알려 주기(말풍선)"]].map(([k, l]) => `<label class="switch"><input type="checkbox" data-pref="${k}" ${prefs[k] ? "checked" : ""}><i></i>${l}</label>`).join("")}
      <label class="field" style="max-width:320px"><span>음량</span><input type="range" min="0" max="1" step="0.05" data-pref="volume" value="${prefs.volume}"></label>
      <label class="field" style="max-width:320px"><span>목소리</span><select data-pref="voiceName"><option value="">기본 한국어</option>${voices.map((v) => `<option ${prefs.voiceName === v.name ? "selected" : ""}>${esc(v.name)}</option>`).join("")}</select></label>
      <label class="field" style="max-width:320px"><span>상황판 모드 전환 간격(초)</span><input type="number" min="5" max="300" data-pref="autoInterval" value="${prefs.autoInterval}"></label>
      <div class="row"><button class="btn" type="button" data-b="voice-test">${icon("volume")}목소리 듣기</button><button class="btn ghost" type="button" data-b="prefs-reset">기본값으로</button></div></div></section>`;
}

export function openDevicePrefs() {
  const h = modal({ title: "이 기기 화면 설정", code: "DEVICE", size: "wide", body: deviceHtml() });
  bindDevice(h.el);
}

function bindDevice(scope) {
  scope.addEventListener("change", (ev) => {
    const p = ev.target.closest("[data-pref]");
    if (!p) return;
    const k = p.dataset.pref;
    prefs[k] = p.type === "checkbox" ? p.checked : p.type === "range" || p.type === "number" ? Number(p.value) : p.value;
    savePrefs();
    if (app.applyPrefs) app.applyPrefs();
    if (k === "scene" && app.toggleScene) {
      const off = document.body.classList.contains("scene-off") || document.body.classList.contains("no-webgl");
      if ((prefs.scene === "off" && !off) || (prefs.scene === "on" && off)) app.toggleScene();
    }
  });
  scope.addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-b]");
    if (!b) return;
    if (b.dataset.b === "voice-test") speak(`안녕하세요, 보급관 ${state.ai.name}예요. 찾는 물건을 말씀해 주세요.`, { force: true });
    if (b.dataset.b === "prefs-reset") { Object.assign(prefs, PREF_DEFAULTS); savePrefs(); if (app.applyPrefs) app.applyPrefs(); toast("기본값으로 돌렸어요"); }
  });
}

// ---------------------------------------------------------------- 정보
// NAS 자동 업데이트(scripts/nas-auto-update.sh) 상태 한 줄
function updateRow(au) {
  const chip = (tone, text) => `<span class="chip-s ${tone}" style="white-space:normal"><i></i>${esc(text)}</span>`;
  if (!au) return chip("muted", "설정 안 됨 — docs/nas-deploy.md 7장 '자동 업데이트'");
  const checked = au.checked_at ? relTime(au.checked_at) : "알 수 없음";
  if (au.checked_at && Date.now() - Date.parse(au.checked_at) > 90 * 60000) return chip("warn", `마지막 확인 ${checked} — NAS 작업 스케줄러가 멈췄는지 확인하세요`);
  const applied = au.applied_at ? ` · 마지막 적용 ${fmtDateTime(au.applied_at)}` : "";
  if (au.status === "error" || au.status === "rolled_back") return chip("crit", au.message || au.status);
  if (au.status === "held") return chip("warn", `${au.message} · 확인 ${checked}`);
  if (au.status === "waiting" || au.status === "dry_run") return chip("warn", `${au.message} · 확인 ${checked}`);
  if (au.status === "updated") return chip("good", `방금 적용: ${au.subject || au.commit}${applied}`);
  return chip("good", `최신 (${au.commit || "?"}) · 확인 ${checked}${applied}`);
}

function aboutHtml() {
  const e = S ? S.env : null;
  const https = location.protocol === "https:" || location.hostname === "localhost";
  const methods = (state.authConfig && state.authConfig.methods) || [];
  const login = state.server.auth === "dev" ? "개발 모드(이메일)" : `Firebase — ${methods.map((m) => (m === "google" ? "구글" : "메일 링크")).join(" · ") || "구글"}`;
  return `<div class="grid g2" style="align-items:start">
    <section class="panel"><div class="panel-h"><span class="code">SYSTEM</span><h3>정보</h3></div>
      <dl class="kv"><dt>버전</dt><dd>inni ${esc(state.server.version)}${state.server.commit ? ` <span class="mono muted">${esc(state.server.commit)}</span>` : ""}</dd><dt>로그인</dt><dd>${esc(login)}</dd>
        ${e ? `<dt>Firebase</dt><dd>${esc(e.firebase_project || "—")}</dd><dt>데이터</dt><dd class="mono">${esc(e.data_dir)}</dd><dt>공개 주소</dt><dd>${esc(e.public_url || "(접속 주소 사용)")}</dd>
        <dt>자동 업데이트</dt><dd>${updateRow(e.auto_update)}</dd>` : ""}
        <dt>이 연결</dt><dd>${https ? '<span class="chip-s good"><i></i>보안 연결 — 카메라·마이크 사용 가능</span>' : '<span class="chip-s warn"><i></i>http — 휴대폰 카메라·마이크가 막혀요</span>'}</dd></dl></section>
    <section class="panel"><div class="panel-h"><span class="code">HTTPS</span><h3>휴대폰 카메라·마이크를 쓰려면</h3></div>
      <ol class="guide-steps"><li>시놀로지: 제어판 → 로그인 포털 → 고급 → <b>역방향 프록시</b>에 inni(예: https://inni.학교.synology.me → http://localhost:8080) 추가</li>
        <li>제어판 → 보안 → 인증서에서 Let's Encrypt 인증서 받기</li><li>Firebase 승인된 도메인에 그 주소 추가, .env 의 PUBLIC_URL 도 그 주소로</li></ol>
      <p class="help" style="margin-top:10px">https 가 없어도: 휴대폰 <b>기본 카메라</b>로 라벨 QR 을 찍으면 물건 화면이 열리고, 스캐너의 <b>사진 찍어서 읽기</b>도 됩니다.</p></section></div>`;
}

// ---------------------------------------------------------------- 그리기·연결
function render() {
  const visible = TABS.filter(([, , , cap]) => !cap || can(cap));
  if (!visible.find((t) => t[0] === tab)) tab = visible[0][0];
  let body = "";
  if (tab === "school") body = schoolHtml();
  else if (tab === "ai") body = AI ? aiHtml() : '<div class="empty">불러오는 중…</div>';
  else if (tab === "product") body = productHtml();
  else if (tab === "alerts") body = alertsHtml();
  else if (tab === "labels") body = labelsHtml();
  else if (tab === "backup") body = backups ? backupHtml() : '<div class="empty">불러오는 중…</div>';
  else if (tab === "device") body = deviceHtml();
  else body = aboutHtml();
  root.innerHTML = `
    <div class="st-inner">
      <div class="st-head"><div class="ttl"><span class="code">STATION 10 · SYSTEMS</span><h1>시스템</h1><p>학교 설정, ${esc(state.ai.name)}의 AI 두뇌, 제품 검색 키, 알림, 백업·이전을 여기서 관리합니다.</p></div></div>
      <div class="tabs">${visible.map(([k, code, l]) => `<button class="tab" type="button" data-tab="${k}" aria-selected="${tab === k}"><span class="code">${code}</span>${l}</button>`).join("")}</div>
      <div data-body>${body}</div>
    </div>`;
  if (tab === "device") bindDevice(root.querySelector("[data-body]"));
}

function collect(prefix) {
  const out = {};
  for (const el of root.querySelectorAll(`[data-f^="${prefix}."]`)) out[el.dataset.f.split(".")[1]] = el.value;
  for (const el of root.querySelectorAll(`[data-sw^="${prefix}."]`)) out[el.dataset.sw.split(".")[1]] = el.checked;
  return out;
}
function secrets() {
  const out = {};
  for (const el of root.querySelectorAll("[data-secret]")) if (el.value.trim()) out[el.dataset.secret] = el.value.trim();
  return out;
}

async function load() {
  try {
    if (can("settings")) S = await api.get("/api/settings");
    if (can("system") && tab === "ai") AI = await api.get("/api/ai/config");
    if (can("system") && tab === "backup") { backups = await api.get("/api/backups"); setupInfo = await api.get("/api/setup/status"); }
  } catch (e) { toastError(e); }
  render();
}

async function testModel(kind) {
  const c = kind === "vision" ? (AI.vision.connection_id || AI.chat.connection_id) : AI.chat.connection_id;
  const model = kind === "vision" ? (AI.vision.model || AI.chat.model) : AI.chat.model;
  if (!c || !model) { toast("연결과 모델을 먼저 고르고 저장하세요", { tone: "warn" }); return; }
  const t = toast(`${model} 시험 중… (처음 부르는 모델은 수십 초 걸릴 수 있어요)`, { timeout: 0, sound: false });
  try {
    const r = await api.post("/api/ai/test", { connection_id: c, model, vision: kind === "vision" });
    t();
    modal({
      title: "연결 시험 결과", code: "LINK TEST", size: "narrow",
      body: `<div style="text-align:center">${characterHtml({ size: "md", mood: r.tools_ok || r.vision_ok ? "happy" : "alert" })}</div>
        <dl class="kv"><dt>모델</dt><dd><code>${esc(r.model)}</code></dd><dt>응답</dt><dd>${(r.latency_ms / 1000).toFixed(1)}초</dd>
        <dt>도구 호출</dt><dd>${r.tools_ok ? "✓ 됨(찾기·제안 가능)" : "× 안 됨(대화만)"}</dd>${r.vision_ok !== null ? `<dt>사진 읽기</dt><dd>${r.vision_ok ? "✓ 됨" : "× 안 됨"}</dd>` : ""}</dl>
        <p class="help" style="margin-top:10px">"${esc(r.reply)}"</p>${r.note ? `<div class="callout warn">${esc(r.note)}</div>` : ""}`,
      actions: [{ label: "닫기", tone: "primary" }],
    });
    AI = await api.get("/api/ai/config");
    render();
  } catch (e) { t(); toastError(e); }
}

export default {
  mount(el) {
    root = el;
    el.addEventListener("click", async (ev) => {
      const t = ev.target.closest("[data-tab]");
      if (t) { tab = t.dataset.tab; history.replaceState(null, "", `#systems?tab=${tab}`); await load(); return; }
      const s = ev.target.closest("[data-save]");
      if (s) {
        const k = s.dataset.save;
        if (k === "school") await patch({ school: collect("school") });
        else if (k === "domains") await patch({ allowed_domains: root.querySelector("[data-domains]").value });
        else if (k === "numbering") await patch({ numbering: collect("numbering"), loan: collect("loan") });
        else if (k === "assistant") { await patch({ assistant: collect("assistant") }); state.ai.name = S.assistant.name; }
        else if (k === "product") await patch({ product_search: collect("product_search"), secrets: secrets() });
        else if (k === "images") await patch({ images: collect("images"), url_import: collect("url_import") });
        else if (k === "telegram") await patch({ telegram: collect("telegram"), secrets: secrets() });
        else if (k === "labels") await patch({ labels: collect("labels") });
        else if (k === "backup") await patch({ backup: collect("backup") });
        render();
        return;
      }
      const a = ev.target.closest("[data-ai]");
      if (a) {
        const k = a.dataset.ai;
        const cardEl = a.closest("[data-c]");
        const conn = cardEl ? AI.connections.find((c) => c.id === cardEl.dataset.c) : null;
        if (k === "add") connectionDialog();
        else if (k === "edit") connectionDialog(conn);
        else if (k === "del") {
          if (await confirmDialog({ title: "연결 지우기", message: `${conn.name} 연결과 저장된 키를 지울까요?`, confirmLabel: "지우기", tone: "danger" })) {
            try { AI = await api.del(`/api/ai/connections/${conn.id}`); await loadAiConfig(); render(); } catch (e) { toastError(e); }
          }
        } else if (k === "models") {
          await busy(a, async () => {
            try {
              const r = await api.post("/api/ai/models", { connection_id: conn.id });
              models[conn.id] = r.models;
              const box = cardEl.querySelector("[data-models]");
              box.innerHTML = `<div class="list" style="max-height:220px;overflow:auto">${r.models.slice(0, 30).map((m) => `<button class="irow" type="button" data-pick="${esc(m.id)}" data-cid="${conn.id}"><span class="tx"><span class="nm mono" style="font-size:12.5px">${esc(m.id)}</span><span class="sub">${esc(m.badge || "")} ${m.tools ? "· 도구 ✓" : m.tools === false ? "· 도구 ×" : ""} ${m.vision ? "· 사진" : ""} ${esc(m.note || "")}</span></span></button>`).join("")}</div><p class="help" style="margin:6px 0 0">누르면 대화 모델로 고릅니다(사진 모델은 아래 칸에).</p>`;
            } catch (e) { toastError(e); }
          });
        } else if (k === "roles") {
          const body = {};
          for (const el2 of root.querySelectorAll("[data-role]")) {
            const [role, key] = el2.dataset.role.split(".");
            body[role] = body[role] || {};
            body[role][key] = el2.value.trim();
          }
          try { AI = await api.post("/api/ai/config", body); await loadAiConfig(); toast("저장했어요", { tone: "good" }); render(); } catch (e) { toastError(e); }
        } else if (k === "rules") {
          const body = {};
          for (const el2 of root.querySelectorAll("[data-rule]")) body[el2.dataset.rule] = Number(el2.value);
          for (const el2 of root.querySelectorAll('[data-sw^="ai."]')) if (el2.dataset.sw !== "ai.enabled") body[el2.dataset.sw.slice(3)] = el2.checked;
          try { AI = await api.post("/api/ai/config", body); toast("규칙을 저장했어요", { tone: "good" }); render(); } catch (e) { toastError(e); }
        } else if (k === "test-chat") testModel("chat");
        else if (k === "test-vision") testModel("vision");
        return;
      }
      const pick = ev.target.closest("[data-pick]");
      if (pick) {
        const cSel = root.querySelector('[data-role="chat.connection_id"]');
        const mIn = root.querySelector('[data-role="chat.model"]');
        cSel.value = pick.dataset.cid;
        mIn.value = pick.dataset.pick;
        mIn.focus();
        toast(`대화 모델: ${pick.dataset.pick} — [저장]을 누르세요`, { timeout: 2500, sound: false });
        return;
      }
      const b = ev.target.closest("[data-b]");
      if (b) {
        const k = b.dataset.b;
        if (k === "tg-test") {
          try { const r = await api.post("/api/telegram/test", {}); toast(`보냈어요: ${r.sent.join(", ")}`, { tone: "good" }); } catch (e) { toastError(e); }
        } else if (k === "label-test") printLabels([{ kind: "item", item: { name: "시험 라벨 · 디지털 멀티미터", qr: "ITEST0001", model: "87V" }, where: "실습동 › 전자실습실" }]);
        else if (k === "backup-now") {
          await busy(b, async () => {
            try { const r = await api.post("/api/backups", {}); toast(`백업 완료: ${r.name} (${bytes(r.bytes)})`, { tone: "good" }); backups = await api.get("/api/backups"); render(); } catch (e) { toastError(e); }
          });
        } else if (k === "restore-file") restoreFromFile();
        else if (k === "legacy") {
          try { const r = await api.post("/api/setup/legacy", {}); toast(`가져왔어요: 품목 ${r.items}, 장비 ${r.units}, 장소 ${r.locations}`, { tone: "good" }); await app.refresh(); } catch (e) { toastError(e); }
        } else if (k === "orphans") {
          try {
            const r = await api.get("/api/maintenance/orphans");
            if (!r.count) { toast("정리할 사진이 없어요"); return; }
            if (await confirmDialog({ title: "사진 정리", message: `어디에도 쓰이지 않는 사진 ${r.count}장(${bytes(r.bytes)})을 지울까요?`, confirmLabel: "지우기", tone: "warn" })) {
              const x = await api.post("/api/maintenance/orphans", {});
              toast(`${x.removed}장 지웠어요`, { tone: "good" });
            }
          } catch (e) { toastError(e); }
        }
        return;
      }
      const row = ev.target.closest("[data-bk]");
      if (row) {
        const name = row.dataset.bk;
        if (ev.target.closest("[data-restore]")) {
          const typed = await promptDialog({ title: "이 백업으로 복원", label: `${name} 로 되돌립니다. 지금 상태는 먼저 자동 백업돼요. 확인을 위해 '복원'을 입력하세요.`, confirmLabel: "복원" });
          if (typed !== "복원") return;
          try { await api.post(`/api/backups/${encodeURIComponent(name)}/restore`, { confirm: "복원" }); toast("복원했어요. 다시 로그인하세요.", { tone: "good" }); setTimeout(() => location.reload(), 2000); } catch (e) { toastError(e); }
        } else if (ev.target.closest("[data-bkdel]")) {
          if (await confirmDialog({ title: "백업 지우기", message: `${name} 을(를) 지울까요?`, confirmLabel: "지우기", tone: "danger" })) {
            try { await api.del(`/api/backups/${encodeURIComponent(name)}`); backups = await api.get("/api/backups"); render(); } catch (e) { toastError(e); }
          }
        }
      }
    });
    el.addEventListener("change", async (ev) => {
      const sw2 = ev.target.closest('[data-sw="ai.enabled"]');
      if (sw2) {
        try { AI = await api.post("/api/ai/config", { enabled: sw2.checked }); await loadAiConfig(); toast(sw2.checked ? `${state.ai.name} 두뇌를 켰어요` : "AI 를 껐어요(기본 명령만)", { tone: "good" }); render(); } catch (e) { sw2.checked = !sw2.checked; toastError(e); }
        return;
      }
      const csv = ev.target.closest("[data-csv]");
      if (csv) {
        const f = csv.files[0];
        csv.value = "";
        if (!f) return;
        try {
          const plan = await api("/api/import/items?dry=1", { method: "POST", raw: f, headers: { "Content-Type": "text/csv" } });
          modal({
            title: "CSV 가져오기 미리보기", code: "IMPORT", size: "wide",
            body: `<dl class="kv"><dt>읽은 줄</dt><dd>${plan.rows}</dd><dt>새 품목</dt><dd>${plan.new_items}</dd><dt>있는 품목에 더함</dt><dd>${plan.updated}</dd><dt>새 장비</dt><dd>${plan.new_units}대</dd><dt>재고 줄</dt><dd>${plan.stock_lines}</dd><dt>새로 만들 장소</dt><dd>${esc(plan.new_locations.join(", ") || "없음")}</dd></dl>
              ${plan.errors.length ? `<div class="callout warn" style="margin-top:10px">건너뛸 줄 ${plan.errors.length}개:<br>${plan.errors.slice(0, 12).map((x) => `${x.line}줄: ${esc(x.reason)}`).join("<br>")}</div>` : ""}`,
            actions: [{ label: "취소", tone: "ghost" }, { label: "가져오기", tone: "primary", onClick: async () => {
              try { const r = await api("/api/import/items?dry=0", { method: "POST", raw: f, headers: { "Content-Type": "text/csv" } }); toast(`가져왔어요: 새 품목 ${r.new_items}, 장비 ${r.new_units}대`, { tone: "good" }); await app.refresh(); return true; } catch (e) { toastError(e); return false; }
            } }],
          });
        } catch (e) { toastError(e); }
      }
    });
  },
  async enter(params = {}) {
    if (params.tab) tab = params.tab;
    await load();
  },
  refresh() { return null; },
};

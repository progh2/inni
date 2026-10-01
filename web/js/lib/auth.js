// 로그인 화면. Firebase 로 학교 계정을 확인하고 서버 세션을 받는다.
//  - 구글 계정(학교 구글 워크스페이스) : 팝업 한 번
//  - 학교 메일 링크 : 구글 계정이 아닌 학교·교육청 메일도 된다. 메일로 받은 링크를 누르면 들어온다
// 개발 모드(AUTH_MODE=dev)에서는 이메일만으로 들어간다(시연용).
import { $, esc, icon } from "./util.js";
import { api } from "./api.js";
import { characterHtml } from "../ai/character.js";
import * as sfx from "./sfx.js";

const FIREBASE_VER = "12.19.0";
const MAIL_KEY = "inni.loginMail"; // 링크를 보낸 메일 주소·이름·돌아갈 화면(이 기기에만)
let firebaseMods = null;

async function loadFirebase(cfg) {
  if (!firebaseMods) {
    const [appMod, authMod] = await Promise.all([
      import(`https://www.gstatic.com/firebasejs/${FIREBASE_VER}/firebase-app.js`),
      import(`https://www.gstatic.com/firebasejs/${FIREBASE_VER}/firebase-auth.js`),
    ]);
    const app = appMod.initializeApp({ apiKey: cfg.apiKey, authDomain: cfg.authDomain, projectId: cfg.projectId });
    firebaseMods = { authMod, auth: authMod.getAuth(app) };
    firebaseMods.auth.languageCode = "ko";
  }
  return firebaseMods;
}

function friendly(e) {
  const code = (e && e.code) || "";
  if (code === "auth/unauthorized-domain") return `이 주소(${location.hostname})가 Firebase 승인된 도메인에 없습니다. Firebase 콘솔 → Authentication → 설정 → 승인된 도메인에 추가하세요.`;
  if (code === "auth/popup-blocked") return "팝업이 막혔습니다. 브라우저 주소창의 팝업 차단을 풀고 다시 누르세요.";
  if (code === "auth/popup-closed-by-user" || code === "auth/cancelled-popup-request") return "로그인 창을 닫았습니다. 다시 눌러 주세요.";
  if (code === "auth/network-request-failed") return "인터넷에 연결되지 않았습니다. 구글 로그인은 인터넷이 필요합니다.";
  if (code === "auth/operation-not-allowed") return "Firebase 콘솔에서 이 로그인 방법이 꺼져 있습니다(Authentication → 로그인 방법 확인).";
  if (code === "auth/invalid-email") return "메일 주소를 다시 확인해 주세요.";
  if (code === "auth/quota-exceeded" || code === "auth/too-many-requests") return "잠시 후 다시 시도해 주세요(요청이 너무 많아요).";
  if (code === "auth/invalid-action-code" || code === "auth/expired-action-code") return "로그인 링크가 만료됐거나 이미 쓰였어요. 링크를 다시 받아 주세요.";
  if (code === "auth/invalid-continue-uri" || code === "auth/unauthorized-continue-uri") return `이 주소(${location.hostname})가 Firebase 승인된 도메인에 없습니다.`;
  if (code === "auth/invalid-api-key" || code === "auth/api-key-not-valid.-please-pass-a-valid-api-key.") return "FIREBASE_API_KEY 가 올바르지 않습니다(.env 확인).";
  return (e && e.message) || String(e);
}

const mailState = {
  get() { try { return JSON.parse(localStorage.getItem(MAIL_KEY) || "null"); } catch { return null; } },
  set(v) { localStorage.setItem(MAIL_KEY, JSON.stringify(v)); },
  clear() { localStorage.removeItem(MAIL_KEY); },
};
// 메일의 로그인 링크로 들어온 주소인가(?mode=signIn&oobCode=…)
const isMailLink = () => /[?&]oobCode=/.test(location.search) && /[?&]mode=signIn/.test(location.search);

/**
 * 로그인 화면을 그리고, 성공하면 resolve(me)
 */
export function showLogin(authCfg, { pendingUser = null } = {}) {
  const box = $("#login");
  box.hidden = false;
  return new Promise((resolve) => {
    const school = authCfg.school || "우리 학교";
    const domains = (authCfg.domains || []).map((d) => `@${d}`).join(", ");
    const methods = authCfg.methods || ["google"];
    const useGoogle = methods.includes("google");
    const useMail = methods.includes("email");
    const sent = mailState.get();
    const pending = pendingUser ? `
      <div class="callout warn" style="text-align:left"><b>${esc(pendingUser.name || pendingUser.email)}</b> 님, 승인을 기다리고 있어요.<br>
      담당 선생님(관리자)이 승무원 화면에서 승인하면 바로 쓸 수 있어요.</div>
      <div class="row" style="justify-content:center"><button class="btn" type="button" id="lg-recheck">${icon("refresh")}다시 확인</button>
      <button class="btn ghost" type="button" id="lg-out">${icon("exit")}다른 계정</button></div>` : "";
    box.innerHTML = `
      <div class="panel login-card">
        <div class="fig">${characterHtml({ size: "xl", mood: pendingUser ? "think" : "idle", cone: true })}</div>
        <h1>INNI</h1>
        <p class="sub">${esc(school)} 물품 보급 함교<br><span class="muted">찾기 · 등록 · 이동 · 대여를 한 곳에서</span></p>
        ${pending || (authCfg.mode === "firebase" ? `
          ${useGoogle ? `<button class="btn primary lg" type="button" id="lg-google">${icon("user")}학교 구글 계정으로 승선</button>` : ""}
          ${useGoogle && useMail ? `<div class="login-or"><span>또는 학교 메일로</span></div>` : ""}
          ${useMail ? `<form class="login-mail" id="lg-mail" novalidate>
            <div class="row nw"><input type="email" id="lg-mail-in" placeholder="${esc((authCfg.domains || [])[0] ? `아이디@${authCfg.domains[0]}` : "학교 메일 주소")}" autocomplete="email" inputmode="email" value="${esc(sent ? sent.email : "")}" aria-label="학교 메일 주소">
            <button class="btn ${useGoogle ? "" : "primary"}" type="submit" id="lg-mail-go">${icon("send")}링크 받기</button></div>
            <input type="text" id="lg-mail-name" placeholder="이름 (처음 한 번만, 예: 김담당)" autocomplete="name" maxlength="40" value="${esc(sent ? sent.name || "" : "")}" aria-label="이름">
            <div id="lg-mail-msg" class="help" aria-live="polite">${sent ? `${esc(sent.email)} 으로 보낸 링크를 <b>이 기기</b>에서 누르면 들어와요.` : "메일로 받은 링크를 누르면 비밀번호 없이 들어와요."}</div>
          </form>` : ""}
          ${domains ? `<p class="help" style="margin-top:10px">${esc(domains)} 계정만 들어올 수 있어요.</p>` : ""}` : "")}
        ${authCfg.mode === "dev" && !pendingUser ? `
          <div class="dev-box">
            <div class="callout warn">개발·시연 모드입니다(AUTH_MODE=dev). 운영에서는 Firebase 로그인을 켜세요.</div>
            <div class="row" style="margin-bottom:8px">
              <button class="btn sm" type="button" data-dev="owner@demo.school">관리자(김담당)</button>
              <button class="btn sm" type="button" data-dev="manager@demo.school">담당교사</button>
              <button class="btn sm" type="button" data-dev="teacher@demo.school">교사</button>
            </div>
            <form class="row nw" id="lg-dev"><input type="email" id="lg-dev-email" placeholder="이메일로 들어가기" autocomplete="email"><button class="btn" type="submit">들어가기</button></form>
          </div>` : ""}
        <div class="login-error" id="lg-err" role="alert"></div>
        <p class="help" style="margin:14px 0 0;font-size:11px">inni ${esc(authCfg.version || "")} · AGPL-3.0</p>
      </div>`;
    const err = $("#lg-err");
    const finish = (me) => {
      sfx.play("boot");
      box.hidden = true;
      box.innerHTML = "";
      resolve(me);
    };
    // Firebase 로그인 결과 → 이 서버의 세션(Firebase 쪽 로그인은 바로 정리)
    const exchange = async ({ authMod, auth }, cred, extra = {}) => {
      const idToken = await cred.user.getIdToken();
      const out = await api.post("/api/auth/session", { idToken, ...extra });
      authMod.signOut(auth).catch(() => {});
      if (out.me.status === "pending") {
        box.hidden = true;
        resolve(showLogin(authCfg, { pendingUser: out.me }));
        return;
      }
      finish(out.me);
    };
    const g = $("#lg-google");
    if (g) {
      g.onclick = async () => {
        err.textContent = "";
        g.classList.add("busy");
        g.disabled = true;
        try {
          const fb = await loadFirebase(authCfg.firebase);
          const provider = new fb.authMod.GoogleAuthProvider();
          const params = { prompt: "select_account" };
          if ((authCfg.domains || []).length === 1) params.hd = authCfg.domains[0];
          provider.setCustomParameters(params);
          const cred = await fb.authMod.signInWithPopup(fb.auth, provider);
          await exchange(fb, cred);
        } catch (e) {
          err.textContent = friendly(e);
          sfx.play("error");
        } finally {
          g.classList.remove("busy");
          g.disabled = false;
        }
      };
    }
    // 학교 메일 링크: 주소를 넣으면 Firebase 가 로그인 링크를 메일로 보낸다
    const mf = $("#lg-mail");
    if (mf) {
      mf.onsubmit = async (ev) => {
        ev.preventDefault();
        err.textContent = "";
        const email = $("#lg-mail-in").value.trim().toLowerCase();
        const name = $("#lg-mail-name").value.trim();
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { err.textContent = "메일 주소를 다시 확인해 주세요."; $("#lg-mail-in").focus(); return; }
        const btn = $("#lg-mail-go");
        btn.classList.add("busy");
        btn.disabled = true;
        try {
          const fb = await loadFirebase(authCfg.firebase);
          await fb.authMod.sendSignInLinkToEmail(fb.auth, email, { url: `${location.origin}/?login=mail`, handleCodeInApp: true });
          mailState.set({ email, name, back: location.hash || "", at: Date.now() });
          $("#lg-mail-msg").innerHTML = `<b>${esc(email)}</b> 으로 로그인 링크를 보냈어요. 메일의 링크를 <b>이 기기</b>에서 누르세요.<br>몇 분 안에 안 오면 스팸함을 보거나 다시 받아 주세요.`;
          btn.innerHTML = `${icon("send")}다시 받기`;
          sfx.play("ok");
        } catch (e) {
          err.textContent = friendly(e);
          sfx.play("error");
        } finally {
          btn.classList.remove("busy");
          btn.disabled = false;
        }
      };
    }
    // 메일의 링크로 열렸으면 바로 마무리한다(다른 기기에서 열었으면 주소를 한 번 더 묻는다)
    if (authCfg.mode === "firebase" && isMailLink() && !pendingUser) {
      (async () => {
        const saved = mailState.get();
        const link = location.href;
        let email = saved && saved.email;
        if (!email) {
          // 링크를 보낸 기기가 아니다 → 주소만 확인받는다
          const f = $("#lg-mail-in");
          if (!f || !mf) return;
          $("#lg-mail-go").innerHTML = `${icon("check")}확인`;
          $("#lg-mail-msg").textContent = "다른 기기에서 링크를 열었어요. 링크를 받은 메일 주소를 한 번 더 넣어 주세요.";
          f.focus();
          email = await new Promise((ok) => { mf.onsubmit = (ev) => { ev.preventDefault(); ok(f.value.trim().toLowerCase()); }; });
        }
        if (!email) return;
        err.textContent = "";
        const msg = $("#lg-mail-msg");
        if (msg) msg.textContent = "로그인 링크를 확인하고 있어요…";
        try {
          const fb = await loadFirebase(authCfg.firebase);
          if (!fb.authMod.isSignInWithEmailLink(fb.auth, link)) throw Object.assign(new Error("로그인 링크가 아니에요"), { code: "auth/invalid-action-code" });
          const cred = await fb.authMod.signInWithEmailLink(fb.auth, email, link);
          // 주소창의 일회용 코드는 지운다
          history.replaceState(null, "", `/${(saved && saved.back) || ""}`);
          mailState.clear();
          await exchange(fb, cred, { name: (saved && saved.name) || $("#lg-mail-name").value.trim() });
        } catch (e) {
          history.replaceState(null, "", "/");
          err.textContent = friendly(e);
          if (msg) msg.textContent = "";
          sfx.play("error");
        }
      })();
    }
    const dev = async (email) => {
      err.textContent = "";
      try {
        const out = await api.post("/api/auth/dev-login", { email });
        if (out.me.status === "pending") {
          resolve(showLogin(authCfg, { pendingUser: out.me }));
          return;
        }
        finish(out.me);
      } catch (e) {
        err.textContent = e.message;
        sfx.play("error");
      }
    };
    for (const b of box.querySelectorAll("[data-dev]")) b.onclick = () => dev(b.dataset.dev);
    const form = $("#lg-dev");
    if (form) form.onsubmit = (ev) => { ev.preventDefault(); dev($("#lg-dev-email").value.trim()); };
    const re = $("#lg-recheck");
    if (re) {
      re.onclick = async () => {
        try {
          const me = await api.get("/api/me");
          if (me.me.status === "active") finish(me.me);
          else { err.textContent = "아직 승인되지 않았어요. 담당 선생님께 알려 주세요."; sfx.play("warn"); }
        } catch (e) { err.textContent = e.message; }
      };
      $("#lg-out").onclick = async () => {
        await api.post("/api/auth/logout", {}).catch(() => {});
        box.hidden = true;
        resolve(showLogin(authCfg));
      };
    }
  });
}

export async function logout() {
  await api.post("/api/auth/logout", {}).catch(() => {});
  location.hash = "";
  location.reload();
}

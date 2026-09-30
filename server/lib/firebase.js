// Firebase ID 토큰 검증. 서비스 계정 없이 구글 공개 인증서만으로 검증한다
// (Firebase 문서의 "타사 JWT 라이브러리로 ID 토큰 확인" 절차와 같다).
import crypto from "node:crypto";

export const CERT_URL = "https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com";
const CLOCK_SKEW_SEC = 300;

export class TokenError extends Error {
  constructor(message, code = "invalid_token") {
    super(message);
    this.code = code;
    this.status = 401;
  }
}

function decodePart(part, what) {
  try {
    return JSON.parse(Buffer.from(part, "base64url").toString("utf8"));
  } catch {
    throw new TokenError(`토큰 ${what}을(를) 읽을 수 없습니다`);
  }
}

export function createCertStore(fetchImpl = globalThis.fetch, url = CERT_URL) {
  let cache = { certs: null, until: 0 };
  let inflight = null;
  async function load() {
    const resp = await fetchImpl(url, { signal: AbortSignal.timeout(10000) });
    if (!resp.ok) throw new TokenError(`구글 인증서를 받지 못했습니다 (HTTP ${resp.status})`, "cert_fetch");
    const certs = await resp.json();
    const cc = (resp.headers && resp.headers.get && resp.headers.get("cache-control")) || "";
    const m = /max-age=(\d+)/.exec(cc);
    cache = { certs, until: Date.now() + (m ? Number(m[1]) * 1000 : 3600000) };
    return certs;
  }
  return {
    async get({ force = false } = {}) {
      if (!force && cache.certs && Date.now() < cache.until) return cache.certs;
      if (!inflight) inflight = load().finally(() => { inflight = null; });
      try {
        return await inflight;
      } catch (e) {
        // 인증서 서버가 잠깐 안 될 때는 이전 인증서로 버틴다
        if (cache.certs) return cache.certs;
        if (e instanceof TokenError) throw e;
        throw new TokenError("구글 인증서를 받지 못했습니다. NAS 의 인터넷 연결을 확인하세요.", "cert_fetch");
      }
    },
  };
}

/**
 * @param {string} token Firebase ID 토큰
 * @param {object} o { projectId, certs: createCertStore(), now: ms }
 * @returns {Promise<object>} 토큰 내용(email, email_verified, name, picture, sub, firebase.sign_in_provider …)
 */
export async function verifyIdToken(token, { projectId, certs, now = Date.now() }) {
  if (!projectId) throw new TokenError("FIREBASE_PROJECT_ID 가 설정되지 않았습니다", "config");
  const parts = String(token || "").split(".");
  if (parts.length !== 3) throw new TokenError("토큰 형식이 올바르지 않습니다");
  const header = decodePart(parts[0], "머리");
  const payload = decodePart(parts[1], "내용");
  if (header.alg !== "RS256") throw new TokenError("토큰 서명 방식이 올바르지 않습니다");
  if (!header.kid) throw new TokenError("토큰에 kid 가 없습니다");

  let table = await certs.get();
  if (!table[header.kid]) table = await certs.get({ force: true });
  const pem = table[header.kid];
  if (!pem) throw new TokenError("토큰 서명 인증서를 찾지 못했습니다(만료된 토큰일 수 있습니다)");

  let key;
  try {
    // 구글은 X.509 인증서 PEM 을 준다(공개키 PEM 도 받는다)
    key = crypto.createPublicKey(pem);
  } catch {
    throw new TokenError("구글 인증서를 읽지 못했습니다", "cert_parse");
  }
  const ok = crypto.verify("RSA-SHA256", Buffer.from(`${parts[0]}.${parts[1]}`), key, Buffer.from(parts[2], "base64url"));
  if (!ok) throw new TokenError("토큰 서명이 맞지 않습니다");

  const t = Math.floor(now / 1000);
  if (payload.aud !== projectId) throw new TokenError("다른 Firebase 프로젝트의 토큰입니다. FIREBASE_PROJECT_ID 를 확인하세요", "aud");
  if (payload.iss !== `https://securetoken.google.com/${projectId}`) throw new TokenError("토큰 발급자가 올바르지 않습니다", "iss");
  if (typeof payload.sub !== "string" || !payload.sub || payload.sub.length > 128) throw new TokenError("토큰 사용자 값이 올바르지 않습니다");
  if (!(Number(payload.exp) > t - CLOCK_SKEW_SEC)) throw new TokenError("토큰이 만료되었습니다. 다시 로그인하세요", "expired");
  if (Number(payload.iat) > t + CLOCK_SKEW_SEC) throw new TokenError("토큰 발급 시각이 미래입니다. 서버 시계를 확인하세요", "iat");
  if (payload.auth_time !== undefined && Number(payload.auth_time) > t + CLOCK_SKEW_SEC) throw new TokenError("로그인 시각이 올바르지 않습니다");
  return payload;
}

// 학교 도메인 확인. 목록이 비면 모든 구글 계정을 받는다(대신 승인 대기로 들어온다).
export function emailAllowed(email, allowedDomains = []) {
  const e = String(email || "").toLowerCase();
  const at = e.lastIndexOf("@");
  if (at < 1) return false;
  if (!allowedDomains.length) return true;
  const domain = e.slice(at + 1);
  return allowedDomains.includes(domain);
}

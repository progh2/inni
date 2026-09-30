// 바깥 주소 가져오기(제품 링크·이미지). 학교 내부망(NAS·공유기) 주소로는 가지 않는다(SSRF 방지).
// DNS 결과를 연결하는 순간에 검사해서, 주소를 바꿔치기하는 공격(DNS rebinding)도 막는다.
import http from "node:http";
import https from "node:https";
import dns from "node:dns";
import net from "node:net";
import zlib from "node:zlib";

export class FetchError extends Error {
  constructor(message, code = "fetch") {
    super(message);
    this.code = code;
    // 주소를 잘못 넣은 것은 사용자 쪽(400), 사진이 아닌 것은 422, 상대 사이트 문제는 502
    this.status = code === "private" || code === "url" ? 400 : code === "not_image" ? 422 : 502;
    this.external = true; // 예상할 수 있는 바깥 문제 → 서버 로그에 스택을 남기지 않는다
  }
}

function v4ToInt(ip) {
  return ip.split(".").reduce((a, b) => (a << 8) + Number(b), 0) >>> 0;
}

function inV4(ip, cidr) {
  const [base, bits] = cidr.split("/");
  const mask = bits === "0" ? 0 : (~0 << (32 - Number(bits))) >>> 0;
  return (v4ToInt(ip) & mask) === (v4ToInt(base) & mask);
}

const PRIVATE_V4 = ["0.0.0.0/8", "10.0.0.0/8", "100.64.0.0/10", "127.0.0.0/8", "169.254.0.0/16", "172.16.0.0/12", "192.0.0.0/24", "192.168.0.0/16", "198.18.0.0/15", "224.0.0.0/4", "240.0.0.0/4"];

export function isPrivateIp(ip) {
  if (net.isIPv4(ip)) return PRIVATE_V4.some((c) => inV4(ip, c));
  if (net.isIPv6(ip)) {
    const s = ip.toLowerCase();
    if (s === "::" || s === "::1") return true;
    const mapped = /^::ffff:(\d+\.\d+\.\d+\.\d+)$/.exec(s);
    if (mapped) return isPrivateIp(mapped[1]);
    return /^(fc|fd|fe8|fe9|fea|feb|ff)/.test(s);
  }
  return true;
}

function guardedLookup(allowPrivate) {
  return (hostname, options, cb) => {
    dns.lookup(hostname, { all: true }, (err, addrs) => {
      if (err) return cb(err);
      const ok = addrs.filter((a) => allowPrivate || !isPrivateIp(a.address));
      if (!ok.length) return cb(Object.assign(new Error("학교 내부망 주소는 가져올 수 없습니다"), { code: "EPRIVATE" }));
      if (options && options.all) return cb(null, ok);
      return cb(null, ok[0].address, ok[0].family);
    });
  };
}

const UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36 inni-inventory";

function once(u, { allowPrivate, maxBytes, timeoutMs, headers, method = "GET", body = null }) {
  return new Promise((resolve, reject) => {
    const host = u.hostname.replace(/^\[|\]$/g, "");
    if (net.isIP(host) && !allowPrivate && isPrivateIp(host)) {
      reject(new FetchError("학교 내부망 주소는 가져올 수 없습니다", "private"));
      return;
    }
    const lib = u.protocol === "https:" ? https : http;
    const req = lib.request(u, {
      method,
      headers: { "User-Agent": UA, Accept: "text/html,application/xhtml+xml,application/json,image/*;q=0.9,*/*;q=0.8", "Accept-Language": "ko-KR,ko;q=0.9,en;q=0.6", "Accept-Encoding": "gzip, deflate, br", ...headers },
      lookup: guardedLookup(allowPrivate),
      timeout: timeoutMs,
    }, (res) => {
      const chunks = [];
      let size = 0;
      let stream = res;
      const enc = String(res.headers["content-encoding"] || "").toLowerCase();
      if (enc === "gzip" || enc === "x-gzip") stream = res.pipe(zlib.createGunzip());
      else if (enc === "deflate") stream = res.pipe(zlib.createInflate());
      else if (enc === "br") stream = res.pipe(zlib.createBrotliDecompress());
      const isRedirect = res.statusCode >= 300 && res.statusCode < 400 && res.headers.location;
      if (isRedirect) {
        res.resume();
        resolve({ status: res.statusCode, headers: res.headers, buffer: Buffer.alloc(0), url: u.href });
        return;
      }
      stream.on("data", (c) => {
        size += c.length;
        if (size > maxBytes) {
          req.destroy();
          reject(new FetchError(`응답이 너무 큽니다(${Math.round(maxBytes / 1048576)}MB 넘음)`, "too_big"));
          return;
        }
        chunks.push(c);
      });
      stream.on("end", () => resolve({ status: res.statusCode, headers: res.headers, buffer: Buffer.concat(chunks), url: u.href }));
      stream.on("error", (e) => reject(new FetchError(`응답을 읽지 못했습니다: ${e.message}`)));
    });
    req.on("timeout", () => req.destroy(new FetchError(`${Math.round(timeoutMs / 1000)}초 안에 응답이 없습니다`, "timeout")));
    req.on("error", (e) => {
      if (e instanceof FetchError) reject(e);
      else if (e.code === "EPRIVATE") reject(new FetchError(e.message, "private"));
      else if (e.code === "ENOTFOUND") reject(new FetchError(`주소를 찾을 수 없습니다: ${u.hostname}`, "dns"));
      else reject(new FetchError(`연결하지 못했습니다 (${e.code || e.message})`, "connect"));
    });
    if (body) req.write(body);
    req.end();
  });
}

export async function safeFetch(url, { allowPrivate = false, maxBytes = 3 * 1048576, timeoutMs = 12000, headers = {}, maxRedirects = 5, method, body } = {}) {
  let u;
  try {
    u = new URL(String(url).trim());
  } catch {
    throw new FetchError("주소 형식이 올바르지 않습니다", "url");
  }
  for (let i = 0; i <= maxRedirects; i++) {
    if (!/^https?:$/.test(u.protocol)) throw new FetchError("http:// 또는 https:// 주소만 가져올 수 있습니다", "url");
    const res = await once(u, { allowPrivate, maxBytes, timeoutMs, headers, method, body });
    if (res.status >= 300 && res.status < 400 && res.headers.location) {
      u = new URL(res.headers.location, u);
      continue;
    }
    return res;
  }
  throw new FetchError("주소가 너무 여러 번 넘겨졌습니다", "redirects");
}

// HTML 글자 인코딩(EUC-KR 쇼핑몰도 있다)
export function decodeHtml(buffer, contentType = "") {
  let charset = (/charset=([\w-]+)/i.exec(contentType) || [])[1];
  if (!charset) {
    const head = buffer.subarray(0, 4096).toString("latin1");
    charset = (/<meta[^>]+charset=["']?([\w-]+)/i.exec(head) || [])[1];
  }
  charset = String(charset || "utf-8").toLowerCase();
  if (charset === "ks_c_5601-1987" || charset === "cp949" || charset === "ms949") charset = "euc-kr";
  try {
    return new TextDecoder(charset).decode(buffer);
  } catch {
    return new TextDecoder("utf-8").decode(buffer);
  }
}

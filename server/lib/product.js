// 제품 찾기·링크로 정보 채우기.
// - 이름으로 찾기: 네이버 쇼핑/이미지, 카카오 이미지 검색 API (설정에 키를 넣으면 켜진다)
// - 링크로 채우기: 쇼핑몰 페이지의 og:·JSON-LD 정보를 읽고, AI 가 있으면 규격·제조사까지 정리한다
import { getSecret } from "./db.js";
import { section, productSearchSources } from "./settings.js";
import { safeFetch, decodeHtml, FetchError } from "./safe-fetch.js";
import { badRequest } from "./util.js";
import { sniffImage } from "./uploads.js";

const stripTags = (s) => String(s || "").replace(/<[^>]+>/g, "").replace(/&amp;/g, "&").replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&quot;/g, '"').replace(/&#39;/g, "'").trim();

async function getJson(url, headers) {
  const res = await safeFetch(url, { headers: { Accept: "application/json", ...headers }, maxBytes: 2 * 1048576, timeoutMs: 10000 });
  let data = null;
  try { data = JSON.parse(res.buffer.toString("utf8")); } catch { data = null; }
  if (res.status === 401 || res.status === 403) throw new FetchError("검색 API 키가 거부되었습니다. 설정의 키를 확인하세요", "auth");
  if (res.status === 429) throw new FetchError("검색 API 하루 한도를 넘었습니다", "rate");
  if (res.status >= 400 || !data) throw new FetchError(`검색 API 오류 (HTTP ${res.status}) ${data && (data.errorMessage || data.message) || ""}`.trim());
  return data;
}

export async function productSearch(ctx, q, source = "") {
  const query = String(q || "").trim().slice(0, 100);
  if (!query) throw badRequest("검색어를 넣으세요");
  const db = ctx.db;
  const available = productSearchSources(db);
  if (!available.length) {
    return { source: null, results: [], hint: "검색 API 키가 아직 없어요. 네이버나 카카오 검색 키를 넣으면 여기서 바로 사진을 고를 수 있어요.", links: externalLinks(query) };
  }
  const pref = section(db, "product_search").provider;
  let src = source && available.includes(source) ? source : pref !== "auto" && available.includes(pref) ? pref : available[0];
  const out = { source: src, results: [], links: externalLinks(query) };
  if (src === "naver_shop" || src === "naver_image") {
    const headers = { "X-Naver-Client-Id": getSecret(db, "naver_client_id"), "X-Naver-Client-Secret": getSecret(db, "naver_client_secret") };
    if (src === "naver_shop") {
      const data = await getJson(`https://openapi.naver.com/v1/search/shop.json?query=${encodeURIComponent(query)}&display=24&sort=sim`, headers);
      out.results = (data.items || []).map((it) => ({
        title: stripTags(it.title), image: it.image, link: it.link, price: Number(it.lprice) || null, mall: it.mallName || "",
        brand: it.brand || "", maker: it.maker || "", category: [it.category1, it.category2, it.category3, it.category4].filter(Boolean).join(" > "),
        product_id: it.productId || "",
      }));
    } else {
      const data = await getJson(`https://openapi.naver.com/v1/search/image?query=${encodeURIComponent(query)}&display=30&filter=medium`, headers);
      out.results = (data.items || []).map((it) => ({ title: stripTags(it.title), image: it.link, thumb: it.thumbnail, link: it.link, width: Number(it.sizewidth) || null, height: Number(it.sizeheight) || null }));
    }
  } else if (src === "kakao_image") {
    const data = await getJson(`https://dapi.kakao.com/v2/search/image?query=${encodeURIComponent(query)}&size=30&sort=accuracy`, { Authorization: `KakaoAK ${getSecret(db, "kakao_rest_key")}` });
    out.results = (data.documents || []).map((d) => ({ title: stripTags(d.display_sitename || ""), image: d.image_url, thumb: d.thumbnail_url, link: d.doc_url, width: d.width, height: d.height }));
  }
  return out;
}

export function externalLinks(q) {
  const e = encodeURIComponent(q);
  return [
    { name: "네이버 쇼핑", url: `https://search.shopping.naver.com/search/all?query=${e}` },
    { name: "다나와", url: `https://search.danawa.com/dsearch.php?query=${e}` },
    { name: "학교장터(S2B)", url: `https://www.s2b.kr/S2BNCustomer/S2B/scrweb/remu/rema/searchengine/s2bCustomerSearch.jsp?searchQuery=${e}` },
    { name: "구글 이미지", url: `https://www.google.com/search?tbm=isch&q=${e}` },
  ];
}

// ---------------------------------------------------------------- 링크 읽기
function metaTags(html) {
  const out = {};
  for (const m of html.matchAll(/<meta\s+[^>]*>/gi)) {
    const tag = m[0];
    const key = (/(?:property|name|itemprop)\s*=\s*["']([^"']+)["']/i.exec(tag) || [])[1];
    const val = (/content\s*=\s*["']([^"']*)["']/i.exec(tag) || [])[1];
    if (key && val !== undefined && !(key.toLowerCase() in out)) out[key.toLowerCase()] = stripTags(val);
  }
  return out;
}

function jsonLdProducts(html) {
  const found = [];
  for (const m of html.matchAll(/<script[^>]+type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)) {
    let data;
    try { data = JSON.parse(m[1].trim()); } catch { continue; }
    const walk = (n) => {
      if (!n || typeof n !== "object") return;
      if (Array.isArray(n)) { n.forEach(walk); return; }
      const t = [].concat(n["@type"] || []).map(String);
      if (t.some((x) => /product/i.test(x))) found.push(n);
      if (n["@graph"]) walk(n["@graph"]);
    };
    walk(data);
  }
  return found;
}

function firstImage(v) {
  if (!v) return "";
  if (typeof v === "string") return v;
  if (Array.isArray(v)) return firstImage(v[0]);
  return v.url || v.contentUrl || "";
}

function pageText(html) {
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, " ")
    .replace(/<style[\s\S]*?<\/style>/gi, " ")
    .replace(/<noscript[\s\S]*?<\/noscript>/gi, " ")
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/g, " ")
    .replace(/&amp;/g, "&")
    .replace(/\s+/g, " ")
    .trim();
}

export async function readProductPage(ctx, url) {
  const cfg = section(ctx.db, "url_import");
  if (!cfg.enabled) throw badRequest("링크로 정보 채우기가 꺼져 있습니다");
  const res = await safeFetch(url, { allowPrivate: cfg.allow_private, maxBytes: 4 * 1048576, timeoutMs: 15000 });
  if (res.status === 403 || res.status === 429) {
    throw new FetchError(`쇼핑몰이 자동 읽기를 막았습니다 (HTTP ${res.status}). 제품 이름으로 찾기나 사진 붙여넣기를 쓰세요.`, "blocked");
  }
  if (res.status >= 400) throw new FetchError(`페이지를 열지 못했습니다 (HTTP ${res.status})`);
  const ctype = String(res.headers["content-type"] || "");
  if (/^image\//i.test(ctype)) {
    return { url: res.url, fields: {}, images: [res.url], text: "", source: "image" };
  }
  const html = decodeHtml(res.buffer, ctype);
  const meta = metaTags(html);
  const title = stripTags((/<title[^>]*>([\s\S]*?)<\/title>/i.exec(html) || [])[1] || "");
  const ld = jsonLdProducts(html)[0] || null;
  const offers = ld && [].concat(ld.offers || [])[0];
  const brand = ld && (typeof ld.brand === "string" ? ld.brand : ld.brand && ld.brand.name);
  const fields = {
    name: (ld && ld.name) || meta["og:title"] || meta["twitter:title"] || title,
    manufacturer: (ld && ld.manufacturer && (ld.manufacturer.name || ld.manufacturer)) || brand || meta["product:brand"] || "",
    model: (ld && (ld.model || ld.mpn || ld.sku)) || "",
    description: (ld && ld.description) || meta["og:description"] || meta.description || "",
    price: Number((offers && (offers.price || offers.lowPrice)) || meta["product:price:amount"] || meta["og:price:amount"] || 0) || null,
    barcode: (ld && (ld.gtin13 || ld.gtin || ld.gtin12 || ld.gtin8)) || "",
    vendor: meta["og:site_name"] || new URL(res.url).hostname.replace(/^www\./, ""),
    product_url: res.url,
  };
  for (const k of Object.keys(fields)) if (typeof fields[k] === "string") fields[k] = stripTags(fields[k]).slice(0, k === "description" ? 1500 : 200);
  const images = [...new Set([firstImage(ld && ld.image), meta["og:image"], meta["og:image:url"], meta["twitter:image"]].filter(Boolean)
    .map((src) => { try { return new URL(src, res.url).href; } catch { return ""; } }).filter(Boolean))].slice(0, 6);
  // 본문의 큰 제품 사진 후보 몇 개 더
  for (const m of html.matchAll(/<img[^>]+(?:data-src|src)=["']([^"']+\.(?:jpe?g|png|webp)(?:\?[^"']*)?)["']/gi)) {
    if (images.length >= 10) break;
    try {
      const abs = new URL(m[1], res.url).href;
      if (!images.includes(abs) && !/logo|icon|banner|sprite|btn|blank/i.test(abs)) images.push(abs);
    } catch { /* 깨진 주소 */ }
  }
  return { url: res.url, fields, images, text: pageText(html).slice(0, 6000), source: ld ? "json-ld" : "meta" };
}

// 이미지 대신 가져오기(브라우저가 다른 사이트 이미지를 캔버스로 다룰 수 있게)
export async function proxyImage(ctx, url) {
  const cfg = section(ctx.db, "url_import");
  const res = await safeFetch(url, { allowPrivate: cfg.allow_private, maxBytes: 10 * 1048576, timeoutMs: 15000, headers: { Accept: "image/webp,image/jpeg,image/png,image/*;q=0.5" } });
  if (res.status >= 400) throw new FetchError(`이미지를 받지 못했습니다 (HTTP ${res.status})`);
  // 상대가 말한 형식이 아니라 내용으로 판단한다(SVG·HTML 을 우리 주소로 내보내지 않게)
  const kind = sniffImage(res.buffer) || sniffAvif(res.buffer);
  if (!kind) throw new FetchError("사진(JPEG·PNG·WebP·GIF·AVIF)이 아닙니다", "not_image");
  return { buffer: res.buffer, type: kind.mime };
}

function sniffAvif(buf) {
  if (!buf || buf.length < 12 || buf.toString("ascii", 4, 8) !== "ftyp") return null;
  return /^avi[fs]$/.test(buf.toString("ascii", 8, 12)) ? { ext: "avif", mime: "image/avif" } : null;
}

// 제품 찾기: 이름으로 쇼핑·이미지 검색해서 사진과 정보를 가져오고, 제품 링크를 붙이면 정보를 채운다.
import { esc, icon, won } from "../lib/util.js";
import { api } from "../lib/api.js";
import { modal, toast, toastError, busy } from "../lib/ui.js";
import { state, can } from "../lib/store.js";
import * as sfx from "../lib/sfx.js";

const SOURCE_LABEL = { naver_shop: "네이버 쇼핑", naver_image: "네이버 이미지", kakao_image: "카카오 이미지" };

/**
 * onPick({ image: 주소|null, fields: {...} }) 로 돌려준다
 */
export function findProduct({ query = "", onPick }) {
  const avail = (state.settings.product_search && state.settings.product_search.available) || [];
  let source = avail[0] || "";
  const body = document.createElement("div");
  body.innerHTML = `
    <form class="row nw" data-f><div class="search" style="flex:1">${icon("search")}<input type="search" value="${esc(query)}" placeholder="제품 이름·모델명" autofocus data-mobile-focus></div><button class="btn primary" type="submit">찾기</button></form>
    ${avail.length > 1 ? `<div class="seg" data-src style="margin-top:8px">${avail.map((s) => `<button type="button" data-v="${s}" aria-pressed="${s === source}">${SOURCE_LABEL[s]}</button>`).join("")}</div>` : ""}
    <div data-out style="margin-top:12px"></div>
    <hr class="sep">
    <div class="pick-sec">LINK · 제품 링크로 채우기</div>
    <form class="row nw" data-u><input type="url" placeholder="쇼핑몰·제조사 제품 페이지 주소를 붙여넣기" inputmode="url"><button class="btn" type="submit">${icon("link")}읽기</button></form>
    <p class="help" style="margin-top:6px">쇼핑몰에서 주소를 복사해 붙이면 이름·제조사·가격·사진을 가져와요${state.ai.available ? `. ${esc(state.ai.name)}가 규격까지 정리해요` : ""}.</p>`;
  const out = body.querySelector("[data-out]");
  const input = body.querySelector("[data-f] input");
  let h = null;

  async function run() {
    const q = input.value.trim();
    if (!q) return;
    out.innerHTML = `<div class="empty"><div class="dots"><i></i><i></i><i></i></div></div>`;
    try {
      const r = await api.get(`/api/product-search?q=${encodeURIComponent(q)}&source=${source}`);
      const links = (r.links || []).map((l) => `<a class="btn sm" href="${esc(l.url)}" target="_blank" rel="noopener">${icon("link")}${esc(l.name)}</a>`).join("");
      if (!r.source) {
        out.innerHTML = `<div class="callout">${esc(r.hint)}${can("system") ? "" : " 관리자에게 부탁하세요."}</div>
          <div class="row" style="margin-top:8px">${links}${can("system") ? `<button class="btn sm ghost" type="button" data-b="keys">${icon("gear")}키 넣으러 가기</button>` : ""}</div>
          <p class="help" style="margin-top:8px">검색 사이트에서 제품 페이지 주소를 복사해 아래에 붙이거나, 사진을 복사해 등록 화면에 붙여넣으세요(Ctrl+V).</p>`;
        const keys = out.querySelector('[data-b="keys"]');
        if (keys) keys.onclick = () => { h.close(); location.hash = "#systems?tab=product"; };
        return;
      }
      if (!r.results.length) {
        out.innerHTML = `<div class="empty">결과가 없어요. 모델명으로 찾아 보세요.</div><div class="row">${links}</div>`;
        return;
      }
      out.innerHTML = `<div class="prod-grid">${r.results.map((x, i) => `<button class="prod" type="button" data-i="${i}"><span class="im"><img src="${esc(x.thumb || x.image)}" alt="" loading="lazy" referrerpolicy="no-referrer"></span>
        <span class="tx"><span class="t">${esc(x.title)}</span>${x.price ? `<span class="p">${won(x.price)}</span>` : ""}${x.mall || x.maker ? `<span class="m">${esc([x.maker || x.brand, x.mall].filter(Boolean).join(" · "))}</span>` : ""}</span></button>`).join("")}</div>
        <div class="row" style="margin-top:10px">${links}</div>`;
      out.querySelectorAll("[data-i]").forEach((b) => {
        b.onclick = () => {
          const x = r.results[Number(b.dataset.i)];
          sfx.play("ok");
          const fields = r.source === "naver_shop"
            ? { name: x.title, manufacturer: x.maker || x.brand || "", price: x.price || null, product_url: x.link, vendor: x.mall || "", category_hint: x.category }
            : {};
          h.close();
          onPick({ image: x.image, fields, source: r.source });
        };
      });
    } catch (e) {
      out.innerHTML = `<div class="callout crit">${esc(e.message)}</div>`;
    }
  }
  body.querySelector("[data-f]").onsubmit = (ev) => { ev.preventDefault(); run(); };
  const src = body.querySelector("[data-src]");
  if (src) src.addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-v]");
    if (!b) return;
    source = b.dataset.v;
    for (const x of src.querySelectorAll("[data-v]")) x.setAttribute("aria-pressed", String(x === b));
    run();
  });
  body.querySelector("[data-u]").onsubmit = async (ev) => {
    ev.preventDefault();
    const url = body.querySelector("[data-u] input").value.trim();
    if (!url) return;
    const btn = body.querySelector("[data-u] button");
    await busy(btn, async () => {
      try {
        const page = await importUrl(url);
        h.close();
        onPick(page);
      } catch (e) { toastError(e); }
    });
  };
  h = modal({ title: "제품 찾기", code: "PRODUCT SCAN", size: "wide", body, onOpen: () => { if (query) run(); } });
  return h;
}

// 링크 → { image, images, fields }
export async function importUrl(url) {
  const page = await api.post("/api/url-import", { url });
  const f = { ...page.fields };
  const a = page.ai || {};
  const pick = (k) => (a[k] ? a[k] : f[k]);
  const fields = {
    name: pick("name"), manufacturer: pick("manufacturer"), model: pick("model"), spec: a.spec || "", price: a.price || f.price || null,
    product_url: page.url, vendor: f.vendor || "", barcode: f.barcode || "", aliases: a.aliases || "", kind: a.kind || "", unit: a.unit || "", category_hint: a.category || "",
    description: f.description || "",
  };
  if (page.ai_error) toast(`AI 정리는 건너뛰었어요: ${page.ai_error}`, { tone: "warn" });
  return { image: page.images[0] || null, images: page.images || [], fields, source: page.source };
}

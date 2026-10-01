// 사진 도우미: 파일·붙여넣기·주소에서 불러오기, 줄이기, 올리기.
import { uploadImage } from "../lib/api.js";

export const MAX_SIDE = 1600;
export const THUMB_SIDE = 480;

// 파일·Blob → 캔버스(휴대폰 사진의 회전 정보도 반영)
export async function loadToCanvas(src, maxSide = MAX_SIDE) {
  let bmp;
  if (src instanceof Blob) {
    bmp = await createImageBitmap(src, { imageOrientation: "from-image" }).catch(() => null);
    if (!bmp) {
      const url = URL.createObjectURL(src);
      try { bmp = await loadImg(url); } finally { setTimeout(() => URL.revokeObjectURL(url), 2000); }
    }
  } else if (typeof src === "string") {
    bmp = await loadImg(src);
  } else bmp = src;
  const w0 = bmp.width || bmp.naturalWidth;
  const h0 = bmp.height || bmp.naturalHeight;
  const s = Math.min(1, maxSide / Math.max(w0, h0));
  const c = document.createElement("canvas");
  c.width = Math.max(1, Math.round(w0 * s));
  c.height = Math.max(1, Math.round(h0 * s));
  const ctx = c.getContext("2d");
  ctx.imageSmoothingQuality = "high";
  ctx.drawImage(bmp, 0, 0, c.width, c.height);
  if (bmp.close) bmp.close();
  return c;
}

function loadImg(url) {
  return new Promise((resolve, reject) => {
    const im = new Image();
    im.crossOrigin = "anonymous";
    im.onload = () => resolve(im);
    im.onerror = () => reject(new Error("이미지를 불러오지 못했습니다"));
    im.src = url;
  });
}

// 다른 사이트 이미지는 서버가 대신 받아 온다(캔버스가 오염되지 않게)
export async function fetchRemote(url) {
  const r = await fetch(`/api/image-proxy?url=${encodeURIComponent(url)}`, { credentials: "same-origin" });
  if (!r.ok) {
    let msg = `이미지를 받지 못했습니다 (HTTP ${r.status})`;
    try { msg = (await r.json()).error || msg; } catch { /* 글자 아님 */ }
    throw new Error(msg);
  }
  return r.blob();
}

export function hasAlpha(canvas) {
  const ctx = canvas.getContext("2d", { willReadFrequently: true });
  const { data } = ctx.getImageData(0, 0, canvas.width, canvas.height);
  for (let i = 3; i < data.length; i += 16) if (data[i] < 250) return true;
  return false;
}

export function scaled(canvas, maxSide) {
  const s = Math.min(1, maxSide / Math.max(canvas.width, canvas.height));
  if (s === 1) return canvas;
  const c = document.createElement("canvas");
  c.width = Math.round(canvas.width * s);
  c.height = Math.round(canvas.height * s);
  const ctx = c.getContext("2d");
  ctx.imageSmoothingQuality = "high";
  ctx.drawImage(canvas, 0, 0, c.width, c.height);
  return c;
}

function toBlob(canvas, type, q) {
  return new Promise((resolve) => canvas.toBlob((b) => resolve(b), type, q));
}

// 투명하면 WebP(없으면 PNG), 아니면 JPEG
export async function encode(canvas, maxSide = MAX_SIDE) {
  const c = scaled(canvas, maxSide);
  if (hasAlpha(c)) {
    const webp = await toBlob(c, "image/webp", 0.9);
    if (webp && webp.type === "image/webp") return webp;
    return toBlob(c, "image/png");
  }
  return toBlob(c, "image/jpeg", 0.87);
}

// 큰 사진 + 작은 사진을 올리고 경로를 돌려준다
export async function uploadCanvas(canvas) {
  const [big, small] = await Promise.all([encode(canvas, MAX_SIDE), encode(canvas, THUMB_SIDE)]);
  const [a, b] = await Promise.all([uploadImage(big), uploadImage(small)]);
  return { image: a.path, thumb: b.path };
}

// AI 사진 읽기에 보낼 작은 JPEG(data URL)
export function toDataUrl(canvas, maxSide = 896) {
  const c = scaled(canvas, maxSide);
  const out = document.createElement("canvas");
  out.width = c.width;
  out.height = c.height;
  const ctx = out.getContext("2d");
  ctx.fillStyle = "#fff";
  ctx.fillRect(0, 0, out.width, out.height);
  ctx.drawImage(c, 0, 0);
  return out.toDataURL("image/jpeg", 0.82);
}

// 클립보드 붙여넣기(Ctrl+V)에서 이미지 꺼내기
export function imageFromPaste(ev) {
  const items = (ev.clipboardData && ev.clipboardData.items) || [];
  for (const it of items) if (it.type && it.type.startsWith("image/")) return it.getAsFile();
  return null;
}

export async function imageFromClipboard() {
  if (!navigator.clipboard || !navigator.clipboard.read) return null;
  try {
    const list = await navigator.clipboard.read();
    for (const item of list) {
      const t = item.types.find((x) => x.startsWith("image/"));
      if (t) return item.getType(t);
    }
  } catch { /* 권한 없음 */ }
  return null;
}

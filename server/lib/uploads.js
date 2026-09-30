// 사진 저장. 브라우저가 줄이고 다듬은 이미지를 받아 data/uploads/연/월/ 에 둔다.
import fs from "node:fs";
import path from "node:path";
import { newId, badRequest, notFound } from "./util.js";

export const MAX_UPLOAD_BYTES = 12 * 1024 * 1024;

export function sniffImage(buf) {
  if (!buf || buf.length < 12) return null;
  if (buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff) return { ext: "jpg", mime: "image/jpeg" };
  if (buf[0] === 0x89 && buf[1] === 0x50 && buf[2] === 0x4e && buf[3] === 0x47) return { ext: "png", mime: "image/png" };
  if (buf.toString("ascii", 0, 4) === "RIFF" && buf.toString("ascii", 8, 12) === "WEBP") return { ext: "webp", mime: "image/webp" };
  if (buf.toString("ascii", 0, 4) === "GIF8") return { ext: "gif", mime: "image/gif" };
  return null;
}

export function saveImage(ctx, buf) {
  if (!Buffer.isBuffer(buf) || !buf.length) throw badRequest("이미지가 비어 있습니다");
  if (buf.length > MAX_UPLOAD_BYTES) throw badRequest("이미지가 너무 큽니다(12MB 이하)");
  const type = sniffImage(buf);
  if (!type) throw badRequest("JPEG·PNG·WebP·GIF 이미지만 올릴 수 있습니다");
  const d = new Date();
  const rel = `${d.getFullYear()}/${String(d.getMonth() + 1).padStart(2, "0")}/${newId()}.${type.ext}`;
  const abs = path.join(ctx.cfg.uploadsDir, rel);
  fs.mkdirSync(path.dirname(abs), { recursive: true });
  fs.writeFileSync(abs, buf);
  return { path: rel, url: `/uploads/${rel}`, bytes: buf.length, mime: type.mime };
}

// 경로 조작(../)을 막는다
export function uploadPath(ctx, rel) {
  const clean = String(rel || "").replace(/^\/?uploads\//, "");
  if (!/^[\w\-/.]+$/.test(clean) || clean.includes("..")) throw notFound();
  const abs = path.resolve(ctx.cfg.uploadsDir, clean);
  if (!abs.startsWith(path.resolve(ctx.cfg.uploadsDir) + path.sep)) throw notFound();
  return abs;
}

// DB 에 없는 사진 파일 정리(하루 지난 것만)
export function orphanUploads(ctx) {
  const used = new Set();
  const db = ctx.db;
  for (const t of ["items", "assets"]) {
    for (const r of db.prepare(`SELECT image, thumb FROM ${t}`).all()) {
      if (r.image) used.add(r.image.replace(/^\/?uploads\//, ""));
      if (r.thumb) used.add(r.thumb.replace(/^\/?uploads\//, ""));
    }
  }
  for (const r of db.prepare("SELECT image FROM locations WHERE image IS NOT NULL UNION ALL SELECT image FROM repairs WHERE image IS NOT NULL").all()) {
    used.add(String(r.image).replace(/^\/?uploads\//, ""));
  }
  const out = [];
  const root = ctx.cfg.uploadsDir;
  const walk = (dir) => {
    let list = [];
    try { list = fs.readdirSync(dir, { withFileTypes: true }); } catch { return; }
    for (const e of list) {
      const p = path.join(dir, e.name);
      if (e.isDirectory()) walk(p);
      else {
        const rel = path.relative(root, p).split(path.sep).join("/");
        if (!used.has(rel)) {
          const st = fs.statSync(p);
          if (Date.now() - st.mtimeMs > 86400000) out.push({ rel, abs: p, bytes: st.size });
        }
      }
    }
  };
  walk(root);
  return out;
}

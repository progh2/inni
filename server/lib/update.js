// NAS 자동 업데이트(scripts/nas-auto-update.sh)가 data/auto-update.json 에 남기는 결과를 읽는다.
// 시스템 → 정보 화면과 관리자 경보에 쓴다.
import fs from "node:fs";
import path from "node:path";

export function readUpdateStatus(ctx) {
  try {
    const s = JSON.parse(fs.readFileSync(path.join(ctx.cfg.dataDir, "auto-update.json"), "utf8"));
    return s && typeof s === "object" ? s : null;
  } catch {
    return null;
  }
}

// 지금 코드의 커밋(짧은 해시). 도커 이미지는 빌드할 때 INNI_COMMIT 으로 받고, 그냥 실행하면 .git 에서 읽는다.
export function gitCommit(root) {
  try {
    const gitDir = path.join(root, ".git");
    const head = fs.readFileSync(path.join(gitDir, "HEAD"), "utf8").trim();
    if (!head.startsWith("ref:")) return head.slice(0, 7);
    const ref = head.slice(4).trim();
    const loose = path.join(gitDir, ref);
    if (fs.existsSync(loose)) return fs.readFileSync(loose, "utf8").trim().slice(0, 7);
    const packed = fs.readFileSync(path.join(gitDir, "packed-refs"), "utf8").split("\n").find((l) => l.endsWith(` ${ref}`));
    return packed ? packed.slice(0, 7) : "";
  } catch {
    return "";
  }
}

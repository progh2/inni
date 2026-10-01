// 처음 설정: 빈 inni 를 연 관리자에게 시작 방법을 고르게 한다.
// 예시로 둘러보기 · 예전 inni(PHP) 데이터 가져오기 · 백업 파일로 복원(다른 NAS 에서 이사) · 장소부터 빠르게 만들기
import { esc, icon } from "../lib/util.js";
import { api } from "../lib/api.js";
import { modal, toast, toastError, busy } from "../lib/ui.js";
import { characterHtml } from "../ai/character.js";
import { app } from "../app.js";
import { restoreFromFile } from "../stations/systems.js";

export async function openSetup() {
  let status;
  try { status = await api.get("/api/setup/status"); } catch { return; }
  if (!status.empty) return;
  const lg = status.legacy;
  const h = modal({
    title: "inni 에 오신 것을 환영해요", code: "FIRST LAUNCH", size: "wide", dismissable: true,
    body: `
      <div class="row nw" style="gap:14px;margin-bottom:14px">${characterHtml({ size: "md", mood: "happy" })}
        <p class="lead" style="margin:0">처음이군요! 저는 보급관 <b>이니</b>예요. 어떻게 시작할까요?</p></div>
      <div class="setup-opts">
        ${lg.found ? `<button class="setup-opt found" type="button" data-o="legacy">${icon("database")}<b>예전 inni 데이터 가져오기</b>
          <span>${esc(lg.school || "")} · 품목 ${lg.counts.items} · 장비 ${lg.counts.assets} · 장소 ${lg.counts.locations}${lg.demo_only ? " (예시 데이터로 보여요)" : ""}<br>이미 붙인 QR 라벨도 그대로 통해요.</span></button>` : ""}
        <button class="setup-opt" type="button" data-o="rooms">${icon("decks")}<b>장소부터 빠르게 만들기</b><span>건물·실 이름을 한 줄씩 적으면 한꺼번에 만들어요. 그다음 물건을 등록하세요.</span></button>
        <button class="setup-opt" type="button" data-o="restore">${icon("upload")}<b>백업 파일로 복원</b><span>다른 NAS·PC 에서 쓰던 inni 백업(zip)을 올려 그대로 옮겨 와요.</span></button>
        <button class="setup-opt" type="button" data-o="demo">${icon("magic")}<b>예시로 둘러보기</b><span>실습실·장비·소모품 예시를 넣어 기능을 먼저 체험해요. 나중에 지우면 돼요.</span></button>
      </div>`,
    actions: [{ label: "나중에", tone: "ghost" }],
  });
  h.el.querySelectorAll("[data-o]").forEach((b) => {
    b.onclick = () => busy(b, async () => {
      const o = b.dataset.o;
      try {
        if (o === "demo") {
          await api.post("/api/setup/demo", {});
          toast("예시 데이터를 넣었어요. 둘러보세요!", { tone: "good" });
          h.close();
          await app.refresh();
          app.go("bridge");
        } else if (o === "legacy") {
          const r = await api.post("/api/setup/legacy", {});
          toast(`가져왔어요: 품목 ${r.items}, 장비 ${r.units}, 재고 ${r.stocks}, 장소 ${r.locations}, 대여 ${r.loans}, 사진 ${r.photos}`, { tone: "good", timeout: 9000 });
          h.close();
          await app.refresh();
          app.go("bridge");
        } else if (o === "restore") {
          h.close();
          restoreFromFile();
        } else if (o === "rooms") {
          h.close();
          quickRooms();
        }
      } catch (e) { toastError(e); }
    });
  });
}

// "실습동: 전자실습실(E-201), 용접실" 처럼 적으면 건물·실을 만든다
export function quickRooms() {
  modal({
    title: "장소 한꺼번에 만들기", code: "DECK BUILDER", size: "wide",
    body: `<p class="help">한 줄에 건물 하나. <b>건물: 실, 실(번호), …</b> 모양으로 적으세요. 건물 없이 실 이름만 적어도 돼요.</p>
      <textarea id="qr-text" rows="8" autofocus placeholder="실습동: 전자실습실(E-201), 용접실(W-103), 공구실, 기자재창고&#10;본관: 교무실, 시청각실&#10;3D프린팅실"></textarea>
      <p class="help" style="margin-top:8px">선반·캐비닛 같은 보관함은 나중에 장소 화면에서 실 아래에 더하면 돼요.</p>`,
    actions: [{ label: "취소", tone: "ghost" }, {
      label: "만들기", tone: "primary", icon: "check",
      onClick: async (h) => {
        const lines = h.el.querySelector("#qr-text").value.split("\n").map((s) => s.trim()).filter(Boolean);
        if (!lines.length) { toast("한 줄 이상 적어 주세요", { tone: "warn" }); return false; }
        let n = 0;
        try {
          for (const line of lines) {
            const m = /^([^:：]+)[:：](.*)$/.exec(line);
            const parseRoom = (s) => { const r = /^(.+?)\s*[(（]([^)）]+)[)）]\s*$/.exec(s.trim()); return r ? { name: r[1].trim(), code: r[2].trim() } : { name: s.trim(), code: "" }; };
            if (m) {
              const b = await api.post("/api/locations", { name: m[1].trim(), kind: "building" });
              n++;
              for (const part of m[2].split(/[,，]/).map((s) => s.trim()).filter(Boolean)) {
                const r = parseRoom(part);
                await api.post("/api/locations", { name: r.name, code: r.code, kind: "room", parent_id: b.location.id });
                n++;
              }
            } else {
              for (const part of line.split(/[,，]/).map((s) => s.trim()).filter(Boolean)) {
                const r = parseRoom(part);
                await api.post("/api/locations", { name: r.name, code: r.code, kind: "room" });
                n++;
              }
            }
          }
          toast(`장소 ${n}곳을 만들었어요. 이제 물건을 등록해 보세요!`, { tone: "good", action: { label: "등록하기", icon: "plus", run: () => app.addItem({}) } });
          await app.refresh();
          app.go("decks");
          return true;
        } catch (e) { toastError(e); return false; }
      },
    }],
  });
}

// 말·글 명령을 규칙으로 처리한다(AI 없이도). 이니 대화창과 검색창이 같이 쓴다.
import { api } from "../lib/api.js";
import { app } from "../app.js";
import { state, can } from "../lib/store.js";
import { parseIntent } from "../shared/intents.js";
import { qty as fmtQty, dueAt } from "../lib/util.js";
import { josa } from "../shared/hangul.js";

async function findOne(name) {
  const r = await api.get(`/api/search?q=${encodeURIComponent(name)}&limit=6`);
  return { top: r.items[0] || null, items: r.items, locations: r.locations, exact: r.exact };
}

function whereText(card) {
  if (!card.where || !card.where.length) return card.status === "on_loan" ? "모두 대여 중이에요" : "등록된 위치가 없어요";
  const parts = card.where.map((w) => `${w.path}${card.kind === "equipment" ? ` ${w.units}대` : ` ${fmtQty(w.qty)}${card.unit}`}`);
  return parts.join(", ") + (card.where_more ? ` 외 ${card.where_more}곳` : "");
}

const DUE_PRESET = { 오늘: "today", 내일: "tomorrow", 모레: "3d", "다음 주": "week", 다음주: "week", 일주일: "week" };

/**
 * @returns {Promise<{ reply: string, handled: boolean, items?: [] }>}
 */
export async function runLocalCommand(text) {
  const it = parseIntent(text);
  const name = state.ai.name || "이니";
  switch (it.type) {
    case "empty":
      return { reply: "무엇을 도와드릴까요?", handled: false };
    case "scan":
      app.scanAndOpen();
      return { reply: "스캐너를 켰어요. 라벨을 비춰 주세요.", handled: true };
    case "nav":
      app.go(it.station, it.params || {});
      return { reply: `${it.label} 화면을 열었어요.`, handled: true };
    case "add":
      if (!can("register")) return { reply: "등록 권한이 없어요. 담당 선생님께 부탁해 주세요.", handled: true };
      app.addItem({ name: it.name || "", quantity: it.qty || undefined });
      return { reply: it.name ? `${josa(it.name, "을", "를")} 등록할게요. 사진을 찍고 장소만 고르면 돼요.` : "등록 화면을 열었어요.", handled: true };
    case "contents": {
      const r = await api.get(`/api/search?q=${encodeURIComponent(it.place)}&limit=3`);
      const loc = r.locations[0];
      if (!loc) return { reply: `"${it.place}" 장소를 찾지 못했어요.`, handled: true };
      app.openLocation(loc.id);
      if (app.scene) app.scene.highlight([loc.id]);
      return { reply: `${loc.path}에는 물건 ${loc.items}종${loc.units ? `(장비 ${loc.units}대)` : ""}이 있어요. 화면을 열었어요.`, handled: true };
    }
    case "find":
    case "search": {
      const q = it.query;
      const r = await findOne(q);
      if (!r.top && r.locations[0]) {
        app.openLocation(r.locations[0].id);
        return { reply: `${r.locations[0].path} 장소를 열었어요.`, handled: true };
      }
      if (!r.top) {
        app.go("search", { q });
        return { reply: `"${q}"${josa(q, "은", "는").slice(q.length)} 찾지 못했어요. 이름을 조금 다르게 적어 보시겠어요? 초성(예: ㅁㅌㅁㅌ)도 돼요.`, handled: true };
      }
      if (r.items.length === 1 || r.exact) {
        app.openItem(r.top.id, r.top.match_unit_id);
        if (app.scene) app.scene.highlight(r.top.where.map((w) => w.id));
        return { reply: `**${r.top.name}**: ${whereText(r.top)}. (${r.top.status_label})`, handled: true, items: [r.top] };
      }
      app.go("search", { q });
      return { reply: `"${q}"에 맞는 것이 ${r.items.length}가지 있어요. 가장 비슷한 건 **${r.top.name}** — ${whereText(r.top)}.`, handled: true, items: r.items.slice(0, 4) };
    }
    case "move": {
      if (!can("move")) return { reply: "옮기기 권한이 없어요.", handled: true };
      const r = await findOne(it.item);
      if (!r.top) return { reply: `"${it.item}"${josa(it.item, "을", "를").slice(it.item.length)} 찾지 못했어요.`, handled: true };
      const to = it.to ? (await api.get(`/api/search?q=${encodeURIComponent(it.to)}&limit=1`)).locations[0] : null;
      app.actions.move({ itemId: r.top.id, unitId: r.top.match_unit_id, count: it.count, toLocationId: to ? to.id : null });
      return { reply: to ? `${r.top.name}${josa(r.top.name, "을", "를").slice(r.top.name.length)} ${to.name}(으)로 옮길 준비를 했어요. 확인하고 눌러 주세요.` : `${r.top.name}을(를) 어디로 옮길지 골라 주세요.`, handled: true };
    }
    case "loan": {
      if (!can("loan")) return { reply: "대여 권한이 없어요.", handled: true };
      const r = await findOne(it.item);
      if (!r.top) return { reply: `"${it.item}"을(를) 찾지 못했어요.`, handled: true };
      const due = DUE_PRESET[String(it.due || "").replace(/\s+/g, " ")] ? dueAt(DUE_PRESET[it.due], state.settings.loan.day_end) : null;
      app.actions.loan({ itemId: r.top.id, unitId: r.top.match_unit_id, count: it.count, borrowerName: it.borrower || "", dueAt: due });
      return { reply: `${r.top.name} 대여 창을 열었어요${it.borrower ? ` (${it.borrower})` : ""}.`, handled: true };
    }
    case "return": {
      if (!can("loan") && state.me.role !== "student") return { reply: "반납 권한이 없어요.", handled: true };
      app.actions.returnFind({ item: it.item, borrower: it.borrower });
      return { reply: "반납할 대여를 찾았어요. 확인해 주세요.", handled: true };
    }
    case "use":
    case "restock": {
      if (!can("stock")) return { reply: "입출고 권한이 없어요.", handled: true };
      const r = await findOne(it.item);
      if (!r.top) return { reply: `"${it.item}"을(를) 찾지 못했어요.`, handled: true };
      if (r.top.kind === "equipment") {
        app.openItem(r.top.id);
        return { reply: `${r.top.name}은(는) 한 대씩 관리하는 장비예요. 물건 화면을 열었어요.`, handled: true };
      }
      if (it.type === "use") app.actions.use({ itemId: r.top.id, qty: it.qty || 1 });
      else app.actions.restock({ itemId: r.top.id, qty: it.qty || 1 });
      return { reply: `${r.top.name} ${it.type === "use" ? "사용" : "입고"} 창을 열었어요.`, handled: true };
    }
    default:
      return { reply: `잘 모르겠어요. "${name}"에게 "멀티미터 어디 있어?"처럼 물어봐 주세요.`, handled: false };
  }
}

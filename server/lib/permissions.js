// 역할별로 할 수 있는 일. 화면과 서버가 같은 목록을 본다(/api/me 가 caps 를 내려준다).
export const ROLES = ["owner", "manager", "teacher", "student"];
export const ROLE_LABEL = { owner: "관리자", manager: "담당교사", teacher: "교사", student: "학생" };
export const STATUS_LABEL = { pending: "승인 대기", active: "사용 중", disabled: "사용 중지" };

// view 보기 · loan 대여/반납 · move 위치 이동 · stock 입고/사용 · register 새 물품 등록 · edit 정보 수정
// delete 삭제/폐기 · audit 실사 · repair 고장 신고 · repair_manage 수리 처리 · users 사용자 승인
// settings 학교 설정 · system AI·백업·복원·외부 키 · ai 이니와 대화
export const CAPS = ["view", "loan", "move", "stock", "register", "edit", "delete", "audit", "repair", "repair_manage", "users", "settings", "system", "ai"];

const ROLE_CAPS = {
  owner: CAPS,
  manager: CAPS.filter((c) => c !== "system"),
  teacher: ["view", "loan", "move", "stock", "register", "repair", "ai"],
  student: ["view", "repair"],
};

export const DEFAULT_ACCESS = Object.freeze({
  auto_approve: false, // 허용 도메인 계정은 승인 없이 바로 들어온다
  default_role: "teacher",
  teacher_can_register: true,
  teacher_can_move: true,
  student_login: false,
  student_ai: false,
});

export function capsFor(user, access = DEFAULT_ACCESS) {
  if (!user || user.status !== "active") return new Set();
  const caps = new Set(ROLE_CAPS[user.role] || []);
  if (user.role === "teacher") {
    if (access.teacher_can_register === false) caps.delete("register");
    if (access.teacher_can_move === false) caps.delete("move");
  }
  if (user.role === "student" && access.student_ai) caps.add("ai");
  return caps;
}

export function can(user, cap, access) {
  return capsFor(user, access).has(cap);
}

// 역할 바꾸기: 관리자(owner)만 관리자를 만들거나 바꿀 수 있다
export function canAssignRole(actor, target, nextRole) {
  if (!actor) return false;
  if (actor.role === "owner") return true;
  if (actor.role !== "manager") return false;
  if (target && target.role === "owner") return false;
  return nextRole !== "owner";
}

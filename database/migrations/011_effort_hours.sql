-- 투입공수는 MD 환산 없이 시간으로 입력·저장한다(effort_hours). 기존 effort_md 는 테스트 값이라 옮기지 않고 버린다.
ALTER TABLE request ADD COLUMN effort_hours REAL;
ALTER TABLE request DROP COLUMN effort_md;

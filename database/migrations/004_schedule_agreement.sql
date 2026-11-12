-- M4: 일정 협의는 작업자 제안 / 요청자 응답 구조라 제안자 구분(side)이 필요 없다. 대안(COUNTER) 날짜 컬럼 추가, 협의 대기는 요청당 1건.

ALTER TABLE schedule_change DROP COLUMN proposer_side;
ALTER TABLE schedule_agreement DROP COLUMN side;
ALTER TABLE schedule_agreement ADD COLUMN counter_end TEXT;

CREATE INDEX ix_schedule_status ON schedule_change (company_cd, request_id, status);
CREATE UNIQUE INDEX ux_schedule_pending ON schedule_change (company_cd, request_id) WHERE status = 'PROPOSED';

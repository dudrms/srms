-- SR 분할: 새 SR 이 어느 SR 에서 누가 분할해 만든 것인지 기록한다(parent_id 가 있으면 분할로 생긴 건).
ALTER TABLE request ADD COLUMN parent_id INTEGER;
ALTER TABLE request ADD COLUMN split_by INTEGER;

CREATE INDEX ix_request_parent ON request (company_cd, parent_id);

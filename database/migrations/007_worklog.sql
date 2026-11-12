-- 작업일지(사후등록): 실제 요청일, 접수 채널, 등록한 IT 담당자. reg_by 가 있으면 작업일지 건이다.
ALTER TABLE request ADD COLUMN request_date TEXT;
ALTER TABLE request ADD COLUMN channel TEXT;
ALTER TABLE request ADD COLUMN reg_by INTEGER;

CREATE INDEX ix_request_reg ON request (company_cd, reg_by);

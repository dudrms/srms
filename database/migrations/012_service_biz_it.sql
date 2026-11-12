-- 서비스별 현업 IT 담당자(주관부서 소속 BIZ_IT 사용자 중 지정). 지정이 없으면 처리자 유형 SERVICE_BIZ_OWNER 는 주관부서 현업 전산담당 전원으로 동작한다.
CREATE TABLE service_biz_it (
  company_cd TEXT NOT NULL,
  service_id INTEGER NOT NULL,
  user_id    INTEGER NOT NULL,
  PRIMARY KEY (company_cd, service_id, user_id),
  FOREIGN KEY (company_cd, service_id) REFERENCES service(company_cd, service_id),
  FOREIGN KEY (company_cd, user_id) REFERENCES user(company_cd, user_id)
);

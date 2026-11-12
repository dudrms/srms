-- 일정조율은 별도 프로세스가 아니므로 기본 시드(SCHEDULE)와 시스템 프로세스 구분(is_system)을 제거한다.

DELETE FROM process_step_cc WHERE step_id IN (
  SELECT s.step_id FROM process_step s
    JOIN process_version v ON v.company_cd = s.company_cd AND v.version_id = s.version_id
   WHERE v.process_id IN (SELECT process_id FROM process_master WHERE process_cd = 'SCHEDULE' AND is_system = 1)
);

DELETE FROM translation WHERE entity = 'STEP' AND entity_key IN (
  SELECT CAST(s.step_id AS TEXT) FROM process_step s
    JOIN process_version v ON v.company_cd = s.company_cd AND v.version_id = s.version_id
   WHERE v.process_id IN (SELECT process_id FROM process_master WHERE process_cd = 'SCHEDULE' AND is_system = 1)
);

DELETE FROM process_step WHERE version_id IN (
  SELECT version_id FROM process_version
   WHERE process_id IN (SELECT process_id FROM process_master WHERE process_cd = 'SCHEDULE' AND is_system = 1)
);

DELETE FROM process_version
 WHERE process_id IN (SELECT process_id FROM process_master WHERE process_cd = 'SCHEDULE' AND is_system = 1);

DELETE FROM translation WHERE entity = 'PROCESS' AND entity_key = 'SCHEDULE'
   AND company_cd IN (SELECT company_cd FROM process_master WHERE process_cd = 'SCHEDULE' AND is_system = 1);

DELETE FROM process_master WHERE process_cd = 'SCHEDULE' AND is_system = 1;

ALTER TABLE process_master DROP COLUMN is_system;

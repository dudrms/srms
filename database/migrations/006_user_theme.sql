-- 사용자별 화면 테마(light / dark). 로그인 전에는 쿠키로 유지한다.

ALTER TABLE user ADD COLUMN theme TEXT NOT NULL DEFAULT 'light';

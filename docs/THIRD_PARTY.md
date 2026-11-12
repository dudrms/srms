# 번들 서드파티 라이브러리

모든 라이브러리는 로컬에 포함되며 CDN 을 사용하지 않습니다(자동 테스트 `cdn:` 가 외부 URL 참조를 검사). 버전을 올릴 때 이 표를 함께 갱신하세요.

| 라이브러리 | 버전 | 라이선스 | 위치 | 용도 |
|---|---|---|---|---|
| Bootstrap | 5.3.8 | MIT | `public/assets/vendor/bootstrap` | UI/레이아웃 |
| Bootstrap Icons | 1.13.2 | MIT | `public/assets/vendor/bootstrap-icons` | 아이콘 |
| Quill | 2.0.3 | BSD-3-Clause | `public/assets/vendor/quill` | 요청 본문 텍스트 에디터 |
| SortableJS | 1.15.7 | MIT | `public/assets/vendor/sortablejs` | 프로세스 단계 드래그 정렬 |
| Chart.js | 4.6.1 | MIT | `public/assets/vendor/chartjs` | 리포트 차트 |
| PHPMailer | 7.2.0 | LGPL-2.1 | `vendor/phpmailer/phpmailer` | SMTP 메일 발송(수정 없이 원본 그대로 포함, 라이선스 파일 동봉) |

라이선스 전문은 각 폴더의 `LICENSE`(Chart.js 는 `LICENSE.md`) 파일을 보세요. Quill 의 라이선스 표기는 라이브러리 배포 정보 기준이며 파일 본문에는 저작권자만 적혀 있습니다.

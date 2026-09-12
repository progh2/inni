<?php

declare(strict_types=1);

/**
 * inni 로컬 / Docker 스모크 설정 예시 → config.php 로 복사
 *
 * 이 파일의 demo_login 은 true 입니다. 데모 버튼이 보여야 하는 로컬·컨테이너 확인용.
 * 운영 배포는 config.production.example.php 를 복사하거나, 반드시 demo_login => false.
 * 키가 빠지면 앱은 false 로 취급합니다 (실패 폐쇄). 실제 Google 키를 이 파일에 넣지 마세요.
 */
return [
    'app_name' => 'inni',
    'school_name' => 'inni 데모 마이스터고',
    'base_url' => '', // 운영 OAuth는 공개 URL로 고정. 예: https://school.kr/inni/public
    'timezone' => 'Asia/Seoul',
    // Local / Docker smoke only. Production MUST be false (see config.production.example.php).
    'demo_login' => true,
    'session_name' => 'inni_sess',

    'google' => [
        'client_id' => '',
        'client_secret' => '',
        // Empty = any Google-verified email. Non-empty = exact domain match only (fail closed).
        'allowed_domains' => [], // 예: ['school.go.kr']
        // Optional exact Console URI. Empty = {base_url}/index.php?r=auth/google/callback
        'redirect_uri' => '',
    ],

    'db_path' => null,     // default: data/inni.sqlite
    'upload_path' => null, // default: public/uploads
    'upload_url' => '/uploads',
    'max_upload_bytes' => 8 * 1024 * 1024,

    // Bot token stays in the server config.php only. Never commit a real token.
    // Empty token = fail closed (UI shows 연결 필요). Chat id / event on-off also live in 설정.
    'telegram' => [
        'bot_token' => '',
        'default_chat_id' => '',
    ],
];

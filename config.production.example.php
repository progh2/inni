<?php

declare(strict_types=1);

/**
 * inni 운영 설정 예시 → config.php 로 복사
 *
 * demo_login 은 false. 로컬·Docker 스모크는 config.example.php (true) 를 쓰세요.
 * Google client_id / client_secret 은 서버의 config.php 에만 넣으세요. 이 파일에 실제 키를 넣지 마세요.
 */
return [
    'app_name' => 'inni',
    'school_name' => '',
    'base_url' => '', // 운영 OAuth는 공개 URL로 고정. 예: https://school.kr/inni/public
    'timezone' => 'Asia/Seoul',
    // Production: keep false. Demo buttons allow login without a school account.
    'demo_login' => false,
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

    // Put the real bot token only in the server copy of config.php. Never commit it.
    // Empty token fails closed. Chat id and per-event switches are saved in 설정 (owner/manager).
    'telegram' => [
        'bot_token' => '',
        'default_chat_id' => '',
    ],
];

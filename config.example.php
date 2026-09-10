<?php

declare(strict_types=1);

/**
 * inni 설정 예시 → config.php 로 복사
 */
return [
    'app_name' => 'inni',
    'school_name' => 'inni 데모 마이스터고',
    'base_url' => '', // 비우면 자동. 서브경로면 예: https://school.kr/inni/public
    'timezone' => 'Asia/Seoul',
    'demo_login' => true,
    'session_name' => 'inni_sess',

    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'allowed_domains' => [], // 예: ['school.go.kr']
    ],

    'db_path' => null,     // default: data/inni.sqlite
    'upload_path' => null, // default: public/uploads
    'upload_url' => '/uploads',
    'max_upload_bytes' => 8 * 1024 * 1024,

    'telegram' => [
        'bot_token' => '',
        'default_chat_id' => '',
    ],
];

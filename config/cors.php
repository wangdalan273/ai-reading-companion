<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    | 移动端原生 App 自身不受浏览器同源策略限制，但上线对外 API、
    | 以及在 WebView/调试场景下，需正确返回 CORS 头。
    | 上线时把 allowed_origins 收紧为你的前端/可信来源。
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

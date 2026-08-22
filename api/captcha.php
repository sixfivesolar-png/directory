<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
start_secure_session();
$left = random_int(2, 9); $right = random_int(1, 9); $token = bin2hex(random_bytes(16));
$_SESSION['review_captcha'] = ['token' => $token, 'answer' => $left + $right, 'expires' => time() + 600];
json_response(['token' => $token, 'question' => "คำตอบของ {$left} + {$right} คือเท่าไร?"]);

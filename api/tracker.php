<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['message' => 'วิธีเรียกใช้งานไม่ถูกต้อง'], 405);
session_start();
$data = request_json();
$slug = valid_slug($data['place_slug'] ?? null);
if (!$slug) json_response(['message' => 'ไม่พบสถานที่'], 422);
$key = 'view_' . $slug;
if (!empty($_SESSION[$key]) && time() - (int) $_SESSION[$key] < 900) json_response(['recorded' => false]);
database()->prepare('INSERT INTO place_views (place_slug, event_type) VALUES (?, "detail_view")')->execute([$slug]);
$_SESSION[$key] = time();
json_response(['recorded' => true], 201);

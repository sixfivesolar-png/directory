<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function json_response(array $payload, int $status = 200): never { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function database(): PDO { try { return new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); } catch (PDOException $exception) { json_response(['message' => 'ยังไม่สามารถเชื่อมต่อฐานข้อมูลได้ โปรดตรวจสอบ MySQL และไฟล์ api/config.php'], 503); } }
function request_json(): array { $payload = json_decode((string) file_get_contents('php://input'), true); if (!is_array($payload)) json_response(['message' => 'รูปแบบข้อมูลไม่ถูกต้อง'], 400); return $payload; }
function valid_slug(mixed $value): ?string {
  return is_string($value) && preg_match('/^[a-z0-9-]{3,140}$/', $value) ? $value : null;
}

function private_claim_storage(): string {
  $root = dirname(__DIR__) . '/storage/private-claims';
  if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) json_response(['message' => 'ไม่สามารถสร้างพื้นที่จัดเก็บไฟล์ได้'], 500);
  return $root;
}

function store_claim_document(array $file, int $claimId): array {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_response(['message' => 'ไม่สามารถอัปโหลดไฟล์หลักฐานได้'], 422);
  $size = (int) ($file['size'] ?? 0);
  if ($size < 1 || $size > 5 * 1024 * 1024) json_response(['message' => 'ไฟล์หลักฐานต้องมีขนาดไม่เกิน 5 MB'], 422);
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file((string) $file['tmp_name']) ?: '';
  $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
  if (!isset($allowed[$mime])) json_response(['message' => 'รองรับเฉพาะ JPG, PNG, WEBP และ PDF'], 422);
  $storedName = 'claim-' . $claimId . '-' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
  $target = private_claim_storage() . '/' . $storedName;
  if (!move_uploaded_file((string) $file['tmp_name'], $target)) json_response(['message' => 'ไม่สามารถบันทึกไฟล์หลักฐานได้'], 500);
  chmod($target, 0600);
  return ['stored_name' => $storedName, 'original_name' => mb_substr(basename((string) $file['name']), 0, 180), 'mime_type' => $mime, 'byte_size' => $size];
}
function start_secure_session(): void { if (session_status() !== PHP_SESSION_ACTIVE) { session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]); session_start(); } }
function current_user(): ?array { start_secure_session(); return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null; }
function require_user(): array { $user = current_user(); if (!$user) json_response(['message' => 'กรุณาเข้าสู่ระบบก่อนดำเนินการ'], 401); return $user; }
function require_admin(): array { $user = require_user(); if (($user['role'] ?? '') !== 'admin') json_response(['message' => 'คุณไม่มีสิทธิ์เข้าถึงส่วนผู้ดูแล'], 403); return $user; }
function valid_status(string $value, array $allowed): bool { return in_array($value, $allowed, true); }

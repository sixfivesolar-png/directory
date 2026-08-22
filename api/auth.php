<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
start_secure_session();
$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'me') json_response(['user' => current_user()]);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') { $_SESSION = []; session_destroy(); json_response(['message' => 'ออกจากระบบเรียบร้อย']); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['register','login'], true)) json_response(['message' => 'ไม่พบคำขอ'], 404);
$data = request_json(); $email = strtolower(trim((string) ($data['email'] ?? ''))); $password = (string) ($data['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) json_response(['message' => 'กรุณาใช้อีเมลที่ถูกต้องและรหัสผ่านอย่างน้อย 8 ตัวอักษร'], 422);
$pdo = database();
if ($action === 'register') {
  $displayName = trim((string) ($data['display_name'] ?? ''));
  if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 80) json_response(['message' => 'กรุณาระบุชื่อที่ต้องการแสดง 2–80 ตัวอักษร'], 422);
  $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?'); $exists->execute([$email]); if ($exists->fetch()) json_response(['message' => 'อีเมลนี้ถูกใช้งานแล้ว'], 409);
  $insert = $pdo->prepare('INSERT INTO users (email, password_hash, display_name, role) VALUES (?, ?, ?, "member")'); $insert->execute([$email, password_hash($password, PASSWORD_DEFAULT), $displayName]);
  $id = (int) $pdo->lastInsertId(); $user = ['id' => $id, 'email' => $email, 'display_name' => $displayName, 'role' => 'member']; session_regenerate_id(true); $_SESSION['user'] = $user; json_response(['message' => 'สมัครสมาชิกเรียบร้อย', 'user' => $user], 201);
}
$statement = $pdo->prepare('SELECT id, email, password_hash, display_name, role FROM users WHERE email = ?'); $statement->execute([$email]); $record = $statement->fetch();
if (!$record || !password_verify($password, $record['password_hash'])) json_response(['message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'], 401);
$user = ['id' => (int) $record['id'], 'email' => $record['email'], 'display_name' => $record['display_name'], 'role' => $record['role']]; session_regenerate_id(true); $_SESSION['user'] = $user; json_response(['message' => 'เข้าสู่ระบบเรียบร้อย', 'user' => $user]);

<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
define('LOYALTY_LIBRARY_ONLY', true); require_once __DIR__ . '/loyalty.php';

function active_coupon_clause(): string { return "status = 'active' AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW()) AND (max_redemptions IS NULL OR redeemed_count < max_redemptions)"; }
function coupon_code(): string { return 'PKK-' . strtoupper(bin2hex(random_bytes(8))); }
function coupon_datetime(string $value, bool $required = false): ?string { $raw = trim($value); if ($raw === '') { if ($required) json_response(['message' => 'กรุณากำหนดวันหมดอายุคูปอง'], 422); return null; } $timestamp = strtotime($raw); if (!$timestamp) json_response(['message' => 'รูปแบบวันสิ้นสุดไม่ถูกต้อง'], 422); return date('Y-m-d H:i:s', $timestamp); }
function code_from_input(mixed $value): ?string { if (!is_string($value)) return null; preg_match('/PKK-[A-F0-9]{8,16}/i', strtoupper($value), $matches); return $matches[0] ?? null; }
function verification_payload(array $row): array { return ['id' => (int) $row['id'], 'coupon_code' => $row['coupon_code'], 'status' => $row['redemption_status'], 'store_name' => $row['store_name'], 'title' => $row['title'], 'description' => $row['description'], 'ends_at' => $row['ends_at'], 'used_at' => $row['used_at']]; }

$pdo = database(); $action = $_GET['action'] ?? 'catalog';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'catalog') {
  $statement = $pdo->query('SELECT id, place_slug, store_name, title, description, point_cost, ends_at, max_redemptions, redeemed_count FROM coupons WHERE ' . active_coupon_clause() . ' ORDER BY point_cost ASC, id DESC');
  json_response(['coupons' => $statement->fetchAll()]);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'mine') {
  $user = require_user(); $statement = $pdo->prepare('SELECT cr.id, c.id AS coupon_id, cr.coupon_code, cr.status, cr.created_at, cr.point_cost, cr.used_at, c.store_name, c.title, c.description, c.ends_at FROM coupon_redemptions cr JOIN coupons c ON c.id = cr.coupon_id WHERE cr.user_id = ? ORDER BY cr.created_at DESC'); $statement->execute([$user['id']]); json_response(['redemptions' => $statement->fetchAll()]);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'admin') {
  require_admin(); $statement = $pdo->query('SELECT c.id, c.place_slug, c.store_name, c.title, c.description, c.point_cost, c.status, c.starts_at, c.ends_at, c.max_redemptions, c.redeemed_count, c.review_note, c.created_at, c.approved_at, owner.display_name AS owner_name FROM coupons c LEFT JOIN users owner ON owner.id = c.owner_id ORDER BY FIELD(c.status, "pending_admin", "active", "inactive", "rejected"), c.created_at DESC'); json_response(['coupons' => $statement->fetchAll()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['message' => 'ไม่พบคำขอ'], 404);
$data = request_json();
if ($action === 'redeem') {
  $user = require_user(); $couponId = filter_var($data['coupon_id'] ?? null, FILTER_VALIDATE_INT); if (!$couponId) json_response(['message' => 'ไม่พบคูปอง'], 422);
  $pdo->beginTransaction();
  try {
    $coupon = $pdo->prepare('SELECT * FROM coupons WHERE id = ? FOR UPDATE'); $coupon->execute([$couponId]); $row = $coupon->fetch();
    if (!$row || $row['status'] !== 'active' || ($row['starts_at'] && strtotime($row['starts_at']) > time()) || ($row['ends_at'] && strtotime($row['ends_at']) < time())) throw new RuntimeException('คูปองนี้ไม่พร้อมแลกในขณะนี้');
    if ($row['max_redemptions'] !== null && (int) $row['redeemed_count'] >= (int) $row['max_redemptions']) throw new RuntimeException('คูปองนี้ถูกแลกครบจำนวนแล้ว');
    $account = $pdo->prepare('SELECT points_balance FROM loyalty_accounts WHERE user_id = ? FOR UPDATE'); $account->execute([$user['id']]); $balance = (int) (($account->fetch()['points_balance'] ?? 0));
    if ($balance < (int) $row['point_cost']) throw new RuntimeException('แต้มคงเหลือไม่เพียงพอสำหรับแลกคูปองนี้');
    $existing = $pdo->prepare('SELECT id FROM coupon_redemptions WHERE user_id = ? AND coupon_id = ?'); $existing->execute([$user['id'], $couponId]); if ($existing->fetch()) throw new RuntimeException('คุณได้แลกคูปองนี้แล้ว');
    $code = coupon_code(); $insert = $pdo->prepare("INSERT INTO coupon_redemptions (user_id, coupon_id, point_cost, coupon_code, status) VALUES (?, ?, ?, ?, 'issued')"); $insert->execute([$user['id'], $couponId, $row['point_cost'], $code]); $redemptionId = (int) $pdo->lastInsertId();
    $event = $pdo->prepare("INSERT INTO loyalty_events (user_id, review_id, redemption_id, event_type, points) VALUES (?, NULL, ?, 'coupon_redeemed', ?)"); $event->execute([$user['id'], $redemptionId, -(int) $row['point_cost']]);
    refresh_loyalty_account($pdo, (int) $user['id']); $pdo->prepare('UPDATE coupons SET redeemed_count = redeemed_count + 1 WHERE id = ?')->execute([$couponId]); $pdo->commit();
    json_response(['message' => 'แลกคูปองสำเร็จ โปรดเปิด QR Code จากหน้าโปรไฟล์เมื่อใช้สิทธิ์', 'coupon_code' => $code], 201);
  } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); json_response(['message' => $exception instanceof RuntimeException ? $exception->getMessage() : 'ไม่สามารถแลกคูปองได้ในขณะนี้'], 422); }
}
if ($action === 'admin-create') {
  $admin = require_admin(); $store = trim((string) ($data['store_name'] ?? '')); $title = trim((string) ($data['title'] ?? '')); $description = trim((string) ($data['description'] ?? '')); $cost = filter_var($data['point_cost'] ?? null, FILTER_VALIDATE_INT); $slug = valid_slug($data['place_slug'] ?? '') ?: null; $limit = filter_var($data['max_redemptions'] ?? null, FILTER_VALIDATE_INT); $ends = coupon_datetime((string) ($data['ends_at'] ?? ''), true);
  if (strtotime((string) $ends) <= time() || mb_strlen($store) < 2 || mb_strlen($title) < 2 || mb_strlen($description) < 4 || $cost === false || $cost < 1 || $cost > 100000 || ($limit !== false && $limit !== null && $limit < 1)) json_response(['message' => 'กรอกชื่อร้าน ชื่อคูปอง รายละเอียด แต้ม จำนวนสิทธิ์ และวันหมดอายุในอนาคตให้ถูกต้อง'], 422);
  $pdo->prepare("INSERT INTO coupons (place_slug, store_name, title, description, point_cost, ends_at, max_redemptions, status, created_by, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, NOW())")->execute([$slug, $store, $title, $description, $cost, $ends, $limit ?: null, $admin['id'], $admin['id']]); json_response(['message' => 'สร้างคูปองสำหรับร้านค้าที่เข้าร่วมแล้ว'], 201);
}
if ($action === 'admin-status') {
  $admin = require_admin(); $couponId = filter_var($data['coupon_id'] ?? null, FILTER_VALIDATE_INT); $status = (string) ($data['status'] ?? ''); $note = mb_substr(trim((string) ($data['review_note'] ?? '')), 0, 500); if (!$couponId || !in_array($status, ['active','inactive','rejected'], true)) json_response(['message' => 'ข้อมูลคูปองไม่ถูกต้อง'], 422);
  if ($status === 'active') $pdo->prepare('UPDATE coupons SET status = ?, approved_by = ?, approved_at = NOW(), review_note = ? WHERE id = ?')->execute([$status, $admin['id'], $note, $couponId]);
  else $pdo->prepare('UPDATE coupons SET status = ?, review_note = ? WHERE id = ?')->execute([$status, $note, $couponId]);
  json_response(['message' => $status === 'active' ? 'อนุมัติและเปิดคูปองแล้ว' : ($status === 'rejected' ? 'ปฏิเสธคำขอคูปองแล้ว' : 'พักการแสดงคูปองแล้ว')]);
}
if ($action === 'verify') {
  $staff = require_user(); if (!in_array($staff['role'] ?? '', ['business_owner','admin'], true)) json_response(['message' => 'เฉพาะเจ้าของธุรกิจหรือผู้ดูแลเท่านั้นที่ตรวจสอบคูปองได้'], 403); $code = code_from_input($data['coupon_code'] ?? null); $confirm = (bool) ($data['confirm'] ?? false); if (!$code) json_response(['message' => 'ไม่พบรหัสคูปองที่ถูกต้อง'], 422);
  $pdo->beginTransaction();
  try {
    $statement = $pdo->prepare('SELECT cr.id, cr.coupon_code, cr.status AS redemption_status, cr.used_at, c.place_slug, c.store_name, c.title, c.description, c.ends_at, c.status AS coupon_status FROM coupon_redemptions cr JOIN coupons c ON c.id = cr.coupon_id WHERE cr.coupon_code = ? FOR UPDATE'); $statement->execute([$code]); $redemption = $statement->fetch();
    if (!$redemption) throw new RuntimeException('ไม่พบคูปองจากรหัสนี้');
    if (($staff['role'] ?? '') !== 'admin') { $claim = $pdo->prepare('SELECT id FROM business_claims WHERE user_id = ? AND place_slug = ? AND status = "approved"'); $claim->execute([$staff['id'], $redemption['place_slug']]); if (!$redemption['place_slug'] || !$claim->fetch()) throw new RuntimeException('บัญชีนี้ไม่มีสิทธิ์ยืนยันคูปองของร้านดังกล่าว'); }
    $payload = verification_payload($redemption);
    if (in_array($redemption['redemption_status'], ['used','redeemed'], true)) { $pdo->commit(); json_response(['state' => 'used', 'message' => 'คูปองนี้ถูกใช้สิทธิ์ไปแล้ว', 'redemption' => $payload], 409); }
    if ($redemption['redemption_status'] !== 'issued') throw new RuntimeException('คูปองนี้ไม่สามารถใช้สิทธิ์ได้');
    if ($redemption['coupon_status'] !== 'active' || ($redemption['ends_at'] && strtotime($redemption['ends_at']) < time())) { $pdo->commit(); json_response(['state' => 'expired', 'message' => 'คูปองนี้หมดอายุหรือถูกระงับแล้ว', 'redemption' => $payload], 422); }
    if (!$confirm) { $pdo->commit(); json_response(['state' => 'ready', 'message' => 'พบคูปองที่พร้อมใช้สิทธิ์ โปรดตรวจสอบข้อมูลแล้วกดยืนยัน', 'redemption' => $payload]); }
    $pdo->prepare("UPDATE coupon_redemptions SET status = 'used', used_by = ?, used_at = NOW(), redeemed_at = NOW() WHERE id = ? AND status = 'issued'")->execute([$staff['id'], $redemption['id']]); $pdo->commit();
    $payload['status'] = 'used'; $payload['used_at'] = date('Y-m-d H:i:s'); json_response(['state' => 'used', 'message' => 'ยืนยันการใช้สิทธิ์คูปองเรียบร้อยแล้ว', 'redemption' => $payload]);
  } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); json_response(['message' => $exception instanceof RuntimeException ? $exception->getMessage() : 'ไม่สามารถตรวจสอบคูปองได้ในขณะนี้'], 422); }
}
json_response(['message' => 'ไม่พบคำขอ'], 404);

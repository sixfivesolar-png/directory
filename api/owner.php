<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$user = require_user(); $pdo = database(); $action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'businesses') { $statement = $pdo->prepare('SELECT place_slug, status, created_at FROM business_claims WHERE user_id = ? ORDER BY created_at DESC'); $statement->execute([$user['id']]); json_response(['claims' => $statement->fetchAll(), 'user' => $user]); }
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'coupons') {
  if (!in_array($user['role'] ?? '', ['business_owner','admin'], true)) json_response(['message' => 'คุณไม่มีสิทธิ์จัดการคูปองร้านค้า'], 403);
  $statement = $pdo->prepare('SELECT id, place_slug, store_name, title, description, point_cost, status, starts_at, ends_at, max_redemptions, redeemed_count, review_note, created_at, approved_at FROM coupons WHERE owner_id = ? ORDER BY created_at DESC'); $statement->execute([$user['id']]); json_response(['coupons' => $statement->fetchAll()]);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'dashboard') {
  $claims = $pdo->prepare('SELECT place_slug, created_at FROM business_claims WHERE user_id = ? AND status = "approved" ORDER BY created_at DESC'); $claims->execute([$user['id']]); $businesses = $claims->fetchAll();
  $slugs = array_column($businesses, 'place_slug'); if (!$slugs) json_response(['businesses' => [], 'summary' => ['seven_days' => 0, 'thirty_days' => 0, 'all_time' => 0], 'daily' => []]);
  $holders = implode(',', array_fill(0, count($slugs), '?'));
  $summary = $pdo->prepare("SELECT SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS seven_days, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS thirty_days, COUNT(*) AS all_time FROM place_views WHERE place_slug IN ($holders) AND event_type = 'detail_view'"); $summary->execute($slugs); $totals = $summary->fetch() ?: [];
  $daily = $pdo->prepare("SELECT DATE(created_at) AS date, COUNT(*) AS views FROM place_views WHERE place_slug IN ($holders) AND event_type = 'detail_view' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at) ORDER BY date ASC"); $daily->execute($slugs);
  json_response(['businesses' => $businesses, 'summary' => ['seven_days' => (int) ($totals['seven_days'] ?? 0), 'thirty_days' => (int) ($totals['thirty_days'] ?? 0), 'all_time' => (int) ($totals['all_time'] ?? 0)], 'daily' => $daily->fetchAll()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['message' => 'ไม่พบคำขอ'], 404);
$data = $action === 'claim' ? $_POST : request_json();
if ($action === 'coupon-create') {
  if (!in_array($user['role'] ?? '', ['business_owner','admin'], true)) json_response(['message' => 'คุณไม่มีสิทธิ์เสนอคูปองร้านค้า'], 403);
  $slug = valid_slug($data['place_slug'] ?? null); $store = trim((string) ($data['store_name'] ?? '')); $title = trim((string) ($data['title'] ?? '')); $description = trim((string) ($data['description'] ?? '')); $cost = filter_var($data['point_cost'] ?? null, FILTER_VALIDATE_INT); $limit = filter_var($data['max_redemptions'] ?? null, FILTER_VALIDATE_INT); $rawEnds = trim((string) ($data['ends_at'] ?? '')); $endsAt = strtotime($rawEnds);
  if (!$slug || mb_strlen($store) < 2 || mb_strlen($title) < 2 || mb_strlen($description) < 4 || $cost === false || $cost < 1 || $cost > 100000 || ($limit !== false && $limit !== null && $limit < 1) || !$endsAt || $endsAt <= time()) json_response(['message' => 'กรุณากรอกข้อมูลคูปอง กำหนดวันหมดอายุในอนาคต และตรวจสอบจำนวนสิทธิ์'], 422);
  $claim = $pdo->prepare('SELECT id FROM business_claims WHERE place_slug = ? AND user_id = ? AND status = "approved"'); $claim->execute([$slug, $user['id']]); if (!$claim->fetch()) json_response(['message' => 'คุณยังไม่ได้รับสิทธิ์จัดการธุรกิจนี้'], 403);
  $pdo->prepare("INSERT INTO coupons (place_slug, store_name, title, description, point_cost, ends_at, max_redemptions, status, created_by, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_admin', ?, ?)")->execute([$slug, $store, $title, $description, $cost, date('Y-m-d H:i:s', $endsAt), $limit ?: null, $user['id'], $user['id']]); json_response(['message' => 'ส่งคำขอสร้างคูปองให้ผู้ดูแลตรวจสอบแล้ว'], 201);
}
$slug = valid_slug($data['place_slug'] ?? null); if (!$slug) json_response(['message' => 'ไม่พบสถานที่'], 422);
if ($action === 'claim') {
  $proof = trim((string) ($data['proof_note'] ?? '')); if (mb_strlen($proof) < 20 || mb_strlen($proof) > 1000) json_response(['message' => 'กรุณาอธิบายหลักฐานการอ้างสิทธิ์อย่างน้อย 20 ตัวอักษร'], 422);
  $files = $_FILES['documents'] ?? null; if (!$files || !is_array($files['name'] ?? null) || count($files['name']) < 1 || count($files['name']) > 3) json_response(['message' => 'กรุณาแนบหลักฐานอย่างน้อย 1 และไม่เกิน 3 ไฟล์'], 422);
  $exists = $pdo->prepare('SELECT id FROM business_claims WHERE user_id = ? AND place_slug = ? AND status IN ("pending","approved")'); $exists->execute([$user['id'], $slug]); if ($exists->fetch()) json_response(['message' => 'บัญชีนี้มีคำขอสำหรับสถานที่ดังกล่าวแล้ว'], 409);
  $pdo->beginTransaction(); try { $pdo->prepare('INSERT INTO business_claims (place_slug, user_id, proof_note, status) VALUES (?, ?, ?, "pending")')->execute([$slug, $user['id'], $proof]); $claimId = (int) $pdo->lastInsertId(); for ($index = 0; $index < count($files['name']); $index++) { $stored = store_claim_document(['name' => $files['name'][$index], 'tmp_name' => $files['tmp_name'][$index], 'error' => $files['error'][$index], 'size' => $files['size'][$index]], $claimId); $pdo->prepare('INSERT INTO claim_documents (claim_id, stored_name, original_name, mime_type, byte_size) VALUES (?, ?, ?, ?, ?)')->execute([$claimId, $stored['stored_name'], $stored['original_name'], $stored['mime_type'], $stored['byte_size']]); } $pdo->commit(); } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
  json_response(['message' => 'ส่งคำขอและหลักฐานเพื่อรอการตรวจสอบแล้ว'], 201);
}
if ($action === 'update') { $claim = $pdo->prepare('SELECT id FROM business_claims WHERE place_slug = ? AND user_id = ? AND status = "approved"'); $claim->execute([$slug, $user['id']]); if (!$claim->fetch()) json_response(['message' => 'คุณยังไม่ได้รับอนุมัติให้จัดการสถานที่นี้'], 403); $contact = trim((string) ($data['contact_note'] ?? '')); $hours = trim((string) ($data['hours_note'] ?? '')); $price = (string) ($data['price_tier'] ?? 'unknown'); if (!valid_status($price, ['unknown','budget','standard','premium']) || (mb_strlen($contact) < 2 && mb_strlen($hours) < 2)) json_response(['message' => 'กรุณาระบุข้อมูลติดต่อหรือเวลาให้บริการที่ต้องการแก้ไข'], 422); $pdo->prepare('INSERT INTO business_updates (place_slug, user_id, contact_note, hours_note, price_tier, status) VALUES (?, ?, ?, ?, ?, "pending")')->execute([$slug, $user['id'], $contact, $hours, $price]); json_response(['message' => 'ส่งคำขอแก้ไขข้อมูลเพื่อรอการอนุมัติแล้ว'], 201); }
json_response(['message' => 'คำขอไม่ถูกต้อง'], 422);

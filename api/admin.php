<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$admin = require_admin(); $pdo = database(); $action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'overview') {
  $reviews = $pdo->query('SELECT r.id, r.place_slug, r.rating, r.comment, r.created_at, u.display_name FROM reviews r JOIN users u ON u.id = r.user_id WHERE r.status = "pending" ORDER BY r.created_at ASC LIMIT 100')->fetchAll();
  $claims = $pdo->query('SELECT c.id, c.place_slug, c.proof_note, c.created_at, u.display_name, u.email FROM business_claims c JOIN users u ON u.id = c.user_id WHERE c.status = "pending" ORDER BY c.created_at ASC LIMIT 100')->fetchAll();
  $claimIds = array_column($claims, 'id'); $documentsByClaim = []; if ($claimIds) { $holders = implode(',', array_fill(0, count($claimIds), '?')); $documents = $pdo->prepare("SELECT id, claim_id, original_name, mime_type, byte_size, created_at FROM claim_documents WHERE claim_id IN ($holders) ORDER BY id ASC"); $documents->execute($claimIds); foreach ($documents->fetchAll() as $document) $documentsByClaim[$document['claim_id']][] = $document; } foreach ($claims as &$claim) $claim['documents'] = $documentsByClaim[$claim['id']] ?? []; unset($claim);
  $updates = $pdo->query('SELECT bu.id, bu.place_slug, bu.contact_note, bu.hours_note, bu.price_tier, bu.created_at, u.display_name FROM business_updates bu JOIN users u ON u.id = bu.user_id WHERE bu.status = "pending" ORDER BY bu.created_at ASC LIMIT 100')->fetchAll();
  $submissions = $pdo->query('SELECT ps.id, ps.name, ps.category, ps.district, ps.subdistrict, ps.address_note, ps.contact_note, ps.hours_note, ps.description, ps.price_tier, ps.created_at, u.display_name, u.email FROM place_submissions ps JOIN users u ON u.id = ps.user_id WHERE ps.status = "pending" ORDER BY ps.created_at ASC LIMIT 100')->fetchAll();
  json_response(['reviews' => $reviews, 'claims' => $claims, 'updates' => $updates, 'submissions' => $submissions, 'admin' => $admin]);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'document') {
  $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT); if (!$id) json_response(['message' => 'ไม่พบไฟล์หลักฐาน'], 422);
  $document = $pdo->prepare('SELECT stored_name, original_name, mime_type FROM claim_documents WHERE id = ?'); $document->execute([$id]); $row = $document->fetch(); if (!$row) json_response(['message' => 'ไม่พบไฟล์หลักฐาน'], 404);
  $path = private_claim_storage() . '/' . $row['stored_name']; if (!is_file($path)) json_response(['message' => 'ไม่พบไฟล์ในพื้นที่จัดเก็บ'], 404);
  header('Content-Type: ' . $row['mime_type']); header('Content-Length: ' . (string) filesize($path)); header('Content-Disposition: attachment; filename="' . rawurlencode($row['original_name']) . '"'); readfile($path); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['message' => 'ไม่พบคำขอ'], 404);
$data = request_json(); $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT); $status = (string) ($data['status'] ?? ''); if (!$id) json_response(['message' => 'ไม่พบรายการ'], 422);
if ($action === 'review' && valid_status($status, ['approved','hidden'])) { define('LOYALTY_LIBRARY_ONLY', true); require_once __DIR__ . '/loyalty.php'; $pdo->beginTransaction(); try { $statement = $pdo->prepare('UPDATE reviews SET status = ?, moderated_by = ?, moderated_at = NOW() WHERE id = ?'); $statement->execute([$status, $admin['id'], $id]); if (!$statement->rowCount()) { $pdo->rollBack(); json_response(['message' => 'ไม่พบรีวิว'], 404); } sync_review_loyalty($pdo, $id, $status); $pdo->commit(); } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; } json_response(['message' => $status === 'approved' ? 'อนุมัติรีวิวและเพิ่ม 10 แต้มให้สมาชิกแล้ว' : 'ซ่อนรีวิวและอัปเดตสถานะแต้มแล้ว']); }
if ($action === 'claim' && valid_status($status, ['approved','rejected'])) { $claim = $pdo->prepare('SELECT user_id FROM business_claims WHERE id = ?'); $claim->execute([$id]); $row = $claim->fetch(); if (!$row) json_response(['message' => 'ไม่พบคำขออ้างสิทธิ์'], 404); $pdo->beginTransaction(); $pdo->prepare('UPDATE business_claims SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')->execute([$status, $admin['id'], $id]); if ($status === 'approved') $pdo->prepare('UPDATE users SET role = "business_owner" WHERE id = ? AND role = "member"')->execute([$row['user_id']]); $pdo->commit(); json_response(['message' => 'อัปเดตคำขออ้างสิทธิ์แล้ว']); }
if ($action === 'update' && valid_status($status, ['approved','rejected'])) { $pdo->prepare('UPDATE business_updates SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')->execute([$status, $admin['id'], $id]); json_response(['message' => 'อัปเดตคำขอแก้ไขแล้ว']); }
if ($action === 'submission' && valid_status($status, ['approved','rejected'])) {
  $statement = $pdo->prepare('SELECT id, name, category, district, subdistrict, price_tier FROM place_submissions WHERE id = ? AND status = "pending"'); $statement->execute([$id]); $submission = $statement->fetch(); if (!$submission) json_response(['message' => 'ไม่พบคำขอเพิ่มสถานที่ที่รอพิจารณา'], 404);
  $pdo->beginTransaction();
  try {
    if ($status === 'approved') {
      $slug = 'community-place-' . $id;
      $pdo->prepare('INSERT INTO places (slug, name, category, district, subdistrict, price_tier) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), district = VALUES(district), subdistrict = VALUES(subdistrict), price_tier = VALUES(price_tier)')->execute([$slug, $submission['name'], $submission['category'], $submission['district'], $submission['subdistrict'], $submission['price_tier']]);
      $pdo->prepare('UPDATE place_submissions SET status = "approved", approved_place_slug = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')->execute([$slug, $admin['id'], $id]);
    } else $pdo->prepare('UPDATE place_submissions SET status = "rejected", reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')->execute([$admin['id'], $id]);
    $pdo->commit();
  } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
  json_response(['message' => $status === 'approved' ? 'อนุมัติและเผยแพร่สถานที่ในไดเรกทอรีแล้ว' : 'ปฏิเสธคำขอเพิ่มสถานที่แล้ว']);
}
json_response(['message' => 'คำขอไม่ถูกต้อง'], 422);

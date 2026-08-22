<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$action = $_GET['action'] ?? '';
$categories = ['ร้านอาหาร','สถานที่ท่องเที่ยว','สถานที่ราชการ','คาเฟ่','ร้านค้า','ที่พัก','ธุรกิจบริการ'];
$districts = ['หัวหิน','ปราณบุรี','สามร้อยยอด','กุยบุรี','เมืองประจวบคีรีขันธ์','ทับสะแก','บางสะพาน','บางสะพานน้อย'];
$priceTiers = ['unknown','budget','standard','premium'];

function contribution_tier(int $approved): array {
  if ($approved >= 10) return ['name' => 'ผู้พิทักษ์แอตลาส', 'next_at' => null, 'description' => 'ร่วมพัฒนาข้อมูลพื้นที่อย่างต่อเนื่อง'];
  if ($approved >= 3) return ['name' => 'นักทำแผนที่ชายฝั่ง', 'next_at' => 10, 'description' => 'มีข้อมูลสถานที่ที่ผ่านอนุมัติแล้ว 3 รายการ'];
  if ($approved >= 1) return ['name' => 'ผู้ร่วมข้อมูลท้องถิ่น', 'next_at' => 3, 'description' => 'เริ่มช่วยเติมข้อมูลพื้นที่ที่ค้นหาได้จริง'];
  return ['name' => 'ผู้ร่วมข้อมูลใหม่', 'next_at' => 1, 'description' => 'เริ่มด้วยรายการแรกเพื่อให้ผู้ดูแลตรวจสอบ'];
}

function submission_visual(string $category): string {
  return match ($category) { 'ร้านอาหาร' => 'food', 'ที่พัก' => 'stay', 'คาเฟ่' => 'cafe', 'สถานที่ราชการ' => 'civic', 'ร้านค้า' => 'shop', 'ธุรกิจบริการ' => 'service', default => 'travel' };
}

function to_directory_listing(array $row): array {
  $hours = trim((string) $row['hours_note']);
  return ['id' => 1000000 + (int) $row['id'], 'slug' => $row['approved_place_slug'], 'name' => $row['name'], 'category' => $row['category'], 'district' => $row['district'], 'subdistrict' => $row['subdistrict'], 'description' => $row['description'], 'overview' => $row['description'], 'visual' => submission_visual($row['category']), 'addressNote' => $row['address_note'], 'contactNote' => $row['contact_note'], 'hoursNote' => $hours !== '' ? $hours : 'ยังไม่ระบุเวลาให้บริการ', 'serviceStatus' => 'verify', 'tags' => [$row['category'], $row['subdistrict'], $row['district'], 'ข้อมูลจากสมาชิก'], 'priceTier' => $row['price_tier'], 'mapQuery' => trim($row['name'] . ' ' . $row['address_note'] . ' ' . $row['district'] . ' ประจวบคีรีขันธ์')];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'approved') {
  $pdo = database();
  $statement = $pdo->query('SELECT id, approved_place_slug, name, category, district, subdistrict, address_note, contact_note, hours_note, description, price_tier FROM place_submissions WHERE status = "approved" AND approved_place_slug IS NOT NULL ORDER BY reviewed_at DESC, id DESC LIMIT 500');
  json_response(['listings' => array_map('to_directory_listing', $statement->fetchAll())]);
}

$user = require_user();
$pdo = database();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'profile') {
  $statement = $pdo->prepare('SELECT id, name, category, district, subdistrict, address_note, contact_note, hours_note, description, price_tier, status, created_at, reviewed_at, approved_place_slug FROM place_submissions WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
  $statement->execute([$user['id']]);
  $submissions = $statement->fetchAll();
  $approved = 0; $active = 0;
  foreach ($submissions as $submission) { if ($submission['status'] === 'approved') $approved++; if ($submission['status'] === 'pending') $active++; }
  json_response(['can_submit' => $active === 0, 'active_submissions' => $active, 'approved_count' => $approved, 'tier' => contribution_tier($approved), 'submissions' => $submissions]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action !== 'create') json_response(['message' => 'ไม่พบคำขอ'], 404);
$data = request_json();
$name = trim((string) ($data['name'] ?? '')); $category = trim((string) ($data['category'] ?? '')); $district = trim((string) ($data['district'] ?? '')); $subdistrict = trim((string) ($data['subdistrict'] ?? '')); $address = trim((string) ($data['address_note'] ?? '')); $contact = trim((string) ($data['contact_note'] ?? '')); $hours = trim((string) ($data['hours_note'] ?? '')); $description = trim((string) ($data['description'] ?? '')); $price = (string) ($data['price_tier'] ?? 'unknown');
if (mb_strlen($name) < 2 || mb_strlen($name) > 180 || !in_array($category, $categories, true) || !in_array($district, $districts, true) || mb_strlen($subdistrict) < 2 || mb_strlen($subdistrict) > 100 || mb_strlen($address) < 5 || mb_strlen($address) > 300 || mb_strlen($contact) < 2 || mb_strlen($contact) > 300 || mb_strlen($hours) > 300 || mb_strlen($description) < 20 || mb_strlen($description) > 1500 || !in_array($price, $priceTiers, true)) json_response(['message' => 'กรุณากรอกชื่อ หมวดหมู่ พื้นที่ ช่องทางติดต่อ และคำอธิบายให้ครบถ้วน'], 422);
$active = $pdo->prepare('SELECT id FROM place_submissions WHERE user_id = ? AND status = "pending" LIMIT 1'); $active->execute([$user['id']]);
if ($active->fetch()) json_response(['message' => 'คุณมีรายการที่รอผู้ดูแลตรวจสอบอยู่แล้ว กรุณารอผลก่อนส่งรายการถัดไป'], 409);
$pdo->prepare('INSERT INTO place_submissions (user_id, name, category, district, subdistrict, address_note, contact_note, hours_note, description, price_tier, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")')->execute([$user['id'], $name, $category, $district, $subdistrict, $address, $contact, $hours, $description, $price]);
json_response(['message' => 'ส่งข้อมูลสถานที่ให้ผู้ดูแลตรวจสอบแล้ว เมื่อมีผลคุณจะส่งรายการถัดไปได้'], 201);

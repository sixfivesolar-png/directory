<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $slug = valid_slug($_GET['place_slug'] ?? null); if (!$slug) json_response(['message' => 'ไม่พบรหัสสถานที่'], 400);
  $pdo = database(); $summary = $pdo->prepare('SELECT ROUND(AVG(rating), 1) AS average_rating, COUNT(*) AS review_count FROM reviews WHERE place_slug = ? AND status = "approved"'); $summary->execute([$slug]); $summaryRow = $summary->fetch() ?: ['average_rating' => null, 'review_count' => 0];
  $reviews = $pdo->prepare('SELECT r.id, u.display_name AS reviewer_name, r.rating, r.comment, r.created_at, COALESCE(la.lifetime_points, 0) AS lifetime_points, CASE WHEN COALESCE(la.lifetime_points, 0) >= 300 THEN "Atlas VIP" WHEN COALESCE(la.lifetime_points, 0) >= 150 THEN "Coastal VIP" WHEN COALESCE(la.lifetime_points, 0) >= 50 THEN "Gulf VIP" ELSE "Explorer" END AS vip_tier FROM reviews r JOIN users u ON u.id = r.user_id LEFT JOIN loyalty_accounts la ON la.user_id = u.id WHERE r.place_slug = ? AND r.status = "approved" ORDER BY r.created_at DESC, r.id DESC LIMIT 100'); $reviews->execute([$slug]);
  json_response(['summary' => ['average_rating' => $summaryRow['average_rating'] === null ? null : (float) $summaryRow['average_rating'], 'review_count' => (int) $summaryRow['review_count']], 'reviews' => $reviews->fetchAll()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['message' => 'วิธีเรียกใช้งานไม่ถูกต้อง'], 405);
$user = require_user(); start_secure_session(); $data = request_json();
if (!empty($data['website'] ?? '')) json_response(['message' => 'ไม่สามารถส่งความคิดเห็นได้'], 400);
$captcha = $_SESSION['review_captcha'] ?? null; $captchaToken = (string) ($data['captcha_token'] ?? ''); $captchaAnswer = filter_var($data['captcha_answer'] ?? null, FILTER_VALIDATE_INT);
if (!$captcha || time() > $captcha['expires'] || !hash_equals($captcha['token'], $captchaToken) || $captchaAnswer !== $captcha['answer']) json_response(['message' => 'คำตอบ CAPTCHA ไม่ถูกต้องหรือหมดอายุ'], 422);
unset($_SESSION['review_captcha']);
$slug = valid_slug($data['place_slug'] ?? null); $rating = filter_var($data['rating'] ?? null, FILTER_VALIDATE_INT); $comment = trim((string) ($data['comment'] ?? ''));
if (!$slug || $rating === false || $rating < 1 || $rating > 5 || mb_strlen($comment) < 10 || mb_strlen($comment) > 1000) json_response(['message' => 'กรุณาให้คะแนน 1–5 ดาวและเขียนความคิดเห็นอย่างน้อย 10 ตัวอักษร'], 422);
$rateKey = 'review_submissions_' . $slug; $previous = $_SESSION[$rateKey] ?? 0; if ($previous && time() - $previous < 60) json_response(['message' => 'โปรดรออย่างน้อย 1 นาทีก่อนส่งความคิดเห็นอีกครั้ง'], 429);
$insert = database()->prepare('INSERT INTO reviews (place_slug, user_id, rating, comment, status) VALUES (?, ?, ?, ?, "pending")'); $insert->execute([$slug, $user['id'], $rating, $comment]); $_SESSION[$rateKey] = time();
json_response(['message' => 'ส่งความคิดเห็นเพื่อรอการอนุมัติเรียบร้อย'], 201);

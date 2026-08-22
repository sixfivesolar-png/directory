<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function loyalty_tier(int $points): array {
  if ($points >= 300) return ['name' => 'Atlas VIP', 'minimum' => 300, 'next' => null, 'next_name' => null];
  if ($points >= 150) return ['name' => 'Coastal VIP', 'minimum' => 150, 'next' => 300, 'next_name' => 'Atlas VIP'];
  if ($points >= 50) return ['name' => 'Gulf VIP', 'minimum' => 50, 'next' => 150, 'next_name' => 'Coastal VIP'];
  return ['name' => 'Explorer', 'minimum' => 0, 'next' => 50, 'next_name' => 'Gulf VIP'];
}

function refresh_loyalty_account(PDO $pdo, int $userId): void {
  $statement = $pdo->prepare('SELECT COALESCE(SUM(points), 0) AS balance, COALESCE(SUM(CASE WHEN event_type = "review_approved" AND points > 0 THEN points ELSE 0 END), 0) AS lifetime FROM loyalty_events WHERE user_id = ?');
  $statement->execute([$userId]); $totals = $statement->fetch() ?: ['balance' => 0, 'lifetime' => 0];
  $pdo->prepare('INSERT INTO loyalty_accounts (user_id, points_balance, lifetime_points) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE points_balance = VALUES(points_balance), lifetime_points = VALUES(lifetime_points), updated_at = NOW()')->execute([$userId, (int) $totals['balance'], (int) $totals['lifetime']]);
}

function sync_review_loyalty(PDO $pdo, int $reviewId, string $reviewStatus): void {
  $review = $pdo->prepare('SELECT user_id FROM reviews WHERE id = ?'); $review->execute([$reviewId]); $row = $review->fetch(); if (!$row) return;
  $points = $reviewStatus === 'approved' ? 10 : 0;
  $event = $reviewStatus === 'approved' ? 'review_approved' : 'review_not_approved';
  $pdo->prepare('INSERT INTO loyalty_events (user_id, review_id, event_type, points) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE event_type = VALUES(event_type), points = VALUES(points), updated_at = NOW()')->execute([(int) $row['user_id'], $reviewId, $event, $points]);
  refresh_loyalty_account($pdo, (int) $row['user_id']);
}

if (defined('LOYALTY_LIBRARY_ONLY')) return;

if ($_SERVER['REQUEST_METHOD'] !== 'GET' || ($_GET['action'] ?? '') !== 'profile') json_response(['message' => 'ไม่พบคำขอ'], 404);
$user = require_user(); $pdo = database();
$account = $pdo->prepare('SELECT points_balance, lifetime_points FROM loyalty_accounts WHERE user_id = ?'); $account->execute([$user['id']]); $row = $account->fetch() ?: ['points_balance' => 0, 'lifetime_points' => 0];
$history = $pdo->prepare('SELECT points, event_type, created_at, review_id FROM loyalty_events WHERE user_id = ? AND points > 0 ORDER BY created_at DESC, id DESC LIMIT 50'); $history->execute([$user['id']]);
$points = (int) $row['points_balance']; $tierPoints = (int) $row['lifetime_points'];
json_response(['account' => ['points' => $points, 'lifetime_points' => $tierPoints, 'tier_points' => $tierPoints, 'tier' => loyalty_tier($tierPoints), 'points_per_approved_review' => 10], 'history' => $history->fetchAll(), 'user' => $user]);

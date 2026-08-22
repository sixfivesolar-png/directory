<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$metric = $_GET['metric'] ?? 'points'; $pdo = database();
if ($metric === 'reviews') {
  $statement = $pdo->query('SELECT u.display_name, COUNT(r.id) AS score, la.lifetime_points FROM users u JOIN reviews r ON r.user_id = u.id AND r.status = "approved" LEFT JOIN loyalty_accounts la ON la.user_id = u.id GROUP BY u.id, u.display_name, la.lifetime_points HAVING score > 0 ORDER BY score DESC, la.lifetime_points DESC, u.id ASC LIMIT 50');
} else {
  $statement = $pdo->query('SELECT u.display_name, la.lifetime_points AS score, COUNT(r.id) AS approved_reviews FROM loyalty_accounts la JOIN users u ON u.id = la.user_id LEFT JOIN reviews r ON r.user_id = u.id AND r.status = "approved" WHERE la.lifetime_points > 0 GROUP BY u.id, u.display_name, la.lifetime_points HAVING score > 0 ORDER BY score DESC, approved_reviews DESC, u.id ASC LIMIT 50');
}
$rows = $statement->fetchAll(); foreach ($rows as $index => &$row) { $points = $metric === 'points' ? (int) $row['score'] : (int) ($row['lifetime_points'] ?? 0); $row['rank'] = $index + 1; $row['tier'] = $points >= 300 ? 'Atlas VIP' : ($points >= 150 ? 'Coastal VIP' : ($points >= 50 ? 'Gulf VIP' : 'Explorer')); } unset($row);
json_response(['metric' => $metric, 'leaders' => $rows]);

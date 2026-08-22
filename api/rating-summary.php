<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$rawSlugs = isset($_GET['slugs']) ? explode(',', (string) $_GET['slugs']) : []; $slugs = array_values(array_filter(array_map('valid_slug', $rawSlugs))); if (!$slugs) json_response(['summaries' => []]);
$placeholders = implode(',', array_fill(0, count($slugs), '?')); $statement = database()->prepare("SELECT place_slug, ROUND(AVG(rating), 1) AS average_rating, COUNT(*) AS review_count FROM reviews WHERE status = 'approved' AND place_slug IN ($placeholders) GROUP BY place_slug"); $statement->execute($slugs);
$summaries = []; foreach ($statement->fetchAll() as $row) $summaries[$row['place_slug']] = ['average_rating' => (float) $row['average_rating'], 'review_count' => (int) $row['review_count']]; json_response(['summaries' => $summaries]);

<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$parentId = (int)($_GET['parent_id'] ?? 0);
if ($parentId) {
    $subs = getSubcategories($parentId);
    echo json_encode($subs);
} else {
    echo json_encode(getAllCategoriesHierarchy());
}

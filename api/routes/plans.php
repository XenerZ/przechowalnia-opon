<?php
// Publiczny endpoint — zwraca aktywne plany (używany na stronie rejestracji)
function handle_plans($method, $id, $body) {
    if ($method !== 'GET') { method_not_allowed(); return; }

    $pdo  = get_pdo();
    $stmt = $pdo->query('SELECT id, name, max_tires AS maxTires, has_customers AS hasCustomers, has_actions AS hasActions, price_monthly AS priceMonthly, sort_order AS sortOrder FROM plans WHERE is_active = 1 ORDER BY sort_order');
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['maxTires']     = $r['maxTires'] !== null ? (int)$r['maxTires'] : null;
        $r['hasCustomers'] = (bool)$r['hasCustomers'];
        $r['hasActions']   = (bool)$r['hasActions'];
        $r['priceMonthly'] = (float)$r['priceMonthly'];
        $r['sortOrder']    = (int)$r['sortOrder'];
    }
    echo json_encode($rows);
}

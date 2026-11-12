<?php
session_start();
require '../connection/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit();
}

// "Successful adoption" dito ay parehong definition na ginagamit sa index.php stat card:
// application_status = 'Approved' AT ang pet mismo ay 'Adopted' pa rin ngayon.
function countAdoptions(PDO $pdo, string $dateColumn, string $whereSql, array $params): int {
    $sql = "SELECT COUNT(*) FROM adoption_applications aa
            JOIN pet_inventory p ON p.pet_id = aa.pet_id
            WHERE aa.application_status = 'Approved' AND p.status = 'Adopted' AND $whereSql";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

// "Trend" ay ang bilang ng mga adoption application na isinumite sa parehong yugto ng panahon.
function countApplications(PDO $pdo, string $whereSql, array $params): int {
    $sql = "SELECT COUNT(*) FROM adoption_applications aa WHERE $whereSql";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

// Sino ang nag-adopt sa yugtong iyon - ipinapakita sa tooltip kapag nag-hover
// sa chart. Isang linya bawat adoption: "Adopter - Pet (petsa)".
function listAdopters(PDO $pdo, string $whereSql, array $params): array {
    $sql = "SELECT aa.full_name, p.name AS pet_name, aa.updated_at
            FROM adoption_applications aa
            JOIN pet_inventory p ON p.pet_id = aa.pet_id
            WHERE aa.application_status = 'Approved' AND p.status = 'Adopted' AND $whereSql
            ORDER BY aa.updated_at ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lines[] = $row['full_name'] . ' - ' . $row['pet_name'] . ' (' . date('M j', strtotime($row['updated_at'])) . ')';
    }
    return $lines;
}

$monthMap = [
    'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
    'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
];

$view = $_GET['view'] ?? 'yearly';
$year = isset($_GET['year']) ? (int) $_GET['year'] : null;
$month = $_GET['month'] ?? null;
$week = $_GET['week'] ?? null;

try {
    if ($view === 'yearly') {
        $minYear = (int) ($pdo->query("SELECT MIN(YEAR(created_at)) FROM adoption_applications")->fetchColumn() ?: date('Y'));
        $maxYear = (int) ($pdo->query("SELECT MAX(YEAR(created_at)) FROM adoption_applications")->fetchColumn() ?: date('Y'));
        $maxYear = max($maxYear, (int) date('Y'));
        // Laging hindi bababa sa 5 taon para may linya ang line chart kahit
        // iisang taon pa lang ang may data
        $minYear = min($minYear, $maxYear - 4);

        $labels = [];
        $trend = [];
        $adoptions = [];
        $details = [];
        for ($y = $minYear; $y <= $maxYear; $y++) {
            $labels[] = (string) $y;
            $trend[] = countApplications($pdo, "YEAR(aa.created_at) = ?", [$y]);
            $adoptions[] = countAdoptions($pdo, 'aa.updated_at', "YEAR(aa.updated_at) = ?", [$y]);
            $details[] = listAdopters($pdo, "YEAR(aa.updated_at) = ?", [$y]);
        }

        echo json_encode(['labels' => $labels, 'trend' => $trend, 'adoptions' => $adoptions, 'details' => $details, 'total' => array_sum($adoptions)]);
        exit();
    }

    if ($view === 'monthly' && $year) {
        $labels = array_keys($monthMap);
        $trend = [];
        $adoptions = [];
        $details = [];
        foreach ($monthMap as $m) {
            $trend[] = countApplications($pdo, "YEAR(aa.created_at) = ? AND MONTH(aa.created_at) = ?", [$year, $m]);
            $adoptions[] = countAdoptions($pdo, 'aa.updated_at', "YEAR(aa.updated_at) = ? AND MONTH(aa.updated_at) = ?", [$year, $m]);
            $details[] = listAdopters($pdo, "YEAR(aa.updated_at) = ? AND MONTH(aa.updated_at) = ?", [$year, $m]);
        }

        echo json_encode(['labels' => $labels, 'trend' => $trend, 'adoptions' => $adoptions, 'details' => $details, 'total' => array_sum($adoptions)]);
        exit();
    }

    if ($view === 'weekly' && $year && $month && isset($monthMap[$month])) {
        $m = $monthMap[$month];
        $daysInMonth = (int) date('t', strtotime("$year-$m-01"));
        $weekRanges = [[1, 7], [8, 14], [15, 21], [22, $daysInMonth]];

        $labels = [];
        $trend = [];
        $adoptions = [];
        $details = [];
        foreach ($weekRanges as $i => $range) {
            [$start, $end] = $range;
            $labels[] = 'Week ' . ($i + 1);
            $trend[] = countApplications($pdo, "YEAR(aa.created_at) = ? AND MONTH(aa.created_at) = ? AND DAY(aa.created_at) BETWEEN ? AND ?", [$year, $m, $start, $end]);
            $adoptions[] = countAdoptions($pdo, 'aa.updated_at', "YEAR(aa.updated_at) = ? AND MONTH(aa.updated_at) = ? AND DAY(aa.updated_at) BETWEEN ? AND ?", [$year, $m, $start, $end]);
            $details[] = listAdopters($pdo, "YEAR(aa.updated_at) = ? AND MONTH(aa.updated_at) = ? AND DAY(aa.updated_at) BETWEEN ? AND ?", [$year, $m, $start, $end]);
        }

        echo json_encode(['labels' => $labels, 'trend' => $trend, 'adoptions' => $adoptions, 'details' => $details, 'total' => array_sum($adoptions)]);
        exit();
    }

    if ($view === 'daily' && $year && $month && $week && isset($monthMap[$month])) {
        $m = $monthMap[$month];
        $daysInMonth = (int) date('t', strtotime("$year-$m-01"));
        $weekRanges = [[1, 7], [8, 14], [15, 21], [22, $daysInMonth]];
        $weekIndex = (int) filter_var($week, FILTER_SANITIZE_NUMBER_INT);
        [$start, $end] = $weekRanges[$weekIndex - 1] ?? [1, $daysInMonth];

        $dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $trendByDay = array_fill_keys($dayLabels, 0);
        $adoptionsByDay = array_fill_keys($dayLabels, 0);
        $detailsByDay = array_fill_keys($dayLabels, []);

        for ($d = $start; $d <= $end; $d++) {
            $date = sprintf('%04d-%02d-%02d', $year, $m, $d);
            $dowLabel = $dayLabels[(int) date('N', strtotime($date)) - 1];
            $trendByDay[$dowLabel] += countApplications($pdo, "DATE(aa.created_at) = ?", [$date]);
            $adoptionsByDay[$dowLabel] += countAdoptions($pdo, 'aa.updated_at', "DATE(aa.updated_at) = ?", [$date]);
            $detailsByDay[$dowLabel] = array_merge($detailsByDay[$dowLabel], listAdopters($pdo, "DATE(aa.updated_at) = ?", [$date]));
        }

        $trend = array_values($trendByDay);
        $adoptions = array_values($adoptionsByDay);
        $details = array_values($detailsByDay);

        echo json_encode(['labels' => $dayLabels, 'trend' => $trend, 'adoptions' => $adoptions, 'details' => $details, 'total' => array_sum($adoptions)]);
        exit();
    }

    echo json_encode(['labels' => [], 'trend' => [], 'adoptions' => [], 'details' => [], 'total' => 0]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'query_failed']);
}

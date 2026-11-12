<?php
// Pet Monitoring - iisang pinagkukunan ng status ng bawat adoption para sa
// surprise visit, para pareho ang sinasabi ng Pet Monitoring page at ng
// Reminders bell.
//
// Patakaran: isang surprise visit kada buwan, simula sa buwan PAGKATAPOS ng
// adoption (ang petsa ng bayad). Tapos na ang monitoring kapag ang huling
// visit ay binawi ng opisina o ibinalik ng adopter ang hayop.
//
// Mga state:
//   upcoming - kaka-adopt lang ngayong buwan; sa susunod na buwan ang unang visit
//   due      - wala pang visit ngayong buwan
//   visited  - may visit na ngayong buwan
//   ended    - tapos na ang monitoring (Reclaimed / Returned)

if (!function_exists('sp_monitoring_list')) {
    function sp_monitoring_list(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                "SELECT aa.application_id, aa.full_name, aa.address, aa.phone_number,
                        p.pet_id, p.name AS pet_name, p.kind, p.breed, p.photo_url,
                        p.age_group, p.size, p.energy_level, p.temperament, p.shedding_level,
                        p.health_condition, p.vaccination_status, p.description,
                        pay.payment_date,
                        (SELECT COUNT(*) FROM pet_visits v WHERE v.application_id = aa.application_id) AS total_visits,
                        (SELECT COUNT(*) FROM pet_visits v WHERE v.application_id = aa.application_id
                            AND DATE_FORMAT(v.visit_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')) AS visits_this_month,
                        (SELECT COUNT(*) FROM pet_visits v WHERE v.application_id = aa.application_id
                            AND DATE_FORMAT(v.visit_date, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')) AS visits_last_month,
                        lv.visit_date AS last_visit_date, lv.pet_condition AS last_pet_condition,
                        lv.living_condition AS last_living_condition, lv.result AS last_result
                 FROM adoption_applications aa
                 JOIN pet_inventory p ON p.pet_id = aa.pet_id
                 JOIN payments pay ON pay.application_id = aa.application_id AND pay.status = 'Paid'
                 LEFT JOIN pet_visits lv ON lv.visit_id = (
                     SELECT v2.visit_id FROM pet_visits v2
                     WHERE v2.application_id = aa.application_id
                     ORDER BY v2.visit_date DESC, v2.visit_id DESC LIMIT 1
                 )
                 WHERE aa.application_status = 'Approved'
                 ORDER BY pay.payment_date ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }

        $curMonth = date('Y-m');
        $prevMonth = date('Y-m', strtotime('first day of last month'));

        foreach ($rows as &$r) {
            $startMonth = date('Y-m', strtotime($r['payment_date']));
            $r['first_visit_month'] = date('Y-m', strtotime('first day of next month', strtotime($r['payment_date'])));
            $r['missed_last_month'] = false;

            if (!empty($r['last_result']) && $r['last_result'] !== 'Stayed with Adopter') {
                $r['state'] = 'ended';
            } elseif ($curMonth <= $startMonth) {
                $r['state'] = 'upcoming';
            } elseif ((int) $r['visits_this_month'] > 0) {
                $r['state'] = 'visited';
            } else {
                $r['state'] = 'due';
                // May kailangang visit noong nakaraang buwan pero wala
                $r['missed_last_month'] = $prevMonth > $startMonth && (int) $r['visits_last_month'] === 0;
            }
        }
        unset($r);

        return $rows;
    }
}

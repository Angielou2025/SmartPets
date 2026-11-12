<?php
/**
 * Sariling page ng Admin para sa pagtingin ng isang adoption application.
 *
 * Dati ay dinadala ang admin sa /Caps2/StaffFile/review_application.php, kaya
 * mukhang nasa loob siya ng Staff section kahit Admin sidebar na ang nakikita.
 * May sarili na siyang URL dito sa ilalim ng AdminFile.
 *
 * Iisa pa rin ang laman ng page - nakasalalay pa rin ito sa implementasyon sa
 * StaffFile para hindi mahati sa dalawang kopya ang mahabang form na ito. Ang
 * 'from=admin' ang nagsasabi roon na Admin ang nanonood: Admin sidebar ang
 * ilalabas, at sa Requests page ng Admin siya babalik.
 */
$_GET['from'] = 'admin';

require __DIR__ . '/../StaffFile/review_application.php';

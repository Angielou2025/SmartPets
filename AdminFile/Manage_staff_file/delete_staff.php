<?php
/**
 * Dating dito direktang ibinabalik sa Adopter ang isang staff account.
 *
 * Hindi na. Dumadaan na ngayon sa pahintulot ng may-ari ng account: hiling muna
 * ang ipinapadala, at sa pop-up sa panig niya siya sumasagot ng Agree o
 * Decline. Katulad pa rin ng dati, hindi binubura ang account - role lang ang
 * bumabalik sa Adopter, kaya buo pa rin ang mga naka-link na records niya.
 */
$_POST['action'] = 'request';
$_POST['to_role'] = 'Adopter';
$_POST['return'] = '/Caps2/AdminFile/Manage_staff_file/manage_staff.php';

require __DIR__ . '/../../connection/request_role_change.php';

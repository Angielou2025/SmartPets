<?php
/**
 * Dating dito direktang ginagawang Staff ang isang adopter.
 *
 * Hindi na. Dumadaan na ngayon sa pahintulot ng may-ari ng account: hiling muna
 * ang ipinapadala, at sa pop-up sa panig niya siya sumasagot ng Agree o
 * Decline. Nananatili ang file na ito bilang daanan papunta sa bagong handler
 * para gumana pa rin ang anumang lumang link o form na nakaturo rito.
 */
$_POST['action'] = 'request';
$_POST['to_role'] = 'Staff';
$_POST['return'] = '/Caps2/AdminFile/Manage_staff_file/manage_staff.php';

require __DIR__ . '/../../connection/request_role_change.php';

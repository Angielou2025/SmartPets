<?php
/**
 * PARA SA INFINITYFREE (o anumang hosting) - HUWAG ITONG GAMITIN SA LOCALHOST.
 *
 * I-upload ang file na ito sa mismong htdocs/ ng hosting (katabi ng Caps2
 * folder, HINDI sa loob nito). Kapag binuksan ng tao ang yourdomain.com,
 * dadalhin sila nito sa SMART PET.
 *
 *   htdocs/
 *     index.php      <- ito
 *     Caps2/         <- ang buong project
 */
header('Location: /Caps2/');
exit();

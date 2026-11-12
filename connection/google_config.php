<?php
/**
 * Google Sign-In ("login with google" sa login page).
 *
 * Paano i-setup:
 *  1. Pumunta sa https://console.cloud.google.com/apis/credentials
 *  2. Create Credentials -> OAuth client ID -> Web application
 *  3. Sa "Authorized JavaScript origins", ilagay ang:
 *       http://localhost
 *       https://yourdomain.com   (ang InfinityFree domain mo)
 *  4. Kopyahin ang Client ID at ilagay sa ibaba.
 *
 * Habang walang Client ID, may lalabas na mensahe kapag pinindot ang button.
 */
const GOOGLE_CLIENT_ID = '1088648677687-erll20tqdu6df39h0gfn8q6shno677ml.apps.googleusercontent.com';

function google_login_enabled(): bool
{
    return GOOGLE_CLIENT_ID !== '';
}

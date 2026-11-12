<?php
/**
 * Maikling animated na kuwento pagkatapos mag-login ang adopter, hango sa
 * reference video: isang kalye sa Caloocan sa dapit-hapon. May nakaparadang
 * truck ng Caloocan Animal Control, dalawang taong nakaupo sa bench, at isang
 * asong gala na umaamoy sa basura. Lumabas ang dalawang rescuer mula sa truck,
 * lumapit sa aso, maingat itong sinalo gamit ang catch pole, at dinala pakanan
 * papunta sa shelter. Pagkatapos, lumilitaw ang mensahe ng araw at ang
 * pagbati, saka diretso na sa Available Pets.
 *
 * Isang beses lang ito lumalabas kada login (may session flag), at kapag
 * binuksan nang diretso ang URL ay lalaktawan lang papunta sa pets.
 */
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Adopter') {
    header('Location: ../Login_Page/login.php');
    exit();
}

$destination = 'search_pets.php';

if (empty($_SESSION['show_intro'])) {
    header('Location: ' . $destination);
    exit();
}
unset($_SESSION['show_intro']);

$displayName = $_SESSION['username'] ?? 'there';

// Mensahe sa popup pagkatapos ng kuwento. Ang salitang nasa <em> ang
// nagiging orange para may diin.
$motd = 'Every pet deserves a <em>second chance</em>.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Welcome</title>
    <style>
        :root {
            --green: #2d6a45;
            --green-dark: #1b4d2e;
            --green-light: #3fae72;
            --orange: #ff8c1a;
            --sky-top: #f3c992;
            --sky-bottom: #fbeedb;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(180deg, var(--sky-top) 0%, var(--sky-bottom) 100%);
            color: var(--green-dark);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .brand {
            position: fixed;
            top: 22px;
            left: 0;
            right: 0;
            text-align: center;
            font-weight: 800;
            font-size: 22px;
            letter-spacing: 0.5px;
            z-index: 2;
        }
        .brand .smart { color: var(--orange); }
        .brand .pet { color: var(--green-dark); }

        .stage {
            position: relative;
            /* Lapad na kasya sa screen at sa taas (72vh × 10/7) nang walang bakanteng gilid */
            width: min(100vw - 32px, 1000px, 102vh);
            aspect-ratio: 10 / 7;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 12px 40px rgba(90, 60, 20, 0.18);
        }
        .stage svg { width: 100%; height: 100%; display: block; }

        .caption {
            margin-top: 12px;
            min-height: 34px;
            font-size: clamp(16px, 2.4vw, 24px);
            font-weight: 700;
            text-align: center;
            padding: 0 20px;
            transition: opacity 0.3s ease;
        }
        .caption.fade { opacity: 0; }

        .skip {
            position: fixed;
            right: 22px;
            bottom: 22px;
            background: #fff;
            border: 1px solid #e6d3b8;
            color: var(--green);
            font-weight: 700;
            font-size: 13px;
            font-family: inherit;
            padding: 9px 16px;
            border-radius: 999px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: background-color 0.2s ease;
            z-index: 3;
        }
        .skip:hover { background: #fbf1e3; }

        /* Pagkatapos ng kuwento: lumalamlam papunta sa berde bago lumipat ng page */
        .curtain {
            position: fixed;
            inset: 0;
            background: var(--green-dark);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.7s ease;
            z-index: 4;
        }
        .curtain.on { opacity: 1; }

        /* ---------- Ang eksena ---------- */

        /* Mga ulap at ibon: mabagal na paggalaw para buhay ang langit */
        .cloud { animation: drift 70s linear infinite; }
        .cloud.c2 { animation-duration: 90s; animation-delay: -40s; }
        .cloud.c3 { animation-duration: 80s; animation-delay: -15s; }
        @keyframes drift {
            from { transform: translateX(-260px); }
            to { transform: translateX(1200px); }
        }
        .bird { animation: fly 22s linear infinite; }
        .bird.b2 { animation-delay: -8s; }
        .bird.b3 { animation-delay: -15s; }
        @keyframes fly {
            from { transform: translate(1050px, 40px); }
            to { transform: translate(-100px, -30px); }
        }

        /* Mga braso at binti: umiikot mula sa balikat / balakang */
        .limb { transform-box: fill-box; transform-origin: 50% 0%; }
        /* Ang brasong may hawak na catch pole ay kasama ang pole sa bounding
           box, kaya tinutukoy nang eksakto ang balikat. */
        .arm.pole { transform-box: fill-box; transform-origin: 5px 0px; transition: transform 0.35s ease; }

        @keyframes swing {
            from { transform: rotate(26deg); }
            to { transform: rotate(-26deg); }
        }
        @keyframes swingBig {
            from { transform: rotate(44deg); }
            to { transform: rotate(-44deg); }
        }

        /* --- Ang aso: nasa kanan, umaamoy sa basura, nakaharap pakanan --- */
        .dog { transform: translate(740px, 520px); }
        .pet-body { transform-box: fill-box; transform-origin: 50% 100%; }
        .dog .head { transform-box: fill-box; transform-origin: 15% 85%; animation: sniff 0.9s ease-in-out infinite; }
        @keyframes sniff {
            0%, 100% { transform: rotate(0deg) translateY(0); }
            50% { transform: rotate(9deg) translateY(5px); }
        }
        .dog .tail { transform-box: fill-box; transform-origin: 100% 100%; animation: wag 0.4s ease-in-out infinite alternate; }
        @keyframes wag {
            from { transform: rotate(-16deg); }
            to { transform: rotate(20deg); }
        }
        .smell { opacity: 0; stroke: #a89b88; stroke-width: 4; fill: none; stroke-linecap: round; }
        .scene:not(.lead) .smell { animation: rise 1.6s ease-out infinite; }
        .scene:not(.lead) .smell.s2 { animation-delay: 0.5s; }
        .scene:not(.lead) .smell.s3 { animation-delay: 1s; }
        @keyframes rise {
            0% { opacity: 0; transform: translateY(0); }
            25% { opacity: 0.8; }
            100% { opacity: 0; transform: translateY(-46px); }
        }

        /* Alikabok sa paa: lumalabas kapag nagpupumiglas o hinihila ang aso */
        .dust circle { opacity: 0; transform-box: fill-box; transform-origin: 50% 50%; fill: #bfb5a6; }
        .scene.catch .dust circle,
        .scene.lead .dust circle { animation: puff 0.7s ease-out infinite; }
        .dust .d2 { animation-delay: 0.25s !important; }
        .dust .d3 { animation-delay: 0.45s !important; }
        @keyframes puff {
            0% { opacity: 0; transform: scale(0.4) translate(0, 0); }
            30% { opacity: 0.85; }
            100% { opacity: 0; transform: scale(1.4) translate(-18px, -14px); }
        }

        /* Nagulat ang aso nang masalo, nagpumiglas */
        .alert { opacity: 0; transform-box: fill-box; transform-origin: 50% 100%; }
        .scene.catch .alert { animation: popIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
        .scene.lead .alert { animation: popOut 0.3s ease forwards; }
        @keyframes popIn {
            from { opacity: 0; transform: scale(0.2); }
            to { opacity: 1; transform: scale(1); }
        }
        @keyframes popOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        .scene.catch .dog .head,
        .scene.lead .dog .head { animation: none; }
        .scene.catch .dog .pet-body { animation: struggle 0.12s linear infinite alternate; }
        @keyframes struggle {
            from { transform: translateX(-4px) rotate(-2deg); }
            to { transform: translateX(4px) rotate(2deg); }
        }

        /* Hinihila pakanan palabas ng tanawin, papunta sa shelter. Ang mga
           paggalaw ay keyframes (hindi transitions) para tiyak ang bilis at
           sabay-sabay ang aso at mga rescuer. */
        .scene.lead .dog { animation: leadDog 3.6s cubic-bezier(0.5, 0, 0.8, 0.45) forwards; }
        @keyframes leadDog {
            from { transform: translate(740px, 520px); }
            to { transform: translate(1500px, 520px); }
        }
        .scene.lead .dog .leg { animation: swingBig 0.22s ease-in-out infinite alternate; }
        .scene.lead .dog .leg.alt { animation-delay: -0.22s; }
        .scene.lead .dog .head { transform: rotate(-10deg); }

        /* --- Mga rescuer: lumalabas mula sa truck, naglalakad pakanan --- */
        .person { opacity: 0; transition: opacity 0.6s ease; }
        .man { transform: translate(235px, 520px); }
        .woman { transform: translate(190px, 520px); }
        .scene.walk .person,
        .scene.catch .person,
        .scene.lead .person { opacity: 1; }
        .scene.walk .man { animation: walkMan 5s linear forwards; }
        .scene.walk .woman { animation: walkWoman 5s linear forwards; }
        @keyframes walkMan {
            from { transform: translate(235px, 520px); }
            to { transform: translate(640px, 520px); }
        }
        @keyframes walkWoman {
            from { transform: translate(190px, 520px); }
            to { transform: translate(574px, 520px); }
        }
        .scene.walk .person .leg,
        .scene.walk .person .arm.free { animation: swing 0.45s ease-in-out infinite alternate; }
        .scene.walk .person .alt { animation-delay: -0.45s; }

        /* Huminto sila, ibinaba nang bahagya ang pole para masalo ang aso */
        .scene.catch .man { transform: translate(640px, 520px); }
        .scene.catch .woman { transform: translate(574px, 520px); }
        .scene.catch .person .leg,
        .scene.catch .person .arm.free { animation: none; transform: rotate(0); }
        .scene.catch .arm.pole,
        .scene.lead .arm.pole { transform: rotate(8deg); }

        /* Sabay silang lumalabas sa kanan kasama ang aso */
        .scene.lead .man { animation: leadMan 3.6s cubic-bezier(0.5, 0, 0.8, 0.45) forwards; }
        .scene.lead .woman { animation: leadWoman 3.6s cubic-bezier(0.5, 0, 0.8, 0.45) forwards; }
        @keyframes leadMan {
            from { transform: translate(640px, 520px); }
            to { transform: translate(1400px, 520px); }
        }
        @keyframes leadWoman {
            from { transform: translate(574px, 520px); }
            to { transform: translate(1334px, 520px); }
        }
        .scene.lead .person .leg,
        .scene.lead .person .arm.free { animation: swing 0.32s ease-in-out infinite alternate; }
        .scene.lead .person .alt { animation-delay: -0.32s; }

        /* Ang mga nakaupo sa bench: bahagyang lumilingon sa nangyayari */
        .sitter .head { transform-box: fill-box; transform-origin: 50% 90%; animation: glance 6s ease-in-out infinite; }
        .sitter.s2 .head { animation-delay: -3s; }
        @keyframes glance {
            0%, 70%, 100% { transform: rotate(0deg); }
            80%, 90% { transform: rotate(6deg); }
        }

        /* Mensahe ng araw - popup sa ibabaw ng bakanteng tanawin pagkaalis ng lahat */
        .motd {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 0 6%;
            opacity: 0;
            transform: translateY(14px);
            transition: opacity 0.9s ease, transform 0.9s ease;
            pointer-events: none;
            background: rgba(255, 246, 230, 0.55);
        }
        .motd.on { opacity: 1; transform: none; }
        /* ---- 3D na paw print ----
           May kapal (mas madilim na "gilid" sa ilalim), gradient at kinang.
           Pumapasok na umiikot na parang barya, tumatalbog, tapos lumulutang
           at dahan-dahang umiikot. Ang anino sa sahig ay lumiliit habang umaangat. */
        .paw-3d {
            position: relative;
            width: clamp(64px, 9vw, 92px);
            height: clamp(64px, 9vw, 92px);
            margin-bottom: 18px;
            perspective: 700px;
        }
        .paw-3d .paw {
            position: relative;
            z-index: 1;
            width: 100%;
            height: 100%;
            transform-style: preserve-3d;
            filter: drop-shadow(0 6px 10px rgba(160, 70, 0, 0.30));
            opacity: 0;
        }
        .motd.on .paw-3d .paw {
            animation:
                pawFlipIn 1.1s cubic-bezier(0.34, 1.56, 0.64, 1) 0.15s forwards,
                pawFloat 3.2s ease-in-out 1.3s infinite;
        }
        @keyframes pawFlipIn {
            0%   { opacity: 0; transform: translateY(-40px) rotateY(-540deg) rotateX(25deg) scale(0.3); }
            60%  { opacity: 1; }
            100% { opacity: 1; transform: translateY(0) rotateY(0) rotateX(0) scale(1); }
        }
        @keyframes pawFloat {
            0%, 100% { opacity: 1; transform: translateY(0) rotateY(-18deg) rotateX(6deg); }
            50%      { opacity: 1; transform: translateY(-9px) rotateY(18deg) rotateX(-4deg); }
        }

        /* Malambot na liwanag na pumipintig sa likod */
        .paw-3d .glow {
            position: absolute;
            inset: -30%;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 170, 70, 0.55) 0%, rgba(255, 170, 70, 0) 65%);
            opacity: 0;
        }
        .motd.on .paw-3d .glow { animation: pawGlow 3.2s ease-in-out 1.1s infinite; }
        @keyframes pawGlow {
            0%, 100% { opacity: 0.45; transform: scale(0.9); }
            50%      { opacity: 0.9; transform: scale(1.08); }
        }

        /* Anino sa "sahig" */
        .paw-3d .floor {
            position: absolute;
            left: 18%;
            right: 18%;
            bottom: -12px;
            height: 10px;
            border-radius: 50%;
            background: radial-gradient(ellipse, rgba(110, 60, 10, 0.35) 0%, rgba(110, 60, 10, 0) 70%);
            opacity: 0;
        }
        .motd.on .paw-3d .floor { animation: pawFloor 3.2s ease-in-out 1.3s infinite; opacity: 1; transition: opacity 0.6s ease 0.9s; }
        @keyframes pawFloor {
            0%, 100% { transform: scaleX(1); opacity: 1; }
            50%      { transform: scaleX(0.7); opacity: 0.55; }
        }

        /* Maliliit na kislap sa paligid */
        .paw-3d .spark {
            position: absolute;
            width: 8px;
            height: 8px;
            opacity: 0;
            background: #ffd27a;
            clip-path: polygon(50% 0, 62% 38%, 100% 50%, 62% 62%, 50% 100%, 38% 62%, 0 50%, 38% 38%);
        }
        .paw-3d .spark.k1 { top: -6%; left: -14%; }
        .paw-3d .spark.k2 { top: 8%; right: -18%; width: 10px; height: 10px; }
        .paw-3d .spark.k3 { bottom: 4%; left: -20%; width: 6px; height: 6px; }
        .motd.on .paw-3d .spark { animation: sparkle 2.4s ease-in-out 1.2s infinite; }
        .motd.on .paw-3d .spark.k2 { animation-delay: 1.8s; }
        .motd.on .paw-3d .spark.k3 { animation-delay: 2.4s; }
        @keyframes sparkle {
            0%, 100% { opacity: 0; transform: scale(0.3) rotate(0deg); }
            40%      { opacity: 1; transform: scale(1) rotate(45deg); }
            70%      { opacity: 0; transform: scale(0.5) rotate(90deg); }
        }
        .motd .line {
            font-size: clamp(22px, 3.6vw, 40px);
            font-weight: 800;
            line-height: 1.25;
            color: var(--green-dark);
            text-shadow: 0 2px 0 rgba(255, 255, 255, 0.7);
            max-width: 22ch;
        }
        .motd .line em { font-style: normal; color: var(--orange); }
        .motd .from {
            margin-top: 12px;
            font-size: clamp(12px, 1.5vw, 15px);
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--green);
            opacity: 0.85;
        }

        @media (prefers-reduced-motion: reduce) {
            .stage { display: none; }
        }
    </style>
</head>
<body>
    <div class="brand"><span class="smart">SMART</span> <span class="pet">PET</span></div>

    <div class="stage">
        <svg class="scene" id="scene" viewBox="0 0 1000 700" aria-label="On a Caloocan street at sunset, two animal control rescuers step out of their truck, gently catch a stray dog sniffing at the trash, and bring it to the shelter">
            <defs>
                <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#efb473"/>
                    <stop offset="0.55" stop-color="#f6d2a0"/>
                    <stop offset="1" stop-color="#f8e3c2"/>
                </linearGradient>
                <linearGradient id="walk" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#ddd6ca"/>
                    <stop offset="1" stop-color="#cfc7ba"/>
                </linearGradient>
            </defs>

            <!-- langit, araw, ulap, ibon -->
            <rect x="0" y="0" width="1000" height="420" fill="url(#sky)"/>
            <circle cx="790" cy="115" r="66" fill="#fbe8c0" opacity="0.9"/>
            <circle cx="790" cy="115" r="40" fill="#fdf3d8"/>
            <g class="cloud" fill="#f9e5c0">
                <ellipse cx="0" cy="60" rx="60" ry="18"/><ellipse cx="40" cy="50" rx="40" ry="20"/><ellipse cx="-36" cy="52" rx="32" ry="16"/>
            </g>
            <g class="cloud c2" fill="#f9e5c0" opacity="0.95">
                <ellipse cx="0" cy="150" rx="70" ry="16"/><ellipse cx="34" cy="140" rx="44" ry="18"/>
            </g>
            <g class="cloud c3" fill="#f9e5c0" opacity="0.9">
                <ellipse cx="0" cy="100" rx="48" ry="14"/><ellipse cx="26" cy="92" rx="30" ry="15"/>
            </g>
            <g class="bird" stroke="#7b5a3a" stroke-width="2.5" fill="none" stroke-linecap="round">
                <path d="M0,60 q7,-9 14,0 q7,-9 14,0"/>
            </g>
            <g class="bird b2" stroke="#7b5a3a" stroke-width="2.5" fill="none" stroke-linecap="round">
                <path d="M0,110 q6,-8 12,0 q6,-8 12,0"/>
            </g>
            <g class="bird b3" stroke="#7b5a3a" stroke-width="2.5" fill="none" stroke-linecap="round">
                <path d="M0,30 q7,-9 14,0 q7,-9 14,0"/>
            </g>

            <!-- mga gusali sa likod -->
            <rect x="0" y="200" width="420" height="220" fill="#a99f95"/>
            <rect x="0" y="200" width="420" height="12" fill="#8b8177"/>
            <rect x="120" y="170" width="200" height="40" fill="#b8ada0"/>
            <rect x="120" y="170" width="200" height="10" fill="#8f7f6e"/>

            <!-- gusaling may awning at banderitas -->
            <rect x="385" y="240" width="220" height="180" fill="#d8c8a6"/>
            <rect x="385" y="240" width="220" height="14" fill="#f28c28"/>
            <rect x="412" y="240" width="28" height="14" fill="#1d7a4a"/>
            <rect x="468" y="240" width="28" height="14" fill="#1d7a4a"/>
            <rect x="524" y="240" width="28" height="14" fill="#1d7a4a"/>
            <rect x="580" y="240" width="25" height="14" fill="#1d7a4a"/>
            <rect x="405" y="300" width="60" height="46" rx="3" fill="#4a3a2c"/>
            <rect x="480" y="300" width="60" height="46" rx="3" fill="#4a3a2c"/>
            <rect x="555" y="340" width="36" height="80" rx="3" fill="#4a3a2c"/>
            <path d="M385,232 Q495,266 605,232" stroke="#6b5a4a" stroke-width="2" fill="none"/>
            <path d="M405,239 l18,0 l-9,20 z" fill="#f28c28"/>
            <path d="M445,248 l18,0 l-9,20 z" fill="#1d7a4a"/>
            <path d="M485,255 l18,0 l-9,20 z" fill="#f1c40f"/>
            <path d="M525,253 l18,0 l-9,20 z" fill="#f28c28"/>
            <path d="M565,245 l18,0 l-9,20 z" fill="#1d7a4a"/>

            <!-- kulay-abong gusali at gusali sa dulong kanan -->
            <rect x="605" y="225" width="215" height="195" fill="#b5bbbd"/>
            <rect x="605" y="225" width="215" height="12" fill="#9aa0a3"/>
            <rect x="630" y="285" width="55" height="45" rx="3" fill="#9fc2d8"/>
            <rect x="720" y="285" width="55" height="45" rx="3" fill="#9fc2d8"/>
            <rect x="820" y="210" width="180" height="210" fill="#c8bfb1"/>
            <rect x="820" y="210" width="180" height="12" fill="#a99f92"/>
            <rect x="845" y="270" width="50" height="42" rx="3" fill="#a0b8c8"/>
            <rect x="925" y="270" width="50" height="42" rx="3" fill="#a0b8c8"/>

            <!-- poste ng kuryente at mga kable -->
            <rect x="330" y="120" width="10" height="300" fill="#8d8378"/>
            <rect x="300" y="128" width="70" height="6" fill="#8d8378"/>
            <rect x="306" y="150" width="58" height="6" fill="#8d8378"/>
            <path d="M335,132 Q600,220 1000,180 M335,154 Q620,236 1000,200 M335,132 Q160,196 0,176 M335,154 Q170,212 0,196" stroke="#6b6259" stroke-width="2" fill="none" opacity="0.8"/>

            <!-- ang truck ng Caloocan Animal Control -->
            <g transform="translate(0, 0)">
                <rect x="0" y="228" width="200" height="66" fill="#166b40"/>
                <ellipse cx="100" cy="270" rx="30" ry="14" fill="#8f5326"/>
                <circle cx="126" cy="258" r="12" fill="#8f5326"/>
                <path d="M118,248 l-3,-12 l10,6 z M130,246 l4,-11 l6,10 z" fill="#6f3f1c"/>
                <rect x="10" y="228" width="5" height="66" fill="#5fb98a"/>
                <rect x="45" y="228" width="5" height="66" fill="#5fb98a"/>
                <rect x="80" y="228" width="5" height="66" fill="#5fb98a"/>
                <rect x="115" y="228" width="5" height="66" fill="#5fb98a"/>
                <rect x="150" y="228" width="5" height="66" fill="#5fb98a"/>
                <rect x="185" y="228" width="5" height="66" fill="#5fb98a"/>
                <rect x="0" y="294" width="200" height="80" fill="#1d7a4a"/>
                <text x="18" y="330" font-family="Arial, sans-serif" font-size="30" font-weight="800" fill="#fff">Caloocan</text>
                <text x="12" y="360" font-family="Arial, sans-serif" font-size="21" font-weight="600" fill="#fff">Animal Control</text>
                <rect x="200" y="236" width="118" height="138" rx="12" fill="#1d7a4a"/>
                <rect x="216" y="252" width="90" height="52" rx="8" fill="#9fd0e8"/>
                <rect x="200" y="336" width="118" height="12" fill="#f28c28"/>
                <rect x="308" y="364" width="18" height="10" rx="3" fill="#8d8d8d"/>
                <circle cx="62" cy="405" r="32" fill="#2b2b2b"/>
                <circle cx="62" cy="405" r="14" fill="#8d8d8d"/>
                <circle cx="258" cy="405" r="32" fill="#2b2b2b"/>
                <circle cx="258" cy="405" r="14" fill="#8d8d8d"/>
            </g>

            <!-- puno -->
            <path d="M898,420 L898,300 Q900,270 905,255 M898,330 Q925,310 940,300" stroke="#8a5a2b" stroke-width="16" stroke-linecap="round" fill="none"/>
            <circle cx="905" cy="262" r="72" fill="#2f8a55"/>
            <circle cx="858" cy="290" r="48" fill="#3a9a62"/>
            <circle cx="952" cy="292" r="50" fill="#3a9a62"/>
            <circle cx="900" cy="222" r="42" fill="#47ad72"/>

            <!-- kalsada at bangketa -->
            <rect x="0" y="418" width="1000" height="54" fill="#9a9187"/>
            <rect x="0" y="418" width="1000" height="8" fill="#b5ada2"/>
            <rect x="0" y="470" width="1000" height="230" fill="url(#walk)"/>
            <rect x="0" y="470" width="1000" height="14" fill="#c6beb1"/>
            <path d="M300,484 V700 M520,484 V700 M740,484 V700 M960,484 V700 M0,560 H1000" stroke="#c4bcaf" stroke-width="3" fill="none"/>
            <g stroke="#8f9c52" stroke-width="2.5" fill="none" stroke-linecap="round">
                <path d="M400,498 l4,-14 l4,9 l4,-14 l4,16"/>
                <path d="M530,492 l4,-14 l4,9 l4,-14 l4,16"/>
                <path d="M760,502 l4,-14 l4,9 l4,-14 l4,16"/>
            </g>

            <!-- basura sa kanan, sa tabi ng puno -->
            <g transform="translate(850, 520)">
                <path d="M-34,0 L-22,-56 Q0,-92 22,-58 L36,0 Z" fill="#2f3a35"/>
                <path d="M-6,-72 q8,-14 16,-2" stroke="#2f3a35" stroke-width="7" stroke-linecap="round" fill="none"/>
                <path d="M-22,-40 l10,-20 M-8,-52 l4,-14" stroke="#4a5650" stroke-width="3" stroke-linecap="round"/>
                <path d="M-40,2 q-8,-12 4,-14 q4,8 -4,14 z" fill="#f1c40f"/>
                <path d="M30,-4 l14,-10 l8,12 z" fill="#efe6d8"/>
                <path class="smell s1" d="M-14,-96 q7,-10 0,-20 q-7,-10 0,-20"/>
                <path class="smell s2" d="M2,-100 q7,-10 0,-20 q-7,-10 0,-20"/>
                <path class="smell s3" d="M18,-94 q7,-10 0,-20 q-7,-10 0,-20"/>
            </g>

            <!-- ang asong gala -->
            <g class="pet dog">
                <text class="alert" x="30" y="-116" text-anchor="middle" font-size="54" font-weight="900" fill="#e74c3c" font-family="Arial, sans-serif">!</text>
                <g class="dust">
                    <circle class="d1" cx="-40" cy="-8" r="10"/>
                    <circle class="d2" cx="-62" cy="-12" r="8"/>
                    <circle class="d3" cx="-20" cy="-4" r="7"/>
                </g>
                <g class="pet-body">
                    <path class="tail" d="M-48,-44 q-18,-8 -14,-32" stroke="#8a4f22" stroke-width="9" fill="none" stroke-linecap="round"/>
                    <rect class="leg limb alt" x="-40" y="-32" width="11" height="32" rx="5" fill="#8f5326"/>
                    <rect class="leg limb" x="14" y="-32" width="11" height="32" rx="5" fill="#8f5326"/>
                    <ellipse cx="-4" cy="-42" rx="50" ry="22" fill="#a8652e"/>
                    <ellipse cx="-6" cy="-32" rx="34" ry="10" fill="#c9905a"/>
                    <rect class="leg limb" x="-26" y="-32" width="11" height="32" rx="5" fill="#a8652e"/>
                    <rect class="leg limb alt" x="28" y="-32" width="11" height="32" rx="5" fill="#a8652e"/>
                    <g class="head">
                        <circle cx="50" cy="-60" r="20" fill="#a8652e"/>
                        <ellipse cx="68" cy="-52" rx="14" ry="9" fill="#c9905a"/>
                        <circle cx="80" cy="-54" r="4.5" fill="#2b1d12"/>
                        <circle cx="56" cy="-66" r="3.5" fill="#2b1d12"/>
                        <circle cx="57.2" cy="-67.2" r="1.1" fill="#fff"/>
                        <path d="M38,-74 l-4,-22 l16,10 z" fill="#8a4f22"/>
                        <path d="M56,-78 l6,-20 l8,18 z" fill="#8a4f22"/>
                        <ellipse cx="66" cy="-42" rx="5" ry="2.5" fill="#e07070"/>
                    </g>
                </g>
            </g>

            <!-- rescuer: babae (walang hawak) -->
            <g class="person woman">
                <g class="dust">
                    <circle class="d1" cx="-24" cy="-6" r="8"/>
                    <circle class="d3" cx="-40" cy="-10" r="6"/>
                </g>
                <rect class="leg limb alt" x="-14" y="-60" width="12" height="60" rx="5" fill="#2b3a5c"/>
                <rect class="leg limb" x="2" y="-60" width="12" height="60" rx="5" fill="#34497e"/>
                <g class="arm limb alt free">
                    <rect x="-30" y="-120" width="11" height="34" rx="5" fill="#2e8b57"/>
                    <circle cx="-24.5" cy="-82" r="6" fill="#e8b48f"/>
                </g>
                <rect x="-21" y="-124" width="42" height="66" rx="9" fill="#2e8b57"/>
                <rect x="-21" y="-116" width="42" height="26" fill="#f28c28"/>
                <rect x="-21" y="-108" width="42" height="3" fill="#fff" opacity="0.9"/>
                <rect x="-21" y="-98" width="42" height="3" fill="#fff" opacity="0.9"/>
                <g class="arm limb free">
                    <rect x="19" y="-120" width="11" height="34" rx="5" fill="#2e8b57"/>
                    <circle cx="24.5" cy="-82" r="6" fill="#e8b48f"/>
                </g>
                <circle cx="0" cy="-144" r="16" fill="#e8b48f"/>
                <path d="M-16,-148 q16,-22 32,-2 q-16,-8 -32,2 z" fill="#2b1d12"/>
                <circle cx="-13" cy="-156" r="8" fill="#2b1d12"/>
                <circle cx="7" cy="-144" r="2" fill="#2b1d12"/>
            </g>

            <!-- rescuer: lalaki, may sombrero at hawak na catch pole -->
            <g class="person man">
                <g class="dust">
                    <circle class="d2" cx="-24" cy="-6" r="8"/>
                    <circle class="d1" cx="-42" cy="-10" r="6"/>
                </g>
                <rect class="leg limb alt" x="-14" y="-62" width="12" height="62" rx="5" fill="#2b3a5c"/>
                <rect class="leg limb" x="2" y="-62" width="12" height="62" rx="5" fill="#34497e"/>
                <g class="arm limb alt free">
                    <rect x="-30" y="-122" width="11" height="34" rx="5" fill="#2e8b57"/>
                    <circle cx="-24.5" cy="-84" r="6" fill="#f2c9a0"/>
                </g>
                <rect x="-21" y="-126" width="42" height="68" rx="9" fill="#2e8b57"/>
                <rect x="-21" y="-118" width="42" height="26" fill="#f28c28"/>
                <rect x="-21" y="-110" width="42" height="3" fill="#fff" opacity="0.9"/>
                <rect x="-21" y="-100" width="42" height="3" fill="#fff" opacity="0.9"/>
                <g class="arm pole">
                    <rect x="19" y="-122" width="11" height="34" rx="5" fill="#2e8b57"/>
                    <circle cx="24.5" cy="-84" r="6" fill="#f2c9a0"/>
                    <line x1="26" y1="-84" x2="130" y2="-46" stroke="#8d8d8d" stroke-width="5" stroke-linecap="round"/>
                    <circle cx="140" cy="-40" r="11" fill="none" stroke="#8d8d8d" stroke-width="3.5"/>
                </g>
                <circle cx="0" cy="-146" r="16" fill="#f2c9a0"/>
                <rect x="-17" y="-164" width="34" height="14" rx="6" fill="#1f3b63"/>
                <rect x="2" y="-154" width="22" height="5" rx="2" fill="#1f3b63"/>
                <circle cx="7" cy="-146" r="2" fill="#2b1d12"/>
            </g>

            <!-- bench sa harapan, may dalawang nakaupo -->
            <g transform="translate(40, 0)">
                <rect x="10" y="536" width="8" height="126" fill="#7a4c22"/>
                <rect x="224" y="536" width="8" height="126" fill="#7a4c22"/>
                <rect x="0" y="540" width="242" height="12" rx="4" fill="#b5763b"/>
                <rect x="0" y="558" width="242" height="12" rx="4" fill="#b5763b"/>
                <rect x="0" y="576" width="242" height="12" rx="4" fill="#b5763b"/>
                <rect x="-6" y="594" width="254" height="14" rx="4" fill="#b5763b"/>

                <g class="sitter s1" transform="translate(70, 0)">
                    <rect x="-22" y="528" width="44" height="66" rx="12" fill="#d64545"/>
                    <rect x="6" y="540" width="12" height="40" rx="5" fill="#d64545"/>
                    <rect x="-2" y="586" width="42" height="16" rx="6" fill="#2b3a5c"/>
                    <rect x="28" y="596" width="14" height="60" rx="5" fill="#2b3a5c"/>
                    <rect x="26" y="654" width="24" height="8" rx="3" fill="#1c1c28"/>
                    <g class="head">
                        <circle cx="0" cy="512" r="18" fill="#e8b48f"/>
                        <path d="M-18,510 q18,-26 36,0 q-18,-10 -36,0 z" fill="#2b1d12"/>
                        <circle cx="8" cy="514" r="2" fill="#2b1d12"/>
                    </g>
                </g>
                <g class="sitter s2" transform="translate(150, 0)">
                    <rect x="-22" y="528" width="44" height="66" rx="12" fill="#e6b422"/>
                    <rect x="6" y="540" width="12" height="40" rx="5" fill="#e6b422"/>
                    <rect x="-2" y="586" width="42" height="16" rx="6" fill="#3b3b52"/>
                    <rect x="28" y="596" width="14" height="60" rx="5" fill="#3b3b52"/>
                    <rect x="26" y="654" width="24" height="8" rx="3" fill="#1c1c28"/>
                    <g class="head">
                        <circle cx="0" cy="512" r="18" fill="#c68a5a"/>
                        <path d="M-18,510 q18,-26 36,0 q-18,-10 -36,0 z" fill="#1f140c"/>
                        <circle cx="8" cy="514" r="2" fill="#2b1d12"/>
                    </g>
                </g>
            </g>
        </svg>

        <!-- Mensahe ng araw, popup sa ibabaw ng bakanteng tanawin -->
        <div class="motd" id="motd" aria-live="polite">
            <div class="paw-3d" aria-hidden="true">
                <span class="glow"></span>
                <span class="spark k1"></span>
                <span class="spark k2"></span>
                <span class="spark k3"></span>
                <svg class="paw" viewBox="0 0 200 210">
                    <defs>
                        <!-- Ibabaw ng pad: maliwanag sa itaas-kaliwa, mas madilim sa ibaba -->
                        <radialGradient id="padFace" cx="35%" cy="30%" r="80%">
                            <stop offset="0" stop-color="#ffc46b"/>
                            <stop offset="0.55" stop-color="#ff9a2e"/>
                            <stop offset="1" stop-color="#f07a12"/>
                        </radialGradient>
                        <!-- Gilid/kapal ng pad -->
                        <linearGradient id="padSide" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="#d9670a"/>
                            <stop offset="1" stop-color="#a84a00"/>
                        </linearGradient>
                        <g id="pawShape">
                            <ellipse cx="50" cy="80" rx="21" ry="29" transform="rotate(-18 50 80)"/>
                            <ellipse cx="83" cy="45" rx="19" ry="27" transform="rotate(-6 83 45)"/>
                            <ellipse cx="117" cy="45" rx="19" ry="27" transform="rotate(6 117 45)"/>
                            <ellipse cx="150" cy="80" rx="21" ry="29" transform="rotate(18 150 80)"/>
                            <path d="M100,104 C118,96 146,104 154,132 C162,164 132,186 100,186 C68,186 38,164 46,132 C54,104 82,96 100,104 Z"/>
                        </g>
                    </defs>

                    <!-- Kapal: ilang patong ng mas madilim na hugis pababa -->
                    <use href="#pawShape" fill="url(#padSide)" transform="translate(0 12)"/>
                    <use href="#pawShape" fill="url(#padSide)" transform="translate(0 8)"/>
                    <use href="#pawShape" fill="#c75a05" transform="translate(0 4)"/>

                    <!-- Ibabaw -->
                    <use href="#pawShape" fill="url(#padFace)" stroke="#fff3e2" stroke-width="3"/>

                    <!-- Kinang -->
                    <g fill="#ffffff" opacity="0.55">
                        <ellipse cx="44" cy="68" rx="6" ry="10" transform="rotate(-18 44 68)"/>
                        <ellipse cx="78" cy="32" rx="5.5" ry="9" transform="rotate(-6 78 32)"/>
                        <ellipse cx="112" cy="32" rx="5.5" ry="9" transform="rotate(6 112 32)"/>
                        <ellipse cx="144" cy="68" rx="6" ry="10" transform="rotate(18 144 68)"/>
                        <ellipse cx="80" cy="122" rx="16" ry="8" transform="rotate(-20 80 122)"/>
                    </g>
                </svg>
                <span class="floor"></span>
            </div>
            <div class="line"><?php echo $motd; ?></div>
            <div class="from">SMART PET · Caloocan City</div>
        </div>
    </div>

    <div class="caption" id="caption">Somewhere in Caloocan City…</div>
    <button type="button" class="skip" id="skip">Skip</button>
    <div class="curtain" id="curtain"></div>

    <script>
    (function () {
        const DESTINATION = <?php echo json_encode($destination); ?>;
        const NAME = <?php echo json_encode($displayName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        const scene = document.getElementById('scene');
        const motd = document.getElementById('motd');
        const caption = document.getElementById('caption');
        const curtain = document.getElementById('curtain');
        const skip = document.getElementById('skip');

        let leaving = false;
        function leave() {
            if (leaving) { return; }
            leaving = true;
            curtain.classList.add('on');
            setTimeout(function () { window.location.replace(DESTINATION); }, 700);
        }

        skip.addEventListener('click', leave);

        // Enter o Space sa keyboard = pindutin ang Skip
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar' || e.code === 'Space') {
                e.preventDefault(); // para hindi mag-scroll ang page kapag Space
                skip.click();
            }
        });

        // Kung ayaw ng user ng galaw (accessibility), laktawan agad
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            leave();
            return;
        }

        function say(text) {
            caption.classList.add('fade');
            setTimeout(function () {
                caption.textContent = text;
                caption.classList.remove('fade');
            }, 300);
        }

        function phase(name) {
            scene.classList.remove('walk', 'catch', 'lead');
            scene.classList.add(name);
        }

        // Yugto 1 (0.4s): lumabas ang mga rescuer sa truck at naglakad papunta sa aso
        // Yugto 2 (5.6s): huminto sila, sinalo ang aso gamit ang catch pole
        // Yugto 3 (7.6s): dinala ang aso pakanan, palabas ng tanawin
        // Yugto 4 (11.4s): bakante ang tanawin - lumilitaw ang popup ng mensahe ng araw
        // Yugto 5 (15s): pagbati, tapos lipat ng page
        setTimeout(function () { phase('walk'); }, 400);

        setTimeout(function () {
            phase('catch');
            say('The rescue team spots a stray.');
        }, 5600);

        setTimeout(function () {
            phase('lead');
            say('Every rescue starts with a chase.');
        }, 7600);

        setTimeout(function () {
            say('');
            motd.classList.add('on');
        }, 11400);

        setTimeout(function () {
            say('Welcome to SMART PET, ' + NAME + '.');
        }, 15000);

        setTimeout(leave, 17000);
    })();
    </script>
</body>
</html>

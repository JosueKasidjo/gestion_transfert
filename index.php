<?php
require_once __DIR__ . '/config.php';

$destination = estConnecte() ? 'dashboard.php' : 'login.php';
$nomApplication = NOM_APPLICATION;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="2;url=<?= securiser($destination) ?>">
    <meta name="theme-color" content="#082f49">
    <title>Chargement - <?= securiser($nomApplication) ?></title>
    <link rel="icon" href="<?= securiser(URL_APPLICATION . LOGO_APPLICATION) ?>" type="image/jpeg">
    <style>
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            overflow: hidden;
            color: #082f49;
            background:
                radial-gradient(ellipse at 18% 82%, rgba(14, 165, 233, .13), transparent 38%),
                radial-gradient(ellipse at 88% 12%, rgba(34, 197, 94, .10), transparent 34%),
                #f4f9fb;
            font-family: Arial, sans-serif;
        }
        .loader {
            width: min(100% - 40px, 360px);
            text-align: center;
            animation: arrive .55s ease-out both;
        }
        .logo {
            width: 112px;
            height: 112px;
            margin: 0 auto 22px;
            display: block;
            border-radius: 12px;
            object-fit: contain;
        }
        h1 {
            margin: 0;
            font-size: 20px;
            line-height: 1.35;
        }
        p {
            margin: 9px 0 27px;
            color: #64748b;
            font-size: 14px;
        }
        .progression {
            height: 4px;
            overflow: hidden;
            border-radius: 4px;
            background: #dbeafe;
        }
        .progression::after {
            display: block;
            width: 38%;
            height: 100%;
            border-radius: inherit;
            background: #0284c7;
            content: "";
            animation: avancer 1.35s ease-in-out infinite;
        }
        .fallback {
            display: inline-block;
            margin-top: 21px;
            color: #0369a1;
            font-size: 13px;
            text-decoration: none;
        }
        .fallback:hover { text-decoration: underline; }
        @keyframes avancer {
            from { transform: translateX(-110%); }
            to { transform: translateX(275%); }
        }
        @keyframes arrive {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
            }
        }
    </style>
</head>
<body>
    <main class="loader" aria-live="polite">
        <img class="logo" src="<?= securiser(URL_APPLICATION . LOGO_APPLICATION) ?>" alt="Logo La Main Victorieuse">
        <h1><?= securiser($nomApplication) ?></h1>
        <p>Préparation de votre espace...</p>
        <div class="progression" role="progressbar" aria-label="Chargement"></div>
        <a class="fallback" href="<?= securiser($destination) ?>">Continuer</a>
    </main>
    <script>
        window.setTimeout(() => window.location.replace(<?= json_encode($destination) ?>), 1400);
    </script>
</body>
</html>
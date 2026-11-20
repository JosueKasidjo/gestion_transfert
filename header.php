<?php
/**
 * En-tête + sidebar commune à toutes les pages
 * À inclure APRÈS config.php et exigerConnexion()
 *
 * Variables attendues avant inclusion :
 *   $titrePage   (string)  Titre de la page
 *   $sousTitre   (string)  Sous-titre optionnel
 *   $pageActive  (string)  Nom du fichier actif (ex: 'milieux.php')
 */

if (!isset($titrePage))   $titrePage = 'Page';
if (!isset($sousTitre))   $sousTitre = '';
if (!isset($pageActive))  $pageActive = basename($_SERVER['PHP_SELF']);

$u = utilisateurConnecte();

$nomUtilisateur = trim(
    ($u['nom'] ?? '') . ' ' .
    ($u['postnom'] ?? '') . ' ' .
    ($u['prenom'] ?? '')
);

if ($nomUtilisateur === '') {
    $nomUtilisateur = $u['nom_utilisateur'] ?? 'Utilisateur';
}

$nomAgenceH = $u['nom_agence'] ?: NOM_APPLICATION;
$roleH      = $u['role'] ?: 'Agent';

$logoH = URL_APPLICATION . LOGO_APPLICATION;

$menusH = [
    'general' => [
        'titre' => 'Général',
        'liens' => [
            ['url' => 'dashboard.php', 'nom' => 'Tableau de bord'],
        ],
    ],
    'transport' => [
        'titre' => 'Transport',
        'liens' => [
            ['url' => 'milieux.php',    'nom' => 'Milieux / Destinations'],
            ['url' => 'tarifs.php',     'nom' => 'Tarifs'],
            ['url' => 'voyages.php',    'nom' => 'Voyages'],
            ['url' => 'voyageurs.php',  'nom' => 'Voyageurs'],
            ['url' => 'billets.php',    'nom' => 'Billets'],
            ['url' => 'chauffeurs.php', 'nom' => 'Chauffeurs'],
            ['url' => 'vehicules.php',  'nom' => 'Véhicules'],
        ],
    ],
    'colis' => [
        'titre' => 'Gestion des colis',
        'liens' => [
            ['url' => 'expediteurs.php', 'nom' => 'Expéditeurs'],
            ['url' => 'colis.php',       'nom' => 'Colis'],
            ['url' => 'suivi_colis.php', 'nom' => 'Suivi des colis'],
        ],
    ],
    'finances' => [
        'titre' => 'Finances',
        'liens' => [
            ['url' => 'caisse.php',   'nom' => 'Caisse'],
            ['url' => 'depenses.php', 'nom' => 'Dépenses'],
            ['url' => 'rapports.php', 'nom' => 'Rapports'],
        ],
    ],
    'administration' => [
        'titre' => 'Administration',
        'liens' => [
            ['url' => 'agences.php',     'nom' => 'Agences'],
            ['url' => 'utilisateurs.php','nom' => 'Utilisateurs'],
            ['url' => 'parametres.php',  'nom' => 'Paramètres'],
            ['url' => 'statistiques.php',  'nom' => 'Statistiques']
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= securiser($titrePage) ?> - <?= securiser($nomAgenceH) ?></title>
    <link rel="icon" href="<?= securiser(URL_APPLICATION . LOGO_APPLICATION) ?>" type="image/jpeg">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        * { box-sizing: border-box; }
        * {
            box-sizing: border-box;
            scrollbar-width: thin;
            scrollbar-color: #0284c7 #e0f2fe;
        }
        body { font-family: Arial, sans-serif; }

        *::-webkit-scrollbar { width: 8px; height: 8px; }
        *::-webkit-scrollbar-track { background: #e0f2fe; border-radius: 9999px; }
        *::-webkit-scrollbar-thumb { background: #0284c7; border: 2px solid #e0f2fe; border-radius: 9999px; }
        *::-webkit-scrollbar-thumb:hover { background: #0369a1; }
        *::-webkit-scrollbar-corner { background: #e0f2fe; }

        .menu-link {
            display: flex; align-items: center;
            padding: 11px 14px; border-radius: 9px;
            color: #dbeafe; transition: all .2s ease; font-size: 14px;
        }
        .menu-link:hover { background: #075985; color: white; }
        .menu-link.actif { background: #0284c7; color: white; font-weight: 600; }
        .app-action-icon { width: 1rem; height: 1rem; margin-right: .45rem; flex-shrink: 0; vertical-align: middle; }

        .carte { transition: transform .2s, box-shadow .2s; }
        .carte:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(14,165,233,.12); }

        .sidebar {
            width: 260px; background: #082f49; color: white;
            position: fixed; top: 0; bottom: 0; left: 0;
            z-index: 50; display: flex; flex-direction: column;
            transition: transform .3s ease;
        }
        .contenu-principal {
            margin-left: 260px; min-width: 0; width: calc(100% - 260px);
        }
        .overlay-menu { display: none; }

        @media (max-width: 767px) {
            .sidebar { width: min(300px, 85vw); transform: translateX(-100%); }
            .sidebar.menu-ouvert { transform: translateX(0); }
            .contenu-principal { margin-left: 0; width: 100%; }
            .overlay-menu.visible {
                display: block; position: fixed; inset: 0;
                z-index: 40; background: rgba(15,23,42,.6);
            }
            body.menu-bloque { overflow: hidden; }
        }

        .tableau-responsive { width: 100%; overflow-x: auto; }
        .tableau-responsive {
            width: 100%; min-width: 0; overflow-x: auto;
            overscroll-behavior-x: contain;
        }
        .tableau-responsive table { min-width: 650px; }

        button, a { -webkit-tap-highlight-color: transparent; }
    </style>
</head>
<body class="bg-slate-100 min-h-screen">

<div id="overlayMenu" class="overlay-menu" onclick="fermerMenu()"></div>

<aside id="sidebar" class="sidebar">

    <div class="p-5 border-b border-sky-800">
        <div class="flex items-center gap-3">
            <?php if (!empty($logoH)): ?>
                <img src="<?= securiser($logoH) ?>"
                     class="w-12 h-12 object-contain rounded-xl bg-white p-1"
                     alt="Logo">
            <?php else: ?>
                <div class="w-12 h-12 rounded-xl bg-sky-700 flex items-center justify-center text-white font-bold text-lg">
                    AV
                </div>
            <?php endif; ?>
            <div class="min-w-0">
                <h1 class="font-bold text-white text-sm truncate"><?= securiser($nomAgenceH) ?></h1>
                <p class="text-xs text-sky-300 mt-1">Gestion agence</p>
            </div>
        </div>
    </div>

    <div class="px-5 py-4 border-b border-sky-800">
        <p class="text-xs text-sky-300">Connecté en tant que</p>
        <p class="font-semibold text-white text-sm mt-1"><?= securiser($nomUtilisateur) ?></p>
        <p class="text-xs text-sky-300 mt-1"><?= securiser($roleH) ?></p>
    </div>

    <nav class="flex-1 p-3 space-y-1 overflow-y-auto text-sm">
        <?php foreach ($menusH as $menu): ?>
            <p class="text-xs uppercase text-sky-400 font-bold px-3 pt-4 pb-1">
                <?= securiser($menu['titre']) ?>
            </p>
            <?php foreach ($menu['liens'] as $lien): ?>
                <a href="<?= securiser($lien['url']) ?>"
                   class="menu-link <?= $lien['url'] === $pageActive ? 'actif' : '' ?>">
                    <?= securiser($lien['nom']) ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="p-4 border-t border-sky-800">
        <a href="logout.php"
           data-app-confirm="Voulez-vous vraiment vous déconnecter ?"
           data-app-confirm-title="Déconnexion"
           data-app-confirm-label="Se déconnecter"
           data-app-confirm-danger="true"
           class="block text-center bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-lg transition">
            Déconnexion
        </a>
    </div>
</aside>

<main class="contenu-principal">

    <header class="bg-white shadow-sm px-4 md:px-8 py-4 sticky top-0 z-30">
        <div class="flex justify-between items-center gap-4">
            <div class="flex items-center gap-3 min-w-0">
                <button type="button" onclick="ouvrirMenu()"
                        class="md:hidden flex-shrink-0 flex items-center gap-2 bg-sky-700 hover:bg-sky-800 text-white px-3 py-2.5 rounded-lg font-semibold text-sm">
                    <i data-lucide="menu" class="h-5 w-5" aria-hidden="true"></i>
                    <span>Menu</span>
                </button>

                <div class="min-w-0">
                    <h2 class="text-xl md:text-2xl font-bold text-slate-800"><?= securiser($titrePage) ?></h2>
                    <?php if ($sousTitre !== ''): ?>
                        <p class="text-xs md:text-sm text-slate-500 truncate"><?= securiser($sousTitre) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="hidden sm:block text-right">
                <p class="font-semibold text-slate-700 text-sm"><?= securiser($roleH) ?></p>
                <p class="text-xs text-sky-600"><?= securiser($nomAgenceH) ?></p>
            </div>
        </div>
    </header>

    <div class="p-4 md:p-6 xl:p-8 max-w-full">
        
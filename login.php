<?php
require_once "config.php";

/* =====================================================
   1. REDIRECTION SI DEJA CONNECTE
===================================================== */

if (estConnecte()) {
    rediriger("dashboard.php");
}

/* =====================================================
   2. VARIABLES
===================================================== */

$erreur = "";
$nom_utilisateur = "";

/* =====================================================
   3. TRAITEMENT DU FORMULAIRE
===================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Protection CSRF
    verifierTokenCSRF();

    $nom_utilisateur = trim($_POST["nom_utilisateur"] ?? "");
    $mot_de_passe    = $_POST["mot_de_passe"] ?? "";

    if ($nom_utilisateur === "" || $mot_de_passe === "") {

        $erreur = "Veuillez remplir tous les champs.";

    } else {

        try {

            // Recherche de l'utilisateur + agence
            $stmt = $pdo->prepare("
                SELECT
                    u.*,
                    a.nom_agence,
                    a.statut AS statut_agence
                FROM utilisateurs u
                LEFT JOIN agences a ON a.id = u.agence_id
                WHERE u.nom_utilisateur = ?
                LIMIT 1
            ");

            $stmt->execute([$nom_utilisateur]);
            $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                $utilisateur
                && password_verify($mot_de_passe, $utilisateur["mot_de_passe"])
            ) {

                // Vérification du compte
                if ($utilisateur["statut"] !== "Actif") {

                    $erreur = "Votre compte est désactivé. Contactez l'administrateur.";

                } elseif (
                    $utilisateur["agence_id"] === null
                    || $utilisateur["statut_agence"] !== "Actif"
                ) {

                    $erreur = "Votre agence n'est pas active ou n'est pas configurée.";

                } else {

                    // Régénération de l'identifiant de session (anti-fixation)
                    session_regenerate_id(true);

                    // Informations utilisateur en session
                    $_SESSION["id_utilisateur"] = $utilisateur["id"];
                    $_SESSION["nom"]             = $utilisateur["nom"];
                    $_SESSION["postnom"]         = $utilisateur["postnom"];
                    $_SESSION["prenom"]          = $utilisateur["prenom"];
                    $_SESSION["nom_utilisateur"] = $utilisateur["nom_utilisateur"];
                    $_SESSION["role"]            = $utilisateur["role"];
                    $_SESSION["agence_id"]       = $utilisateur["agence_id"];
                    $_SESSION["nom_agence"]      = $utilisateur["nom_agence"];
                    $_SESSION["photo"]           = $utilisateur["photo"];

                    // Enregistrement de la dernière connexion
                    $update = $pdo->prepare("
                        UPDATE utilisateurs
                        SET derniere_connexion = NOW()
                        WHERE id = ?
                    ");
                    $update->execute([$utilisateur["id"]]);

                    // Redirection
                    rediriger("dashboard.php");
                }

            } else {

                $erreur = "Nom d'utilisateur ou mot de passe incorrect.";
            }

        } catch (PDOException $e) {

            error_log($e->getMessage());
            $erreur = "Une erreur est survenue. Veuillez réessayer.";
        }
    }
}

/* =====================================================
   4. INFORMATIONS AGENCE POUR L'AFFICHAGE
===================================================== */

try {
    $parametres = parametresAgence($pdo);
} catch (Throwable $e) {
    $parametres = [];
}

$nom_affiche = $parametres["nom_agence"] ?? NOM_APPLICATION;
$slogan      = $parametres["slogan"]     ?? "";
$logo_url = cheminLogo(LOGO_APPLICATION);

?>
<!DOCTYPE html>
<html lang="fr">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Connexion - <?= securiser($nom_affiche) ?></title>
    <link rel="icon" href="<?= securiser($logo_url) ?>" type="image/jpeg">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; }
    </style>

</head>

<body class="min-h-screen bg-sky-50 flex items-center justify-center p-4">

    <div class="w-full max-w-5xl bg-white rounded-3xl
                shadow-2xl overflow-hidden grid md:grid-cols-2">

        <!-- PARTIE GAUCHE : PRESENTATION -->
        <div class="hidden md:flex flex-col justify-between
                    bg-gradient-to-br from-sky-500 to-blue-800
                    p-10 text-white">

            <div>

                <div class="flex items-center gap-3 mb-12">

                    <div class="bg-white/20 p-3 rounded-xl">
                        <svg xmlns="http://www.w3.org/2000/svg"
                             class="w-9 h-9"
                             fill="none"
                             viewBox="0 0 24 24"
                             stroke="currentColor"
                             stroke-width="1.8">
                            <path stroke-linecap="round"
                                  stroke-linejoin="round"
                                  d="M4 16V8a2 2 0 012-2h12a2 2 0 012 2v8M4 16h16M4 16l-1 3h18l-1-3M8 10h8M8 13h3"/>
                        </svg>
                    </div>

                    <div>
                        <h1 class="text-xl font-bold">AGENCE VOYAGE</h1>
                        <p class="text-xs text-sky-100">Transport & Logistique</p>
                    </div>

                </div>

                <h2 class="text-4xl font-bold leading-tight mb-5">
                    Gérez vos voyages en toute simplicité.
                </h2>

                <p class="text-sky-100 leading-relaxed">
                    Une plateforme complète pour gérer les voyageurs,
                    les chauffeurs, les véhicules, les colis et
                    les différentes agences.
                </p>

            </div>

            <div class="space-y-4">

                <div class="flex items-center gap-3">
                    <div class="bg-white/20 p-2 rounded-lg text-sm">✓</div>
                    <span class="text-sm">Gestion des voyageurs et billets</span>
                </div>

                <div class="flex items-center gap-3">
                    <div class="bg-white/20 p-2 rounded-lg text-sm">✓</div>
                    <span class="text-sm">Gestion des colis et tarifications</span>
                </div>

                <div class="flex items-center gap-3">
                    <div class="bg-white/20 p-2 rounded-lg text-sm">✓</div>
                    <span class="text-sm">Gestion de plusieurs agences</span>
                </div>

                <p class="text-xs text-sky-100 pt-5">
                    © <?= date("Y") ?> - <?= securiser($nom_affiche) ?>
                </p>

            </div>

        </div>

        <!-- PARTIE DROITE : FORMULAIRE -->
        <div class="p-6 sm:p-10 md:p-12 flex flex-col justify-center">

            <div class="mb-8 text-center">

                <?php if ($logo_url !== ''): ?>

                    <img src="<?= securiser($logo_url) ?>"
                         alt="Logo de l'agence"
                         class="h-20 max-w-40 object-contain mx-auto mb-4">

                <?php else: ?>

                    <div class="w-20 h-20 rounded-2xl
                                bg-sky-100 text-sky-600
                                flex items-center justify-center
                                mx-auto mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg"
                             class="w-11 h-11"
                             fill="none"
                             viewBox="0 0 24 24"
                             stroke="currentColor"
                             stroke-width="1.7">
                            <path stroke-linecap="round"
                                  stroke-linejoin="round"
                                  d="M3 16h18M5 16l1-9h12l1 9M8 11h8M8 16v2m8-2v2"/>
                        </svg>
                    </div>

                <?php endif; ?>

                <h2 class="text-2xl font-bold text-slate-800">Bienvenue !</h2>

                <p class="text-slate-500 mt-2 text-sm">
                    Connectez-vous à votre espace de gestion.
                </p>

                <p class="text-sky-600 font-semibold mt-2">
                    <?= securiser($nom_affiche) ?>
                </p>

                <?php if ($slogan !== ''): ?>
                    <p class="text-xs text-slate-400 mt-1"><?= securiser($slogan) ?></p>
                <?php endif; ?>

            </div>

            <!-- MESSAGE D'ERREUR -->
            <?php if ($erreur !== ''): ?>
                <div class="bg-red-50 border border-red-200
                            text-red-700 rounded-xl p-4 mb-5
                            text-sm flex gap-3 items-start">
                    <i data-lucide="triangle-alert" class="h-5 w-5 shrink-0" aria-hidden="true"></i>
                    <p><?= securiser($erreur) ?></p>
                </div>
            <?php endif; ?>

            <!-- FORMULAIRE -->
            <form method="POST" action="" class="space-y-5" autocomplete="on">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= securiser(genererTokenCSRF()) ?>">

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">
                        Nom d'utilisateur
                    </label>

                    <input type="text"
                           name="nom_utilisateur"
                           value="<?= securiser($nom_utilisateur) ?>"
                           placeholder="Entrez votre identifiant"
                           required
                           autocomplete="username"
                           autofocus
                           class="w-full px-4 py-3.5
                                  border border-slate-200
                                  rounded-xl outline-none
                                  focus:ring-2 focus:ring-sky-400
                                  focus:border-sky-500
                                  bg-slate-50 focus:bg-white
                                  transition">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">
                        Mot de passe
                    </label>

                    <div class="relative">
                        <input type="password"
                               id="mot_de_passe"
                               name="mot_de_passe"
                               placeholder="Entrez votre mot de passe"
                               required
                               autocomplete="current-password"
                               class="w-full px-4 pr-24 py-3.5
                                      border border-slate-200
                                      rounded-xl outline-none
                                      focus:ring-2 focus:ring-sky-400
                                      focus:border-sky-500
                                      bg-slate-50 focus:bg-white
                                      transition">

                        <button type="button"
                                onclick="afficherMotDePasse()"
                                class="absolute right-3 top-1/2
                                       -translate-y-1/2
                                       text-xs font-semibold
                                       text-sky-600 hover:text-sky-800
                                       bg-sky-50 hover:bg-sky-100
                                       px-3 py-1.5 rounded-lg transition"
                                id="btn_mdp">
                            Afficher
                        </button>
                    </div>
                </div>

                <button type="submit"
                        class="w-full bg-sky-600 hover:bg-sky-700
                               text-white font-bold py-3.5
                               rounded-xl shadow-lg shadow-sky-100
                               transition duration-200
                               hover:-translate-y-0.5">
                    Se connecter →
                </button>

            </form>

            <div class="mt-8 text-center">

                <p class="text-xs text-slate-400">
                    Accès réservé au personnel autorisé.
                </p>

                <p class="text-xs text-slate-400 mt-2">
                    Système de gestion sécurisé
                </p>

            </div>

        </div>

    </div>

    <script>
        function afficherMotDePasse() {
            const champ = document.getElementById("mot_de_passe");
            const btn = document.getElementById("btn_mdp");

            if (champ.type === "password") {
                champ.type = "text";
                btn.textContent = "Masquer";
            } else {
                champ.type = "password";
                btn.textContent = "Afficher";
            }
        }
    </script>

<script src="icons.js"></script>
</body>
</html>
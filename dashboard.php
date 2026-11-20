<?php
require_once "config.php";
exigerConnexion();

/* =====================================================
   1. INFORMATIONS UTILISATEUR
===================================================== */

$utilisateur = utilisateurConnecte();
$agenceId    = (int)($_SESSION['agence_id'] ?? 0);

$nomUtilisateur = trim(
    ($utilisateur['nom'] ?? '') . ' ' .
    ($utilisateur['postnom'] ?? '') . ' ' .
    ($utilisateur['prenom'] ?? '')
);

if ($nomUtilisateur === '') {
    $nomUtilisateur = $_SESSION['nom_utilisateur'] ?? 'Utilisateur';
}

$nomAgence = $_SESSION['nom_agence'] ?? NOM_APPLICATION;
$role      = $_SESSION['role'] ?? 'Agent';

/* =====================================================
   2. FONCTIONS STATISTIQUES
===================================================== */

function dashNombre(PDO $pdo, string $table, int $agenceId): int
{
    $tablesAutorisees = [
        'voyages', 'vehicules', 'chauffeurs',
        'voyageurs', 'colis', 'billets'
    ];

    if (!in_array($table, $tablesAutorisees, true)) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE agence_id = ?");
        $stmt->execute([$agenceId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function dashMontant(PDO $pdo, string $sql, array $params = []): float
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function dashFC(float $montant): string
{
    return number_format($montant, 2, ',', ' ') . ' FC';
}

function dashUSD(float $montant): string
{
    return number_format($montant, 2, ',', ' ') . ' USD';
}

/* =====================================================
   3. STATISTIQUES GENERALES
===================================================== */

$totalVoyages    = dashNombre($pdo, 'voyages', $agenceId);
$totalVehicules  = dashNombre($pdo, 'vehicules', $agenceId);
$totalChauffeurs = dashNombre($pdo, 'chauffeurs', $agenceId);
$totalVoyageurs  = dashNombre($pdo, 'voyageurs', $agenceId);
$totalColis      = dashNombre($pdo, 'colis', $agenceId);
$totalBillets    = dashNombre($pdo, 'billets', $agenceId);

// Agences : total global (pas filtré par agence)
$totalAgences = 0;
try {
    $totalAgences = (int)$pdo->query("SELECT COUNT(*) FROM agences")->fetchColumn();
} catch (Throwable $e) {
    $totalAgences = 0;
}

/* =====================================================
   4. RECETTES BILLETS
===================================================== */

$recettesBilletsFC = dashMontant(
    $pdo,
    "SELECT COALESCE(SUM(b.prix),0)
     FROM billets b
     INNER JOIN voyages v ON b.voyage_id = v.id
     WHERE v.agence_id = ?
       AND b.devise = 'FC'
       AND b.statut != 'Annule'",
    [$agenceId]
);

$recettesBilletsUSD = dashMontant(
    $pdo,
    "SELECT COALESCE(SUM(b.prix),0)
     FROM billets b
     INNER JOIN voyages v ON b.voyage_id = v.id
     WHERE v.agence_id = ?
       AND b.devise = 'USD'
       AND b.statut != 'Annule'",
    [$agenceId]
);

/* =====================================================
   5. RECETTES COLIS
===================================================== */

$recettesColisFC = dashMontant(
    $pdo,
    "SELECT COALESCE(SUM(prix),0)
     FROM colis
     WHERE agence_id = ?
       AND devise = 'FC'
       AND statut != 'Annule'",
    [$agenceId]
);

$recettesColisUSD = dashMontant(
    $pdo,
    "SELECT COALESCE(SUM(prix),0)
     FROM colis
     WHERE agence_id = ?
       AND devise = 'USD'
       AND statut != 'Annule'",
    [$agenceId]
);

/* =====================================================
   6. DEPENSES
===================================================== */

$depensesFC = dashMontant(
    $pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM depenses
     WHERE agence_id = ?
       AND devise = 'FC'",
    [$agenceId]
);

$depensesUSD = dashMontant(
    $pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM depenses
     WHERE agence_id = ?
       AND devise = 'USD'",
    [$agenceId]
);

/* =====================================================
   7. CAISSE
===================================================== */

$entreesFC = dashMontant(
    $pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM caisse
     WHERE agence_id = ?
       AND type_mouvement = 'Entree'
       AND devise = 'FC'",
    [$agenceId]
);

$sortiesFC = dashMontant(
    $pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM caisse
     WHERE agence_id = ?
       AND type_mouvement = 'Sortie'
       AND devise = 'FC'",
    [$agenceId]
);

$entreesUSD = dashMontant(
    $pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM caisse
     WHERE agence_id = ?
       AND type_mouvement = 'Entree'
       AND devise = 'USD'",
    [$agenceId]
);

$sortiesUSD = dashMontant(
    $pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM caisse
     WHERE agence_id = ?
       AND type_mouvement = 'Sortie'
       AND devise = 'USD'",
    [$agenceId]
);

$soldeFC  = $entreesFC  - $sortiesFC;
$soldeUSD = $entreesUSD - $sortiesUSD;

/* =====================================================
   8. STATS 7 DERNIERS JOURS (mini graphique)
===================================================== */

$stats7j = [];

try {
    $stmt = $pdo->prepare("
        SELECT DATE(b.date_enregistrement) AS jour,
               COUNT(*) AS nb,
               COALESCE(SUM(b.prix), 0) AS total
        FROM billets b
        INNER JOIN voyages v ON v.id = b.voyage_id
        WHERE v.agence_id = ?
          AND b.date_enregistrement >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          AND b.statut != 'Annule'
        GROUP BY DATE(b.date_enregistrement)
        ORDER BY jour ASC
    ");
    $stmt->execute([$agenceId]);
    $stats7j = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $stats7j = [];
}

// Construire une map jour => nb
$ventesParJour = [];
foreach ($stats7j as $s) {
    $ventesParJour[$s['jour']] = (int)$s['nb'];
}

// Générer 7 jours
$jours7 = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $jours7[$date] = $ventesParJour[$date] ?? 0;
}

$maxVentes = max($jours7) ?: 1;

/* =====================================================
   9. DERNIERS VOYAGES
===================================================== */

$derniersVoyages = [];
try {
    $stmt = $pdo->prepare("
        SELECT v.*,
               d.nom_milieu AS depart,
               a.nom_milieu AS arrivee
        FROM voyages v
        INNER JOIN milieux d ON d.id = v.milieu_depart_id
        INNER JOIN milieux a ON a.id = v.milieu_arrivee_id
        WHERE v.agence_id = ?
        ORDER BY v.id DESC
        LIMIT 5
    ");
    $stmt->execute([$agenceId]);
    $derniersVoyages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $derniersVoyages = [];
}

/* =====================================================
   10. DERNIERS COLIS
===================================================== */

$derniersColis = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*,
               d.nom_milieu AS depart,
               a.nom_milieu AS arrivee,
               exp.nom_complet AS expediteur,
               dest.nom_complet AS destinataire
        FROM colis c
        INNER JOIN milieux d ON d.id = c.milieu_depart_id
        INNER JOIN milieux a ON a.id = c.milieu_arrivee_id
        INNER JOIN expediteurs exp ON exp.id = c.expediteur_id
        INNER JOIN destinataires dest ON dest.id = c.destinataire_id
        WHERE c.agence_id = ?
        ORDER BY c.id DESC
        LIMIT 5
    ");
    $stmt->execute([$agenceId]);
    $derniersColis = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $derniersColis = [];
}

/* =====================================================
   11. HEADER COMMUN
===================================================== */

$titrePage  = 'Tableau de bord';
$sousTitre  = 'Bienvenue, ' . $nomUtilisateur;
$pageActive = 'dashboard.php';

require 'header.php';
?>

<!-- BANNIERE D'ACCUEIL -->
<section class="bg-gradient-to-r from-sky-600 to-blue-700 text-white rounded-2xl p-6 md:p-8 mb-8 shadow-md">

    <p class="text-sm text-sky-100 mb-2">Espace de gestion</p>

    <h3 class="text-xl md:text-2xl font-bold mb-3">
        Bonjour <?= securiser($nomUtilisateur) ?>
    </h3>

    <p class="text-sky-50 text-sm md:text-base max-w-3xl">
        Gérez vos voyages, vos billets, vos colis, vos chauffeurs
        et vos finances depuis un seul endroit.
    </p>

    <div class="mt-6 flex flex-wrap gap-3">

        <a href="voyages.php"
           class="bg-white text-sky-700 px-5 py-3 rounded-lg font-semibold hover:bg-sky-50 text-sm">
            Nouveau voyage
        </a>

        <a href="billets.php"
           class="bg-sky-800 text-white px-5 py-3 rounded-lg font-semibold hover:bg-sky-900 text-sm border border-sky-400">
            Nouveau billet
        </a>

        <a href="colis.php"
           class="bg-sky-800 text-white px-5 py-3 rounded-lg font-semibold hover:bg-sky-900 text-sm border border-sky-400">
            Enregistrer un colis
        </a>

    </div>
</section>

<!-- STATISTIQUES GENERALES -->
<section class="mb-8">

    <div class="mb-5">
        <h3 class="text-lg font-bold text-slate-800">Vue générale</h3>
        <p class="text-sm text-slate-500">Situation de votre agence</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">

        <div class="carte bg-white rounded-xl p-5 shadow-sm border border-slate-200">
            <p class="text-sm text-slate-500">Total des voyages</p>
            <h3 class="text-3xl font-bold text-slate-800 mt-3"><?= $totalVoyages ?></h3>
            <a href="voyages.php" class="text-sky-700 text-sm font-semibold mt-4 inline-block">
                Voir les voyages →
            </a>
        </div>

        <div class="carte bg-white rounded-xl p-5 shadow-sm border border-slate-200">
            <p class="text-sm text-slate-500">Véhicules</p>
            <h3 class="text-3xl font-bold text-slate-800 mt-3"><?= $totalVehicules ?></h3>
            <a href="vehicules.php" class="text-sky-700 text-sm font-semibold mt-4 inline-block">
                Voir les véhicules →
            </a>
        </div>

        <div class="carte bg-white rounded-xl p-5 shadow-sm border border-slate-200">
            <p class="text-sm text-slate-500">Chauffeurs</p>
            <h3 class="text-3xl font-bold text-slate-800 mt-3"><?= $totalChauffeurs ?></h3>
            <a href="chauffeurs.php" class="text-sky-700 text-sm font-semibold mt-4 inline-block">
                Voir les chauffeurs →
            </a>
        </div>

        <div class="carte bg-white rounded-xl p-5 shadow-sm border border-slate-200">
            <p class="text-sm text-slate-500">Voyageurs enregistrés</p>
            <h3 class="text-3xl font-bold text-slate-800 mt-3"><?= $totalVoyageurs ?></h3>
            <a href="voyageurs.php" class="text-sky-700 text-sm font-semibold mt-4 inline-block">
                Voir les voyageurs →
            </a>
        </div>

        <div class="carte bg-white rounded-xl p-5 shadow-sm border border-slate-200">
            <p class="text-sm text-slate-500">Colis enregistrés</p>
            <h3 class="text-3xl font-bold text-slate-800 mt-3"><?= $totalColis ?></h3>
            <a href="colis.php" class="text-sky-700 text-sm font-semibold mt-4 inline-block">
                Voir les colis →
            </a>
        </div>

        <div class="carte bg-white rounded-xl p-5 shadow-sm border border-slate-200">
            <p class="text-sm text-slate-500">Billets enregistrés</p>
            <h3 class="text-3xl font-bold text-slate-800 mt-3"><?= $totalBillets ?></h3>
            <a href="billets.php" class="text-sky-700 text-sm font-semibold mt-4 inline-block">
                Voir les billets →
            </a>
        </div>

    </div>
</section>

<!-- RESUME FINANCIER -->
<section class="mb-8">

    <div class="mb-5">
        <h3 class="text-lg font-bold text-slate-800">Résumé financier</h3>
        <p class="text-sm text-slate-500">Recettes, dépenses et soldes de caisse</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">

        <div class="bg-white rounded-xl p-5 shadow-sm border-l-4 border-sky-500">
            <p class="text-sm text-slate-500">Recettes billets (FC)</p>
            <h3 class="text-xl md:text-2xl font-bold text-sky-700 mt-3">
                <?= dashFC($recettesBilletsFC) ?>
            </h3>
        </div>

        <div class="bg-white rounded-xl p-5 shadow-sm border-l-4 border-blue-500">
            <p class="text-sm text-slate-500">Recettes billets (USD)</p>
            <h3 class="text-xl md:text-2xl font-bold text-blue-700 mt-3">
                <?= dashUSD($recettesBilletsUSD) ?>
            </h3>
        </div>

        <div class="bg-white rounded-xl p-5 shadow-sm border-l-4 border-cyan-500">
            <p class="text-sm text-slate-500">Recettes colis (FC)</p>
            <h3 class="text-xl md:text-2xl font-bold text-cyan-700 mt-3">
                <?= dashFC($recettesColisFC) ?>
            </h3>
        </div>

        <div class="bg-white rounded-xl p-5 shadow-sm border-l-4 border-indigo-500">
            <p class="text-sm text-slate-500">Recettes colis (USD)</p>
            <h3 class="text-xl md:text-2xl font-bold text-indigo-700 mt-3">
                <?= dashUSD($recettesColisUSD) ?>
            </h3>
        </div>

        <div class="bg-white rounded-xl p-5 shadow-sm border-l-4 border-red-400">
            <p class="text-sm text-slate-500">Dépenses (FC)</p>
            <h3 class="text-xl md:text-2xl font-bold text-red-600 mt-3">
                <?= dashFC($depensesFC) ?>
            </h3>
        </div>

        <div class="bg-white rounded-xl p-5 shadow-sm border-l-4 border-red-400">
            <p class="text-sm text-slate-500">Dépenses (USD)</p>
            <h3 class="text-xl md:text-2xl font-bold text-red-600 mt-3">
                <?= dashUSD($depensesUSD) ?>
            </h3>
        </div>

        <div class="bg-white rounded-xl p-5 shadow-sm border-l-4 border-emerald-500">
            <p class="text-sm text-slate-500">Solde caisse (FC)</p>
            <h3 class="text-xl md:text-2xl font-bold <?= $soldeFC >= 0 ? 'text-emerald-700' : 'text-red-700' ?> mt-3">
                <?= dashFC($soldeFC) ?>
            </h3>
        </div>

        <div class="bg-white rounded-xl p-5 shadow-sm border-l-4 border-emerald-500">
            <p class="text-sm text-slate-500">Solde caisse (USD)</p>
            <h3 class="text-xl md:text-2xl font-bold <?= $soldeUSD >= 0 ? 'text-emerald-700' : 'text-red-700' ?> mt-3">
                <?= dashUSD($soldeUSD) ?>
            </h3>
        </div>

    </div>
</section>

<!-- GRAPHIQUE 7 JOURS -->
<section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-8">

    <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
        <div>
            <h3 class="font-bold text-slate-800 text-lg">Billets vendus (7 derniers jours)</h3>
            <p class="text-sm text-slate-500">Aperçu rapide de l'activité</p>
        </div>
        <a href="rapports.php" class="text-sky-700 text-sm font-semibold hover:underline">
            Voir les rapports →
        </a>
    </div>

    <div class="flex items-end justify-between gap-2 h-40">
        <?php foreach ($jours7 as $date => $nb): ?>
            <?php
            $hauteur = $maxVentes > 0 ? max(8, (int)round(($nb / $maxVentes) * 100)) : 8;
            ?>
            <div class="flex-1 flex flex-col items-center gap-2">
                <span class="text-xs font-semibold text-slate-600"><?= $nb ?></span>
                <div class="w-full bg-sky-100 rounded-t-md relative" style="height: 120px;">
                    <div class="absolute bottom-0 left-0 right-0 bg-sky-500 rounded-t-md"
                         style="height: <?= $hauteur ?>%;"></div>
                </div>
                <span class="text-xs text-slate-500 text-center">
                    <?= date('d/m', strtotime($date)) ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

</section>

<!-- DERNIERS VOYAGES -->
<section class="bg-white rounded-xl shadow-sm border border-slate-200 mb-8 overflow-hidden">

    <div class="p-5 border-b border-slate-100 flex flex-wrap justify-between items-center gap-3">
        <div>
            <h3 class="font-bold text-slate-800 text-lg">Derniers voyages</h3>
            <p class="text-sm text-slate-500 mt-1">Les cinq derniers voyages enregistrés</p>
        </div>
        <a href="voyages.php" class="text-sky-700 text-sm font-semibold hover:underline">
            Voir tous les voyages
        </a>
    </div>

    <div class="tableau-responsive">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="px-5 py-4">Code</th>
                    <th class="px-5 py-4">Trajet</th>
                    <th class="px-5 py-4">Date de départ</th>
                    <th class="px-5 py-4">Statut</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (!empty($derniersVoyages)): ?>
                    <?php foreach ($derniersVoyages as $v): ?>
                        <tr class="hover:bg-sky-50">
                            <td class="px-5 py-4 font-semibold text-slate-800">
                                <?= securiser($v['code_voyage']) ?>
                            </td>
                            <td class="px-5 py-4 text-slate-600">
                                <?= securiser($v['depart']) ?>
                                <span class="text-sky-500 mx-1">→</span>
                                <?= securiser($v['arrivee']) ?>
                            </td>
                            <td class="px-5 py-4 text-slate-600">
                                <?= date('d/m/Y H:i', strtotime($v['date_depart'])) ?>
                            </td>
                            <td class="px-5 py-4">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?php
                                    echo match($v['statut']) {
                                        'Programme' => 'bg-sky-100 text-sky-700',
                                        'En cours'  => 'bg-amber-100 text-amber-700',
                                        'Termine'   => 'bg-emerald-100 text-emerald-700',
                                        default     => 'bg-red-100 text-red-700',
                                    };
                                    ?>">
                                    <?= securiser($v['statut']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="px-5 py-10 text-center text-slate-400">
                            Aucun voyage enregistré pour le moment.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- DERNIERS COLIS -->
<section class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">

    <div class="p-5 border-b border-slate-100 flex flex-wrap justify-between items-center gap-3">
        <div>
            <h3 class="font-bold text-slate-800 text-lg">Derniers colis</h3>
            <p class="text-sm text-slate-500 mt-1">Les cinq derniers colis enregistrés</p>
        </div>
        <a href="colis.php" class="text-sky-700 text-sm font-semibold hover:underline">
            Voir tous les colis
        </a>
    </div>

    <div class="tableau-responsive">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="px-5 py-4">Code</th>
                    <th class="px-5 py-4">Trajet</th>
                    <th class="px-5 py-4">Expéditeur</th>
                    <th class="px-5 py-4">Destinataire</th>
                    <th class="px-5 py-4">Prix</th>
                    <th class="px-5 py-4">Statut</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (!empty($derniersColis)): ?>
                    <?php foreach ($derniersColis as $c): ?>
                        <tr class="hover:bg-sky-50">
                            <td class="px-5 py-4 font-semibold text-slate-800">
                                <?= securiser($c['code_colis']) ?>
                            </td>
                            <td class="px-5 py-4 text-slate-600 whitespace-nowrap">
                                <?= securiser($c['depart']) ?>
                                <span class="text-sky-500 mx-1">→</span>
                                <?= securiser($c['arrivee']) ?>
                            </td>
                            <td class="px-5 py-4 text-slate-600">
                                <?= securiser($c['expediteur']) ?>
                            </td>
                            <td class="px-5 py-4 text-slate-600">
                                <?= securiser($c['destinataire']) ?>
                            </td>
                            <td class="px-5 py-4 font-semibold text-slate-700 whitespace-nowrap">
                                <?= number_format((float)$c['prix'], 2, ',', ' ') ?>
                                <?= securiser($c['devise']) ?>
                            </td>
                            <td class="px-5 py-4">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?php
                                    echo match($c['statut']) {
                                        'Enregistre' => 'bg-sky-100 text-sky-700',
                                        'En transit' => 'bg-amber-100 text-amber-700',
                                        'Arrive'     => 'bg-cyan-100 text-cyan-700',
                                        'Retire'     => 'bg-emerald-100 text-emerald-700',
                                        default      => 'bg-red-100 text-red-700',
                                    };
                                    ?>">
                                    <?= securiser($c['statut']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="px-5 py-10 text-center text-slate-400">
                            Aucun colis enregistré pour le moment.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require 'footer.php'; ?>
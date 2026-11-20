<?php
require_once "config.php";
exigerConnexion();

$agenceId = (int)($_SESSION['agence_id'] ?? 0);

/* =====================================================
   1. PERIODE
===================================================== */

$periode = (int)($_GET['periode'] ?? 30); // jours

if (!in_array($periode, [7, 30, 90, 365], true)) {
    $periode = 30;
}

$dateFin   = date('Y-m-d 23:59:59');
$dateDebut = date('Y-m-d 00:00:00', strtotime("-{$periode} days"));

/* =====================================================
   2. DONNEES : VENTES BILLETS PAR JOUR
===================================================== */

$ventesParJour = [];

try {
    $stmt = $pdo->prepare("
        SELECT DATE(b.date_enregistrement) AS jour,
               COUNT(*) AS nb,
               COALESCE(SUM(b.prix), 0) AS total
        FROM billets b
        INNER JOIN voyages v ON v.id = b.voyage_id
        WHERE v.agence_id = ?
          AND b.date_enregistrement BETWEEN ? AND ?
          AND b.statut != 'Annule'
        GROUP BY DATE(b.date_enregistrement)
        ORDER BY jour ASC
    ");
    $stmt->execute([$agenceId, $dateDebut, $dateFin]);
    $ventesParJour = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $ventesParJour = [];
}

// Construire une série continue de jours
$serieJours = [];
$serieNbBillets = [];
$serieMontant = [];

$mapVentes = [];
foreach ($ventesParJour as $v) {
    $mapVentes[$v['jour']] = $v;
}

for ($i = $periode; $i >= 0; $i--) {
    $jour = date('Y-m-d', strtotime("-$i days"));
    $serieJours[]      = date('d/m', strtotime($jour));
    $serieNbBillets[]  = (int)($mapVentes[$jour]['nb'] ?? 0);
    $serieMontant[]    = (float)($mapVentes[$jour]['total'] ?? 0);
}

/* =====================================================
   3. DONNEES : REPARTITION PAR TYPE DE COLIS
===================================================== */

$colisParType = [];

try {
    $stmt = $pdo->prepare("
        SELECT t.nom_type, COUNT(*) AS nb
        FROM colis c
        INNER JOIN types_colis t ON t.id = c.type_colis_id
        WHERE c.agence_id = ?
          AND c.date_enregistrement BETWEEN ? AND ?
          AND c.statut != 'Annule'
        GROUP BY t.nom_type
        ORDER BY nb DESC
    ");
    $stmt->execute([$agenceId, $dateDebut, $dateFin]);
    $colisParType = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $colisParType = [];
}

$labelsTypesColis = array_column($colisParType, 'nom_type');
$valeursTypesColis = array_map('intval', array_column($colisParType, 'nb'));

/* =====================================================
   4. DONNEES : DEPENSES PAR CATEGORIE
===================================================== */

$depensesParCategorie = [];

try {
    $stmt = $pdo->prepare("
        SELECT categorie, COALESCE(SUM(montant), 0) AS total
        FROM depenses
        WHERE agence_id = ?
          AND date_depense BETWEEN ? AND ?
        GROUP BY categorie
        ORDER BY total DESC
    ");
    $stmt->execute([$agenceId, $dateDebut, $dateFin]);
    $depensesParCategorie = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $depensesParCategorie = [];
}

$labelsDepenses = array_map(fn($d) => $d['categorie'] ?: 'Autre', $depensesParCategorie);
$valeursDepenses = array_map(fn($d) => (float)$d['total'], $depensesParCategorie);

/* =====================================================
   5. DONNEES : RECETTES VS DEPENSES (par devise)
===================================================== */

function totalSimple(PDO $pdo, string $sql, array $params): float
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

$recettesBilletsFC = totalSimple($pdo,
    "SELECT COALESCE(SUM(b.prix),0)
     FROM billets b
     INNER JOIN voyages v ON v.id = b.voyage_id
     WHERE v.agence_id = ? AND b.devise = 'FC'
       AND b.statut != 'Annule'
       AND b.date_enregistrement BETWEEN ? AND ?",
    [$agenceId, $dateDebut, $dateFin]
);

$recettesBilletsUSD = totalSimple($pdo,
    "SELECT COALESCE(SUM(b.prix),0)
     FROM billets b
     INNER JOIN voyages v ON v.id = b.voyage_id
     WHERE v.agence_id = ? AND b.devise = 'USD'
       AND b.statut != 'Annule'
       AND b.date_enregistrement BETWEEN ? AND ?",
    [$agenceId, $dateDebut, $dateFin]
);

$recettesColisFC = totalSimple($pdo,
    "SELECT COALESCE(SUM(prix),0) FROM colis
     WHERE agence_id = ? AND devise = 'FC' AND statut != 'Annule'
       AND date_enregistrement BETWEEN ? AND ?",
    [$agenceId, $dateDebut, $dateFin]
);

$recettesColisUSD = totalSimple($pdo,
    "SELECT COALESCE(SUM(prix),0) FROM colis
     WHERE agence_id = ? AND devise = 'USD' AND statut != 'Annule'
       AND date_enregistrement BETWEEN ? AND ?",
    [$agenceId, $dateDebut, $dateFin]
);

$depensesFC = totalSimple($pdo,
    "SELECT COALESCE(SUM(montant),0) FROM depenses
     WHERE agence_id = ? AND devise = 'FC'
       AND date_depense BETWEEN ? AND ?",
    [$agenceId, $dateDebut, $dateFin]
);

$depensesUSD = totalSimple($pdo,
    "SELECT COALESCE(SUM(montant),0) FROM depenses
     WHERE agence_id = ? AND devise = 'USD'
       AND date_depense BETWEEN ? AND ?",
    [$agenceId, $dateDebut, $dateFin]
);

/* =====================================================
   6. DONNEES : TOP 5 TRAJETS
===================================================== */

$topTrajets = [];

try {
    $stmt = $pdo->prepare("
        SELECT d.nom_milieu AS depart, a.nom_milieu AS arrivee,
               COUNT(b.id) AS nb
        FROM billets b
        INNER JOIN voyages v ON v.id = b.voyage_id
        INNER JOIN milieux d ON d.id = v.milieu_depart_id
        INNER JOIN milieux a ON a.id = v.milieu_arrivee_id
        WHERE v.agence_id = ?
          AND b.date_enregistrement BETWEEN ? AND ?
          AND b.statut != 'Annule'
        GROUP BY d.nom_milieu, a.nom_milieu
        ORDER BY nb DESC
        LIMIT 5
    ");
    $stmt->execute([$agenceId, $dateDebut, $dateFin]);
    $topTrajets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $topTrajets = [];
}

$labelsTrajets = array_map(fn($t) => $t['depart'] . ' → ' . $t['arrivee'], $topTrajets);
$valeursTrajets = array_map(fn($t) => (int)$t['nb'], $topTrajets);

/* =====================================================
   7. DONNEES : STATUTS BILLETS ET COLIS
===================================================== */

$statutsBillets = ['Reserve', 'Valide', 'Annule'];
$valeursStatutsBillets = [];

foreach ($statutsBillets as $s) {
    $valeursStatutsBillets[] = (int)totalSimple($pdo,
        "SELECT COUNT(*) FROM billets b
         INNER JOIN voyages v ON v.id = b.voyage_id
         WHERE v.agence_id = ? AND b.statut = ?
           AND b.date_enregistrement BETWEEN ? AND ?",
        [$agenceId, $s, $dateDebut, $dateFin]
    );
}

$statutsColis = ['Enregistre', 'En transit', 'Arrive', 'Retire', 'Annule'];
$valeursStatutsColis = [];

foreach ($statutsColis as $s) {
    $valeursStatutsColis[] = (int)totalSimple($pdo,
        "SELECT COUNT(*) FROM colis
         WHERE agence_id = ? AND statut = ?
           AND date_enregistrement BETWEEN ? AND ?",
        [$agenceId, $s, $dateDebut, $dateFin]
    );
}

/* =====================================================
   8. HEADER
===================================================== */

$titrePage  = 'Statistiques avancées';
$sousTitre  = "Analyse détaillée sur les {$periode} derniers jours.";
$pageActive = 'statistiques.php';

require 'header.php';
?>

<!-- SELECTEUR DE PERIODE -->
<section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6">
    <form method="GET" class="flex flex-wrap items-center gap-3">
        <label class="text-sm font-medium text-slate-700">Période :</label>

        <?php foreach ([7 => '7 jours', 30 => '30 jours', 90 => '90 jours', 365 => '1 an'] as $j => $label): ?>
            <a href="?periode=<?= $j ?>"
               class="px-4 py-2 rounded-lg text-sm font-semibold transition
                      <?= $periode === $j
                          ? 'bg-sky-700 text-white'
                          : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                <?= $label ?>
            </a>
        <?php endforeach; ?>
    </form>
</section>

<!-- CARTES RESUME -->
<section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5 mb-6">

    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-sky-500">
        <p class="text-sm text-slate-500">Recettes billets (FC)</p>
        <p class="text-xl font-bold text-sky-700 mt-2">
            <?= number_format($recettesBilletsFC, 2, ',', ' ') ?> FC
        </p>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-blue-500">
        <p class="text-sm text-slate-500">Recettes billets (USD)</p>
        <p class="text-xl font-bold text-blue-700 mt-2">
            <?= number_format($recettesBilletsUSD, 2, ',', ' ') ?> USD
        </p>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-cyan-500">
        <p class="text-sm text-slate-500">Recettes colis (FC)</p>
        <p class="text-xl font-bold text-cyan-700 mt-2">
            <?= number_format($recettesColisFC, 2, ',', ' ') ?> FC
        </p>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-indigo-500">
        <p class="text-sm text-slate-500">Recettes colis (USD)</p>
        <p class="text-xl font-bold text-indigo-700 mt-2">
            <?= number_format($recettesColisUSD, 2, ',', ' ') ?> USD
        </p>
    </div>

</section>

<!-- GRAPHIQUE 1 : EVOLUTION DES VENTES -->
<section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6">
    <h3 class="font-bold text-slate-800 mb-4">Évolution des ventes de billets</h3>
    <div class="relative" style="height: 320px;">
        <canvas id="graphVentes"></canvas>
    </div>
</section>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

    <!-- GRAPHIQUE 2 : REPARTITION COLIS -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="font-bold text-slate-800 mb-4">Colis par type</h3>
        <div class="relative" style="height: 300px;">
            <canvas id="graphColisTypes"></canvas>
        </div>
    </section>

    <!-- GRAPHIQUE 3 : STATUTS BILLETS -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="font-bold text-slate-800 mb-4">Statuts des billets</h3>
        <div class="relative" style="height: 300px;">
            <canvas id="graphStatutsBillets"></canvas>
        </div>
    </section>

</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

    <!-- GRAPHIQUE 4 : DEPENSES PAR CATEGORIE -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="font-bold text-slate-800 mb-4">Dépenses par catégorie</h3>
        <div class="relative" style="height: 300px;">
            <canvas id="graphDepenses"></canvas>
        </div>
    </section>

    <!-- GRAPHIQUE 5 : STATUTS COLIS -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="font-bold text-slate-800 mb-4">Statuts des colis</h3>
        <div class="relative" style="height: 300px;">
            <canvas id="graphStatutsColis"></canvas>
        </div>
    </section>

</div>

<!-- GRAPHIQUE 6 : TOP TRAJETS -->
<section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6">
    <h3 class="font-bold text-slate-800 mb-4">Top 5 des trajets les plus vendus</h3>
    <div class="relative" style="height: 300px;">
        <canvas id="graphTrajets"></canvas>
    </div>
</section>

<!-- RECETTES VS DEPENSES -->
<section class="grid grid-cols-1 md:grid-cols-2 gap-6">

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="font-bold text-slate-800 mb-4">Recettes vs Dépenses (FC)</h3>

        <div class="space-y-3 text-sm">
            <div class="flex justify-between">
                <span class="text-slate-600">Recettes</span>
                <span class="font-bold text-emerald-600">
                    + <?= number_format($recettesBilletsFC + $recettesColisFC, 2, ',', ' ') ?> FC
                </span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-600">Dépenses</span>
                <span class="font-bold text-red-600">
                    − <?= number_format($depensesFC, 2, ',', ' ') ?> FC
                </span>
            </div>
            <div class="flex justify-between pt-3 border-t-2 border-slate-300">
                <span class="font-bold">Résultat</span>
                <span class="font-bold text-lg <?= ($recettesBilletsFC + $recettesColisFC - $depensesFC) >= 0 ? 'text-emerald-700' : 'text-red-700' ?>">
                    <?= number_format($recettesBilletsFC + $recettesColisFC - $depensesFC, 2, ',', ' ') ?> FC
                </span>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="font-bold text-slate-800 mb-4">Recettes vs Dépenses (USD)</h3>

        <div class="space-y-3 text-sm">
            <div class="flex justify-between">
                <span class="text-slate-600">Recettes</span>
                <span class="font-bold text-emerald-600">
                    + <?= number_format($recettesBilletsUSD + $recettesColisUSD, 2, ',', ' ') ?> USD
                </span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-600">Dépenses</span>
                <span class="font-bold text-red-600">
                    − <?= number_format($depensesUSD, 2, ',', ' ') ?> USD
                </span>
            </div>
            <div class="flex justify-between pt-3 border-t-2 border-slate-300">
                <span class="font-bold">Résultat</span>
                <span class="font-bold text-lg <?= ($recettesBilletsUSD + $recettesColisUSD - $depensesUSD) >= 0 ? 'text-emerald-700' : 'text-red-700' ?>">
                    <?= number_format($recettesBilletsUSD + $recettesColisUSD - $depensesUSD, 2, ',', ' ') ?> USD
                </span>
            </div>
        </div>
    </div>

</section>

<!-- CHART.JS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
// =====================================================
// DONNEES PHP → JS
// =====================================================

const serieJours       = <?= json_encode($serieJours) ?>;
const serieNbBillets   = <?= json_encode($serieNbBillets) ?>;
const serieMontant     = <?= json_encode($serieMontant) ?>;

const labelsTypesColis = <?= json_encode($labelsTypesColis) ?>;
const valeursTypesColis = <?= json_encode($valeursTypesColis) ?>;

const statutsBilletsLabels = <?= json_encode($statutsBillets) ?>;
const statutsBilletsValeurs = <?= json_encode($valeursStatutsBillets) ?>;

const statutsColisLabels = <?= json_encode($statutsColis) ?>;
const statutsColisValeurs = <?= json_encode($valeursStatutsColis) ?>;

const labelsDepenses = <?= json_encode($labelsDepenses) ?>;
const valeursDepenses = <?= json_encode($valeursDepenses) ?>;

const labelsTrajets = <?= json_encode($labelsTrajets) ?>;
const valeursTrajets = <?= json_encode($valeursTrajets) ?>;

// =====================================================
// PALETTE BLEUE
// =====================================================

const paletteBleue = [
    '#0ea5e9', '#0284c7', '#0369a1', '#075985', '#38bdf8',
    '#7dd3fc', '#bae6fd', '#1e40af', '#3b82f6', '#60a5fa'
];

const optionCommune = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'bottom',
            labels: { font: { size: 12 }, padding: 12 }
        }
    }
};

// =====================================================
// 1. EVOLUTION VENTES
// =====================================================

new Chart(document.getElementById('graphVentes'), {
    type: 'line',
    data: {
        labels: serieJours,
        datasets: [
            {
                label: 'Nombre de billets',
                data: serieNbBillets,
                borderColor: '#0284c7',
                backgroundColor: 'rgba(2, 132, 199, 0.1)',
                tension: 0.35,
                fill: true,
                pointBackgroundColor: '#0284c7',
                pointRadius: 3,
                yAxisID: 'y'
            },
            {
                label: 'Montant (FC)',
                data: serieMontant,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.05)',
                tension: 0.35,
                fill: false,
                pointBackgroundColor: '#10b981',
                pointRadius: 3,
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        ...optionCommune,
        interaction: { mode: 'index', intersect: false },
        scales: {
            y: {
                type: 'linear',
                position: 'left',
                beginAtZero: true,
                title: { display: true, text: 'Billets' },
                grid: { color: 'rgba(0,0,0,.05)' }
            },
            y1: {
                type: 'linear',
                position: 'right',
                beginAtZero: true,
                title: { display: true, text: 'Montant (FC)' },
                grid: { drawOnChartArea: false }
            }
        }
    }
});

// =====================================================
// 2. COLIS PAR TYPE (DONUT)
// =====================================================

new Chart(document.getElementById('graphColisTypes'), {
    type: 'doughnut',
    data: {
        labels: labelsTypesColis,
        datasets: [{
            data: valeursTypesColis,
            backgroundColor: paletteBleue,
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        ...optionCommune,
        cutout: '55%'
    }
});

// =====================================================
// 3. STATUTS BILLETS (BARRES)
// =====================================================

new Chart(document.getElementById('graphStatutsBillets'), {
    type: 'bar',
    data: {
        labels: statutsBilletsLabels,
        datasets: [{
            label: 'Billets',
            data: statutsBilletsValeurs,
            backgroundColor: ['#0ea5e9', '#10b981', '#ef4444'],
            borderRadius: 6
        }]
    },
    options: {
        ...optionCommune,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { precision: 0 } }
        }
    }
});

// =====================================================
// 4. DEPENSES PAR CATEGORIE (HORIZONTAL BAR)
// =====================================================

new Chart(document.getElementById('graphDepenses'), {
    type: 'bar',
    data: {
        labels: labelsDepenses,
        datasets: [{
            label: 'Montant',
            data: valeursDepenses,
            backgroundColor: '#ef4444',
            borderRadius: 6
        }]
    },
    options: {
        ...optionCommune,
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true }
        }
    }
});

// =====================================================
// 5. STATUTS COLIS (POLAR)
// =====================================================

new Chart(document.getElementById('graphStatutsColis'), {
    type: 'polarArea',
    data: {
        labels: statutsColisLabels,
        datasets: [{
            data: statutsColisValeurs,
            backgroundColor: [
                '#38bdf8', '#fbbf24', '#22d3ee',
                '#10b981', '#ef4444'
            ]
        }]
    },
    options: optionCommune
});

// =====================================================
// 6. TOP TRAJETS (BARRES HORIZONTALES)
// =====================================================

new Chart(document.getElementById('graphTrajets'), {
    type: 'bar',
    data: {
        labels: labelsTrajets,
        datasets: [{
            label: 'Billets vendus',
            data: valeursTrajets,
            backgroundColor: '#0284c7',
            borderRadius: 6
        }]
    },
    options: {
        ...optionCommune,
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true, ticks: { precision: 0 } }
        }
    }
});
</script>

<?php require 'footer.php'; ?>
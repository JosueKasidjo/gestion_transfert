<?php
require_once "config.php";
exigerConnexion();

$agenceId = (int)($_SESSION['agence_id'] ?? 0);

/* =====================================================
   1. PERIODE
===================================================== */

$dateDebut = trim($_GET['date_debut'] ?? date('Y-m-01'));
$dateFin   = trim($_GET['date_fin'] ?? date('Y-m-d'));

// Validation basique
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDebut)) {
    $dateDebut = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFin)) {
    $dateFin = date('Y-m-d');
}

if ($dateDebut > $dateFin) {
    [$dateDebut, $dateFin] = [$dateFin, $dateDebut];
}

$dtDebut = $dateDebut . ' 00:00:00';
$dtFin   = $dateFin   . ' 23:59:59';

/* =====================================================
   2. FONCTION DE CALCUL
===================================================== */

function totalPeriode(PDO $pdo, string $sql, array $params): float
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/* =====================================================
   3. RECETTES BILLETS
===================================================== */

$recettesBilletsFC = totalPeriode($pdo,
    "SELECT COALESCE(SUM(b.prix),0)
     FROM billets b
     INNER JOIN voyages v ON v.id = b.voyage_id
     WHERE v.agence_id = ?
       AND b.devise = 'FC'
       AND b.statut != 'Annule'
       AND b.date_enregistrement BETWEEN ? AND ?",
    [$agenceId, $dtDebut, $dtFin]
);

$recettesBilletsUSD = totalPeriode($pdo,
    "SELECT COALESCE(SUM(b.prix),0)
     FROM billets b
     INNER JOIN voyages v ON v.id = b.voyage_id
     WHERE v.agence_id = ?
       AND b.devise = 'USD'
       AND b.statut != 'Annule'
       AND b.date_enregistrement BETWEEN ? AND ?",
    [$agenceId, $dtDebut, $dtFin]
);

/* =====================================================
   4. RECETTES COLIS
===================================================== */

$recettesColisFC = totalPeriode($pdo,
    "SELECT COALESCE(SUM(prix),0)
     FROM colis
     WHERE agence_id = ?
       AND devise = 'FC'
       AND statut != 'Annule'
       AND date_enregistrement BETWEEN ? AND ?",
    [$agenceId, $dtDebut, $dtFin]
);

$recettesColisUSD = totalPeriode($pdo,
    "SELECT COALESCE(SUM(prix),0)
     FROM colis
     WHERE agence_id = ?
       AND devise = 'USD'
       AND statut != 'Annule'
       AND date_enregistrement BETWEEN ? AND ?",
    [$agenceId, $dtDebut, $dtFin]
);

/* =====================================================
   5. DEPENSES
===================================================== */

$depensesFC = totalPeriode($pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM depenses
     WHERE agence_id = ? AND devise = 'FC'
       AND date_depense BETWEEN ? AND ?",
    [$agenceId, $dtDebut, $dtFin]
);

$depensesUSD = totalPeriode($pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM depenses
     WHERE agence_id = ? AND devise = 'USD'
       AND date_depense BETWEEN ? AND ?",
    [$agenceId, $dtDebut, $dtFin]
);

/* =====================================================
   6. CAISSE
===================================================== */

$caisseEntreesFC = totalPeriode($pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM caisse
     WHERE agence_id = ? AND type_mouvement = 'Entree'
       AND devise = 'FC'
       AND date_operation BETWEEN ? AND ?",
    [$agenceId, $dtDebut, $dtFin]
);

$caisseSortiesFC = totalPeriode($pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM caisse
     WHERE agence_id = ? AND type_mouvement = 'Sortie'
       AND devise = 'FC'
       AND date_operation BETWEEN ? AND ?",
    [$agenceId, $dtDebut, $dtFin]
);

$caisseEntreesUSD = totalPeriode($pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM caisse
     WHERE agence_id = ? AND type_mouvement = 'Entree'
       AND devise = 'USD'
       AND date_operation BETWEEN ? AND ?",
    [$agenceId, $dtDebut, $dtFin]
);

$caisseSortiesUSD = totalPeriode($pdo,
    "SELECT COALESCE(SUM(montant),0)
     FROM caisse
     WHERE agence_id = ? AND type_mouvement = 'Sortie'
       AND devise = 'USD'
       AND date_operation BETWEEN ? AND ?",
    [$agenceId, $dtDebut, $dtFin]
);

/* =====================================================
   7. BILANS
===================================================== */

$totalRecettesFC  = $recettesBilletsFC + $recettesColisFC;
$totalRecettesUSD = $recettesBilletsUSD + $recettesColisUSD;

$resultatFC  = $totalRecettesFC  - $depensesFC;
$resultatUSD = $totalRecettesUSD - $depensesUSD;

$soldeCaisseFC  = $caisseEntreesFC  - $caisseSortiesFC;
$soldeCaisseUSD = $caisseEntreesUSD - $caisseSortiesUSD;

/* =====================================================
   8. DEPENSES PAR CATEGORIE
===================================================== */

$stmt = $pdo->prepare("
    SELECT categorie, devise,
           SUM(montant) AS total,
           COUNT(*) AS nb
    FROM depenses
    WHERE agence_id = ?
      AND date_depense BETWEEN ? AND ?
    GROUP BY categorie, devise
    ORDER BY total DESC
");
$stmt->execute([$agenceId, $dtDebut, $dtFin]);
$depensesParCategorie = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   9. VENTES PAR JOUR (5 derniers jours de la période)
===================================================== */

$stmt = $pdo->prepare("
    SELECT DATE(date_enregistrement) AS jour,
           devise,
           COUNT(*) AS nb,
           SUM(prix) AS total
    FROM billets b
    INNER JOIN voyages v ON v.id = b.voyage_id
    WHERE v.agence_id = ?
      AND b.statut != 'Annule'
      AND b.date_enregistrement BETWEEN ? AND ?
    GROUP BY DATE(date_enregistrement), devise
    ORDER BY jour DESC
    LIMIT 10
");
$stmt->execute([$agenceId, $dtDebut, $dtFin]);
$ventesParJour = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   10. HEADER
===================================================== */

$titrePage  = 'Rapports financiers';
$sousTitre  = 'Analysez les recettes, dépenses et soldes sur une période.';
$pageActive = 'rapports.php';

require 'header.php';
?>

<!-- FILTRE PERIODE -->
<section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6">

    <form method="GET" class="flex flex-wrap items-end gap-3">

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Du</label>
            <input type="date" name="date_debut" value="<?= securiser($dateDebut) ?>"
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Au</label>
            <input type="date" name="date_fin" value="<?= securiser($dateFin) ?>"
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
        </div>

        <button type="submit"
                class="bg-sky-700 hover:bg-sky-800 text-white px-5 py-2.5 rounded-lg font-semibold text-sm">
            Générer le rapport
        </button>

        <button type="button" onclick="window.print()"
                class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-5 py-2.5 rounded-lg font-semibold text-sm">
            Imprimer
        </button>

    </form>
</section>

<!-- PERIODE AFFICHEE -->
<div class="bg-sky-50 border border-sky-200 text-sky-800 rounded-xl p-4 mb-6 text-sm">
    Période analysée : du <strong><?= date('d/m/Y', strtotime($dateDebut)) ?></strong>
    au <strong><?= date('d/m/Y', strtotime($dateFin)) ?></strong>
</div>

<!-- BILAN GENERAL -->
<section class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">

    <!-- FC -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <h3 class="font-bold text-slate-800 mb-4 flex items-center justify-between">
            Bilan en FC
            <span class="text-sm text-slate-400 font-normal">
                <?= $resultatFC >= 0 ? 'Bénéfice' : 'Perte' ?>
            </span>
        </h3>

        <div class="space-y-3 text-sm">
            <div class="flex justify-between">
                <span class="text-slate-600">Recettes billets</span>
                <span class="font-semibold"><?= number_format($recettesBilletsFC, 2, ',', ' ') ?> FC</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-600">Recettes colis</span>
                <span class="font-semibold"><?= number_format($recettesColisFC, 2, ',', ' ') ?> FC</span>
            </div>
            <div class="flex justify-between pt-2 border-t border-slate-200">
                <span class="font-semibold text-sky-700">Total recettes</span>
                <span class="font-bold text-sky-700"><?= number_format($totalRecettesFC, 2, ',', ' ') ?> FC</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-600">Dépenses</span>
                <span class="font-semibold text-red-600">− <?= number_format($depensesFC, 2, ',', ' ') ?> FC</span>
            </div>
            <div class="flex justify-between pt-3 border-t-2 border-slate-300">
                <span class="font-bold text-slate-800">Résultat</span>
                <span class="font-bold text-lg <?= $resultatFC >= 0 ? 'text-emerald-700' : 'text-red-700' ?>">
                    <?= number_format($resultatFC, 2, ',', ' ') ?> FC
                </span>
            </div>
        </div>
    </div>

    <!-- USD -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <h3 class="font-bold text-slate-800 mb-4 flex items-center justify-between">
            Bilan en USD
            <span class="text-sm text-slate-400 font-normal">
                <?= $resultatUSD >= 0 ? 'Bénéfice' : 'Perte' ?>
            </span>
        </h3>

        <div class="space-y-3 text-sm">
            <div class="flex justify-between">
                <span class="text-slate-600">Recettes billets</span>
                <span class="font-semibold"><?= number_format($recettesBilletsUSD, 2, ',', ' ') ?> USD</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-600">Recettes colis</span>
                <span class="font-semibold"><?= number_format($recettesColisUSD, 2, ',', ' ') ?> USD</span>
            </div>
            <div class="flex justify-between pt-2 border-t border-slate-200">
                <span class="font-semibold text-sky-700">Total recettes</span>
                <span class="font-bold text-sky-700"><?= number_format($totalRecettesUSD, 2, ',', ' ') ?> USD</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-600">Dépenses</span>
                <span class="font-semibold text-red-600">− <?= number_format($depensesUSD, 2, ',', ' ') ?> USD</span>
            </div>
            <div class="flex justify-between pt-3 border-t-2 border-slate-300">
                <span class="font-bold text-slate-800">Résultat</span>
                <span class="font-bold text-lg <?= $resultatUSD >= 0 ? 'text-emerald-700' : 'text-red-700' ?>">
                    <?= number_format($resultatUSD, 2, ',', ' ') ?> USD
                </span>
            </div>
        </div>
    </div>

</section>

<!-- CAISSE -->
<section class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
    <h3 class="font-bold text-slate-800 mb-4">Mouvements de caisse sur la période</h3>

    <div class="tableau-responsive">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="px-4 py-3">Devise</th>
                    <th class="px-4 py-3">Entrées</th>
                    <th class="px-4 py-3">Sorties</th>
                    <th class="px-4 py-3">Solde période</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr>
                    <td class="px-4 py-3 font-semibold">FC</td>
                    <td class="px-4 py-3 text-emerald-700">
                        + <?= number_format($caisseEntreesFC, 2, ',', ' ') ?>
                    </td>
                    <td class="px-4 py-3 text-red-700">
                        − <?= number_format($caisseSortiesFC, 2, ',', ' ') ?>
                    </td>
                    <td class="px-4 py-3 font-bold <?= $soldeCaisseFC >= 0 ? 'text-emerald-700' : 'text-red-700' ?>">
                        <?= number_format($soldeCaisseFC, 2, ',', ' ') ?> FC
                    </td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-semibold">USD</td>
                    <td class="px-4 py-3 text-emerald-700">
                        + <?= number_format($caisseEntreesUSD, 2, ',', ' ') ?>
                    </td>
                    <td class="px-4 py-3 text-red-700">
                        − <?= number_format($caisseSortiesUSD, 2, ',', ' ') ?>
                    </td>
                    <td class="px-4 py-3 font-bold <?= $soldeCaisseUSD >= 0 ? 'text-emerald-700' : 'text-red-700' ?>">
                        <?= number_format($soldeCaisseUSD, 2, ',', ' ') ?> USD
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<!-- DEPENSES PAR CATEGORIE -->
<section class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
    <h3 class="font-bold text-slate-800 mb-4">Dépenses par catégorie</h3>

    <?php if (empty($depensesParCategorie)): ?>
        <p class="text-center text-slate-500 py-6">Aucune dépense sur cette période.</p>
    <?php else: ?>

        <?php
        // Regroupement par catégorie
        $parCategorie = [];
        foreach ($depensesParCategorie as $d) {
            $cat = $d['categorie'] ?: 'Autre';
            if (!isset($parCategorie[$cat])) {
                $parCategorie[$cat] = ['FC' => 0, 'USD' => 0, 'nb' => 0];
            }
            $parCategorie[$cat][$d['devise']] += (float)$d['total'];
            $parCategorie[$cat]['nb'] += (int)$d['nb'];
        }
        ?>

        <div class="tableau-responsive">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-4 py-3">Catégorie</th>
                        <th class="px-4 py-3">Nombre</th>
                        <th class="px-4 py-3">Total FC</th>
                        <th class="px-4 py-3">Total USD</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($parCategorie as $cat => $data): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-semibold text-slate-800"><?= securiser($cat) ?></td>
                            <td class="px-4 py-3 text-slate-600"><?= $data['nb'] ?></td>
                            <td class="px-4 py-3 text-red-600">
                                <?= $data['FC'] > 0 ? number_format($data['FC'], 2, ',', ' ') . ' FC' : '—' ?>
                            </td>
                            <td class="px-4 py-3 text-red-600">
                                <?= $data['USD'] > 0 ? number_format($data['USD'], 2, ',', ' ') . ' USD' : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>
</section>

<!-- VENTES PAR JOUR -->
<section class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
    <h3 class="font-bold text-slate-800 mb-4">Ventes de billets par jour</h3>

    <?php if (empty($ventesParJour)): ?>
        <p class="text-center text-slate-500 py-6">Aucune vente sur cette période.</p>
    <?php else: ?>
        <div class="tableau-responsive">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Devise</th>
                        <th class="px-4 py-3">Nombre de billets</th>
                        <th class="px-4 py-3">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($ventesParJour as $v): ?>
                        <tr>
                            <td class="px-4 py-3 text-slate-600">
                                <?= date('d/m/Y', strtotime($v['jour'])) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600"><?= securiser($v['devise']) ?></td>
                            <td class="px-4 py-3 text-slate-700 font-semibold"><?= (int)$v['nb'] ?></td>
                            <td class="px-4 py-3 font-semibold text-sky-700">
                                <?= number_format((float)$v['total'], 2, ',', ' ') ?>
                                <?= securiser($v['devise']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<style>
@media print {
    .sidebar, header, footer, button, .overlay-menu {
        display: none !important;
    }
    .contenu-principal {
        margin-left: 0 !important;
        width: 100% !important;
    }
    body {
        background: white !important;
    }
}
</style>

<?php require 'footer.php'; ?>
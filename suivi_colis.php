<?php
require_once "config.php";
exigerConnexion();

$agenceId = (int)($_SESSION['agence_id'] ?? 0);

/* =====================================================
   1. RECHERCHE
===================================================== */

$codeRecherche = trim($_GET['code'] ?? '');
$colisTrouve   = null;
$historique    = [];
$message       = '';
$typeMessage   = 'success';

if ($codeRecherche !== '') {

    try {

        // Recherche du colis (limité à l'agence pour sécurité)
        $stmt = $pdo->prepare("
            SELECT c.*,
                   t.nom_type, t.mode_tarification,
                   d.nom_milieu AS depart,
                   a.nom_milieu AS arrivee,
                   exp.nom_complet AS expediteur,
                   exp.telephone AS expediteur_tel,
                   dest.nom_complet AS destinataire,
                   dest.telephone AS destinataire_tel,
                   v.code_voyage,
                   v.date_depart
            FROM colis c
            INNER JOIN types_colis t ON t.id = c.type_colis_id
            INNER JOIN milieux d ON d.id = c.milieu_depart_id
            INNER JOIN milieux a ON a.id = c.milieu_arrivee_id
            INNER JOIN expediteurs exp ON exp.id = c.expediteur_id
            INNER JOIN destinataires dest ON dest.id = c.destinataire_id
            LEFT JOIN voyages v ON v.id = c.voyage_id
            WHERE c.code_colis = ? AND c.agence_id = ?
            LIMIT 1
        ");

        $stmt->execute([$codeRecherche, $agenceId]);
        $colisTrouve = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$colisTrouve) {
            $message = "Aucun colis trouvé avec le code « " . securiser($codeRecherche) . " ».";
            $typeMessage = 'error';
        }

    } catch (PDOException $e) {
        $message = "Erreur : " . $e->getMessage();
        $typeMessage = 'error';
    }
}

/* =====================================================
   2. DERNIERS COLIS (aperçu)
===================================================== */

$stmt = $pdo->prepare("
    SELECT c.id, c.code_colis, c.statut, c.prix, c.devise, c.date_enregistrement,
           d.nom_milieu AS depart, a.nom_milieu AS arrivee,
           dest.nom_complet AS destinataire
    FROM colis c
    INNER JOIN milieux d ON d.id = c.milieu_depart_id
    INNER JOIN milieux a ON a.id = c.milieu_arrivee_id
    INNER JOIN destinataires dest ON dest.id = c.destinataire_id
    WHERE c.agence_id = ?
    ORDER BY c.id DESC
    LIMIT 8
");
$stmt->execute([$agenceId]);
$derniersColis = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   3. HEADER
===================================================== */

$titrePage  = 'Suivi des colis';
$sousTitre  = 'Recherchez un colis par son code pour suivre son état.';
$pageActive = 'suivi_colis.php';

require 'header.php';
?>

<?php if ($message !== ''): ?>
    <div class="mb-5 p-4 rounded-xl border
        <?= $typeMessage === 'success'
            ? 'bg-green-50 text-green-800 border-green-200'
            : 'bg-red-50 text-red-800 border-red-200' ?>">
        <?= securiser($message) ?>
    </div>
<?php endif; ?>

<!-- RECHERCHE -->
<section class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
    <h3 class="text-lg font-bold text-slate-800 mb-4">Rechercher un colis</h3>

    <form method="GET" action="suivi_colis.php" class="flex flex-col sm:flex-row gap-3">
        <input type="text" name="code" required
               value="<?= securiser($codeRecherche) ?>"
               placeholder="Entrez le code du colis (ex : COL-20250101-ABCD1234)"
               class="flex-1 border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
        <button type="submit"
                class="bg-sky-700 hover:bg-sky-800 text-white font-semibold px-6 py-3 rounded-lg">
            Suivre
        </button>
    </form>
</section>

<?php if ($colisTrouve): ?>

    <!-- FICHE COLIS -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">

        <div class="bg-gradient-to-r from-sky-600 to-blue-700 text-white p-6">
            <p class="text-sm text-sky-100 mb-1">Code colis</p>
            <h2 class="text-2xl font-bold mb-3"><?= securiser($colisTrouve['code_colis']) ?></h2>

            <div class="flex flex-wrap gap-2">
                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-white/20">
                    <?= securiser($colisTrouve['nom_type']) ?>
                </span>
                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-white/20">
                    <?= securiser($colisTrouve['statut']) ?>
                </span>
            </div>
        </div>

        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- Trajet -->
            <div>
                <h4 class="text-sm font-semibold text-slate-500 uppercase mb-3">Trajet</h4>
                <div class="flex items-center gap-3 text-lg font-bold text-slate-800">
                    <span><?= securiser($colisTrouve['depart']) ?></span>
                    <span class="text-sky-500">→</span>
                    <span><?= securiser($colisTrouve['arrivee']) ?></span>
                </div>
                <?php if (!empty($colisTrouve['code_voyage'])): ?>
                    <p class="text-sm text-slate-500 mt-2">
                        Voyage : <?= securiser($colisTrouve['code_voyage']) ?>
                        <?php if ($colisTrouve['date_depart']): ?>
                            — <?= date('d/m/Y H:i', strtotime($colisTrouve['date_depart'])) ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Prix -->
            <div>
                <h4 class="text-sm font-semibold text-slate-500 uppercase mb-3">Prix</h4>
                <p class="text-2xl font-bold text-sky-700">
                    <?= number_format((float)$colisTrouve['prix'], 2, ',', ' ') ?>
                    <?= securiser($colisTrouve['devise']) ?>
                </p>
                <?php if ($colisTrouve['mode_tarification'] === 'Kilogramme'): ?>
                    <p class="text-sm text-slate-500 mt-2">
                        <?= number_format((float)$colisTrouve['poids'], 2, ',', ' ') ?> kg
                        × <?= number_format((float)$colisTrouve['prix_unitaire_kg'], 2, ',', ' ') ?>
                        <?= securiser($colisTrouve['devise']) ?>/kg
                    </p>
                <?php endif; ?>
            </div>

            <!-- Expéditeur -->
            <div>
                <h4 class="text-sm font-semibold text-slate-500 uppercase mb-3">Expéditeur</h4>
                <p class="font-semibold text-slate-800"><?= securiser($colisTrouve['expediteur']) ?></p>
                <p class="text-sm text-slate-600"><?= securiser($colisTrouve['expediteur_tel']) ?></p>
            </div>

            <!-- Destinataire -->
            <div>
                <h4 class="text-sm font-semibold text-slate-500 uppercase mb-3">Destinataire</h4>
                <p class="font-semibold text-slate-800"><?= securiser($colisTrouve['destinataire']) ?></p>
                <p class="text-sm text-slate-600"><?= securiser($colisTrouve['destinataire_tel']) ?></p>
            </div>

            <!-- Description -->
            <?php if (!empty($colisTrouve['description'])): ?>
                <div class="md:col-span-2">
                    <h4 class="text-sm font-semibold text-slate-500 uppercase mb-3">Description</h4>
                    <p class="text-slate-700"><?= securiser($colisTrouve['description']) ?></p>
                </div>
            <?php endif; ?>

            <!-- Dates -->
            <div>
                <h4 class="text-sm font-semibold text-slate-500 uppercase mb-3">Date d'enregistrement</h4>
                <p class="text-slate-700">
                    <?= date('d/m/Y H:i', strtotime($colisTrouve['date_enregistrement'])) ?>
                </p>
            </div>

            <?php if (!empty($colisTrouve['date_retrait'])): ?>
                <div>
                    <h4 class="text-sm font-semibold text-slate-500 uppercase mb-3">Date de retrait</h4>
                    <p class="text-slate-700">
                        <?= date('d/m/Y H:i', strtotime($colisTrouve['date_retrait'])) ?>
                    </p>
                </div>
            <?php endif; ?>

        </div>

        <!-- Etapes -->
        <div class="border-t border-slate-200 p-6">
            <h4 class="text-sm font-semibold text-slate-500 uppercase mb-4">Progression</h4>

            <?php
            $etapes = ['Enregistre', 'En transit', 'Arrive', 'Retire'];
            $statutActuel = $colisTrouve['statut'];
            $indexActuel = array_search($statutActuel, $etapes, true);
            if ($indexActuel === false) $indexActuel = -1;
            ?>

            <?php if ($statutActuel === 'Annule'): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4">
                    <i data-lucide="triangle-alert" class="inline-block h-4 w-4 mr-1 align-middle" aria-hidden="true"></i>
                    Ce colis a été <strong>annulé</strong>.
                </div>
            <?php else: ?>
                <div class="flex items-center justify-between gap-2">
                    <?php foreach ($etapes as $i => $etape): ?>
                        <div class="flex-1 flex flex-col items-center text-center">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold
                                <?= $i <= $indexActuel
                                    ? 'bg-sky-600 text-white'
                                    : 'bg-slate-200 text-slate-500' ?>">
                                <?= $i + 1 ?>
                            </div>
                            <p class="text-xs font-semibold mt-2
                                <?= $i <= $indexActuel ? 'text-sky-700' : 'text-slate-400' ?>">
                                <?= securiser($etape) ?>
                            </p>
                        </div>
                        <?php if ($i < count($etapes) - 1): ?>
                            <div class="flex-shrink-0 h-0.5 w-6 md:w-12
                                <?= $i < $indexActuel ? 'bg-sky-600' : 'bg-slate-200' ?>">
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </section>

<?php endif; ?>

<!-- DERNIERS COLIS -->
<section class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="p-5 border-b border-slate-100">
        <h3 class="font-bold text-slate-800 text-lg">Derniers colis enregistrés</h3>
        <p class="text-sm text-slate-500 mt-1">Cliquez sur un code pour suivre un colis.</p>
    </div>

    <div class="tableau-responsive">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="px-4 py-3">Code</th>
                    <th class="px-4 py-3">Trajet</th>
                    <th class="px-4 py-3">Destinataire</th>
                    <th class="px-4 py-3">Statut</th>
                    <th class="px-4 py-3">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($derniersColis)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-10 text-slate-500">
                            Aucun colis enregistré.
                        </td>
                    </tr>
                <?php else: foreach ($derniersColis as $c): ?>
                    <tr class="hover:bg-sky-50 cursor-pointer"
                        onclick="window.location='suivi_colis.php?code=<?= urlencode($c['code_colis']) ?>'">
                        <td class="px-4 py-3 font-semibold text-sky-700">
                            <?= securiser($c['code_colis']) ?>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            <?= securiser($c['depart']) ?> → <?= securiser($c['arrivee']) ?>
                        </td>
                        <td class="px-4 py-3 text-slate-600"><?= securiser($c['destinataire']) ?></td>
                        <td class="px-4 py-3">
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
                        <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                            <?= date('d/m/Y', strtotime($c['date_enregistrement'])) ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require 'footer.php'; ?>
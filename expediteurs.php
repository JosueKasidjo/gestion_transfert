<?php
require_once "config.php";
exigerConnexion();

/* =====================================================
   1. SECURITE
===================================================== */

if (empty($_SESSION['csrf_expediteurs'])) {
    $_SESSION['csrf_expediteurs'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_expediteurs'];

function verifierCSRFExpediteurs($token): bool
{
    return isset($_SESSION['csrf_expediteurs'])
        && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_expediteurs'], $token);
}

$message = '';
$typeMessage = 'success';
$recherche = trim($_GET['recherche'] ?? '');

/* =====================================================
   2. SUPPRESSION (nettoyage uniquement)
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFExpediteurs($_POST['csrf_token'] ?? null)) {

        $message = "Formulaire expiré. Rechargez la page.";
        $typeMessage = 'error';

    } elseif (($_POST['action'] ?? '') === 'supprimer') {

        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM expediteurs WHERE id = ?");
                $stmt->execute([$id]);

                $ok = $stmt->rowCount() > 0;
                $message = $ok ? "Expéditeur supprimé." : "Expéditeur introuvable.";
                $typeMessage = $ok ? 'success' : 'error';

            } catch (PDOException $e) {
                $message = "Impossible de supprimer : cet expéditeur est lié à des colis.";
                $typeMessage = 'error';
            }
        }
    }
}

/* =====================================================
   3. LISTE EXPEDITEURS
===================================================== */

$sql = "SELECT e.*,
               (SELECT COUNT(*) FROM colis c WHERE c.expediteur_id = e.id) AS nb_colis
        FROM expediteurs e
        WHERE 1=1";
$params = [];

if ($recherche !== '') {
    $sql .= " AND (e.nom_complet LIKE ? OR e.telephone LIKE ?)";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
}

$sql .= " ORDER BY e.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expediteurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nombreExpediteurs = count($expediteurs);

/* =====================================================
   4. LISTE DESTINATAIRES
===================================================== */

$sqlD = "SELECT d.*,
                (SELECT COUNT(*) FROM colis c WHERE c.destinataire_id = d.id) AS nb_colis
         FROM destinataires d
         WHERE 1=1";
$paramsD = [];

if ($recherche !== '') {
    $sqlD .= " AND (d.nom_complet LIKE ? OR d.telephone LIKE ?)";
    $paramsD[] = "%$recherche%";
    $paramsD[] = "%$recherche%";
}

$sqlD .= " ORDER BY d.id DESC";

$stmt = $pdo->prepare($sqlD);
$stmt->execute($paramsD);
$destinataires = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   5. HEADER
===================================================== */

$titrePage  = 'Répertoire expéditeurs & destinataires';
$sousTitre  = 'Les personnes sont créées automatiquement lors de l\'enregistrement d\'un colis.';
$pageActive = 'expediteurs.php';

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

<!-- INFO -->
<div class="bg-sky-50 border border-sky-200 text-sky-800 rounded-xl p-4 mb-6 text-sm">
    <i data-lucide="lightbulb" class="inline-block h-4 w-4 mr-1 align-middle" aria-hidden="true"></i>
    Les expéditeurs et destinataires sont <strong>créés automatiquement</strong> quand vous enregistrez un colis.
    Cette page sert uniquement de <strong>répertoire consultable</strong>.
</div>

<!-- RECHERCHE -->
<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-6">
    <form method="GET" class="flex gap-2">
        <input type="text" name="recherche" value="<?= securiser($recherche) ?>"
               placeholder="Rechercher un nom ou un téléphone..."
               class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm">
        <button type="submit"
                class="bg-sky-700 hover:bg-sky-800 text-white px-4 py-2 rounded-lg text-sm">
            Rechercher
        </button>
    </form>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- EXPEDITEURS -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="text-lg font-bold text-slate-800 mb-4">
            Expéditeurs <span class="text-sm text-slate-500 font-normal">(<?= $nombreExpediteurs ?>)</span>
        </h3>

        <div class="tableau-responsive">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-3 py-3">Nom</th>
                        <th class="px-3 py-3">Téléphone</th>
                        <th class="px-3 py-3">Colis</th>
                        <th class="px-3 py-3 text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($expediteurs)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-8 text-slate-500">
                                Aucun expéditeur.
                            </td>
                        </tr>
                    <?php else: foreach ($expediteurs as $e): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-3 font-semibold text-slate-800">
                                <?= securiser($e['nom_complet']) ?>
                            </td>
                            <td class="px-3 py-3 text-slate-600"><?= securiser($e['telephone']) ?></td>
                            <td class="px-3 py-3">
                                <span class="px-2 py-1 rounded-full bg-sky-100 text-sky-700 text-xs font-semibold">
                                    <?= (int)$e['nb_colis'] ?>
                                </span>
                            </td>
                            <td class="px-3 py-3 text-center">
                                <?php if ((int)$e['nb_colis'] === 0): ?>
                                    <form method="POST" data-app-confirm="Supprimer cet expéditeur ?" data-app-confirm-title="Supprimer l’expéditeur" data-app-confirm-label="Supprimer">
                                        <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
                                        <input type="hidden" name="action" value="supprimer">
                                        <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                                        <button type="submit"
                                                class="bg-red-100 text-red-700 hover:bg-red-200 px-2 py-1 rounded text-xs">
                                            Supprimer
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-xs text-slate-400">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- DESTINATAIRES -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="text-lg font-bold text-slate-800 mb-4">
            Destinataires <span class="text-sm text-slate-500 font-normal">(<?= count($destinataires) ?>)</span>
        </h3>

        <div class="tableau-responsive">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-3 py-3">Nom</th>
                        <th class="px-3 py-3">Téléphone</th>
                        <th class="px-3 py-3">Colis</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($destinataires)): ?>
                        <tr>
                            <td colspan="3" class="text-center py-8 text-slate-500">
                                Aucun destinataire.
                            </td>
                        </tr>
                    <?php else: foreach ($destinataires as $d): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-3 font-semibold text-slate-800">
                                <?= securiser($d['nom_complet']) ?>
                            </td>
                            <td class="px-3 py-3 text-slate-600"><?= securiser($d['telephone']) ?></td>
                            <td class="px-3 py-3">
                                <span class="px-2 py-1 rounded-full bg-sky-100 text-sky-700 text-xs font-semibold">
                                    <?= (int)$d['nb_colis'] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>

<?php require 'footer.php'; ?>
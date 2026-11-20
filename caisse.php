<?php
require_once "config.php";
exigerConnexion();

$agenceId = (int)($_SESSION['agence_id'] ?? 0);
$utilisateurId = (int)($_SESSION['id_utilisateur'] ?? 0);

/* =====================================================
   1. SECURITE CSRF
===================================================== */

if (empty($_SESSION['csrf_caisse'])) {
    $_SESSION['csrf_caisse'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_caisse'];

function verifierCSRFCaisse($token): bool
{
    return isset($_SESSION['csrf_caisse'])
        && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_caisse'], $token);
}

$message = '';
$typeMessage = 'success';

/* =====================================================
   2. TRAITEMENT FORMULAIRES
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFCaisse($_POST['csrf_token'] ?? null)) {

        $message = "Formulaire expiré. Rechargez la page.";
        $typeMessage = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        /* ---------------- AJOUT MOUVEMENT ---------------- */

        if ($action === 'ajouter') {

            $typeMouvement = trim($_POST['type_mouvement'] ?? '');
            $libelle       = trim($_POST['libelle'] ?? '');
            $montant       = str_replace(',', '.', trim($_POST['montant'] ?? '0'));
            $devise        = strtoupper(trim($_POST['devise'] ?? 'FC'));
            $reference     = trim($_POST['reference'] ?? '');
            $dateOperation = trim($_POST['date_operation'] ?? '');

            if ($libelle === '') {

                $message = "Le libellé est obligatoire.";
                $typeMessage = 'error';

            } elseif (!in_array($typeMouvement, ['Entree', 'Sortie'], true)) {

                $message = "Type de mouvement invalide.";
                $typeMessage = 'error';

            } elseif (!is_numeric($montant) || (float)$montant <= 0) {

                $message = "Le montant doit être supérieur à zéro.";
                $typeMessage = 'error';

            } elseif (!in_array($devise, ['FC', 'USD'], true)) {

                $message = "Devise invalide.";
                $typeMessage = 'error';

            } else {

                try {

                    $stmt = $pdo->prepare("
                        INSERT INTO caisse
                        (agence_id, utilisateur_id, type_mouvement,
                         libelle, montant, devise, reference, date_operation)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $agenceId,
                        $utilisateurId ?: null,
                        $typeMouvement,
                        $libelle,
                        (float)$montant,
                        $devise,
                        $reference,
                        $dateOperation !== '' ? $dateOperation : date('Y-m-d H:i:s')
                    ]);

                    $message = "Mouvement de caisse enregistré avec succès.";
                    $typeMessage = 'success';

                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage();
                    $typeMessage = 'error';
                }
            }
        }

        /* ---------------- SUPPRESSION ---------------- */

        elseif ($action === 'supprimer') {

            $id = (int)($_POST['id'] ?? 0);

            if ($id > 0) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM caisse WHERE id = ? AND agence_id = ?");
                    $stmt->execute([$id, $agenceId]);

                    $ok = $stmt->rowCount() > 0;
                    $message = $ok ? "Mouvement supprimé." : "Mouvement introuvable.";
                    $typeMessage = $ok ? 'success' : 'error';

                } catch (PDOException $e) {
                    $message = "Impossible de supprimer ce mouvement.";
                    $typeMessage = 'error';
                }
            }
        }
    }
}

/* =====================================================
   3. FILTRES
===================================================== */

$filtreType   = trim($_GET['type'] ?? '');
$filtreDevise = trim($_GET['devise'] ?? '');
$recherche    = trim($_GET['recherche'] ?? '');

/* =====================================================
   4. SOLDES
===================================================== */

$sqlSolde = "SELECT
    COALESCE(SUM(CASE WHEN type_mouvement = 'Entree' AND devise = 'FC'  THEN montant ELSE 0 END), 0) AS entree_fc,
    COALESCE(SUM(CASE WHEN type_mouvement = 'Sortie' AND devise = 'FC'  THEN montant ELSE 0 END), 0) AS sortie_fc,
    COALESCE(SUM(CASE WHEN type_mouvement = 'Entree' AND devise = 'USD' THEN montant ELSE 0 END), 0) AS entree_usd,
    COALESCE(SUM(CASE WHEN type_mouvement = 'Sortie' AND devise = 'USD' THEN montant ELSE 0 END), 0) AS sortie_usd
    FROM caisse WHERE agence_id = ?";

$stmt = $pdo->prepare($sqlSolde);
$stmt->execute([$agenceId]);
$soldes = $stmt->fetch(PDO::FETCH_ASSOC);

$soldeFC  = (float)$soldes['entree_fc'] - (float)$soldes['sortie_fc'];
$soldeUSD = (float)$soldes['entree_usd'] - (float)$soldes['sortie_usd'];

/* =====================================================
   5. LISTE MOUVEMENTS
===================================================== */

$sql = "SELECT c.*, u.nom, u.prenom
        FROM caisse c
        LEFT JOIN utilisateurs u ON u.id = c.utilisateur_id
        WHERE c.agence_id = ?";
$params = [$agenceId];

if ($filtreType !== '' && in_array($filtreType, ['Entree', 'Sortie'], true)) {
    $sql .= " AND c.type_mouvement = ?";
    $params[] = $filtreType;
}

if ($filtreDevise !== '' && in_array($filtreDevise, ['FC', 'USD'], true)) {
    $sql .= " AND c.devise = ?";
    $params[] = $filtreDevise;
}

if ($recherche !== '') {
    $sql .= " AND (c.libelle LIKE ? OR c.reference LIKE ?)";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
}

$sql .= " ORDER BY c.date_operation DESC, c.id DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$mouvements = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   6. HEADER
===================================================== */

$titrePage  = 'Gestion de la caisse';
$sousTitre  = 'Enregistrez les entrées et sorties d\'argent de votre agence.';
$pageActive = 'caisse.php';

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

<!-- SOLDES -->
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-6">
    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-emerald-500">
        <p class="text-sm text-slate-500">Solde caisse (FC)</p>
        <p class="text-2xl font-bold <?= $soldeFC >= 0 ? 'text-emerald-700' : 'text-red-700' ?> mt-2">
            <?= number_format($soldeFC, 2, ',', ' ') ?> FC
        </p>
        <p class="text-xs text-slate-400 mt-1">
            Entrées : <?= number_format((float)$soldes['entree_fc'], 2, ',', ' ') ?>
            · Sorties : <?= number_format((float)$soldes['sortie_fc'], 2, ',', ' ') ?>
        </p>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-emerald-500">
        <p class="text-sm text-slate-500">Solde caisse (USD)</p>
        <p class="text-2xl font-bold <?= $soldeUSD >= 0 ? 'text-emerald-700' : 'text-red-700' ?> mt-2">
            <?= number_format($soldeUSD, 2, ',', ' ') ?> USD
        </p>
        <p class="text-xs text-slate-400 mt-1">
            Entrées : <?= number_format((float)$soldes['entree_usd'], 2, ',', ' ') ?>
            · Sorties : <?= number_format((float)$soldes['sortie_usd'], 2, ',', ' ') ?>
        </p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- FORMULAIRE -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <h3 class="text-lg font-bold text-slate-800 mb-5">Nouveau mouvement</h3>

        <form method="POST" action="caisse.php" class="space-y-4">

            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
            <input type="hidden" name="action" value="ajouter">

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Type *</label>
                <select name="type_mouvement" required
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="Entree">Entrée (encaissement)</option>
                    <option value="Sortie">Sortie (décaissement)</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Libellé *</label>
                <input type="text" name="libelle" required
                       placeholder="Ex : Vente billet, Achat carburant..."
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Montant *</label>
                    <input type="number" step="0.01" min="0.01" name="montant" required
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Devise</label>
                    <select name="devise"
                            class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                        <option value="FC">FC</option>
                        <option value="USD">USD</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Référence</label>
                <input type="text" name="reference"
                       placeholder="N° billet, facture..."
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Date</label>
                <input type="datetime-local" name="date_operation"
                       value="<?= date('Y-m-d\TH:i') ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <button type="submit"
                    class="w-full bg-sky-700 hover:bg-sky-800 text-white font-semibold py-3 rounded-lg">
                Enregistrer le mouvement
            </button>

        </form>

    </section>

    <!-- LISTE -->
    <section class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
            <h3 class="text-lg font-bold text-slate-800">Mouvements récents</h3>

            <form method="GET" class="flex flex-wrap gap-2">
                <select name="type"
                        class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">Tous types</option>
                    <option value="Entree" <?= $filtreType === 'Entree' ? 'selected' : '' ?>>Entrées</option>
                    <option value="Sortie" <?= $filtreType === 'Sortie' ? 'selected' : '' ?>>Sorties</option>
                </select>

                <select name="devise"
                        class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">Toutes devises</option>
                    <option value="FC" <?= $filtreDevise === 'FC' ? 'selected' : '' ?>>FC</option>
                    <option value="USD" <?= $filtreDevise === 'USD' ? 'selected' : '' ?>>USD</option>
                </select>

                <input type="text" name="recherche" value="<?= securiser($recherche) ?>"
                       placeholder="Rechercher..."
                       class="border border-slate-300 rounded-lg px-3 py-2 text-sm">

                <button type="submit"
                        class="bg-sky-700 hover:bg-sky-800 text-white px-4 py-2 rounded-lg text-sm">
                    Filtrer
                </button>
            </form>
        </div>

        <div class="tableau-responsive">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Libellé</th>
                        <th class="px-4 py-3">Montant</th>
                        <th class="px-4 py-3">Référence</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($mouvements)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-10 text-slate-500">
                                Aucun mouvement enregistré.
                            </td>
                        </tr>
                    <?php else: foreach ($mouvements as $m): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                <?= date('d/m/Y H:i', strtotime($m['date_operation'])) ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?= $m['type_mouvement'] === 'Entree'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-red-100 text-red-700' ?>">
                                    <?= securiser($m['type_mouvement']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-700"><?= securiser($m['libelle']) ?></td>
                            <td class="px-4 py-3 font-semibold whitespace-nowrap
                                <?= $m['type_mouvement'] === 'Entree' ? 'text-emerald-700' : 'text-red-700' ?>">
                                <?= $m['type_mouvement'] === 'Entree' ? '+' : '−' ?>
                                <?= number_format((float)$m['montant'], 2, ',', ' ') ?>
                                <?= securiser($m['devise']) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-500 text-xs">
                                <?= securiser($m['reference'] ?: '-') ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <form method="POST" data-app-confirm="Supprimer ce mouvement ?" data-app-confirm-title="Supprimer le mouvement" data-app-confirm-label="Supprimer">
                                    <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
                                    <input type="hidden" name="action" value="supprimer">
                                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                    <button type="submit"
                                            class="bg-red-100 text-red-700 hover:bg-red-200 px-3 py-2 rounded-lg text-xs">
                                        Supprimer
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

    </section>
</div>

<?php require 'footer.php'; ?>
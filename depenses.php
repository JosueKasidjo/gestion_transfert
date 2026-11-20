<?php
require_once "config.php";
exigerConnexion();

$agenceId = (int)($_SESSION['agence_id'] ?? 0);
$utilisateurId = (int)($_SESSION['id_utilisateur'] ?? 0);

/* =====================================================
   1. SECURITE CSRF
===================================================== */

if (empty($_SESSION['csrf_depenses'])) {
    $_SESSION['csrf_depenses'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_depenses'];

function verifierCSRFDepenses($token): bool
{
    return isset($_SESSION['csrf_depenses'])
        && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_depenses'], $token);
}

$message = '';
$typeMessage = 'success';
$edition = false;
$depenseEdition = null;

/* =====================================================
   2. CATEGORIES PREDEFINIES
===================================================== */

$categories = [
    'Carburant',
    'Salaires',
    'Loyer',
    'Entretien véhicules',
    'Pièces détachées',
    'Assurance',
    'Taxes et impôts',
    'Fournitures bureau',
    'Communication',
    'Transport',
    'Autre'
];

/* =====================================================
   3. TRAITEMENT FORMULAIRES
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFDepenses($_POST['csrf_token'] ?? null)) {

        $message = "Formulaire expiré. Rechargez la page.";
        $typeMessage = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'ajouter' || $action === 'modifier') {

            $id            = (int)($_POST['id'] ?? 0);
            $libelle       = trim($_POST['libelle'] ?? '');
            $categorie     = trim($_POST['categorie'] ?? 'Autre');
            $montant       = str_replace(',', '.', trim($_POST['montant'] ?? '0'));
            $devise        = strtoupper(trim($_POST['devise'] ?? 'FC'));
            $beneficiaire  = trim($_POST['beneficiaire'] ?? '');
            $dateDepense   = trim($_POST['date_depense'] ?? '');
            $observations  = trim($_POST['observations'] ?? '');

            if ($libelle === '') {

                $message = "Le libellé est obligatoire.";
                $typeMessage = 'error';

            } elseif (!is_numeric($montant) || (float)$montant <= 0) {

                $message = "Le montant doit être supérieur à zéro.";
                $typeMessage = 'error';

            } elseif (!in_array($devise, ['FC', 'USD'], true)) {

                $message = "Devise invalide.";
                $typeMessage = 'error';

            } elseif (!in_array($categorie, $categories, true)) {

                $message = "Catégorie invalide.";
                $typeMessage = 'error';

            } else {

                try {

                    if ($action === 'ajouter') {

                        $stmt = $pdo->prepare("
                            INSERT INTO depenses
                            (agence_id, utilisateur_id, libelle, categorie,
                             montant, devise, beneficiaire, date_depense, observations)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $stmt->execute([
                            $agenceId,
                            $utilisateurId ?: null,
                            $libelle,
                            $categorie,
                            (float)$montant,
                            $devise,
                            $beneficiaire,
                            $dateDepense !== '' ? $dateDepense : date('Y-m-d H:i:s'),
                            $observations
                        ]);

                        $message = "Dépense enregistrée avec succès.";

                    } else {

                        if ($id <= 0) throw new Exception("Identifiant invalide.");

                        $stmt = $pdo->prepare("
                            UPDATE depenses
                            SET libelle = ?, categorie = ?, montant = ?,
                                devise = ?, beneficiaire = ?,
                                date_depense = ?, observations = ?
                            WHERE id = ? AND agence_id = ?
                        ");

                        $stmt->execute([
                            $libelle, $categorie, (float)$montant,
                            $devise, $beneficiaire,
                            $dateDepense !== '' ? $dateDepense : date('Y-m-d H:i:s'),
                            $observations,
                            $id, $agenceId
                        ]);

                        $message = "Dépense modifiée avec succès.";
                    }

                    $typeMessage = 'success';

                } catch (Exception $e) {
                    $message = $e->getMessage();
                    $typeMessage = 'error';
                } catch (PDOException $e) {
                    $message = "Erreur : " . $e->getMessage();
                    $typeMessage = 'error';
                }
            }
        }

        elseif ($action === 'supprimer') {

            $id = (int)($_POST['id'] ?? 0);

            if ($id > 0) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM depenses WHERE id = ? AND agence_id = ?");
                    $stmt->execute([$id, $agenceId]);

                    $ok = $stmt->rowCount() > 0;
                    $message = $ok ? "Dépense supprimée." : "Dépense introuvable.";
                    $typeMessage = $ok ? 'success' : 'error';

                } catch (PDOException $e) {
                    $message = "Impossible de supprimer cette dépense.";
                    $typeMessage = 'error';
                }
            }
        }
    }
}

/* =====================================================
   4. EDITION
===================================================== */

if (isset($_GET['modifier'])) {

    $id = (int)$_GET['modifier'];

    $stmt = $pdo->prepare("SELECT * FROM depenses WHERE id = ? AND agence_id = ?");
    $stmt->execute([$id, $agenceId]);

    $depenseEdition = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($depenseEdition) {
        $edition = true;
    } else {
        $message = "Dépense introuvable.";
        $typeMessage = 'error';
    }
}

/* =====================================================
   5. FILTRES
===================================================== */

$filtreCategorie = trim($_GET['categorie'] ?? '');
$filtreDevise    = trim($_GET['devise'] ?? '');
$recherche       = trim($_GET['recherche'] ?? '');
$dateDebut       = trim($_GET['date_debut'] ?? '');
$dateFin         = trim($_GET['date_fin'] ?? '');

/* =====================================================
   6. STATISTIQUES
===================================================== */

$sqlStats = "SELECT
    COALESCE(SUM(CASE WHEN devise = 'FC'  THEN montant ELSE 0 END), 0) AS total_fc,
    COALESCE(SUM(CASE WHEN devise = 'USD' THEN montant ELSE 0 END), 0) AS total_usd,
    COUNT(*) AS nb
    FROM depenses WHERE agence_id = ?";

$stmt = $pdo->prepare($sqlStats);
$stmt->execute([$agenceId]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

/* =====================================================
   7. LISTE
===================================================== */

$sql = "SELECT d.*, u.nom, u.prenom
        FROM depenses d
        LEFT JOIN utilisateurs u ON u.id = d.utilisateur_id
        WHERE d.agence_id = ?";
$params = [$agenceId];

if ($filtreCategorie !== '' && in_array($filtreCategorie, $categories, true)) {
    $sql .= " AND d.categorie = ?";
    $params[] = $filtreCategorie;
}

if ($filtreDevise !== '' && in_array($filtreDevise, ['FC', 'USD'], true)) {
    $sql .= " AND d.devise = ?";
    $params[] = $filtreDevise;
}

if ($recherche !== '') {
    $sql .= " AND (d.libelle LIKE ? OR d.beneficiaire LIKE ?)";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
}

if ($dateDebut !== '') {
    $sql .= " AND DATE(d.date_depense) >= ?";
    $params[] = $dateDebut;
}

if ($dateFin !== '') {
    $sql .= " AND DATE(d.date_depense) <= ?";
    $params[] = $dateFin;
}

$sql .= " ORDER BY d.date_depense DESC, d.id DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$depenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   8. HEADER
===================================================== */

$titrePage  = 'Gestion des dépenses';
$sousTitre  = 'Enregistrez et suivez les dépenses de votre agence.';
$pageActive = 'depenses.php';

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

<!-- STATISTIQUES -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-6">
    <div class="bg-white rounded-xl shadow-sm p-5 border border-slate-200">
        <p class="text-sm text-slate-500">Nombre de dépenses</p>
        <p class="text-3xl font-bold text-sky-700 mt-2"><?= (int)$stats['nb'] ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-red-400">
        <p class="text-sm text-slate-500">Total dépenses (FC)</p>
        <p class="text-xl md:text-2xl font-bold text-red-600 mt-2">
            <?= number_format((float)$stats['total_fc'], 2, ',', ' ') ?> FC
        </p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-red-400">
        <p class="text-sm text-slate-500">Total dépenses (USD)</p>
        <p class="text-xl md:text-2xl font-bold text-red-600 mt-2">
            <?= number_format((float)$stats['total_usd'], 2, ',', ' ') ?> USD
        </p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- FORMULAIRE -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <h3 class="text-lg font-bold text-slate-800 mb-5">
            <?= $edition ? 'Modifier une dépense' : 'Nouvelle dépense' ?>
        </h3>

        <form method="POST" action="depenses.php" class="space-y-4">

            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
            <input type="hidden" name="action" value="<?= $edition ? 'modifier' : 'ajouter' ?>">
            <?php if ($edition): ?>
                <input type="hidden" name="id" value="<?= (int)$depenseEdition['id'] ?>">
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Libellé *</label>
                <input type="text" name="libelle" required
                       value="<?= $edition ? securiser($depenseEdition['libelle']) : '' ?>"
                       placeholder="Ex : Achat carburant minibus"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Catégorie</label>
                <select name="categorie"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= securiser($c) ?>"
                            <?= $edition && $depenseEdition['categorie'] === $c ? 'selected' : '' ?>>
                            <?= securiser($c) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Montant *</label>
                    <input type="number" step="0.01" min="0.01" name="montant" required
                           value="<?= $edition ? securiser($depenseEdition['montant']) : '' ?>"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Devise</label>
                    <select name="devise"
                            class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                        <option value="FC" <?= $edition && $depenseEdition['devise'] === 'FC' ? 'selected' : '' ?>>FC</option>
                        <option value="USD" <?= $edition && $depenseEdition['devise'] === 'USD' ? 'selected' : '' ?>>USD</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Bénéficiaire</label>
                <input type="text" name="beneficiaire"
                       value="<?= $edition ? securiser($depenseEdition['beneficiaire']) : '' ?>"
                       placeholder="Personne ou entreprise payée"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Date</label>
                <input type="datetime-local" name="date_depense"
                       value="<?= $edition ? date('Y-m-d\TH:i', strtotime($depenseEdition['date_depense'])) : date('Y-m-d\TH:i') ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Observations</label>
                <textarea name="observations" rows="2"
                          class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none"><?= $edition ? securiser($depenseEdition['observations']) : '' ?></textarea>
            </div>

            <div class="flex gap-2 pt-2">
                <button type="submit"
                        class="flex-1 bg-sky-700 hover:bg-sky-800 text-white font-semibold py-3 rounded-lg">
                    <?= $edition ? 'Enregistrer les modifications' : 'Enregistrer la dépense' ?>
                </button>
                <?php if ($edition): ?>
                    <a href="depenses.php"
                       class="px-4 py-3 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700">Annuler</a>
                <?php endif; ?>
            </div>

        </form>

    </section>

    <!-- LISTE -->
    <section class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <h3 class="text-lg font-bold text-slate-800 mb-4">Liste des dépenses</h3>

        <!-- FILTRES -->
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5 gap-2 mb-5">

            <select name="categorie" class="w-full min-w-0 border border-slate-300 rounded-lg px-2 py-2 text-sm">
                <option value="">Catégorie</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= securiser($c) ?>" <?= $filtreCategorie === $c ? 'selected' : '' ?>>
                        <?= securiser($c) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="devise" class="w-full min-w-0 border border-slate-300 rounded-lg px-2 py-2 text-sm">
                <option value="">Devise</option>
                <option value="FC" <?= $filtreDevise === 'FC' ? 'selected' : '' ?>>FC</option>
                <option value="USD" <?= $filtreDevise === 'USD' ? 'selected' : '' ?>>USD</option>
            </select>

            <input type="date" name="date_debut" value="<?= securiser($dateDebut) ?>"
                     class="w-full min-w-0 h-9 border border-slate-300 rounded-lg px-2 py-1 text-xs"
                   title="Date de début">

            <input type="date" name="date_fin" value="<?= securiser($dateFin) ?>"
                     class="w-full min-w-0 h-9 border border-slate-300 rounded-lg px-2 py-1 text-xs"
                   title="Date de fin">

                 <div class="flex min-w-0 gap-1">
                <input type="text" name="recherche" value="<?= securiser($recherche) ?>"
                       placeholder="Rechercher..."
                      class="min-w-0 flex-1 border border-slate-300 rounded-lg px-2 py-2 text-sm">
                <button type="submit"
                        class="bg-sky-700 hover:bg-sky-800 text-white px-3 py-2 rounded-lg text-sm">
                    OK
                </button>
            </div>

        </form>

        <div class="tableau-responsive">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Libellé</th>
                        <th class="px-4 py-3">Catégorie</th>
                        <th class="px-4 py-3">Bénéficiaire</th>
                        <th class="px-4 py-3">Montant</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($depenses)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-10 text-slate-500">
                                Aucune dépense enregistrée.
                            </td>
                        </tr>
                    <?php else: foreach ($depenses as $d): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                <?= date('d/m/Y H:i', strtotime($d['date_depense'])) ?>
                            </td>
                            <td class="px-4 py-3 font-semibold text-slate-800">
                                <?= securiser($d['libelle']) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                <span class="px-2 py-1 rounded bg-slate-100 text-slate-700 text-xs">
                                    <?= securiser($d['categorie']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                <?= securiser($d['beneficiaire'] ?: '-') ?>
                            </td>
                            <td class="px-4 py-3 font-semibold text-red-600 whitespace-nowrap">
                                − <?= number_format((float)$d['montant'], 2, ',', ' ') ?>
                                <?= securiser($d['devise']) ?>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-2">
                                    <a href="depenses.php?modifier=<?= (int)$d['id'] ?>"
                                       class="bg-amber-100 text-amber-700 hover:bg-amber-200 px-3 py-2 rounded-lg text-xs">
                                        Modifier
                                    </a>
                                    <form method="POST" data-app-confirm="Supprimer cette dépense ?" data-app-confirm-title="Supprimer la dépense" data-app-confirm-label="Supprimer">
                                        <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
                                        <input type="hidden" name="action" value="supprimer">
                                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                        <button type="submit"
                                                class="bg-red-100 text-red-700 hover:bg-red-200 px-3 py-2 rounded-lg text-xs">
                                            Supprimer
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

    </section>
</div>

<?php require 'footer.php'; ?>
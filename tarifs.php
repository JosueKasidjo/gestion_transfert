<?php
require_once "config.php";
exigerConnexion();

/* =====================================================
   1. SECURITE CSRF
===================================================== */

if (empty($_SESSION['csrf_tarifs'])) {
    $_SESSION['csrf_tarifs'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_tarifs'];

function verifierCSRFTarifs($token): bool
{
    return isset($_SESSION['csrf_tarifs'])
        && is_string($token)
        && $token !== ''
        && hash_equals($_SESSION['csrf_tarifs'], $token);
}

$message = '';
$typeMessage = 'success';
$edition = false;
$tarifEdition = null;

/* =====================================================
   2. VERIFICATION DES TABLES
===================================================== */

try {
    $pdo->query("SELECT id FROM tarifs LIMIT 1");

    $colonnesMilieux = $pdo->query("SHOW COLUMNS FROM milieux")
                           ->fetchAll(PDO::FETCH_ASSOC);

    $nomsColonnes = array_column($colonnesMilieux, 'Field');

    $colonneNomMilieu = null;

    foreach (['nom_milieu', 'nom', 'milieu', 'libelle', 'name'] as $possible) {
        if (in_array($possible, $nomsColonnes, true)) {
            $colonneNomMilieu = $possible;
            break;
        }
    }

    if (!$colonneNomMilieu) {
        die("La colonne contenant le nom du milieu est introuvable.");
    }

} catch (PDOException $e) {
    die("Erreur de base de données : " . securiser($e->getMessage()));
}

/* =====================================================
   3. CHARGEMENT DES MILIEUX
===================================================== */

$stmtMilieux = $pdo->query(
    "SELECT id, `$colonneNomMilieu` AS nom
     FROM milieux
     ORDER BY `$colonneNomMilieu` ASC"
);

$milieux = $stmtMilieux->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   4. TRAITEMENT DES FORMULAIRES
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFTarifs($_POST['csrf_token'] ?? null)) {

        $message = "Le formulaire a expiré. Rechargez la page et réessayez.";
        $typeMessage = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        /* ---------------- AJOUT / MODIFICATION ---------------- */

        if ($action === 'ajouter' || $action === 'modifier') {

            $depart   = (int)($_POST['milieu_depart_id'] ?? 0);
            $arrivee  = (int)($_POST['milieu_arrivee_id'] ?? 0);

            $prixVoyageur = str_replace(',', '.', trim($_POST['prix_voyageur'] ?? '0'));
            $prixKg       = str_replace(',', '.', trim($_POST['prix_kg_non_fragile'] ?? '0'));

            $devise = strtoupper(trim($_POST['devise'] ?? 'FC'));
            $statut = trim($_POST['statut'] ?? 'Actif');
            $id     = (int)($_POST['id'] ?? 0);

            if ($depart <= 0 || $arrivee <= 0) {

                $message = "Veuillez sélectionner le départ et l'arrivée.";
                $typeMessage = 'error';

            } elseif ($depart === $arrivee) {

                $message = "Le milieu de départ et celui d'arrivée doivent être différents.";
                $typeMessage = 'error';

            } elseif (
                !is_numeric($prixVoyageur) ||
                !is_numeric($prixKg) ||
                (float)$prixVoyageur < 0 ||
                (float)$prixKg < 0
            ) {

                $message = "Veuillez saisir des montants valides et positifs.";
                $typeMessage = 'error';

            } elseif (!in_array($devise, ['FC', 'USD'], true)) {

                $message = "Devise invalide.";
                $typeMessage = 'error';

            } elseif (!in_array($statut, ['Actif', 'Inactif'], true)) {

                $message = "Statut invalide.";
                $typeMessage = 'error';

            } else {

                try {

                    if ($action === 'ajouter') {

                        $stmt = $pdo->prepare("
                            INSERT INTO tarifs
                            (milieu_depart_id, milieu_arrivee_id,
                             prix_voyageur, prix_kg_non_fragile,
                             devise, statut)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");

                        $stmt->execute([
                            $depart,
                            $arrivee,
                            $prixVoyageur,
                            $prixKg,
                            $devise,
                            $statut
                        ]);

                        $message = "Le tarif a été enregistré avec succès.";
                        $typeMessage = 'success';

                    } else {

                        if ($id <= 0) {
                            throw new Exception("Identifiant du tarif invalide.");
                        }

                        $stmt = $pdo->prepare("
                            UPDATE tarifs
                            SET milieu_depart_id = ?,
                                milieu_arrivee_id = ?,
                                prix_voyageur = ?,
                                prix_kg_non_fragile = ?,
                                devise = ?,
                                statut = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $depart,
                            $arrivee,
                            $prixVoyageur,
                            $prixKg,
                            $devise,
                            $statut,
                            $id
                        ]);

                        $message = "Le tarif a été modifié avec succès.";
                        $typeMessage = 'success';
                    }

                } catch (PDOException $e) {

                    if ($e->getCode() === '23000') {
                        $message = "Un tarif existe déjà pour ce trajet et cette devise.";
                    } else {
                        $message = "Erreur lors de l'enregistrement : " . $e->getMessage();
                    }

                    $typeMessage = 'error';

                } catch (Exception $e) {

                    $message = $e->getMessage();
                    $typeMessage = 'error';
                }
            }
        }

        /* ---------------- SUPPRESSION ---------------- */

        elseif ($action === 'supprimer') {

            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {

                $message = "Identifiant invalide.";
                $typeMessage = 'error';

            } else {

                try {

                    $stmt = $pdo->prepare("DELETE FROM tarifs WHERE id = ?");
                    $stmt->execute([$id]);

                    if ($stmt->rowCount() > 0) {
                        $message = "Le tarif a été supprimé.";
                        $typeMessage = 'success';
                    } else {
                        $message = "Tarif introuvable.";
                        $typeMessage = 'error';
                    }

                } catch (PDOException $e) {

                    $message = "Impossible de supprimer ce tarif. Il est peut-être utilisé.";
                    $typeMessage = 'error';
                }
            }
        }
    }
}

/* =====================================================
   5. CHARGEMENT D'UN TARIF POUR MODIFICATION
===================================================== */

if (isset($_GET['modifier'])) {

    $id = (int)$_GET['modifier'];

    $stmt = $pdo->prepare("SELECT * FROM tarifs WHERE id = ?");
    $stmt->execute([$id]);

    $tarifEdition = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tarifEdition) {
        $edition = true;
    } else {
        $message = "Tarif introuvable.";
        $typeMessage = 'error';
    }
}

/* =====================================================
   6. RECHERCHE ET LISTE
===================================================== */

$recherche = trim($_GET['recherche'] ?? '');

$sql = "SELECT
            t.*,
            d.`$colonneNomMilieu` AS nom_depart,
            a.`$colonneNomMilieu` AS nom_arrivee
        FROM tarifs t
        INNER JOIN milieux d ON d.id = t.milieu_depart_id
        INNER JOIN milieux a ON a.id = t.milieu_arrivee_id
        WHERE 1=1";

$parametres = [];

if ($recherche !== '') {
    $sql .= " AND (
        d.`$colonneNomMilieu` LIKE ?
        OR a.`$colonneNomMilieu` LIKE ?
        OR t.devise LIKE ?
    )";

    $parametres[] = "%$recherche%";
    $parametres[] = "%$recherche%";
    $parametres[] = "%$recherche%";
}

$sql .= " ORDER BY t.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($parametres);
$tarifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nombreTarifs = count($tarifs);
$nombreActifs = 0;

foreach ($tarifs as $t) {
    if ($t['statut'] === 'Actif') {
        $nombreActifs++;
    }
}

/* =====================================================
   7. HEADER COMMUN
===================================================== */

$titrePage  = 'Gestion des tarifs';
$sousTitre  = 'Définissez les prix voyageur et les prix au kilogramme par trajet.';
$pageActive = 'tarifs.php';

require 'header.php';
?>

<!-- MESSAGE -->
<?php if ($message !== ''): ?>
    <div class="mb-5 p-4 rounded-xl border
        <?= $typeMessage === 'success'
            ? 'bg-green-50 text-green-800 border-green-200'
            : 'bg-red-50 text-red-800 border-red-200' ?>">
        <?= securiser($message) ?>
    </div>
<?php endif; ?>

<!-- STATISTIQUES -->
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-6">

    <div class="bg-white rounded-xl shadow-sm p-5 border border-slate-200">
        <p class="text-sm text-slate-500">Total des tarifs</p>
        <p class="text-3xl font-bold text-sky-700 mt-2"><?= $nombreTarifs ?></p>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-5 border border-slate-200">
        <p class="text-sm text-slate-500">Tarifs actifs</p>
        <p class="text-3xl font-bold text-emerald-600 mt-2"><?= $nombreActifs ?></p>
    </div>

</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- FORMULAIRE -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <h3 class="text-lg font-bold text-slate-800 mb-5">
            <?= $edition ? 'Modifier un tarif' : 'Ajouter un tarif' ?>
        </h3>

        <form method="POST" action="tarifs.php" class="space-y-4">

            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
            <input type="hidden" name="action"
                   value="<?= $edition ? 'modifier' : 'ajouter' ?>">

            <?php if ($edition): ?>
                <input type="hidden" name="id"
                       value="<?= (int)$tarifEdition['id'] ?>">
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Milieu de départ *
                </label>
                <select name="milieu_depart_id" required
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="">Choisir</option>
                    <?php foreach ($milieux as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"
                            <?= $edition && (int)$tarifEdition['milieu_depart_id'] === (int)$m['id'] ? 'selected' : '' ?>>
                            <?= securiser($m['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Milieu d'arrivée *
                </label>
                <select name="milieu_arrivee_id" required
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="">Choisir</option>
                    <?php foreach ($milieux as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"
                            <?= $edition && (int)$tarifEdition['milieu_arrivee_id'] === (int)$m['id'] ? 'selected' : '' ?>>
                            <?= securiser($m['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Prix voyageur
                </label>
                <input type="number" step="0.01" min="0" name="prix_voyageur"
                       value="<?= $edition ? securiser($tarifEdition['prix_voyageur']) : '0' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Prix au kilogramme (non fragile)
                </label>
                <input type="number" step="0.01" min="0" name="prix_kg_non_fragile"
                       value="<?= $edition ? securiser($tarifEdition['prix_kg_non_fragile']) : '0' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Devise
                </label>
                <select name="devise"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="FC" <?= $edition && $tarifEdition['devise'] === 'FC' ? 'selected' : '' ?>>FC</option>
                    <option value="USD" <?= $edition && $tarifEdition['devise'] === 'USD' ? 'selected' : '' ?>>USD</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Statut
                </label>
                <select name="statut"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="Actif" <?= $edition && $tarifEdition['statut'] === 'Actif' ? 'selected' : '' ?>>Actif</option>
                    <option value="Inactif" <?= $edition && $tarifEdition['statut'] === 'Inactif' ? 'selected' : '' ?>>Inactif</option>
                </select>
            </div>

            <div class="flex gap-2 pt-2">
                <button type="submit"
                        class="flex-1 bg-sky-700 hover:bg-sky-800 text-white font-semibold py-3 rounded-lg">
                    <?= $edition ? 'Enregistrer les modifications' : 'Ajouter le tarif' ?>
                </button>
                <?php if ($edition): ?>
                    <a href="tarifs.php"
                       class="px-4 py-3 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700">
                        Annuler
                    </a>
                <?php endif; ?>
            </div>

        </form>

    </section>

    <!-- LISTE -->
    <section class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
            <h3 class="text-lg font-bold text-slate-800">Liste des tarifs</h3>

            <form method="GET" action="tarifs.php" class="flex gap-2">
                <input type="text" name="recherche"
                       value="<?= securiser($recherche) ?>"
                       placeholder="Rechercher..."
                       class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                <button type="submit"
                        class="bg-sky-700 hover:bg-sky-800 text-white px-4 py-2 rounded-lg text-sm">
                    Rechercher
                </button>
            </form>
        </div>

        <div class="tableau-responsive">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-4 py-3">N°</th>
                        <th class="px-4 py-3">Trajet</th>
                        <th class="px-4 py-3">Voyageur</th>
                        <th class="px-4 py-3">Prix / kg</th>
                        <th class="px-4 py-3">Devise</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($tarifs)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-10 text-slate-500">
                                Aucun tarif enregistré.
                            </td>
                        </tr>
                    <?php else: foreach ($tarifs as $i => $t): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-slate-500"><?= $i + 1 ?></td>
                            <td class="px-4 py-3 font-semibold text-slate-800 whitespace-nowrap">
                                <?= securiser($t['nom_depart']) ?>
                                <span class="text-sky-500 mx-1">→</span>
                                <?= securiser($t['nom_arrivee']) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                <?= number_format((float)$t['prix_voyageur'], 2, ',', ' ') ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                <?= number_format((float)$t['prix_kg_non_fragile'], 2, ',', ' ') ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600"><?= securiser($t['devise']) ?></td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?= $t['statut'] === 'Actif'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-slate-200 text-slate-600' ?>">
                                    <?= securiser($t['statut']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-2">
                                    <a href="tarifs.php?modifier=<?= (int)$t['id'] ?>"
                                       class="bg-amber-100 text-amber-700 hover:bg-amber-200 px-3 py-2 rounded-lg">
                                        Modifier
                                    </a>
                                    <form method="POST" action="tarifs.php"
                                          data-app-confirm="Supprimer ce tarif ?" data-app-confirm-title="Supprimer le tarif" data-app-confirm-label="Supprimer">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= securiser($csrf) ?>">
                                        <input type="hidden" name="action" value="supprimer">
                                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                        <button type="submit"
                                                class="bg-red-100 text-red-700 hover:bg-red-200 px-3 py-2 rounded-lg">
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
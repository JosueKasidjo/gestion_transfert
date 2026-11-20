<?php
require_once "config.php";
exigerConnexion();

$agenceId = (int)($_SESSION['agence_id'] ?? 0);

/* =====================================================
   1. SECURITE CSRF
===================================================== */

if (empty($_SESSION['csrf_voyageurs'])) {
    $_SESSION['csrf_voyageurs'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_voyageurs'];

function verifierCSRFVoyageurs($token): bool
{
    return isset($_SESSION['csrf_voyageurs'])
        && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_voyageurs'], $token);
}

$message = '';
$typeMessage = 'success';
$edition = false;
$voyageurEdition = null;
$recherche = trim($_GET['recherche'] ?? '');

/* =====================================================
   2. TRAITEMENT FORMULAIRES
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFVoyageurs($_POST['csrf_token'] ?? null)) {

        $message = "Formulaire expiré. Rechargez la page.";
        $typeMessage = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'ajouter' || $action === 'modifier') {

            $id          = (int)($_POST['id'] ?? 0);
            $nomComplet  = trim($_POST['nom_complet'] ?? '');
            $genre       = trim($_POST['genre'] ?? '');
            $telephone   = trim($_POST['telephone'] ?? '');

            if ($nomComplet === '') {

                $message = "Le nom complet est obligatoire.";
                $typeMessage = 'error';

            } else {

                try {

                    if ($action === 'ajouter') {

                        $stmt = $pdo->prepare("
                            INSERT INTO voyageurs
                            (agence_id, nom_complet, genre, telephone)
                            VALUES (?, ?, ?, ?)
                        ");

                        $stmt->execute([
                            $agenceId, $nomComplet, $genre, $telephone
                        ]);

                        $message = "Le voyageur a été ajouté avec succès.";

                    } else {

                        if ($id <= 0) throw new Exception("Identifiant invalide.");

                        $stmt = $pdo->prepare("
                            UPDATE voyageurs
                            SET nom_complet = ?, genre = ?, telephone = ?
                            WHERE id = ? AND agence_id = ?
                        ");

                        $stmt->execute([
                            $nomComplet, $genre, $telephone, $id, $agenceId
                        ]);

                        $message = "Le voyageur a été modifié avec succès.";
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
                    $stmt = $pdo->prepare("DELETE FROM voyageurs WHERE id = ? AND agence_id = ?");
                    $stmt->execute([$id, $agenceId]);

                    $ok = $stmt->rowCount() > 0;
                    $message = $ok ? "Voyageur supprimé." : "Voyageur introuvable.";
                    $typeMessage = $ok ? 'success' : 'error';

                } catch (PDOException $e) {
                    $message = "Impossible de supprimer : ce voyageur possède des billets.";
                    $typeMessage = 'error';
                }
            }
        }
    }
}

/* =====================================================
   3. EDITION
===================================================== */

if (isset($_GET['modifier'])) {

    $id = (int)$_GET['modifier'];

    $stmt = $pdo->prepare("SELECT * FROM voyageurs WHERE id = ? AND agence_id = ?");
    $stmt->execute([$id, $agenceId]);

    $voyageurEdition = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($voyageurEdition) {
        $edition = true;
    } else {
        $message = "Voyageur introuvable.";
        $typeMessage = 'error';
    }
}

/* =====================================================
   4. LISTE
===================================================== */

$sql = "SELECT v.*,
               (SELECT COUNT(*) FROM billets b WHERE b.voyageur_id = v.id) AS nb_billets
        FROM voyageurs v
        WHERE v.agence_id = ?";
$params = [$agenceId];

if ($recherche !== '') {
    $sql .= " AND (v.nom_complet LIKE ? OR v.telephone LIKE ?)";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
}

$sql .= " ORDER BY v.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$voyageurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nombreVoyageurs = count($voyageurs);

/* =====================================================
   5. HEADER
===================================================== */

$titrePage  = 'Gestion des voyageurs';
$sousTitre  = 'Enregistrez et gérez les voyageurs de votre agence.';
$pageActive = 'voyageurs.php';

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

<div class="bg-white rounded-xl shadow-sm p-5 mb-6 border border-slate-200">
    <p class="text-sm text-slate-500">Nombre de voyageurs</p>
    <p class="text-3xl font-bold text-sky-700 mt-2"><?= $nombreVoyageurs ?></p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- FORMULAIRE -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <h3 class="text-lg font-bold text-slate-800 mb-5">
            <?= $edition ? 'Modifier un voyageur' : 'Ajouter un voyageur' ?>
        </h3>

        <form method="POST" action="voyageurs.php" class="space-y-4">

            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
            <input type="hidden" name="action" value="<?= $edition ? 'modifier' : 'ajouter' ?>">
            <?php if ($edition): ?>
                <input type="hidden" name="id" value="<?= (int)$voyageurEdition['id'] ?>">
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Nom complet *</label>
                <input type="text" name="nom_complet" required
                       value="<?= $edition ? securiser($voyageurEdition['nom_complet']) : '' ?>"
                       placeholder="Ex : Jean KABILA"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Genre</label>
                <select name="genre"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="">Choisir</option>
                    <option value="Masculin" <?= $edition && $voyageurEdition['genre'] === 'Masculin' ? 'selected' : '' ?>>Masculin</option>
                    <option value="Féminin" <?= $edition && $voyageurEdition['genre'] === 'Féminin' ? 'selected' : '' ?>>Féminin</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Téléphone</label>
                <input type="text" name="telephone"
                       value="<?= $edition ? securiser($voyageurEdition['telephone']) : '' ?>"
                       placeholder="Ex : 0812345678"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div class="flex gap-2 pt-2">
                <button type="submit"
                        class="flex-1 bg-sky-700 hover:bg-sky-800 text-white font-semibold py-3 rounded-lg">
                    <?= $edition ? 'Enregistrer les modifications' : 'Ajouter le voyageur' ?>
                </button>
                <?php if ($edition): ?>
                    <a href="voyageurs.php"
                       class="px-4 py-3 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700">Annuler</a>
                <?php endif; ?>
            </div>

        </form>

    </section>

    <!-- LISTE -->
    <section class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
            <h3 class="text-lg font-bold text-slate-800">Liste des voyageurs</h3>
            <form method="GET" class="flex gap-2">
                <input type="text" name="recherche" value="<?= securiser($recherche) ?>"
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
                        <th class="px-4 py-3">Nom complet</th>
                        <th class="px-4 py-3">Genre</th>
                        <th class="px-4 py-3">Téléphone</th>
                        <th class="px-4 py-3">Billets</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($voyageurs)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-10 text-slate-500">
                                Aucun voyageur enregistré.
                            </td>
                        </tr>
                    <?php else: foreach ($voyageurs as $i => $v): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-slate-500"><?= $i + 1 ?></td>
                            <td class="px-4 py-3 font-semibold text-slate-800">
                                <?= securiser($v['nom_complet']) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600"><?= securiser($v['genre'] ?? '-') ?></td>
                            <td class="px-4 py-3 text-slate-600"><?= securiser($v['telephone'] ?? '-') ?></td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded-full bg-sky-100 text-sky-700 text-xs font-semibold">
                                    <?= (int)$v['nb_billets'] ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-2">
                                    <a href="voyageurs.php?modifier=<?= (int)$v['id'] ?>"
                                       class="bg-amber-100 text-amber-700 hover:bg-amber-200 px-3 py-2 rounded-lg">
                                        Modifier
                                    </a>
                                    <form method="POST" data-app-confirm="Supprimer ce voyageur ?" data-app-confirm-title="Supprimer le voyageur" data-app-confirm-label="Supprimer">
                                        <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
                                        <input type="hidden" name="action" value="supprimer">
                                        <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
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
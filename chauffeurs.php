<?php
require_once "config.php";
exigerConnexion();

$agenceId = (int)($_SESSION['agence_id'] ?? 0);

/* =====================================================
   1. SECURITE CSRF
===================================================== */

if (empty($_SESSION['csrf_chauffeurs'])) {
    $_SESSION['csrf_chauffeurs'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_chauffeurs'];

function verifierCSRFChauffeurs($token): bool
{
    return isset($_SESSION['csrf_chauffeurs'])
        && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_chauffeurs'], $token);
}

$message = '';
$typeMessage = 'success';
$edition = false;
$chauffeurEdition = null;
$recherche = trim($_GET['recherche'] ?? '');

/* =====================================================
   2. TRAITEMENT FORMULAIRES
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFChauffeurs($_POST['csrf_token'] ?? null)) {

        $message = "Formulaire expiré. Rechargez la page.";
        $typeMessage = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        /* ---------------- AJOUT / MODIFICATION ---------------- */

        if ($action === 'ajouter' || $action === 'modifier') {

            $id        = (int)($_POST['id'] ?? 0);
            $nom       = trim($_POST['nom'] ?? '');
            $postnom   = trim($_POST['postnom'] ?? '');
            $prenom    = trim($_POST['prenom'] ?? '');
            $genre     = trim($_POST['genre'] ?? '');
            $telephone = trim($_POST['telephone'] ?? '');
            $adresse   = trim($_POST['adresse'] ?? '');
            $permis    = trim($_POST['numero_permis'] ?? '');
            $statut    = trim($_POST['statut'] ?? 'Actif');

            if ($nom === '' || $telephone === '') {

                $message = "Le nom et le téléphone sont obligatoires.";
                $typeMessage = 'error';

            } elseif (!in_array($statut, ['Actif', 'Inactif', 'Suspendu'], true)) {

                $message = "Statut invalide.";
                $typeMessage = 'error';

            } else {

                try {

                    if ($action === 'ajouter') {

                        $stmt = $pdo->prepare("
                            INSERT INTO chauffeurs
                            (agence_id, nom, postnom, prenom, genre,
                             telephone, adresse, numero_permis, statut)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $stmt->execute([
                            $agenceId, $nom, $postnom, $prenom, $genre,
                            $telephone, $adresse, $permis, $statut
                        ]);

                        $message = "Le chauffeur a été ajouté avec succès.";

                    } else {

                        if ($id <= 0) throw new Exception("Identifiant invalide.");

                        $stmt = $pdo->prepare("
                            UPDATE chauffeurs
                            SET nom = ?, postnom = ?, prenom = ?, genre = ?,
                                telephone = ?, adresse = ?, numero_permis = ?,
                                statut = ?
                            WHERE id = ? AND agence_id = ?
                        ");

                        $stmt->execute([
                            $nom, $postnom, $prenom, $genre,
                            $telephone, $adresse, $permis, $statut,
                            $id, $agenceId
                        ]);

                        $message = "Le chauffeur a été modifié avec succès.";
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

        /* ---------------- SUPPRESSION ---------------- */

        elseif ($action === 'supprimer') {

            $id = (int)($_POST['id'] ?? 0);

            if ($id > 0) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM chauffeurs WHERE id = ? AND agence_id = ?");
                    $stmt->execute([$id, $agenceId]);

                    $ok = $stmt->rowCount() > 0;
                    $message = $ok ? "Chauffeur supprimé." : "Chauffeur introuvable.";
                    $typeMessage = $ok ? 'success' : 'error';

                } catch (PDOException $e) {
                    $message = "Impossible de supprimer : ce chauffeur est utilisé dans un voyage.";
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

    $stmt = $pdo->prepare("SELECT * FROM chauffeurs WHERE id = ? AND agence_id = ?");
    $stmt->execute([$id, $agenceId]);

    $chauffeurEdition = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($chauffeurEdition) {
        $edition = true;
    } else {
        $message = "Chauffeur introuvable.";
        $typeMessage = 'error';
    }
}

/* =====================================================
   4. LISTE
===================================================== */

$sql = "SELECT * FROM chauffeurs WHERE agence_id = ?";
$params = [$agenceId];

if ($recherche !== '') {
    $sql .= " AND (nom LIKE ? OR postnom LIKE ? OR prenom LIKE ? OR telephone LIKE ?)";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$chauffeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nombreChauffeurs = count($chauffeurs);
$nombreActifs = 0;
foreach ($chauffeurs as $c) {
    if ($c['statut'] === 'Actif') $nombreActifs++;
}

/* =====================================================
   5. HEADER
===================================================== */

$titrePage  = 'Gestion des chauffeurs';
$sousTitre  = 'Enregistrez et gérez les chauffeurs de votre agence.';
$pageActive = 'chauffeurs.php';

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
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-6">
    <div class="bg-white rounded-xl shadow-sm p-5 border border-slate-200">
        <p class="text-sm text-slate-500">Total chauffeurs</p>
        <p class="text-3xl font-bold text-sky-700 mt-2"><?= $nombreChauffeurs ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5 border border-slate-200">
        <p class="text-sm text-slate-500">Chauffeurs actifs</p>
        <p class="text-3xl font-bold text-emerald-600 mt-2"><?= $nombreActifs ?></p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- FORMULAIRE -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <h3 class="text-lg font-bold text-slate-800 mb-5">
            <?= $edition ? 'Modifier un chauffeur' : 'Ajouter un chauffeur' ?>
        </h3>

        <form method="POST" action="chauffeurs.php" class="space-y-4">

            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
            <input type="hidden" name="action" value="<?= $edition ? 'modifier' : 'ajouter' ?>">
            <?php if ($edition): ?>
                <input type="hidden" name="id" value="<?= (int)$chauffeurEdition['id'] ?>">
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Nom *</label>
                <input type="text" name="nom" required
                       value="<?= $edition ? securiser($chauffeurEdition['nom']) : '' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Postnom</label>
                    <input type="text" name="postnom"
                           value="<?= $edition ? securiser($chauffeurEdition['postnom']) : '' ?>"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Prénom</label>
                    <input type="text" name="prenom"
                           value="<?= $edition ? securiser($chauffeurEdition['prenom']) : '' ?>"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Genre</label>
                <select name="genre"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="">Choisir</option>
                    <option value="Masculin" <?= $edition && $chauffeurEdition['genre'] === 'Masculin' ? 'selected' : '' ?>>Masculin</option>
                    <option value="Féminin" <?= $edition && $chauffeurEdition['genre'] === 'Féminin' ? 'selected' : '' ?>>Féminin</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Téléphone *</label>
                <input type="text" name="telephone" required
                       value="<?= $edition ? securiser($chauffeurEdition['telephone']) : '' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Adresse</label>
                <input type="text" name="adresse"
                       value="<?= $edition ? securiser($chauffeurEdition['adresse']) : '' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">N° permis</label>
                <input type="text" name="numero_permis"
                       value="<?= $edition ? securiser($chauffeurEdition['numero_permis']) : '' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Statut</label>
                <select name="statut"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="Actif" <?= $edition && $chauffeurEdition['statut'] === 'Actif' ? 'selected' : '' ?>>Actif</option>
                    <option value="Inactif" <?= $edition && $chauffeurEdition['statut'] === 'Inactif' ? 'selected' : '' ?>>Inactif</option>
                    <option value="Suspendu" <?= $edition && $chauffeurEdition['statut'] === 'Suspendu' ? 'selected' : '' ?>>Suspendu</option>
                </select>
            </div>

            <div class="flex gap-2 pt-2">
                <button type="submit"
                        class="flex-1 bg-sky-700 hover:bg-sky-800 text-white font-semibold py-3 rounded-lg">
                    <?= $edition ? 'Enregistrer les modifications' : 'Ajouter le chauffeur' ?>
                </button>
                <?php if ($edition): ?>
                    <a href="chauffeurs.php"
                       class="px-4 py-3 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700">Annuler</a>
                <?php endif; ?>
            </div>

        </form>

    </section>

    <!-- LISTE -->
    <section class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
            <h3 class="text-lg font-bold text-slate-800">Liste des chauffeurs</h3>
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
                        <th class="px-4 py-3">Téléphone</th>
                        <th class="px-4 py-3">Permis</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($chauffeurs)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-10 text-slate-500">
                                Aucun chauffeur enregistré.
                            </td>
                        </tr>
                    <?php else: foreach ($chauffeurs as $i => $c): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-slate-500"><?= $i + 1 ?></td>
                            <td class="px-4 py-3 font-semibold text-slate-800">
                                <?= securiser(trim(($c['nom'] ?? '') . ' ' . ($c['postnom'] ?? '') . ' ' . ($c['prenom'] ?? ''))) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600"><?= securiser($c['telephone'] ?? '-') ?></td>
                            <td class="px-4 py-3 text-slate-600"><?= securiser($c['numero_permis'] ?? '-') ?></td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?= $c['statut'] === 'Actif'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : ($c['statut'] === 'Suspendu'
                                            ? 'bg-red-100 text-red-700'
                                            : 'bg-slate-200 text-slate-600') ?>">
                                    <?= securiser($c['statut']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-2">
                                    <a href="chauffeurs.php?modifier=<?= (int)$c['id'] ?>"
                                       class="bg-amber-100 text-amber-700 hover:bg-amber-200 px-3 py-2 rounded-lg">
                                        Modifier
                                    </a>
                                    <form method="POST" data-app-confirm="Supprimer ce chauffeur ?" data-app-confirm-title="Supprimer le chauffeur" data-app-confirm-label="Supprimer">
                                        <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
                                        <input type="hidden" name="action" value="supprimer">
                                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
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
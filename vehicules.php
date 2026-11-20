<?php
require_once "config.php";
exigerConnexion();

$agenceId = (int)($_SESSION['agence_id'] ?? 0);

/* =====================================================
   1. SECURITE CSRF
===================================================== */

if (empty($_SESSION['csrf_vehicules'])) {
    $_SESSION['csrf_vehicules'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_vehicules'];

function verifierCSRFVehicules($token): bool
{
    return isset($_SESSION['csrf_vehicules'])
        && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_vehicules'], $token);
}

$message = '';
$typeMessage = 'success';
$edition = false;
$vehiculeEdition = null;
$recherche = trim($_GET['recherche'] ?? '');

/* =====================================================
   2. TRAITEMENT FORMULAIRES
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFVehicules($_POST['csrf_token'] ?? null)) {

        $message = "Formulaire expiré. Rechargez la page.";
        $typeMessage = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'ajouter' || $action === 'modifier') {

            $id             = (int)($_POST['id'] ?? 0);
            $code           = trim($_POST['code_vehicule'] ?? '');
            $marque         = trim($_POST['marque'] ?? '');
            $modele         = trim($_POST['modele'] ?? '');
            $immatriculation= trim($_POST['immatriculation'] ?? '');
            $places         = (int)($_POST['nombre_places'] ?? 0);
            $statut         = trim($_POST['statut'] ?? 'Disponible');

            if ($code === '' || $marque === '' || $immatriculation === '') {

                $message = "Le code, la marque et l'immatriculation sont obligatoires.";
                $typeMessage = 'error';

            } elseif ($places < 0) {

                $message = "Le nombre de places ne peut pas être négatif.";
                $typeMessage = 'error';

            } elseif (!in_array($statut, ['Disponible', 'En voyage', 'En maintenance', 'Hors service'], true)) {

                $message = "Statut invalide.";
                $typeMessage = 'error';

            } else {

                try {

                    if ($action === 'ajouter') {

                        $stmt = $pdo->prepare("
                            INSERT INTO vehicules
                            (agence_id, code_vehicule, marque, modele,
                             immatriculation, nombre_places, statut)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");

                        $stmt->execute([
                            $agenceId, $code, $marque, $modele,
                            $immatriculation, $places, $statut
                        ]);

                        $message = "Le véhicule a été ajouté avec succès.";

                    } else {

                        if ($id <= 0) throw new Exception("Identifiant invalide.");

                        $stmt = $pdo->prepare("
                            UPDATE vehicules
                            SET code_vehicule = ?, marque = ?, modele = ?,
                                immatriculation = ?, nombre_places = ?, statut = ?
                            WHERE id = ? AND agence_id = ?
                        ");

                        $stmt->execute([
                            $code, $marque, $modele, $immatriculation,
                            $places, $statut, $id, $agenceId
                        ]);

                        $message = "Le véhicule a été modifié avec succès.";
                    }

                    $typeMessage = 'success';

                } catch (PDOException $e) {

                    if ($e->getCode() === '23000') {
                        $message = "Ce code ou cette immatriculation existe déjà.";
                    } else {
                        $message = "Erreur : " . $e->getMessage();
                    }

                    $typeMessage = 'error';

                } catch (Exception $e) {
                    $message = $e->getMessage();
                    $typeMessage = 'error';
                }
            }
        }

        elseif ($action === 'supprimer') {

            $id = (int)($_POST['id'] ?? 0);

            if ($id > 0) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM vehicules WHERE id = ? AND agence_id = ?");
                    $stmt->execute([$id, $agenceId]);

                    $ok = $stmt->rowCount() > 0;
                    $message = $ok ? "Véhicule supprimé." : "Véhicule introuvable.";
                    $typeMessage = $ok ? 'success' : 'error';

                } catch (PDOException $e) {
                    $message = "Impossible de supprimer : ce véhicule est utilisé dans un voyage.";
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

    $stmt = $pdo->prepare("SELECT * FROM vehicules WHERE id = ? AND agence_id = ?");
    $stmt->execute([$id, $agenceId]);

    $vehiculeEdition = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($vehiculeEdition) {
        $edition = true;
    } else {
        $message = "Véhicule introuvable.";
        $typeMessage = 'error';
    }
}

/* =====================================================
   4. LISTE
===================================================== */

$sql = "SELECT * FROM vehicules WHERE agence_id = ?";
$params = [$agenceId];

if ($recherche !== '') {
    $sql .= " AND (code_vehicule LIKE ? OR marque LIKE ? OR modele LIKE ? OR immatriculation LIKE ?)";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehicules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nombreVehicules = count($vehicules);
$nombreDisponibles = 0;
foreach ($vehicules as $v) {
    if ($v['statut'] === 'Disponible') $nombreDisponibles++;
}

/* =====================================================
   5. HEADER
===================================================== */

$titrePage  = 'Gestion des véhicules';
$sousTitre  = 'Gérez le parc automobile de votre agence.';
$pageActive = 'vehicules.php';

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

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-6">
    <div class="bg-white rounded-xl shadow-sm p-5 border border-slate-200">
        <p class="text-sm text-slate-500">Total véhicules</p>
        <p class="text-3xl font-bold text-sky-700 mt-2"><?= $nombreVehicules ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5 border border-slate-200">
        <p class="text-sm text-slate-500">Véhicules disponibles</p>
        <p class="text-3xl font-bold text-emerald-600 mt-2"><?= $nombreDisponibles ?></p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- FORMULAIRE -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <h3 class="text-lg font-bold text-slate-800 mb-5">
            <?= $edition ? 'Modifier un véhicule' : 'Ajouter un véhicule' ?>
        </h3>

        <form method="POST" action="vehicules.php" class="space-y-4">

            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
            <input type="hidden" name="action" value="<?= $edition ? 'modifier' : 'ajouter' ?>">
            <?php if ($edition): ?>
                <input type="hidden" name="id" value="<?= (int)$vehiculeEdition['id'] ?>">
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Code véhicule *</label>
                <input type="text" name="code_vehicule" required
                       value="<?= $edition ? securiser($vehiculeEdition['code_vehicule']) : '' ?>"
                       placeholder="Ex : VH-001"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Marque *</label>
                    <input type="text" name="marque" required
                           value="<?= $edition ? securiser($vehiculeEdition['marque']) : '' ?>"
                           placeholder="Toyota"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Modèle</label>
                    <input type="text" name="modele"
                           value="<?= $edition ? securiser($vehiculeEdition['modele']) : '' ?>"
                           placeholder="Hiace"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Immatriculation *</label>
                <input type="text" name="immatriculation" required
                       value="<?= $edition ? securiser($vehiculeEdition['immatriculation']) : '' ?>"
                       placeholder="Ex : 1234AB01"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Nombre de places</label>
                <input type="number" min="0" name="nombre_places"
                       value="<?= $edition ? (int)$vehiculeEdition['nombre_places'] : '0' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Statut</label>
                <select name="statut"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <?php
                    $statuts = ['Disponible', 'En voyage', 'En maintenance', 'Hors service'];
                    foreach ($statuts as $s): ?>
                        <option value="<?= securiser($s) ?>"
                            <?= $edition && $vehiculeEdition['statut'] === $s ? 'selected' : '' ?>>
                            <?= securiser($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex gap-2 pt-2">
                <button type="submit"
                        class="flex-1 bg-sky-700 hover:bg-sky-800 text-white font-semibold py-3 rounded-lg">
                    <?= $edition ? 'Enregistrer les modifications' : 'Ajouter le véhicule' ?>
                </button>
                <?php if ($edition): ?>
                    <a href="vehicules.php"
                       class="px-4 py-3 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700">Annuler</a>
                <?php endif; ?>
            </div>

        </form>

    </section>

    <!-- LISTE -->
    <section class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
            <h3 class="text-lg font-bold text-slate-800">Liste des véhicules</h3>
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
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Marque / Modèle</th>
                        <th class="px-4 py-3">Immatriculation</th>
                        <th class="px-4 py-3">Places</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($vehicules)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-10 text-slate-500">
                                Aucun véhicule enregistré.
                            </td>
                        </tr>
                    <?php else: foreach ($vehicules as $i => $v): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-slate-500"><?= $i + 1 ?></td>
                            <td class="px-4 py-3 font-semibold text-slate-800"><?= securiser($v['code_vehicule']) ?></td>
                            <td class="px-4 py-3 text-slate-600">
                                <?= securiser($v['marque']) ?>
                                <?= $v['modele'] ? ' - ' . securiser($v['modele']) : '' ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600"><?= securiser($v['immatriculation']) ?></td>
                            <td class="px-4 py-3 text-slate-600"><?= (int)$v['nombre_places'] ?></td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?php
                                    echo match($v['statut']) {
                                        'Disponible'     => 'bg-emerald-100 text-emerald-700',
                                        'En voyage'      => 'bg-sky-100 text-sky-700',
                                        'En maintenance' => 'bg-amber-100 text-amber-700',
                                        default          => 'bg-red-100 text-red-700',
                                    };
                                    ?>">
                                    <?= securiser($v['statut']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-2">
                                    <a href="vehicules.php?modifier=<?= (int)$v['id'] ?>"
                                       class="bg-amber-100 text-amber-700 hover:bg-amber-200 px-3 py-2 rounded-lg">
                                        Modifier
                                    </a>
                                    <form method="POST" data-app-confirm="Supprimer ce véhicule ?" data-app-confirm-title="Supprimer le véhicule" data-app-confirm-label="Supprimer">
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
<?php
require_once "config.php";
exigerConnexion();

if (empty($_SESSION['csrf_milieux'])) {
    $_SESSION['csrf_milieux'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_milieux'];

function verifierCSRFMilieux($token): bool
{
    return isset($_SESSION['csrf_milieux'])
        && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_milieux'], $token);
}

$agenceId    = (int)($_SESSION['agence_id'] ?? 0);
$message     = '';
$typeMessage = 'success';
$edition     = false;
$milieuEdition = null;
$recherche   = trim($_GET['recherche'] ?? '');

/* Vérification de la table milieux */
try {
    $verification = $pdo->query("SHOW TABLES LIKE 'milieux'");
    if (!$verification->fetch()) {
        die("Erreur : la table milieux n'existe pas. Exécutez installation.php.");
    }
    $colonnes = $pdo->query("SHOW COLUMNS FROM milieux")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur : " . securiser($e->getMessage()));
}

function trouverColonneMilieux(array $colonnes, array $possibilites)
{
    foreach ($possibilites as $p) {
        foreach ($colonnes as $c) {
            if (strtolower($c['Field']) === strtolower($p)) return $c['Field'];
        }
    }
    return null;
}

$colId       = trouverColonneMilieux($colonnes, ['id', 'id_milieu']);
$colNom      = trouverColonneMilieux($colonnes, ['nom_milieu', 'nom', 'milieu', 'libelle']);
$colType     = trouverColonneMilieux($colonnes, ['type_milieu', 'type']);
$colProvince = trouverColonneMilieux($colonnes, ['province', 'nom_province']);
$colStatut   = trouverColonneMilieux($colonnes, ['statut', 'status', 'etat']);
$colAgence   = trouverColonneMilieux($colonnes, ['agence_id', 'id_agence']);

if (!$colId || !$colNom) {
    die("Colonnes principales de milieux introuvables.");
}

/* Traitement POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFMilieux($_POST['csrf_token'] ?? null)) {
        $message = "Formulaire expiré. Rechargez la page.";
        $typeMessage = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'ajouter') {
            $nom = trim($_POST['nom_milieu'] ?? '');
            $type = trim($_POST['type_milieu'] ?? '');
            $province = trim($_POST['province'] ?? '');

            if ($nom === '') {
                $message = "Veuillez saisir le nom du milieu.";
                $typeMessage = 'error';
            } else {
                $sql = "SELECT COUNT(*) FROM milieux WHERE LOWER(`$colNom`) = LOWER(?)";
                $params = [$nom];
                if ($colAgence && $agenceId > 0) {
                    $sql .= " AND `$colAgence` = ?";
                    $params[] = $agenceId;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                if ((int)$stmt->fetchColumn() > 0) {
                    $message = "Ce milieu existe déjà.";
                    $typeMessage = 'error';
                } else {
                    $champs = ["`$colNom`"]; $valeurs = [$nom];
                    if ($colType)     { $champs[] = "`$colType`";     $valeurs[] = $type; }
                    if ($colProvince) { $champs[] = "`$colProvince`"; $valeurs[] = $province; }
                    if ($colStatut)   { $champs[] = "`$colStatut`";   $valeurs[] = 'Actif'; }
                    if ($colAgence && $agenceId > 0) { $champs[] = "`$colAgence`"; $valeurs[] = $agenceId; }

                    $points = implode(', ', array_fill(0, count($champs), '?'));
                    $stmt = $pdo->prepare("INSERT INTO milieux (" . implode(', ', $champs) . ") VALUES ($points)");
                    $stmt->execute($valeurs);

                    $message = "Le milieu a été ajouté avec succès.";
                }
            }
        } elseif ($action === 'modifier') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = trim($_POST['nom_milieu'] ?? '');
            $type = trim($_POST['type_milieu'] ?? '');
            $province = trim($_POST['province'] ?? '');

            if ($id <= 0 || $nom === '') {
                $message = "Informations de modification invalides.";
                $typeMessage = 'error';
            } else {
                $sets = ["`$colNom` = ?"]; $valeurs = [$nom];
                if ($colType)     { $sets[] = "`$colType` = ?";     $valeurs[] = $type; }
                if ($colProvince) { $sets[] = "`$colProvince` = ?"; $valeurs[] = $province; }

                $sql = "UPDATE milieux SET " . implode(', ', $sets) . " WHERE `$colId` = ?";
                $valeurs[] = $id;
                if ($colAgence && $agenceId > 0) {
                    $sql .= " AND `$colAgence` = ?";
                    $valeurs[] = $agenceId;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($valeurs);

                $message = "Le milieu a été modifié avec succès.";
            }
        } elseif ($action === 'supprimer') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $message = "Identifiant invalide.";
                $typeMessage = 'error';
            } else {
                $sql = "DELETE FROM milieux WHERE `$colId` = ?";
                $valeurs = [$id];
                if ($colAgence && $agenceId > 0) {
                    $sql .= " AND `$colAgence` = ?";
                    $valeurs[] = $agenceId;
                }
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($valeurs);
                    $ok = $stmt->rowCount() > 0;
                    $message = $ok ? "Le milieu a été supprimé." : "Milieu introuvable.";
                    $typeMessage = $ok ? 'success' : 'error';
                } catch (PDOException $e) {
                    $message = "Impossible de supprimer : ce milieu est utilisé ailleurs.";
                    $typeMessage = 'error';
                }
            }
        }
    }
}

/* Édition */
if (isset($_GET['modifier'])) {
    $id = (int)$_GET['modifier'];
    $sql = "SELECT * FROM milieux WHERE `$colId` = ?";
    $valeurs = [$id];
    if ($colAgence && $agenceId > 0) { $sql .= " AND `$colAgence` = ?"; $valeurs[] = $agenceId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($valeurs);
    $milieuEdition = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($milieuEdition) { $edition = true; }
    else { $message = "Milieu introuvable."; $typeMessage = 'error'; }
}

/* Liste */
$sql = "SELECT * FROM milieux WHERE 1=1";
$valeurs = [];
if ($colAgence && $agenceId > 0) { $sql .= " AND `$colAgence` = ?"; $valeurs[] = $agenceId; }
if ($recherche !== '') { $sql .= " AND `$colNom` LIKE ?"; $valeurs[] = "%$recherche%"; }
$sql .= " ORDER BY `$colNom` ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($valeurs);
$milieux = $stmt->fetchAll(PDO::FETCH_ASSOC);
$nombreMilieux = count($milieux);

/* Header commun */
$titrePage   = 'Gestion des milieux';
$sousTitre   = 'Enregistrez les villes et localités de vos itinéraires.';
$pageActive  = 'milieux.php';
require 'header.php';
?>

<?php if ($message !== ''): ?>
    <div class="mb-5 p-4 rounded-xl border <?= $typeMessage === 'success' ? 'bg-green-50 text-green-800 border-green-200' : 'bg-red-50 text-red-800 border-red-200' ?>">
        <?= securiser($message) ?>
    </div>
<?php endif; ?>

<div class="bg-white rounded-xl shadow-sm p-5 mb-6 border border-slate-200">
    <p class="text-sm text-slate-500">Nombre de milieux enregistrés</p>
    <p class="text-3xl font-bold text-sky-700 mt-2"><?= $nombreMilieux ?></p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <h3 class="text-lg font-bold text-slate-800 mb-5">
            <?= $edition ? 'Modifier un milieu' : 'Ajouter un milieu' ?>
        </h3>

        <form method="POST" action="milieux.php" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
            <input type="hidden" name="action" value="<?= $edition ? 'modifier' : 'ajouter' ?>">
            <?php if ($edition): ?>
                <input type="hidden" name="id" value="<?= (int)$milieuEdition[$colId] ?>">
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Nom du milieu *</label>
                <input type="text" name="nom_milieu" required
                       value="<?= $edition ? securiser($milieuEdition[$colNom] ?? '') : '' ?>"
                       placeholder="Ex : Kinshasa"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <?php if ($colType): ?>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Type</label>
                    <select name="type_milieu"
                            class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                        <option value="">Choisir</option>
                        <?php
                        $types = ['Ville','Territoire','Commune','Localité','Village','Autre'];
                        $actuel = $edition ? ($milieuEdition[$colType] ?? '') : '';
                        foreach ($types as $t): ?>
                            <option value="<?= securiser($t) ?>" <?= $actuel === $t ? 'selected' : '' ?>>
                                <?= securiser($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($colProvince): ?>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Province</label>
                    <input type="text" name="province"
                           value="<?= $edition ? securiser($milieuEdition[$colProvince] ?? '') : '' ?>"
                           placeholder="Ex : Kinshasa"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
            <?php endif; ?>

            <div class="flex gap-2 pt-2">
                <button type="submit"
                        class="flex-1 bg-sky-700 hover:bg-sky-800 text-white font-semibold py-3 rounded-lg">
                    <?= $edition ? 'Enregistrer les modifications' : 'Ajouter le milieu' ?>
                </button>
                <?php if ($edition): ?>
                    <a href="milieux.php"
                       class="px-4 py-3 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700">Annuler</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
            <h3 class="text-lg font-bold text-slate-800">Liste des milieux</h3>
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
                        <th class="px-4 py-3">Milieu</th>
                        <?php if ($colType): ?><th class="px-4 py-3">Type</th><?php endif; ?>
                        <?php if ($colProvince): ?><th class="px-4 py-3">Province</th><?php endif; ?>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($milieux)): ?>
                        <tr>
                            <td colspan="<?= 3 + (int)(bool)$colType + (int)(bool)$colProvince ?>"
                                class="text-center py-10 text-slate-500">
                                Aucun milieu enregistré.
                            </td>
                        </tr>
                    <?php else: foreach ($milieux as $i => $m): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-slate-500"><?= $i + 1 ?></td>
                            <td class="px-4 py-3 font-semibold text-slate-800"><?= securiser($m[$colNom] ?? '') ?></td>
                            <?php if ($colType): ?><td class="px-4 py-3 text-slate-600"><?= securiser($m[$colType] ?? '-') ?></td><?php endif; ?>
                            <?php if ($colProvince): ?><td class="px-4 py-3 text-slate-600"><?= securiser($m[$colProvince] ?? '-') ?></td><?php endif; ?>
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-2">
                                    <a href="milieux.php?modifier=<?= (int)$m[$colId] ?>"
                                       class="bg-amber-100 text-amber-700 hover:bg-amber-200 px-3 py-2 rounded-lg">
                                        Modifier
                                    </a>
                                    <form method="POST" data-app-confirm="Supprimer ce milieu ?" data-app-confirm-title="Supprimer le milieu" data-app-confirm-label="Supprimer">
                                        <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
                                        <input type="hidden" name="action" value="supprimer">
                                        <input type="hidden" name="id" value="<?= (int)$m[$colId] ?>">
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
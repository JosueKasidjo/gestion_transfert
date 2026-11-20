<?php
require_once "config.php";
exigerConnexion();
exigerRole(['Administrateur']);

$agenceId = (int)($_SESSION['agence_id'] ?? 0);

/* =====================================================
   1. SECURITE CSRF
===================================================== */

if (empty($_SESSION['csrf_agences'])) {
    $_SESSION['csrf_agences'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_agences'];

function verifierCSRFAgences($token): bool
{
    return isset($_SESSION['csrf_agences'])
        && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_agences'], $token);
}

$message = '';
$typeMessage = 'success';
$edition = false;
$agenceEdition = null;
$recherche = trim($_GET['recherche'] ?? '');

/* =====================================================
   2. TRAITEMENT FORMULAIRES
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFAgences($_POST['csrf_token'] ?? null)) {

        $message = "Formulaire expiré. Rechargez la page.";
        $typeMessage = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'ajouter' || $action === 'modifier') {

            $id             = (int)($_POST['id'] ?? 0);
            $nom            = trim($_POST['nom_agence'] ?? '');
            $code           = strtoupper(trim($_POST['code_agence'] ?? ''));
            $typeAgence     = trim($_POST['type_agence'] ?? 'Principale');
            $parentId       = (int)($_POST['agence_parent_id'] ?? 0);
            $adresse        = trim($_POST['adresse'] ?? '');
            $milieu         = trim($_POST['milieu'] ?? '');
            $telephone1     = trim($_POST['telephone1'] ?? '');
            $telephone2     = trim($_POST['telephone2'] ?? '');
            $email          = trim($_POST['email'] ?? '');
            $statut         = trim($_POST['statut'] ?? 'Actif');

            if ($nom === '' || $code === '') {

                $message = "Le nom et le code de l'agence sont obligatoires.";
                $typeMessage = 'error';

            } elseif (!preg_match('/^[A-Z0-9\-_]+$/', $code)) {

                $message = "Le code doit contenir uniquement des lettres majuscules, chiffres, tirets ou underscores.";
                $typeMessage = 'error';

            } elseif (!in_array($typeAgence, ['Principale', 'Succursale', 'Agence'], true)) {

                $message = "Type d'agence invalide.";
                $typeMessage = 'error';

            } elseif (!in_array($statut, ['Actif', 'Inactif'], true)) {

                $message = "Statut invalide.";
                $typeMessage = 'error';

            } elseif ($parentId === $id && $id > 0) {

                $message = "Une agence ne peut pas être son propre parent.";
                $typeMessage = 'error';

            } else {

                try {

                    if ($action === 'ajouter') {

                        $stmt = $pdo->prepare("
                            INSERT INTO agences
                            (agence_parent_id, nom_agence, code_agence, type_agence,
                             adresse, milieu, telephone1, telephone2, email, statut)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $stmt->execute([
                            $parentId > 0 ? $parentId : null,
                            $nom, $code, $typeAgence,
                            $adresse, $milieu,
                            $telephone1, $telephone2, $email, $statut
                        ]);

                        $message = "L'agence a été créée avec succès.";

                    } else {

                        if ($id <= 0) throw new Exception("Identifiant invalide.");

                        $stmt = $pdo->prepare("
                            UPDATE agences
                            SET agence_parent_id = ?, nom_agence = ?, code_agence = ?,
                                type_agence = ?, adresse = ?, milieu = ?,
                                telephone1 = ?, telephone2 = ?, email = ?, statut = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $parentId > 0 ? $parentId : null,
                            $nom, $code, $typeAgence,
                            $adresse, $milieu,
                            $telephone1, $telephone2, $email, $statut,
                            $id
                        ]);

                        $message = "L'agence a été modifiée avec succès.";
                    }

                    $typeMessage = 'success';

                } catch (PDOException $e) {

                    if ($e->getCode() === '23000') {
                        $message = "Ce code d'agence existe déjà.";
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

                // Empêcher la suppression de sa propre agence
                if ($id === $agenceId) {

                    $message = "Vous ne pouvez pas supprimer votre propre agence.";
                    $typeMessage = 'error';

                } else {
                    try {
                        $stmt = $pdo->prepare("DELETE FROM agences WHERE id = ?");
                        $stmt->execute([$id]);

                        $ok = $stmt->rowCount() > 0;
                        $message = $ok ? "Agence supprimée." : "Agence introuvable.";
                        $typeMessage = $ok ? 'success' : 'error';

                    } catch (PDOException $e) {
                        $message = "Impossible de supprimer : cette agence est liée à des utilisateurs, voyages ou autres données.";
                        $typeMessage = 'error';
                    }
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

    $stmt = $pdo->prepare("SELECT * FROM agences WHERE id = ?");
    $stmt->execute([$id]);

    $agenceEdition = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($agenceEdition) {
        $edition = true;
    } else {
        $message = "Agence introuvable.";
        $typeMessage = 'error';
    }
}

/* =====================================================
   4. LISTE DES AGENCES PARENTS POSSIBLES
===================================================== */

$sqlParents = "SELECT id, nom_agence FROM agences WHERE 1=1";
$paramsParents = [];

if ($edition && $agenceEdition) {
    // Exclure l'agence en cours d'édition et ses descendants directs
    $sqlParents .= " AND id != ?";
    $paramsParents[] = (int)$agenceEdition['id'];
}

$sqlParents .= " ORDER BY nom_agence ASC";

$stmt = $pdo->prepare($sqlParents);
$stmt->execute($paramsParents);
$agencesParents = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   5. LISTE PRINCIPALE
===================================================== */

$sql = "SELECT a.*,
               p.nom_agence AS parent_nom,
               (SELECT COUNT(*) FROM utilisateurs u WHERE u.agence_id = a.id) AS nb_utilisateurs,
               (SELECT COUNT(*) FROM voyages v WHERE v.agence_id = a.id) AS nb_voyages
        FROM agences a
        LEFT JOIN agences p ON p.id = a.agence_parent_id
        WHERE 1=1";

$params = [];

if ($recherche !== '') {
    $sql .= " AND (a.nom_agence LIKE ? OR a.code_agence LIKE ? OR a.milieu LIKE ?)";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
}

$sql .= " ORDER BY a.type_agence ASC, a.nom_agence ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$agences = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nombreAgences = count($agences);
$nombreActives = 0;
foreach ($agences as $a) {
    if ($a['statut'] === 'Actif') $nombreActives++;
}

/* =====================================================
   6. HEADER
===================================================== */

$titrePage  = 'Gestion des agences';
$sousTitre  = 'Gérez les agences et leurs succursales.';
$pageActive = 'agences.php';

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
        <p class="text-sm text-slate-500">Total agences</p>
        <p class="text-3xl font-bold text-sky-700 mt-2"><?= $nombreAgences ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5 border border-slate-200">
        <p class="text-sm text-slate-500">Agences actives</p>
        <p class="text-3xl font-bold text-emerald-600 mt-2"><?= $nombreActives ?></p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- FORMULAIRE -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <h3 class="text-lg font-bold text-slate-800 mb-5">
            <?= $edition ? 'Modifier une agence' : 'Créer une agence' ?>
        </h3>

        <form method="POST" action="agences.php" class="space-y-4">

            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
            <input type="hidden" name="action" value="<?= $edition ? 'modifier' : 'ajouter' ?>">
            <?php if ($edition): ?>
                <input type="hidden" name="id" value="<?= (int)$agenceEdition['id'] ?>">
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Nom de l'agence *</label>
                <input type="text" name="nom_agence" required
                       value="<?= $edition ? securiser($agenceEdition['nom_agence']) : '' ?>"
                       placeholder="Ex : Agence Kinshasa"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Code agence *</label>
                <input type="text" name="code_agence" required
                       value="<?= $edition ? securiser($agenceEdition['code_agence']) : '' ?>"
                       placeholder="Ex : AG-KIN"
                       pattern="[A-Z0-9\-_]+"
                       title="Lettres majuscules, chiffres, tirets et underscores uniquement"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none uppercase">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Type d'agence</label>
                <select name="type_agence"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <?php foreach (['Principale', 'Succursale', 'Agence'] as $t): ?>
                        <option value="<?= securiser($t) ?>"
                            <?= $edition && $agenceEdition['type_agence'] === $t ? 'selected' : '' ?>>
                            <?= securiser($t) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Agence parente</label>
                <select name="agence_parent_id"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="">Aucune (agence racine)</option>
                    <?php foreach ($agencesParents as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"
                            <?= $edition && (int)$agenceEdition['agence_parent_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= securiser($p['nom_agence']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Milieu / Ville</label>
                <input type="text" name="milieu"
                       value="<?= $edition ? securiser($agenceEdition['milieu']) : '' ?>"
                       placeholder="Ex : Kinshasa"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Adresse</label>
                <input type="text" name="adresse"
                       value="<?= $edition ? securiser($agenceEdition['adresse']) : '' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Téléphone 1</label>
                    <input type="text" name="telephone1"
                           value="<?= $edition ? securiser($agenceEdition['telephone1']) : '' ?>"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Téléphone 2</label>
                    <input type="text" name="telephone2"
                           value="<?= $edition ? securiser($agenceEdition['telephone2']) : '' ?>"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                <input type="email" name="email"
                       value="<?= $edition ? securiser($agenceEdition['email']) : '' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Statut</label>
                <select name="statut"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="Actif" <?= $edition && $agenceEdition['statut'] === 'Actif' ? 'selected' : '' ?>>Actif</option>
                    <option value="Inactif" <?= $edition && $agenceEdition['statut'] === 'Inactif' ? 'selected' : '' ?>>Inactif</option>
                </select>
            </div>

            <div class="flex gap-2 pt-2">
                <button type="submit"
                        class="flex-1 bg-sky-700 hover:bg-sky-800 text-white font-semibold py-3 rounded-lg">
                    <?= $edition ? 'Enregistrer les modifications' : 'Créer l\'agence' ?>
                </button>
                <?php if ($edition): ?>
                    <a href="agences.php"
                       class="px-4 py-3 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700">Annuler</a>
                <?php endif; ?>
            </div>

        </form>

    </section>

    <!-- LISTE -->
    <section class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
            <h3 class="text-lg font-bold text-slate-800">Liste des agences</h3>
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
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Nom</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Parente</th>
                        <th class="px-4 py-3">Milieu</th>
                        <th class="px-4 py-3">Utilisateurs</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($agences)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-10 text-slate-500">
                                Aucune agence enregistrée.
                            </td>
                        </tr>
                    <?php else: foreach ($agences as $a): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-semibold text-slate-800 whitespace-nowrap">
                                <?= securiser($a['code_agence']) ?>
                            </td>
                            <td class="px-4 py-3 font-semibold text-slate-800">
                                <?= securiser($a['nom_agence']) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                <span class="px-2 py-1 rounded text-xs font-semibold
                                    <?php
                                    echo match($a['type_agence']) {
                                        'Principale' => 'bg-sky-100 text-sky-700',
                                        'Succursale' => 'bg-amber-100 text-amber-700',
                                        default      => 'bg-slate-100 text-slate-600',
                                    };
                                    ?>">
                                    <?= securiser($a['type_agence']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                <?= securiser($a['parent_nom'] ?? '—') ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                <?= securiser($a['milieu'] ?: '-') ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 rounded-full bg-sky-100 text-sky-700 text-xs font-semibold">
                                    <?= (int)$a['nb_utilisateurs'] ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?= $a['statut'] === 'Actif'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-red-100 text-red-700' ?>">
                                    <?= securiser($a['statut']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-2">
                                    <a href="agences.php?modifier=<?= (int)$a['id'] ?>"
                                       class="bg-amber-100 text-amber-700 hover:bg-amber-200 px-3 py-2 rounded-lg">
                                        Modifier
                                    </a>
                                    <?php if ((int)$a['id'] !== $agenceId): ?>
                                        <form method="POST" data-app-confirm="Supprimer cette agence ? Cette action est irréversible." data-app-confirm-title="Supprimer l’agence" data-app-confirm-label="Supprimer">
                                            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
                                            <input type="hidden" name="action" value="supprimer">
                                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                            <button type="submit"
                                                    class="bg-red-100 text-red-700 hover:bg-red-200 px-3 py-2 rounded-lg">
                                                Supprimer
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="px-3 py-2 text-xs text-slate-400 italic">Agence courante</span>
                                    <?php endif; ?>
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
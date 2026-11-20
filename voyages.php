<?php
require_once "config.php";
exigerConnexion();

$agenceId = (int)($_SESSION['agence_id'] ?? 0);

/* =====================================================
   1. SECURITE CSRF
===================================================== */

if (empty($_SESSION['csrf_voyages'])) {
    $_SESSION['csrf_voyages'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_voyages'];

function verifierCSRFVoyages($token): bool
{
    return isset($_SESSION['csrf_voyages'])
        && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_voyages'], $token);
}

$message = '';
$typeMessage = 'success';
$edition = false;
$voyageEdition = null;
$recherche = trim($_GET['recherche'] ?? '');

/* =====================================================
   2. CHARGEMENT DES LISTES
===================================================== */

// Chauffeurs actifs de l'agence
$stmt = $pdo->prepare("SELECT id, nom, postnom, prenom FROM chauffeurs
                       WHERE agence_id = ? AND statut = 'Actif' ORDER BY nom ASC");
$stmt->execute([$agenceId]);
$chauffeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Véhicules disponibles
$stmt = $pdo->prepare("SELECT id, code_vehicule, marque, modele FROM vehicules
                       WHERE agence_id = ? AND statut = 'Disponible' ORDER BY code_vehicule ASC");
$stmt->execute([$agenceId]);
$vehicules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Milieux
$stmt = $pdo->query("SELECT id, nom_milieu FROM milieux ORDER BY nom_milieu ASC");
$milieux = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   3. TRAITEMENT FORMULAIRES
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFVoyages($_POST['csrf_token'] ?? null)) {

        $message = "Formulaire expiré. Rechargez la page.";
        $typeMessage = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'ajouter' || $action === 'modifier') {

            $id             = (int)($_POST['id'] ?? 0);
            $chauffeurId    = (int)($_POST['chauffeur_id'] ?? 0);
            $vehiculeId     = (int)($_POST['vehicule_id'] ?? 0);
            $departId       = (int)($_POST['milieu_depart_id'] ?? 0);
            $arriveeId      = (int)($_POST['milieu_arrivee_id'] ?? 0);
            $dateDepart     = trim($_POST['date_depart'] ?? '');
            $dateArrivee    = trim($_POST['date_arrivee_prevue'] ?? '');
            $statut         = trim($_POST['statut'] ?? 'Programme');
            $observations   = trim($_POST['observations'] ?? '');

            if ($chauffeurId <= 0 || $vehiculeId <= 0 || $departId <= 0 || $arriveeId <= 0 || $dateDepart === '') {

                $message = "Tous les champs marqués * sont obligatoires.";
                $typeMessage = 'error';

            } elseif ($departId === $arriveeId) {

                $message = "Le départ et l'arrivée doivent être différents.";
                $typeMessage = 'error';

            } elseif (!in_array($statut, ['Programme', 'En cours', 'Termine', 'Annule'], true)) {

                $message = "Statut invalide.";
                $typeMessage = 'error';

            } else {

                try {

                    if ($action === 'ajouter') {

                        $code = genererReference('VOY');

                        $stmt = $pdo->prepare("
                            INSERT INTO voyages
                            (agence_id, chauffeur_id, vehicule_id,
                             milieu_depart_id, milieu_arrivee_id,
                             code_voyage, date_depart, date_arrivee_prevue,
                             statut, observations)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $stmt->execute([
                            $agenceId, $chauffeurId, $vehiculeId,
                            $departId, $arriveeId,
                            $code, $dateDepart,
                            $dateArrivee !== '' ? $dateArrivee : null,
                            $statut, $observations
                        ]);

                        $message = "Le voyage a été créé avec succès. Code : $code";

                    } else {

                        if ($id <= 0) throw new Exception("Identifiant invalide.");

                        $stmt = $pdo->prepare("
                            UPDATE voyages
                            SET chauffeur_id = ?, vehicule_id = ?,
                                milieu_depart_id = ?, milieu_arrivee_id = ?,
                                date_depart = ?, date_arrivee_prevue = ?,
                                statut = ?, observations = ?
                            WHERE id = ? AND agence_id = ?
                        ");

                        $stmt->execute([
                            $chauffeurId, $vehiculeId,
                            $departId, $arriveeId,
                            $dateDepart,
                            $dateArrivee !== '' ? $dateArrivee : null,
                            $statut, $observations,
                            $id, $agenceId
                        ]);

                        $message = "Le voyage a été modifié avec succès.";
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
                    $stmt = $pdo->prepare("DELETE FROM voyages WHERE id = ? AND agence_id = ?");
                    $stmt->execute([$id, $agenceId]);

                    $ok = $stmt->rowCount() > 0;
                    $message = $ok ? "Voyage supprimé." : "Voyage introuvable.";
                    $typeMessage = $ok ? 'success' : 'error';

                } catch (PDOException $e) {
                    $message = "Impossible de supprimer : ce voyage possède des billets ou colis.";
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

    $stmt = $pdo->prepare("SELECT * FROM voyages WHERE id = ? AND agence_id = ?");
    $stmt->execute([$id, $agenceId]);

    $voyageEdition = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($voyageEdition) {
        $edition = true;
    } else {
        $message = "Voyage introuvable.";
        $typeMessage = 'error';
    }
}

/* =====================================================
   5. LISTE
===================================================== */

$sql = "SELECT v.*,
               d.nom_milieu AS depart,
               a.nom_milieu AS arrivee,
               ch.nom AS ch_nom, ch.postnom AS ch_postnom, ch.prenom AS ch_prenom,
               ve.code_vehicule, ve.marque
        FROM voyages v
        INNER JOIN milieux d ON d.id = v.milieu_depart_id
        INNER JOIN milieux a ON a.id = v.milieu_arrivee_id
        INNER JOIN chauffeurs ch ON ch.id = v.chauffeur_id
        INNER JOIN vehicules ve ON ve.id = v.vehicule_id
        WHERE v.agence_id = ?";

$params = [$agenceId];

if ($recherche !== '') {
    $sql .= " AND (v.code_voyage LIKE ? OR d.nom_milieu LIKE ? OR a.nom_milieu LIKE ?)";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
}

$sql .= " ORDER BY v.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$voyages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nombreVoyages = count($voyages);

/* =====================================================
   6. HEADER
===================================================== */

$titrePage  = 'Gestion des voyages';
$sousTitre  = 'Planifiez et suivez les voyages de votre agence.';
$pageActive = 'voyages.php';

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
    <p class="text-sm text-slate-500">Nombre de voyages</p>
    <p class="text-3xl font-bold text-sky-700 mt-2"><?= $nombreVoyages ?></p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- FORMULAIRE -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <h3 class="text-lg font-bold text-slate-800 mb-5">
            <?= $edition ? 'Modifier un voyage' : 'Programmer un voyage' ?>
        </h3>

        <form method="POST" action="voyages.php" class="space-y-4">

            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
            <input type="hidden" name="action" value="<?= $edition ? 'modifier' : 'ajouter' ?>">
            <?php if ($edition): ?>
                <input type="hidden" name="id" value="<?= (int)$voyageEdition['id'] ?>">
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Chauffeur *</label>
                <select name="chauffeur_id" required
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="">Choisir</option>
                    <?php foreach ($chauffeurs as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"
                            <?= $edition && (int)$voyageEdition['chauffeur_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= securiser(trim($c['nom'] . ' ' . $c['postnom'] . ' ' . $c['prenom'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Véhicule *</label>
                <select name="vehicule_id" required
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="">Choisir</option>
                    <?php foreach ($vehicules as $v): ?>
                        <option value="<?= (int)$v['id'] ?>"
                            <?= $edition && (int)$voyageEdition['vehicule_id'] === (int)$v['id'] ? 'selected' : '' ?>>
                            <?= securiser($v['code_vehicule'] . ' - ' . $v['marque'] . ' ' . $v['modele']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Départ *</label>
                    <select name="milieu_depart_id" required
                            class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                        <option value="">Choisir</option>
                        <?php foreach ($milieux as $m): ?>
                            <option value="<?= (int)$m['id'] ?>"
                                <?= $edition && (int)$voyageEdition['milieu_depart_id'] === (int)$m['id'] ? 'selected' : '' ?>>
                                <?= securiser($m['nom_milieu']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Arrivée *</label>
                    <select name="milieu_arrivee_id" required
                            class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                        <option value="">Choisir</option>
                        <?php foreach ($milieux as $m): ?>
                            <option value="<?= (int)$m['id'] ?>"
                                <?= $edition && (int)$voyageEdition['milieu_arrivee_id'] === (int)$m['id'] ? 'selected' : '' ?>>
                                <?= securiser($m['nom_milieu']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Date de départ *</label>
                <input type="datetime-local" name="date_depart" required
                       value="<?= $edition ? date('Y-m-d\TH:i', strtotime($voyageEdition['date_depart'])) : '' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Date d'arrivée prévue</label>
                <input type="datetime-local" name="date_arrivee_prevue"
                       value="<?= $edition && $voyageEdition['date_arrivee_prevue'] ? date('Y-m-d\TH:i', strtotime($voyageEdition['date_arrivee_prevue'])) : '' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Statut</label>
                <select name="statut"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <?php
                    $statuts = ['Programme', 'En cours', 'Termine', 'Annule'];
                    foreach ($statuts as $s): ?>
                        <option value="<?= securiser($s) ?>"
                            <?= $edition && $voyageEdition['statut'] === $s ? 'selected' : '' ?>>
                            <?= securiser($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Observations</label>
                <textarea name="observations" rows="3"
                          class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none"><?= $edition ? securiser($voyageEdition['observations']) : '' ?></textarea>
            </div>

            <div class="flex gap-2 pt-2">
                <button type="submit"
                        class="flex-1 bg-sky-700 hover:bg-sky-800 text-white font-semibold py-3 rounded-lg">
                    <?= $edition ? 'Enregistrer les modifications' : 'Programmer le voyage' ?>
                </button>
                <?php if ($edition): ?>
                    <a href="voyages.php"
                       class="px-4 py-3 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700">Annuler</a>
                <?php endif; ?>
            </div>

        </form>

    </section>

    <!-- LISTE -->
    <section class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
            <h3 class="text-lg font-bold text-slate-800">Liste des voyages</h3>
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
                        <th class="px-4 py-3">Trajet</th>
                        <th class="px-4 py-3">Chauffeur</th>
                        <th class="px-4 py-3">Véhicule</th>
                        <th class="px-4 py-3">Départ</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($voyages)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-10 text-slate-500">
                                Aucun voyage programmé.
                            </td>
                        </tr>
                    <?php else: foreach ($voyages as $v): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-semibold text-slate-800 whitespace-nowrap">
                                <?= securiser($v['code_voyage']) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                <?= securiser($v['depart']) ?>
                                <span class="text-sky-500 mx-1">→</span>
                                <?= securiser($v['arrivee']) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                <?= securiser(trim($v['ch_nom'] . ' ' . $v['ch_postnom'] . ' ' . $v['ch_prenom'])) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                <?= securiser($v['code_vehicule']) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                <?= date('d/m/Y H:i', strtotime($v['date_depart'])) ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?php
                                    echo valeurCorrespondante($v['statut'], [
                                        'Programme' => 'bg-sky-100 text-sky-700',
                                        'En cours'  => 'bg-amber-100 text-amber-700',
                                        'Termine'   => 'bg-emerald-100 text-emerald-700',
                                    ], 'bg-red-100 text-red-700');
                                    ?>">
                                    <?= securiser($v['statut']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-2">
                                    <a href="voyages.php?modifier=<?= (int)$v['id'] ?>"
                                       class="bg-amber-100 text-amber-700 hover:bg-amber-200 px-3 py-2 rounded-lg">
                                        Modifier
                                    </a>
                                    <form method="POST" data-app-confirm="Supprimer ce voyage ?" data-app-confirm-title="Supprimer le voyage" data-app-confirm-label="Supprimer">
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
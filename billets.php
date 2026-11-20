<?php
require_once "config.php";
exigerConnexion();

$agenceId = (int)($_SESSION['agence_id'] ?? 0);

/* =====================================================
   1. SECURITE CSRF
===================================================== */

if (empty($_SESSION['csrf_billets'])) {
    $_SESSION['csrf_billets'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_billets'];

function verifierCSRFBillets($token): bool
{
    return isset($_SESSION['csrf_billets'])
        && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_billets'], $token);
}

$message = '';
$typeMessage = 'success';
$edition = false;
$billetEdition = null;
$recherche = trim($_GET['recherche'] ?? '');

/* =====================================================
   2. CHARGEMENT DES LISTES
===================================================== */

// Voyages de l'agence (non annulés)
$stmt = $pdo->prepare("
    SELECT v.id, v.code_voyage, v.date_depart,
           d.nom_milieu AS depart, a.nom_milieu AS arrivee,
           tarif_fc.prix_voyageur AS prix_fc,
           tarif_usd.prix_voyageur AS prix_usd
    FROM voyages v
    INNER JOIN milieux d ON d.id = v.milieu_depart_id
    INNER JOIN milieux a ON a.id = v.milieu_arrivee_id
    LEFT JOIN tarifs tarif_fc
        ON tarif_fc.milieu_depart_id = v.milieu_depart_id
        AND tarif_fc.milieu_arrivee_id = v.milieu_arrivee_id
        AND tarif_fc.devise = 'FC' AND tarif_fc.statut = 'Actif'
    LEFT JOIN tarifs tarif_usd
        ON tarif_usd.milieu_depart_id = v.milieu_depart_id
        AND tarif_usd.milieu_arrivee_id = v.milieu_arrivee_id
        AND tarif_usd.devise = 'USD' AND tarif_usd.statut = 'Actif'
    WHERE v.agence_id = ? AND v.statut != 'Annule'
    ORDER BY v.date_depart DESC
");
$stmt->execute([$agenceId]);
$voyages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Voyageurs de l'agence
$stmt = $pdo->prepare("SELECT id, nom_complet FROM voyageurs WHERE agence_id = ? ORDER BY nom_complet ASC");
$stmt->execute([$agenceId]);
$voyageurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   3. TRAITEMENT FORMULAIRES
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFBillets($_POST['csrf_token'] ?? null)) {

        $message = "Formulaire expiré. Rechargez la page.";
        $typeMessage = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'ajouter' || $action === 'modifier') {

            $id             = (int)($_POST['id'] ?? 0);
            $voyageId       = (int)($_POST['voyage_id'] ?? 0);
            $voyageurId     = (int)($_POST['voyageur_id'] ?? 0);
            $prix           = str_replace(',', '.', trim($_POST['prix'] ?? '0'));
            $devise         = strtoupper(trim($_POST['devise'] ?? 'FC'));
            $statutPaiement = trim($_POST['statut_paiement'] ?? 'Non paye');
            $statut         = trim($_POST['statut'] ?? 'Reserve');

            if ($voyageId <= 0 || $voyageurId <= 0) {

                $message = "Veuillez sélectionner un voyage et un voyageur.";
                $typeMessage = 'error';

            } elseif (!is_numeric($prix) || (float)$prix < 0) {

                $message = "Prix invalide.";
                $typeMessage = 'error';

            } elseif (!in_array($devise, ['FC', 'USD'], true)) {

                $message = "Devise invalide.";
                $typeMessage = 'error';

            } elseif (!in_array($statutPaiement, ['Non paye', 'Partiel', 'Paye'], true)) {

                $message = "Statut de paiement invalide.";
                $typeMessage = 'error';

            } elseif (!in_array($statut, ['Reserve', 'Valide', 'Annule'], true)) {

                $message = "Statut invalide.";
                $typeMessage = 'error';

            } else {

                try {

                    // Vérifier que le voyage appartient à l'agence
                    $check = $pdo->prepare("SELECT id FROM voyages WHERE id = ? AND agence_id = ?");
                    $check->execute([$voyageId, $agenceId]);
                    if (!$check->fetch()) {
                        throw new Exception("Voyage introuvable ou non autorisé.");
                    }

                    // Vérifier que le voyageur appartient à l'agence
                    $check = $pdo->prepare("SELECT id FROM voyageurs WHERE id = ? AND agence_id = ?");
                    $check->execute([$voyageurId, $agenceId]);
                    if (!$check->fetch()) {
                        throw new Exception("Voyageur introuvable ou non autorisé.");
                    }

                    if ($action === 'ajouter') {

                        $numero = genererReference('BIL');

                        $stmt = $pdo->prepare("
                            INSERT INTO billets
                            (agence_id, voyage_id, voyageur_id,
                             numero_billet, prix, devise,
                             statut_paiement, statut)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $stmt->execute([
                            $agenceId, $voyageId, $voyageurId,
                            $numero, $prix, $devise,
                            $statutPaiement, $statut
                        ]);

                        $message = "Billet enregistré avec succès. N° : $numero";

                    } else {

                        if ($id <= 0) throw new Exception("Identifiant invalide.");

                        $stmt = $pdo->prepare("
                            UPDATE billets
                            SET voyage_id = ?, voyageur_id = ?,
                                prix = ?, devise = ?,
                                statut_paiement = ?, statut = ?
                            WHERE id = ? AND agence_id = ?
                        ");

                        $stmt->execute([
                            $voyageId, $voyageurId,
                            $prix, $devise,
                            $statutPaiement, $statut,
                            $id, $agenceId
                        ]);

                        $message = "Billet modifié avec succès.";
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
                    $stmt = $pdo->prepare("DELETE FROM billets WHERE id = ? AND agence_id = ?");
                    $stmt->execute([$id, $agenceId]);

                    $ok = $stmt->rowCount() > 0;
                    $message = $ok ? "Billet supprimé." : "Billet introuvable.";
                    $typeMessage = $ok ? 'success' : 'error';

                } catch (PDOException $e) {
                    $message = "Impossible de supprimer ce billet.";
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

    $stmt = $pdo->prepare("SELECT * FROM billets WHERE id = ? AND agence_id = ?");
    $stmt->execute([$id, $agenceId]);

    $billetEdition = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($billetEdition) {
        $edition = true;
    } else {
        $message = "Billet introuvable.";
        $typeMessage = 'error';
    }
}

/* =====================================================
   5. LISTE
===================================================== */

$sql = "SELECT b.*,
               v.code_voyage, v.date_depart,
               d.nom_milieu AS depart, a.nom_milieu AS arrivee,
               voy.nom_complet AS voyageur
        FROM billets b
        INNER JOIN voyages v ON v.id = b.voyage_id
        INNER JOIN milieux d ON d.id = v.milieu_depart_id
        INNER JOIN milieux a ON a.id = v.milieu_arrivee_id
        INNER JOIN voyageurs voy ON voy.id = b.voyageur_id
        WHERE b.agence_id = ?";

$params = [$agenceId];

if ($recherche !== '') {
    $sql .= " AND (b.numero_billet LIKE ? OR voy.nom_complet LIKE ? OR v.code_voyage LIKE ?)";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
}

$sql .= " ORDER BY b.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$billets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nombreBillets = count($billets);

$recettesFC = 0;
$recettesUSD = 0;
foreach ($billets as $b) {
    if ($b['statut'] === 'Valide' || $b['statut'] === 'Reserve') {
        if ($b['devise'] === 'FC')  $recettesFC  += (float)$b['prix'];
        if ($b['devise'] === 'USD') $recettesUSD += (float)$b['prix'];
    }
}

/* =====================================================
   6. HEADER
===================================================== */

$titrePage  = 'Gestion des billets';
$sousTitre  = 'Émettez et gérez les billets de voyage.';
$pageActive = 'billets.php';

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
        <p class="text-sm text-slate-500">Total billets</p>
        <p class="text-3xl font-bold text-sky-700 mt-2"><?= $nombreBillets ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-sky-500">
        <p class="text-sm text-slate-500">Recettes (FC)</p>
        <p class="text-xl md:text-2xl font-bold text-sky-700 mt-2">
            <?= number_format($recettesFC, 2, ',', ' ') ?> FC
        </p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-blue-500">
        <p class="text-sm text-slate-500">Recettes (USD)</p>
        <p class="text-xl md:text-2xl font-bold text-blue-700 mt-2">
            <?= number_format($recettesUSD, 2, ',', ' ') ?> USD
        </p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- FORMULAIRE -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <h3 class="text-lg font-bold text-slate-800 mb-5">
            <?= $edition ? 'Modifier un billet' : 'Émettre un billet' ?>
        </h3>

        <form method="POST" action="billets.php" class="space-y-4">

            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
            <input type="hidden" name="action" value="<?= $edition ? 'modifier' : 'ajouter' ?>">
            <?php if ($edition): ?>
                <input type="hidden" name="id" value="<?= (int)$billetEdition['id'] ?>">
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Voyage *</label>
                <select id="voyageBillet" name="voyage_id" required
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="">Choisir</option>
                    <?php foreach ($voyages as $v): ?>
                        <option value="<?= (int)$v['id'] ?>"
                            data-prix-fc="<?= $v['prix_fc'] !== null ? number_format((float)$v['prix_fc'], 2, '.', '') : '' ?>"
                            data-prix-usd="<?= $v['prix_usd'] !== null ? number_format((float)$v['prix_usd'], 2, '.', '') : '' ?>"
                            <?= $edition && (int)$billetEdition['voyage_id'] === (int)$v['id'] ? 'selected' : '' ?>>
                            <?= securiser($v['code_voyage']) ?>
                            - <?= securiser($v['depart']) ?> → <?= securiser($v['arrivee']) ?>
                            (<?= date('d/m/Y H:i', strtotime($v['date_depart'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Voyageur *</label>
                <select name="voyageur_id" required
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="">Choisir</option>
                    <?php foreach ($voyageurs as $v): ?>
                        <option value="<?= (int)$v['id'] ?>"
                            <?= $edition && (int)$billetEdition['voyageur_id'] === (int)$v['id'] ? 'selected' : '' ?>>
                            <?= securiser($v['nom_complet']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Prix *</label>
                          <input id="prixBillet" type="number" step="0.01" min="0" name="prix" required
                           value="<?= $edition ? securiser($billetEdition['prix']) : '0' ?>"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Devise</label>
                        <select id="deviseBillet" name="devise"
                            class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                        <option value="FC" <?= $edition && $billetEdition['devise'] === 'FC' ? 'selected' : '' ?>>FC</option>
                        <option value="USD" <?= $edition && $billetEdition['devise'] === 'USD' ? 'selected' : '' ?>>USD</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Statut de paiement</label>
                <select name="statut_paiement"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <?php foreach (['Non paye', 'Partiel', 'Paye'] as $sp): ?>
                        <option value="<?= securiser($sp) ?>"
                            <?= $edition && $billetEdition['statut_paiement'] === $sp ? 'selected' : '' ?>>
                            <?= securiser($sp) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Statut billet</label>
                <select name="statut"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <?php foreach (['Reserve', 'Valide', 'Annule'] as $s): ?>
                        <option value="<?= securiser($s) ?>"
                            <?= $edition && $billetEdition['statut'] === $s ? 'selected' : '' ?>>
                            <?= securiser($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex gap-2 pt-2">
                <button type="submit"
                        class="flex-1 bg-sky-700 hover:bg-sky-800 text-white font-semibold py-3 rounded-lg">
                    <?= $edition ? 'Enregistrer les modifications' : 'Émettre le billet' ?>
                </button>
                <?php if ($edition): ?>
                    <a href="billets.php"
                       class="px-4 py-3 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700">Annuler</a>
                <?php endif; ?>
            </div>

        </form>

    </section>

    <!-- LISTE -->
    <section class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
            <h3 class="text-lg font-bold text-slate-800">Liste des billets</h3>
            <form method="GET" class="flex flex-wrap gap-2" id="formSelection">
                    <input type="text" name="recherche" value="<?= securiser($recherche) ?>"
                        placeholder="Rechercher..."
                        class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
                    <button type="submit"
                            class="bg-sky-700 hover:bg-sky-800 text-white px-4 py-2 rounded-lg text-sm">
                        Rechercher
                    </button>

                    <button type="button"
                            onclick="imprimerSelection()"
                            class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-semibold">
                        <i data-lucide="printer" class="inline-block h-4 w-4 mr-1 align-middle" aria-hidden="true"></i>
                        Imprimer la sélection
                    </button>
        </form>
        </div>

        <div class="tableau-responsive">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-2 py-3">
                            <input type="checkbox" onclick="cocherTout(this)" class="rounded">
                        </th>
                        <th class="px-4 py-3">N° billet</th>
                        <th class="px-4 py-3">Voyage</th>
                        <th class="px-4 py-3">Voyageur</th>
                        <th class="px-4 py-3">Prix</th>
                        <th class="px-4 py-3">Paiement</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($billets)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-10 text-slate-500">
                                Aucun billet émis.
                            </td>
                        </tr>
                    <?php else: foreach ($billets as $b): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-2 py-3">
                                <input type="checkbox" class="case-billet rounded" value="<?= (int)$b['id'] ?>">
                            </td>
                            <td class="px-4 py-3 font-semibold text-slate-800 whitespace-nowrap">
                                <?= securiser($b['numero_billet']) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                <?= securiser($b['depart']) ?>
                                <span class="text-sky-500 mx-1">→</span>
                                <?= securiser($b['arrivee']) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600"><?= securiser($b['voyageur']) ?></td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                <?= number_format((float)$b['prix'], 2, ',', ' ') ?>
                                <?= securiser($b['devise']) ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?php
                                    echo match($b['statut_paiement']) {
                                        'Paye'    => 'bg-emerald-100 text-emerald-700',
                                        'Partiel' => 'bg-amber-100 text-amber-700',
                                        default   => 'bg-red-100 text-red-700',
                                    };
                                    ?>">
                                    <?= securiser($b['statut_paiement']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?php
                                    echo match($b['statut']) {
                                        'Valide'  => 'bg-emerald-100 text-emerald-700',
                                        'Reserve' => 'bg-sky-100 text-sky-700',
                                        default   => 'bg-red-100 text-red-700',
                                    };
                                    ?>">
                                    <?= securiser($b['statut']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-2 flex-wrap">
                                    <a href="billet_print.php?id=<?= (int)$b['id'] ?>"
                                       target="_blank"
                                       class="bg-emerald-100 text-emerald-700 hover:bg-emerald-200 px-3 py-2 rounded-lg text-xs font-semibold">
                                        <i data-lucide="ticket" class="inline-block h-4 w-4 mr-1 align-middle" aria-hidden="true"></i> Imprimer
                                    </a>
                                    <a href="billets.php?modifier=<?= (int)$b['id'] ?>"
                                       class="bg-amber-100 text-amber-700 hover:bg-amber-200 px-3 py-2 rounded-lg text-xs">
                                        <i data-lucide="pencil" class="inline-block h-4 w-4 mr-1 align-middle" aria-hidden="true"></i>Modifier
                                    </a>
                                    <form method="POST" data-app-confirm="Supprimer ce billet ?" data-app-confirm-title="Supprimer le billet" data-app-confirm-label="Supprimer">
                                        <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
                                        <input type="hidden" name="action" value="supprimer">
                                        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                        <button type="submit"
                                                class="bg-red-100 text-red-700 hover:bg-red-200 px-3 py-2 rounded-lg text-xs">
                                            <i data-lucide="trash-2" class="inline-block h-4 w-4 mr-1 align-middle" aria-hidden="true"></i>Supprimer
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

<script>
const voyageBillet = document.getElementById('voyageBillet');
const prixBillet = document.getElementById('prixBillet');
const deviseBillet = document.getElementById('deviseBillet');

function appliquerTarifBillet() {
    const option = voyageBillet.options[voyageBillet.selectedIndex];
    if (!option || !option.value) return;

    let prix = deviseBillet.value === 'USD' ? option.dataset.prixUsd : option.dataset.prixFc;

    if (prix === '') {
        const autreDevise = deviseBillet.value === 'USD' ? 'FC' : 'USD';
        const autrePrix = autreDevise === 'USD' ? option.dataset.prixUsd : option.dataset.prixFc;

        if (autrePrix !== '') {
            deviseBillet.value = autreDevise;
            prix = autrePrix;
        }
    }

    prixBillet.value = prix;
}

voyageBillet.addEventListener('change', appliquerTarifBillet);
deviseBillet.addEventListener('change', appliquerTarifBillet);

function cocherTout(source) {
    document.querySelectorAll('.case-billet').forEach(cb => cb.checked = source.checked);
}

function imprimerSelection() {
    const ids = [];
    document.querySelectorAll('.case-billet:checked').forEach(cb => ids.push(cb.value));

    if (ids.length === 0) {
        window.appNotify('Veuillez sélectionner au moins un billet.', 'warning');
        return;
    }

    window.open('billet_print_lot.php?ids=' + ids.join(','), '_blank');
}
</script>

<?php require 'footer.php'; ?>
<?php
require_once "config.php";
exigerConnexion();

$agenceId = (int)($_SESSION['agence_id'] ?? 0);

/* =====================================================
   1. SECURITE CSRF
===================================================== */

if (empty($_SESSION['csrf_colis'])) {
    $_SESSION['csrf_colis'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_colis'];

function verifierCSRFColis($token): bool
{
    return isset($_SESSION['csrf_colis'])
        && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_colis'], $token);
}

$message = '';
$typeMessage = 'success';
$edition = false;
$colisEdition = null;
$recherche = trim($_GET['recherche'] ?? '');

/* =====================================================
   2. FONCTIONS UTILITAIRES
===================================================== */

/**
 * Recherche une personne par nom + téléphone, la crée si absente.
 * Retourne l'ID.
 */
function trouverOuCreerPersonne(PDO $pdo, string $table, string $nom, string $telephone, string $adresse = ''): int
{
    $nom = trim($nom);
    $telephone = trim($telephone);

    // Recherche par nom + téléphone
    $stmt = $pdo->prepare("
        SELECT id FROM `$table`
        WHERE LOWER(nom_complet) = LOWER(?)
          AND telephone = ?
        LIMIT 1
    ");
    $stmt->execute([$nom, $telephone]);
    $id = $stmt->fetchColumn();

    if ($id) {
        return (int)$id;
    }

    // Création
    $stmt = $pdo->prepare("
        INSERT INTO `$table` (nom_complet, telephone, adresse)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$nom, $telephone, $adresse]);

    return (int)$pdo->lastInsertId();
}

/* =====================================================
   3. CHARGEMENT DES LISTES (voyages, milieux, types)
===================================================== */

$stmt = $pdo->query("SELECT id, nom_type, mode_tarification FROM types_colis ORDER BY id ASC");
$typesColis = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT id, nom_milieu FROM milieux ORDER BY nom_milieu ASC");
$milieux = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT v.id, v.code_voyage,
           d.nom_milieu AS depart, a.nom_milieu AS arrivee
    FROM voyages v
    INNER JOIN milieux d ON d.id = v.milieu_depart_id
    INNER JOIN milieux a ON a.id = v.milieu_arrivee_id
    WHERE v.agence_id = ? AND v.statut != 'Annule'
    ORDER BY v.date_depart DESC
");
$stmt->execute([$agenceId]);
$voyages = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   4. TRAITEMENT FORMULAIRES
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFColis($_POST['csrf_token'] ?? null)) {

        $message = "Formulaire expiré. Rechargez la page.";
        $typeMessage = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'ajouter' || $action === 'modifier') {

            $id            = (int)($_POST['id'] ?? 0);
            $voyageId      = (int)($_POST['voyage_id'] ?? 0);
            $typeColisId   = (int)($_POST['type_colis_id'] ?? 0);
            $departId      = (int)($_POST['milieu_depart_id'] ?? 0);
            $arriveeId     = (int)($_POST['milieu_arrivee_id'] ?? 0);

            $expNom        = trim($_POST['expediteur_nom'] ?? '');
            $expTel        = trim($_POST['expediteur_telephone'] ?? '');
            $expAdresse    = trim($_POST['expediteur_adresse'] ?? '');

            $destNom       = trim($_POST['destinataire_nom'] ?? '');
            $destTel       = trim($_POST['destinataire_telephone'] ?? '');
            $destAdresse   = trim($_POST['destinataire_adresse'] ?? '');

            $description   = trim($_POST['description'] ?? '');
            $poids         = str_replace(',', '.', trim($_POST['poids'] ?? '0'));
            $prixUnitaire  = str_replace(',', '.', trim($_POST['prix_unitaire_kg'] ?? '0'));
            $prixManuel    = str_replace(',', '.', trim($_POST['prix'] ?? '0'));
            $devise        = strtoupper(trim($_POST['devise'] ?? 'FC'));
            $statut        = trim($_POST['statut'] ?? 'Enregistre');

            /* -------- Validation -------- */

            if ($typeColisId <= 0 || $departId <= 0 || $arriveeId <= 0) {

                $message = "Type de colis, départ et arrivée sont obligatoires.";
                $typeMessage = 'error';

            } elseif ($departId === $arriveeId) {

                $message = "Le départ et l'arrivée doivent être différents.";
                $typeMessage = 'error';

            } elseif ($expNom === '' || $expTel === '') {

                $message = "Le nom et le téléphone de l'expéditeur sont obligatoires.";
                $typeMessage = 'error';

            } elseif ($destNom === '' || $destTel === '') {

                $message = "Le nom et le téléphone du destinataire sont obligatoires.";
                $typeMessage = 'error';

            } elseif (!in_array($devise, ['FC', 'USD'], true)) {

                $message = "Devise invalide.";
                $typeMessage = 'error';

            } elseif (!in_array($statut, ['Enregistre', 'En transit', 'Arrive', 'Retire', 'Annule'], true)) {

                $message = "Statut invalide.";
                $typeMessage = 'error';

            } else {

                try {

                    /* -------- Type de colis -------- */

                    $stmtType = $pdo->prepare("SELECT nom_type, mode_tarification FROM types_colis WHERE id = ?");
                    $stmtType->execute([$typeColisId]);
                    $typeInfo = $stmtType->fetch(PDO::FETCH_ASSOC);

                    if (!$typeInfo) {
                        throw new Exception("Type de colis introuvable.");
                    }

                    /* -------- Calcul du prix -------- */

                    if ($typeInfo['mode_tarification'] === 'Kilogramme') {

                        if (!is_numeric($poids) || (float)$poids <= 0) {
                            throw new Exception("Le poids doit être supérieur à zéro pour un colis non fragile.");
                        }
                        if (!is_numeric($prixUnitaire) || (float)$prixUnitaire < 0) {
                            throw new Exception("Prix au kilogramme invalide.");
                        }

                        $prixFinal = (float)$poids * (float)$prixUnitaire;

                    } else {
                        if (!is_numeric($prixManuel) || (float)$prixManuel < 0) {
                            throw new Exception("Le prix forfaitaire est invalide.");
                        }
                        $prixFinal = (float)$prixManuel;
                        $poids = 0;
                        $prixUnitaire = 0;
                    }

                    /* -------- Expéditeur & destinataire (find or create) -------- */

                    $pdo->beginTransaction();

                    $expediteurId  = trouverOuCreerPersonne($pdo, 'expediteurs', $expNom, $expTel, $expAdresse);
                    $destinataireId = trouverOuCreerPersonne($pdo, 'destinataires', $destNom, $destTel, $destAdresse);

                    /* -------- Enregistrement du colis -------- */

                    if ($action === 'ajouter') {

                        $code = genererReference('COL');

                        $stmt = $pdo->prepare("
                            INSERT INTO colis
                            (agence_id, voyage_id, type_colis_id,
                             milieu_depart_id, milieu_arrivee_id,
                             expediteur_id, destinataire_id,
                             code_colis, description, poids,
                             prix_unitaire_kg, prix, devise, statut)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $stmt->execute([
                            $agenceId,
                            $voyageId > 0 ? $voyageId : null,
                            $typeColisId,
                            $departId, $arriveeId,
                            $expediteurId, $destinataireId,
                            $code, $description, $poids,
                            $prixUnitaire, $prixFinal,
                            $devise, $statut
                        ]);

                        $message = "Colis enregistré. Code : $code";

                    } else {

                        if ($id <= 0) throw new Exception("Identifiant invalide.");

                        $stmt = $pdo->prepare("
                            UPDATE colis
                            SET voyage_id = ?, type_colis_id = ?,
                                milieu_depart_id = ?, milieu_arrivee_id = ?,
                                expediteur_id = ?, destinataire_id = ?,
                                description = ?, poids = ?,
                                prix_unitaire_kg = ?, prix = ?,
                                devise = ?, statut = ?
                            WHERE id = ? AND agence_id = ?
                        ");

                        $stmt->execute([
                            $voyageId > 0 ? $voyageId : null,
                            $typeColisId,
                            $departId, $arriveeId,
                            $expediteurId, $destinataireId,
                            $description, $poids,
                            $prixUnitaire, $prixFinal,
                            $devise, $statut,
                            $id, $agenceId
                        ]);

                        $message = "Colis modifié avec succès.";
                    }

                    $pdo->commit();
                    $typeMessage = 'success';

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $message = $e->getMessage();
                    $typeMessage = 'error';
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $message = "Erreur : " . $e->getMessage();
                    $typeMessage = 'error';
                }
            }
        }

        elseif ($action === 'supprimer') {

            $id = (int)($_POST['id'] ?? 0);

            if ($id > 0) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM colis WHERE id = ? AND agence_id = ?");
                    $stmt->execute([$id, $agenceId]);

                    $ok = $stmt->rowCount() > 0;
                    $message = $ok ? "Colis supprimé." : "Colis introuvable.";
                    $typeMessage = $ok ? 'success' : 'error';

                } catch (PDOException $e) {
                    $message = "Impossible de supprimer ce colis.";
                    $typeMessage = 'error';
                }
            }
        }
    }
}

/* =====================================================
   5. EDITION — chargement avec infos expéditeur/destinataire
===================================================== */

if (isset($_GET['modifier'])) {

    $id = (int)$_GET['modifier'];

    $stmt = $pdo->prepare("
        SELECT c.*,
               exp.nom_complet AS exp_nom, exp.telephone AS exp_tel, exp.adresse AS exp_adr,
               dest.nom_complet AS dest_nom, dest.telephone AS dest_tel, dest.adresse AS dest_adr
        FROM colis c
        INNER JOIN expediteurs exp ON exp.id = c.expediteur_id
        INNER JOIN destinataires dest ON dest.id = c.destinataire_id
        WHERE c.id = ? AND c.agence_id = ?
    ");
    $stmt->execute([$id, $agenceId]);

    $colisEdition = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($colisEdition) {
        $edition = true;
    } else {
        $message = "Colis introuvable.";
        $typeMessage = 'error';
    }
}

/* =====================================================
   6. LISTE
===================================================== */

$sql = "SELECT c.*,
               t.nom_type, t.mode_tarification,
               d.nom_milieu AS depart,
               a.nom_milieu AS arrivee,
               exp.nom_complet AS expediteur,
               exp.telephone AS expediteur_tel,
               dest.nom_complet AS destinataire,
               dest.telephone AS destinataire_tel,
               v.code_voyage
        FROM colis c
        INNER JOIN types_colis t ON t.id = c.type_colis_id
        INNER JOIN milieux d ON d.id = c.milieu_depart_id
        INNER JOIN milieux a ON a.id = c.milieu_arrivee_id
        INNER JOIN expediteurs exp ON exp.id = c.expediteur_id
        INNER JOIN destinataires dest ON dest.id = c.destinataire_id
        LEFT JOIN voyages v ON v.id = c.voyage_id
        WHERE c.agence_id = ?";

$params = [$agenceId];

if ($recherche !== '') {
    $sql .= " AND (c.code_colis LIKE ? OR exp.nom_complet LIKE ? OR dest.nom_complet LIKE ?)";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
}

$sql .= " ORDER BY c.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$colis = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nombreColis = count($colis);
$totalFC = 0;
$totalUSD = 0;
foreach ($colis as $c) {
    if ($c['statut'] !== 'Annule') {
        if ($c['devise'] === 'FC')  $totalFC  += (float)$c['prix'];
        if ($c['devise'] === 'USD') $totalUSD += (float)$c['prix'];
    }
}

/* =====================================================
   7. HEADER
===================================================== */

$titrePage  = 'Gestion des colis';
$sousTitre  = 'Enregistrez un colis en saisissant directement l\'expéditeur et le destinataire.';
$pageActive = 'colis.php';

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
        <p class="text-sm text-slate-500">Total colis</p>
        <p class="text-3xl font-bold text-sky-700 mt-2"><?= $nombreColis ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-sky-500">
        <p class="text-sm text-slate-500">Montant total (FC)</p>
        <p class="text-xl md:text-2xl font-bold text-sky-700 mt-2">
            <?= number_format($totalFC, 2, ',', ' ') ?> FC
        </p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-blue-500">
        <p class="text-sm text-slate-500">Montant total (USD)</p>
        <p class="text-xl md:text-2xl font-bold text-blue-700 mt-2">
            <?= number_format($totalUSD, 2, ',', ' ') ?> USD
        </p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- FORMULAIRE -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <h3 class="text-lg font-bold text-slate-800 mb-5">
            <?= $edition ? 'Modifier un colis' : 'Enregistrer un colis' ?>
        </h3>

        <form method="POST" action="colis.php" class="space-y-4" id="formColis">

            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
            <input type="hidden" name="action" value="<?= $edition ? 'modifier' : 'ajouter' ?>">
            <?php if ($edition): ?>
                <input type="hidden" name="id" value="<?= (int)$colisEdition['id'] ?>">
            <?php endif; ?>

            <!-- TYPE DE COLIS -->
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Type de colis *</label>
                <select name="type_colis_id" id="typeColis" required
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="">Choisir</option>
                    <?php foreach ($typesColis as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"
                                data-mode="<?= securiser($t['mode_tarification']) ?>"
                            <?= $edition && (int)$colisEdition['type_colis_id'] === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= securiser($t['nom_type']) ?>
                            (<?= securiser($t['mode_tarification']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- VOYAGE -->
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Voyage (optionnel)</label>
                <select name="voyage_id"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="">Aucun</option>
                    <?php foreach ($voyages as $v): ?>
                        <option value="<?= (int)$v['id'] ?>"
                            <?= $edition && (int)$colisEdition['voyage_id'] === (int)$v['id'] ? 'selected' : '' ?>>
                            <?= securiser($v['code_voyage']) ?>
                            - <?= securiser($v['depart']) ?> → <?= securiser($v['arrivee']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- TRAJET -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Départ *</label>
                    <select name="milieu_depart_id" required
                            class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                        <option value="">Choisir</option>
                        <?php foreach ($milieux as $m): ?>
                            <option value="<?= (int)$m['id'] ?>"
                                <?= $edition && (int)$colisEdition['milieu_depart_id'] === (int)$m['id'] ? 'selected' : '' ?>>
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
                                <?= $edition && (int)$colisEdition['milieu_arrivee_id'] === (int)$m['id'] ? 'selected' : '' ?>>
                                <?= securiser($m['nom_milieu']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- EXPEDITEUR -->
            <fieldset class="border border-slate-200 rounded-lg p-3 space-y-3">
                <legend class="px-2 text-sm font-semibold text-sky-700">Expéditeur</legend>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nom complet *</label>
                    <input type="text" name="expediteur_nom" required
                           value="<?= $edition ? securiser($colisEdition['exp_nom']) : '' ?>"
                           placeholder="Ex : Paul MUKENDI"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Téléphone *</label>
                        <input type="text" name="expediteur_telephone" required
                               value="<?= $edition ? securiser($colisEdition['exp_tel']) : '' ?>"
                               class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Adresse</label>
                        <input type="text" name="expediteur_adresse"
                               value="<?= $edition ? securiser($colisEdition['exp_adr']) : '' ?>"
                               class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    </div>
                </div>
            </fieldset>

            <!-- DESTINATAIRE -->
            <fieldset class="border border-slate-200 rounded-lg p-3 space-y-3">
                <legend class="px-2 text-sm font-semibold text-sky-700">Destinataire</legend>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nom complet *</label>
                    <input type="text" name="destinataire_nom" required
                           value="<?= $edition ? securiser($colisEdition['dest_nom']) : '' ?>"
                           placeholder="Ex : Marie KASONGO"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Téléphone *</label>
                        <input type="text" name="destinataire_telephone" required
                               value="<?= $edition ? securiser($colisEdition['dest_tel']) : '' ?>"
                               class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Adresse</label>
                        <input type="text" name="destinataire_adresse"
                               value="<?= $edition ? securiser($colisEdition['dest_adr']) : '' ?>"
                               class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    </div>
                </div>
            </fieldset>

            <!-- DESCRIPTION -->
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
                <textarea name="description" rows="2"
                          class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none"><?= $edition ? securiser($colisEdition['description']) : '' ?></textarea>
            </div>

            <!-- CHAMPS KILOGRAMME -->
            <div id="champsKg" class="space-y-3 hidden">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Poids (kg)</label>
                        <input type="number" step="0.01" min="0" name="poids"
                               value="<?= $edition ? securiser($colisEdition['poids']) : '0' ?>"
                               class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Prix / kg</label>
                        <input type="number" step="0.01" min="0" name="prix_unitaire_kg"
                               value="<?= $edition ? securiser($colisEdition['prix_unitaire_kg']) : '0' ?>"
                               class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    </div>
                </div>
            </div>

            <!-- CHAMP FORFAIT -->
            <div id="champForfait" class="hidden">
                <label class="block text-sm font-medium text-slate-700 mb-1">Prix forfaitaire</label>
                <input type="number" step="0.01" min="0" name="prix"
                       value="<?= $edition ? securiser($colisEdition['prix']) : '0' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <!-- DEVISE / STATUT -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Devise</label>
                    <select name="devise"
                            class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                        <option value="FC" <?= $edition && $colisEdition['devise'] === 'FC' ? 'selected' : '' ?>>FC</option>
                        <option value="USD" <?= $edition && $colisEdition['devise'] === 'USD' ? 'selected' : '' ?>>USD</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Statut</label>
                    <select name="statut"
                            class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                        <?php foreach (['Enregistre','En transit','Arrive','Retire','Annule'] as $s): ?>
                            <option value="<?= securiser($s) ?>"
                                <?= $edition && $colisEdition['statut'] === $s ? 'selected' : '' ?>>
                                <?= securiser($s) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex gap-2 pt-2">
                <button type="submit"
                        class="flex-1 bg-sky-700 hover:bg-sky-800 text-white font-semibold py-3 rounded-lg">
                    <?= $edition ? 'Enregistrer les modifications' : 'Enregistrer le colis' ?>
                </button>
                <?php if ($edition): ?>
                    <a href="colis.php"
                       class="px-4 py-3 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700">Annuler</a>
                <?php endif; ?>
            </div>

        </form>

    </section>

    <!-- LISTE -->
    <section class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
            <h3 class="text-lg font-bold text-slate-800">Liste des colis</h3>
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
                        <th class="px-4 py-3">Expéditeur</th>
                        <th class="px-4 py-3">Destinataire</th>
                        <th class="px-4 py-3">Prix</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($colis)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-10 text-slate-500">
                                Aucun colis enregistré.
                            </td>
                        </tr>
                    <?php else: foreach ($colis as $c): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-semibold text-slate-800 whitespace-nowrap">
                                <?= securiser($c['code_colis']) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                <?= securiser($c['depart']) ?>
                                <span class="text-sky-500 mx-1">→</span>
                                <?= securiser($c['arrivee']) ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                <?= securiser($c['expediteur']) ?><br>
                                <span class="text-xs text-slate-400"><?= securiser($c['expediteur_tel']) ?></span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                <?= securiser($c['destinataire']) ?><br>
                                <span class="text-xs text-slate-400"><?= securiser($c['destinataire_tel']) ?></span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                <?= number_format((float)$c['prix'], 2, ',', ' ') ?>
                                <?= securiser($c['devise']) ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?php
                                    echo valeurCorrespondante($c['statut'], [
                                        'Enregistre' => 'bg-sky-100 text-sky-700',
                                        'En transit' => 'bg-amber-100 text-amber-700',
                                        'Arrive'     => 'bg-cyan-100 text-cyan-700',
                                        'Retire'     => 'bg-emerald-100 text-emerald-700',
                                    ], 'bg-red-100 text-red-700');
                                    ?>">
                                    <?= securiser($c['statut']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-2 flex-wrap">
                                    <a href="colis_print.php?id=<?= (int)$c['id'] ?>"
                                       target="_blank"
                                       class="inline-flex w-28 justify-center bg-emerald-100 text-emerald-700 hover:bg-emerald-200 px-3 py-2 rounded-lg text-xs font-semibold">
                                        Reçu
                                    </a>
                                    <a href="colis.php?modifier=<?= (int)$c['id'] ?>"
                                                    class="inline-flex w-28 justify-center bg-amber-100 text-amber-700 hover:bg-amber-200 px-3 py-2 rounded-lg text-xs">
                                        Modifier
                                    </a>
                                    <form method="POST" class="w-28" data-app-confirm="Supprimer ce colis ?" data-app-confirm-title="Supprimer le colis" data-app-confirm-label="Supprimer">
                                        <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
                                        <input type="hidden" name="action" value="supprimer">
                                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                        <button type="submit"
                                            class="w-full bg-red-100 text-red-700 hover:bg-red-200 px-3 py-2 rounded-lg text-xs">
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

<script>
const typeSelect   = document.getElementById('typeColis');
const champsKg     = document.getElementById('champsKg');
const champForfait = document.getElementById('champForfait');

function majChamps() {
    const option = typeSelect.options[typeSelect.selectedIndex];
    const mode = option ? option.getAttribute('data-mode') : '';

    if (mode === 'Kilogramme') {
        champsKg.classList.remove('hidden');
        champForfait.classList.add('hidden');
    } else if (mode === 'Forfait') {
        champsKg.classList.add('hidden');
        champForfait.classList.remove('hidden');
    } else {
        champsKg.classList.add('hidden');
        champForfait.classList.add('hidden');
    }
}

typeSelect.addEventListener('change', majChamps);
majChamps();
</script>

<?php require 'footer.php'; ?>
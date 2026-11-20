<?php
require_once "config.php";
exigerConnexion();
exigerRole(['Administrateur']);

$agenceId      = (int)($_SESSION['agence_id'] ?? 0);
$utilisateurId = (int)($_SESSION['id_utilisateur'] ?? 0);

/* =====================================================
   1. SECURITE CSRF
===================================================== */

if (empty($_SESSION['csrf_utilisateurs'])) {
    $_SESSION['csrf_utilisateurs'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_utilisateurs'];

function verifierCSRFUtilisateurs($token): bool
{
    return isset($_SESSION['csrf_utilisateurs'])
        && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_utilisateurs'], $token);
}

$message = '';
$typeMessage = 'success';
$edition = false;
$utilisateurEdition = null;
$recherche = trim($_GET['recherche'] ?? '');

/* =====================================================
   2. ROLES DISPONIBLES
===================================================== */

$roles = ['Administrateur', 'Gestionnaire', 'Caissier', 'Agent'];

/* =====================================================
   3. TRAITEMENT FORMULAIRES
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFUtilisateurs($_POST['csrf_token'] ?? null)) {

        $message = "Formulaire expiré. Rechargez la page.";
        $typeMessage = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        /* ============================================
           AJOUT / MODIFICATION
        ============================================ */

        if ($action === 'ajouter' || $action === 'modifier') {

            $id             = (int)($_POST['id'] ?? 0);
            $nom            = trim($_POST['nom'] ?? '');
            $postnom        = trim($_POST['postnom'] ?? '');
            $prenom         = trim($_POST['prenom'] ?? '');
            $nomUtilisateur = trim($_POST['nom_utilisateur'] ?? '');
            $telephone      = trim($_POST['telephone'] ?? '');
            $email          = trim($_POST['email'] ?? '');
            $role           = trim($_POST['role'] ?? 'Agent');
            $agenceCible    = (int)($_POST['agence_id'] ?? 0);
            $statut         = trim($_POST['statut'] ?? 'Actif');
            $motDePasse     = $_POST['mot_de_passe'] ?? '';
            $confirmation   = $_POST['mot_de_passe_confirm'] ?? '';

            /* -------- Validation -------- */

            if ($nom === '' || $nomUtilisateur === '') {

                $message = "Le nom et le nom d'utilisateur sont obligatoires.";
                $typeMessage = 'error';

            } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $nomUtilisateur)) {

                $message = "Le nom d'utilisateur doit contenir 3 à 50 caractères (lettres, chiffres, . _ -).";
                $typeMessage = 'error';

            } elseif (!in_array($role, $roles, true)) {

                $message = "Rôle invalide.";
                $typeMessage = 'error';

            } elseif (!in_array($statut, ['Actif', 'Inactif'], true)) {

                $message = "Statut invalide.";
                $typeMessage = 'error';

            } elseif ($agenceCible <= 0) {

                $message = "Veuillez sélectionner une agence.";
                $typeMessage = 'error';

            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

                $message = "Adresse email invalide.";
                $typeMessage = 'error';

            } elseif ($action === 'ajouter' && $motDePasse === '') {

                $message = "Le mot de passe est obligatoire pour un nouvel utilisateur.";
                $typeMessage = 'error';

            } elseif ($motDePasse !== '' && strlen($motDePasse) < 6) {

                $message = "Le mot de passe doit contenir au moins 6 caractères.";
                $typeMessage = 'error';

            } elseif ($motDePasse !== '' && $motDePasse !== $confirmation) {

                $message = "Les deux mots de passe ne correspondent pas.";
                $typeMessage = 'error';

            } else {

                try {

                    /* -------- Vérifier l'existence de l'agence -------- */

                    $stmtCheck = $pdo->prepare("SELECT id FROM agences WHERE id = ?");
                    $stmtCheck->execute([$agenceCible]);
                    if (!$stmtCheck->fetch()) {
                        throw new Exception("Agence introuvable.");
                    }

                    /* -------- Vérifier l'unicité du nom d'utilisateur -------- */

                    $sqlUniq = "SELECT id FROM utilisateurs WHERE nom_utilisateur = ?";
                    $paramsUniq = [$nomUtilisateur];
                    if ($action === 'modifier' && $id > 0) {
                        $sqlUniq .= " AND id != ?";
                        $paramsUniq[] = $id;
                    }
                    $stmtUniq = $pdo->prepare($sqlUniq);
                    $stmtUniq->execute($paramsUniq);

                    if ($stmtUniq->fetch()) {
                        throw new Exception("Ce nom d'utilisateur existe déjà.");
                    }

                    /* -------- AJOUT -------- */

                    if ($action === 'ajouter') {

                        $hash = password_hash($motDePasse, PASSWORD_DEFAULT);

                        $stmt = $pdo->prepare("
                            INSERT INTO utilisateurs
                            (agence_id, nom, postnom, prenom, nom_utilisateur,
                             telephone, email, mot_de_passe, role, statut)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $stmt->execute([
                            $agenceCible, $nom, $postnom, $prenom, $nomUtilisateur,
                            $telephone, $email, $hash, $role, $statut
                        ]);

                        $message = "L'utilisateur a été créé avec succès.";

                    }

                    /* -------- MODIFICATION -------- */

                    else {

                        if ($id <= 0) throw new Exception("Identifiant invalide.");

                        // Empêcher l'auto-désactivation de son propre compte
                        if ($id === $utilisateurId && $statut === 'Inactif') {
                            throw new Exception("Vous ne pouvez pas désactiver votre propre compte.");
                        }

                        // Empêcher l'auto-rétrogradation
                        if ($id === $utilisateurId && $role !== 'Administrateur') {
                            throw new Exception("Vous ne pouvez pas retirer votre propre rôle Administrateur.");
                        }

                        if ($motDePasse !== '') {

                            $hash = password_hash($motDePasse, PASSWORD_DEFAULT);

                            $stmt = $pdo->prepare("
                                UPDATE utilisateurs
                                SET agence_id = ?, nom = ?, postnom = ?, prenom = ?,
                                    nom_utilisateur = ?, telephone = ?, email = ?,
                                    mot_de_passe = ?, role = ?, statut = ?
                                WHERE id = ?
                            ");

                            $stmt->execute([
                                $agenceCible, $nom, $postnom, $prenom,
                                $nomUtilisateur, $telephone, $email,
                                $hash, $role, $statut, $id
                            ]);

                            $message = "L'utilisateur a été modifié (mot de passe inclus).";

                        } else {

                            $stmt = $pdo->prepare("
                                UPDATE utilisateurs
                                SET agence_id = ?, nom = ?, postnom = ?, prenom = ?,
                                    nom_utilisateur = ?, telephone = ?, email = ?,
                                    role = ?, statut = ?
                                WHERE id = ?
                            ");

                            $stmt->execute([
                                $agenceCible, $nom, $postnom, $prenom,
                                $nomUtilisateur, $telephone, $email,
                                $role, $statut, $id
                            ]);

                            $message = "L'utilisateur a été modifié avec succès.";
                        }
                    }

                    $typeMessage = 'success';

                } catch (PDOException $e) {

                    if ($e->getCode() === '23000') {
                        $message = "Ce nom d'utilisateur existe déjà.";
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

        /* ============================================
           SUPPRESSION
        ============================================ */

        elseif ($action === 'supprimer') {

            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                $message = "Identifiant invalide.";
                $typeMessage = 'error';

            } elseif ($id === $utilisateurId) {

                $message = "Vous ne pouvez pas supprimer votre propre compte.";
                $typeMessage = 'error';

            } else {

                try {
                    $stmt = $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?");
                    $stmt->execute([$id]);

                    $ok = $stmt->rowCount() > 0;
                    $message = $ok ? "Utilisateur supprimé." : "Utilisateur introuvable.";
                    $typeMessage = $ok ? 'success' : 'error';

                } catch (PDOException $e) {
                    $message = "Impossible de supprimer : cet utilisateur est lié à des opérations.";
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

    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
    $stmt->execute([$id]);

    $utilisateurEdition = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($utilisateurEdition) {
        $edition = true;
    } else {
        $message = "Utilisateur introuvable.";
        $typeMessage = 'error';
    }
}

/* =====================================================
   5. LISTE DES AGENCES
===================================================== */

$stmt = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence ASC");
$agences = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   6. LISTE UTILISATEURS
===================================================== */

$sql = "SELECT u.*,
               a.nom_agence
        FROM utilisateurs u
        LEFT JOIN agences a ON a.id = u.agence_id
        WHERE 1=1";

$params = [];

if ($recherche !== '') {
    $sql .= " AND (u.nom LIKE ? OR u.postnom LIKE ? OR u.prenom LIKE ?
              OR u.nom_utilisateur LIKE ? OR u.telephone LIKE ? OR u.email LIKE ?)";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
    $params[] = "%$recherche%";
}

$sql .= " ORDER BY u.nom ASC, u.prenom ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nombreUtilisateurs = count($utilisateurs);
$nombreActifs = 0;
foreach ($utilisateurs as $u) {
    if ($u['statut'] === 'Actif') $nombreActifs++;
}

/* =====================================================
   7. HEADER
===================================================== */

$titrePage  = 'Gestion des utilisateurs';
$sousTitre  = 'Créez et gérez les comptes de votre personnel.';
$pageActive = 'utilisateurs.php';

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
        <p class="text-sm text-slate-500">Total utilisateurs</p>
        <p class="text-3xl font-bold text-sky-700 mt-2"><?= $nombreUtilisateurs ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5 border border-slate-200">
        <p class="text-sm text-slate-500">Comptes actifs</p>
        <p class="text-3xl font-bold text-emerald-600 mt-2"><?= $nombreActifs ?></p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- FORMULAIRE -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <h3 class="text-lg font-bold text-slate-800 mb-5">
            <?= $edition ? 'Modifier un utilisateur' : 'Créer un utilisateur' ?>
        </h3>

        <form method="POST" action="utilisateurs.php" class="space-y-4" autocomplete="off">

            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
            <input type="hidden" name="action" value="<?= $edition ? 'modifier' : 'ajouter' ?>">
            <?php if ($edition): ?>
                <input type="hidden" name="id" value="<?= (int)$utilisateurEdition['id'] ?>">
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Nom *</label>
                <input type="text" name="nom" required
                       value="<?= $edition ? securiser($utilisateurEdition['nom']) : '' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Postnom</label>
                    <input type="text" name="postnom"
                           value="<?= $edition ? securiser($utilisateurEdition['postnom']) : '' ?>"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Prénom</label>
                    <input type="text" name="prenom"
                           value="<?= $edition ? securiser($utilisateurEdition['prenom']) : '' ?>"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Nom d'utilisateur *</label>
                <input type="text" name="nom_utilisateur" required
                       pattern="[a-zA-Z0-9._-]{3,50}"
                       title="3 à 50 caractères : lettres, chiffres, . _ -"
                       value="<?= $edition ? securiser($utilisateurEdition['nom_utilisateur']) : '' ?>"
                       class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Téléphone</label>
                    <input type="text" name="telephone"
                           value="<?= $edition ? securiser($utilisateurEdition['telephone']) : '' ?>"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                    <input type="email" name="email"
                           value="<?= $edition ? securiser($utilisateurEdition['email']) : '' ?>"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Agence *</label>
                <select name="agence_id" required
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="">Choisir</option>
                    <?php foreach ($agences as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"
                            <?= $edition && (int)$utilisateurEdition['agence_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                            <?= securiser($a['nom_agence']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Rôle *</label>
                <select name="role" required
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= securiser($r) ?>"
                            <?= $edition && $utilisateurEdition['role'] === $r ? 'selected' : '' ?>>
                            <?= securiser($r) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Statut</label>
                <select name="statut"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                    <option value="Actif" <?= $edition && $utilisateurEdition['statut'] === 'Actif' ? 'selected' : '' ?>>Actif</option>
                    <option value="Inactif" <?= $edition && $utilisateurEdition['statut'] === 'Inactif' ? 'selected' : '' ?>>Inactif</option>
                </select>
            </div>

            <fieldset class="border border-slate-200 rounded-lg p-3 space-y-3">
                <legend class="px-2 text-sm font-semibold text-sky-700">
                    <?= $edition ? 'Changer le mot de passe (optionnel)' : 'Mot de passe *' ?>
                </legend>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        Mot de passe <?= $edition ? '' : '*' ?>
                    </label>
                    <input type="password" name="mot_de_passe"
                           <?= $edition ? '' : 'required' ?>
                           minlength="6"
                           autocomplete="new-password"
                           placeholder="<?= $edition ? 'Laisser vide pour ne pas changer' : 'Au moins 6 caractères' ?>"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Confirmer</label>
                    <input type="password" name="mot_de_passe_confirm"
                           minlength="6"
                           autocomplete="new-password"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>
            </fieldset>

            <div class="flex gap-2 pt-2">
                <button type="submit"
                        class="flex-1 bg-sky-700 hover:bg-sky-800 text-white font-semibold py-3 rounded-lg">
                    <?= $edition ? 'Enregistrer les modifications' : 'Créer l\'utilisateur' ?>
                </button>
                <?php if ($edition): ?>
                    <a href="utilisateurs.php"
                       class="px-4 py-3 bg-slate-200 hover:bg-slate-300 rounded-lg text-slate-700">Annuler</a>
                <?php endif; ?>
            </div>

        </form>

    </section>

    <!-- LISTE -->
    <section class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5">

        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
            <h3 class="text-lg font-bold text-slate-800">Liste des utilisateurs</h3>
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
                        <th class="px-4 py-3">Nom complet</th>
                        <th class="px-4 py-3">Identifiant</th>
                        <th class="px-4 py-3">Rôle</th>
                        <th class="px-4 py-3">Agence</th>
                        <th class="px-4 py-3">Dernière connexion</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($utilisateurs)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-10 text-slate-500">
                                Aucun utilisateur enregistré.
                            </td>
                        </tr>
                    <?php else: foreach ($utilisateurs as $u): ?>
                        <tr class="hover:bg-slate-50 <?= (int)$u['id'] === $utilisateurId ? 'bg-sky-50/50' : '' ?>">
                            <td class="px-4 py-3 font-semibold text-slate-800">
                                <?= securiser(trim(($u['nom'] ?? '') . ' ' . ($u['postnom'] ?? '') . ' ' . ($u['prenom'] ?? ''))) ?>
                                <?php if ((int)$u['id'] === $utilisateurId): ?>
                                    <span class="ml-1 text-xs text-sky-600">(vous)</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                <?= securiser($u['nom_utilisateur']) ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 rounded text-xs font-semibold
                                    <?php
                                    echo valeurCorrespondante($u['role'], [
                                        'Administrateur' => 'bg-sky-100 text-sky-700',
                                        'Gestionnaire'   => 'bg-amber-100 text-amber-700',
                                        'Caissier'       => 'bg-cyan-100 text-cyan-700',
                                    ], 'bg-slate-100 text-slate-600');
                                    ?>">
                                    <?= securiser($u['role']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                <?= securiser($u['nom_agence'] ?? '—') ?>
                            </td>
                            <td class="px-4 py-3 text-slate-500 text-xs whitespace-nowrap">
                                <?= $u['derniere_connexion']
                                    ? date('d/m/Y H:i', strtotime($u['derniere_connexion']))
                                    : 'Jamais' ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                    <?= $u['statut'] === 'Actif'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-red-100 text-red-700' ?>">
                                    <?= securiser($u['statut']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-center gap-2">
                                    <a href="utilisateurs.php?modifier=<?= (int)$u['id'] ?>"
                                       class="bg-amber-100 text-amber-700 hover:bg-amber-200 px-3 py-2 rounded-lg">
                                        Modifier
                                    </a>
                                    <?php if ((int)$u['id'] !== $utilisateurId): ?>
                                        <form method="POST" data-app-confirm="Supprimer cet utilisateur ?" data-app-confirm-title="Supprimer l’utilisateur" data-app-confirm-label="Supprimer">
                                            <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">
                                            <input type="hidden" name="action" value="supprimer">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit"
                                                    class="bg-red-100 text-red-700 hover:bg-red-200 px-3 py-2 rounded-lg">
                                                Supprimer
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="px-3 py-2 text-xs text-slate-400 italic">Compte actuel</span>
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
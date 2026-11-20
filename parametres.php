<?php
require_once "config.php";
exigerConnexion();
exigerRole(['Administrateur']);

$agenceId      = (int)($_SESSION['agence_id'] ?? 0);
$utilisateurId = (int)($_SESSION['id_utilisateur'] ?? 0);

/* =====================================================
   1. SECURITE CSRF
===================================================== */

if (empty($_SESSION['csrf_parametres'])) {
    $_SESSION['csrf_parametres'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_parametres'];

function verifierCSRFParametres($token): bool
{
    return isset($_SESSION['csrf_parametres'])
        && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_parametres'], $token);
}

$message = '';
$typeMessage = 'success';

/* =====================================================
   2. DOSSIER LOGOS
===================================================== */

$dossierLogos = __DIR__ . '/uploads/logos';

if (!is_dir($dossierLogos)) {
    @mkdir($dossierLogos, 0755, true);
}

/* =====================================================
   3. CHARGEMENT DES PARAMETRES
===================================================== */

try {
    $stmt = $pdo->query("SELECT * FROM parametres ORDER BY id ASC LIMIT 1");
    $parametres = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $parametres = null;
}

if (!$parametres) {
    $parametres = [
        'id' => null,
        'nom_agence' => '',
        'slogan' => '',
        'logo' => '',
        'adresse' => '',
        'telephone1' => '',
        'telephone2' => '',
        'email' => '',
        'devise_principale' => 'FC',
        'devise_secondaire' => 'USD',
        'pied_facture' => '',
    ];
}

/* =====================================================
   4. TRAITEMENT FORMULAIRE
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifierCSRFParametres($_POST['csrf_token'] ?? null)) {

        $message = "Formulaire expiré. Rechargez la page.";
        $typeMessage = 'error';

    } else {

        $nomAgence        = trim($_POST['nom_agence'] ?? '');
        $slogan           = trim($_POST['slogan'] ?? '');
        $adresse          = trim($_POST['adresse'] ?? '');
        $telephone1       = trim($_POST['telephone1'] ?? '');
        $telephone2       = trim($_POST['telephone2'] ?? '');
        $email            = trim($_POST['email'] ?? '');
        $devisePrincipale = strtoupper(trim($_POST['devise_principale'] ?? 'FC'));
        $deviseSecondaire = strtoupper(trim($_POST['devise_secondaire'] ?? 'USD'));
        $piedFacture      = trim($_POST['pied_facture'] ?? '');
        $supprimerLogo    = isset($_POST['supprimer_logo']);

        /* -------- Validation -------- */

        if ($nomAgence === '') {

            $message = "Le nom de l'agence est obligatoire.";
            $typeMessage = 'error';

        } elseif (!in_array($devisePrincipale, ['FC', 'USD'], true)) {

            $message = "Devise principale invalide.";
            $typeMessage = 'error';

        } elseif (!in_array($deviseSecondaire, ['FC', 'USD'], true)) {

            $message = "Devise secondaire invalide.";
            $typeMessage = 'error';

        } elseif ($devisePrincipale === $deviseSecondaire) {

            $message = "Les deux devises doivent être différentes.";
            $typeMessage = 'error';

        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $message = "Adresse email invalide.";
            $typeMessage = 'error';

        } else {

            try {

                /* -------- Gestion du logo -------- */

                $logoActuel = $parametres['logo'] ?? '';
                $nouveauLogo = $logoActuel;

                // Suppression du logo
                if ($supprimerLogo && $logoActuel !== '') {
                    $chemin = __DIR__ . '/' . ltrim($logoActuel, '/');
                    if (is_file($chemin)) @unlink($chemin);
                    $nouveauLogo = '';
                }

                // Upload d'un nouveau logo
                if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {

                    $tmp  = $_FILES['logo']['tmp_name'];
                    $taille = (int)$_FILES['logo']['size'];
                    $mime = mime_content_type($tmp);

                    $mimesAutorises = [
                        'image/png'  => 'png',
                        'image/jpeg' => 'jpg',
                        'image/gif'  => 'gif',
                        'image/webp' => 'webp',
                    ];

                    if (!isset($mimesAutorises[$mime])) {
                        throw new Exception("Le logo doit être une image (PNG, JPG, GIF ou WEBP).");
                    }

                    if ($taille > 2 * 1024 * 1024) {
                        throw new Exception("Le logo ne doit pas dépasser 2 Mo.");
                    }

                    $extension = $mimesAutorises[$mime];
                    $nomFichier = 'logo_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                    $destination = $dossierLogos . '/' . $nomFichier;

                    if (!move_uploaded_file($tmp, $destination)) {
                        throw new Exception("Impossible d'enregistrer le fichier du logo.");
                    }

                    // Suppression de l'ancien logo
                    if ($logoActuel !== '') {
                        $ancien = __DIR__ . '/' . ltrim($logoActuel, '/');
                        if (is_file($ancien)) @unlink($ancien);
                    }

                    $nouveauLogo = 'uploads/logos/' . $nomFichier;
                }

                /* -------- Enregistrement -------- */

                if ($parametres['id']) {

                    $stmt = $pdo->prepare("
                        UPDATE parametres SET
                            nom_agence = ?, slogan = ?, logo = ?, adresse = ?,
                            telephone1 = ?, telephone2 = ?, email = ?,
                            devise_principale = ?, devise_secondaire = ?,
                            pied_facture = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $nomAgence, $slogan, $nouveauLogo, $adresse,
                        $telephone1, $telephone2, $email,
                        $devisePrincipale, $deviseSecondaire,
                        $piedFacture,
                        $parametres['id']
                    ]);

                } else {

                    $stmt = $pdo->prepare("
                        INSERT INTO parametres
                        (nom_agence, slogan, logo, adresse,
                         telephone1, telephone2, email,
                         devise_principale, devise_secondaire, pied_facture)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $nomAgence, $slogan, $nouveauLogo, $adresse,
                        $telephone1, $telephone2, $email,
                        $devisePrincipale, $deviseSecondaire,
                        $piedFacture
                    ]);
                }

                $message = "Les paramètres ont été enregistrés avec succès.";
                $typeMessage = 'success';

                // Rechargement
                $stmt = $pdo->query("SELECT * FROM parametres ORDER BY id ASC LIMIT 1");
                $parametres = $stmt->fetch(PDO::FETCH_ASSOC);

                // Mise à jour session nom agence
                $_SESSION['nom_agence'] = $parametres['nom_agence'];

            } catch (Exception $e) {
                $message = $e->getMessage();
                $typeMessage = 'error';
            } catch (PDOException $e) {
                $message = "Erreur : " . $e->getMessage();
                $typeMessage = 'error';
            }
        }
    }
}

/* =====================================================
   5. HEADER
===================================================== */

$titrePage  = 'Paramètres généraux';
$sousTitre  = 'Configurez l\'identité et les informations de votre agence.';
$pageActive = 'parametres.php';

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

<div class="max-w-4xl mx-auto">

    <form method="POST" action="parametres.php" enctype="multipart/form-data" class="space-y-6">

        <input type="hidden" name="csrf_token" value="<?= securiser($csrf) ?>">

        <!-- IDENTITE -->
        <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h3 class="text-lg font-bold text-slate-800 mb-5">Identité de l'agence</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nom de l'agence *</label>
                    <input type="text" name="nom_agence" required
                           value="<?= securiser($parametres['nom_agence'] ?? '') ?>"
                           placeholder="Ex : Agence Voyage Express"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Slogan</label>
                    <input type="text" name="slogan"
                           value="<?= securiser($parametres['slogan'] ?? '') ?>"
                           placeholder="Ex : Voyagez en toute sécurité"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>

            </div>

            <!-- LOGO -->
            <div class="mt-4">

                <label class="block text-sm font-medium text-slate-700 mb-2">Logo</label>

                <div class="flex flex-wrap items-center gap-4">

                    <div class="w-24 h-24 rounded-xl border border-slate-200 bg-slate-50 flex items-center justify-center overflow-hidden">
                        <?php if (!empty($parametres['logo'])): ?>
                            <img src="<?= securiser(URL_APPLICATION . ltrim($parametres['logo'], '/')) ?>"
                                 alt="Logo"
                                 class="w-full h-full object-contain p-1">
                        <?php else: ?>
                            <span class="text-slate-400 text-xs text-center px-2">Aucun logo</span>
                        <?php endif; ?>
                    </div>

                    <div class="flex-1 min-w-[200px] space-y-2">

                        <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp"
                               class="block w-full text-sm text-slate-600
                                      file:mr-3 file:py-2 file:px-4
                                      file:rounded-lg file:border-0
                                      file:bg-sky-100 file:text-sky-700
                                      file:font-semibold hover:file:bg-sky-200">

                        <p class="text-xs text-slate-500">
                            Formats acceptés : PNG, JPG, GIF, WEBP — max 2 Mo.
                        </p>

                        <?php if (!empty($parametres['logo'])): ?>
                            <label class="inline-flex items-center gap-2 text-sm text-red-600">
                                <input type="checkbox" name="supprimer_logo" value="1" class="rounded">
                                Supprimer le logo actuel
                            </label>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </section>

        <!-- CONTACT -->
        <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h3 class="text-lg font-bold text-slate-800 mb-5">Coordonnées</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Adresse</label>
                    <input type="text" name="adresse"
                           value="<?= securiser($parametres['adresse'] ?? '') ?>"
                           placeholder="Ex : 123 Avenue du Commerce, Kinshasa"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Téléphone 1</label>
                    <input type="text" name="telephone1"
                           value="<?= securiser($parametres['telephone1'] ?? '') ?>"
                           placeholder="Ex : +243 81 234 5678"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Téléphone 2</label>
                    <input type="text" name="telephone2"
                           value="<?= securiser($parametres['telephone2'] ?? '') ?>"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                    <input type="email" name="email"
                           value="<?= securiser($parametres['email'] ?? '') ?>"
                           placeholder="Ex : contact@agence.com"
                           class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                </div>

            </div>
        </section>

        <!-- DEVISES -->
        <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h3 class="text-lg font-bold text-slate-800 mb-5">Devises</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Devise principale *</label>
                    <select name="devise_principale"
                            class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                        <option value="FC"  <?= ($parametres['devise_principale'] ?? 'FC') === 'FC' ? 'selected' : '' ?>>FC (Franc Congolais)</option>
                        <option value="USD" <?= ($parametres['devise_principale'] ?? '') === 'USD' ? 'selected' : '' ?>>USD (Dollar Américain)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Devise secondaire *</label>
                    <select name="devise_secondaire"
                            class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none">
                        <option value="USD" <?= ($parametres['devise_secondaire'] ?? 'USD') === 'USD' ? 'selected' : '' ?>>USD (Dollar Américain)</option>
                        <option value="FC"  <?= ($parametres['devise_secondaire'] ?? '') === 'FC' ? 'selected' : '' ?>>FC (Franc Congolais)</option>
                    </select>
                </div>

            </div>
        </section>

        <!-- FACTURATION -->
        <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <h3 class="text-lg font-bold text-slate-800 mb-5">Facturation</h3>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Pied de facture / reçu</label>
                <textarea name="pied_facture" rows="3"
                          placeholder="Ex : Merci pour votre confiance. Bon voyage !"
                          class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-sky-500 outline-none"><?= securiser($parametres['pied_facture'] ?? '') ?></textarea>
                <p class="text-xs text-slate-500 mt-1">
                    Ce texte apparaîtra en bas des billets et reçus imprimés.
                </p>
            </div>
        </section>

        <!-- BOUTON -->
        <div class="flex flex-wrap gap-3">
            <button type="submit"
                    class="flex-1 bg-sky-700 hover:bg-sky-800 text-white font-semibold py-4 rounded-xl shadow-sm">
                Enregistrer les paramètres
            </button>
        </div>

    </form>

</div>

<?php require 'footer.php'; ?>
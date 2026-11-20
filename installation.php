
<?php
/**
 * ==========================================================
 * GESTION D'AGENCE DE VOYAGE
 * Installation automatique de la base de données
 * PHP + MySQL + PDO
 * Design prévu : BLEU CIEL ET BLANC
 * ==========================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ==========================================================
   1. CONFIGURATION DE LA CONNEXION
   ========================================================== */

// Configuration XAMPP
$serveur = "localhost";
$utilisateur = "root";
$mot_de_passe = "";
$base_de_donnees = "gestion_agence_voyage";

// Sur InfinityFree, remplacez les informations ci-dessus
// par celles fournies dans votre panneau MySQL.
// Créez la base dans le panneau d'hébergement.
// Dans ce cas, mettez $creer_base = false.

$creer_base = true;

$message = "";
$type_message = "success";

try {

    /* ======================================================
       2. CONNEXION MYSQL
       ====================================================== */

    $pdo = new PDO(
        "mysql:host=$serveur;charset=utf8mb4",
        $utilisateur,
        $mot_de_passe,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    /* ======================================================
       3. CREATION DE LA BASE DE DONNEES
       ====================================================== */

    if ($creer_base) {
        $pdo->exec("
            CREATE DATABASE IF NOT EXISTS `$base_de_donnees`
            CHARACTER SET utf8mb4
            COLLATE utf8mb4_unicode_ci
        ");
    }

    $pdo->exec("USE `$base_de_donnees`");

    /* ======================================================
       4. TABLE DES AGENCES
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS agences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agence_parent_id INT NULL,
            nom_agence VARCHAR(150) NOT NULL,
            code_agence VARCHAR(50) NOT NULL UNIQUE,
            type_agence VARCHAR(30) NOT NULL DEFAULT 'Principale',
            adresse TEXT,
            milieu VARCHAR(150),
            telephone1 VARCHAR(30),
            telephone2 VARCHAR(30),
            email VARCHAR(150),
            logo VARCHAR(255),
            statut VARCHAR(30) DEFAULT 'Actif',
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            CONSTRAINT fk_agence_parent
            FOREIGN KEY (agence_parent_id)
            REFERENCES agences(id)
            ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       5. TABLE DES UTILISATEURS
       Chaque agent est affecté à une agence.
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS utilisateurs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agence_id INT NULL,
            nom VARCHAR(100) NOT NULL,
            postnom VARCHAR(100),
            prenom VARCHAR(100),
            nom_utilisateur VARCHAR(100) NOT NULL UNIQUE,
            telephone VARCHAR(30),
            email VARCHAR(150),
            mot_de_passe VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'Agent',
            photo VARCHAR(255),
            statut VARCHAR(30) DEFAULT 'Actif',
            derniere_connexion DATETIME NULL,
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (agence_id)
            REFERENCES agences(id)
            ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       6. TABLE DES PARAMETRES GENERAUX
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS parametres (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom_agence VARCHAR(150),
            slogan VARCHAR(255),
            logo VARCHAR(255),
            adresse TEXT,
            telephone1 VARCHAR(30),
            telephone2 VARCHAR(30),
            email VARCHAR(150),
            devise_principale VARCHAR(10) DEFAULT 'FC',
            devise_secondaire VARCHAR(10) DEFAULT 'USD',
            pied_facture TEXT,
            date_modification TIMESTAMP
            DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       7. TABLE DES MILIEUX
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS milieux (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom_milieu VARCHAR(150) NOT NULL UNIQUE,
            description TEXT,
            statut VARCHAR(30) DEFAULT 'Actif',
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       8. TABLE DES CHAUFFEURS
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chauffeurs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agence_id INT NOT NULL,
            nom VARCHAR(100) NOT NULL,
            postnom VARCHAR(100),
            prenom VARCHAR(100),
            genre VARCHAR(20),
            photo VARCHAR(255),
            telephone VARCHAR(30) NOT NULL,
            adresse TEXT,
            numero_permis VARCHAR(100) NULL,
            statut VARCHAR(30) DEFAULT 'Actif',
            date_enregistrement TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (agence_id)
            REFERENCES agences(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       9. TABLE DES VEHICULES
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agence_id INT NOT NULL,
            code_vehicule VARCHAR(50) NOT NULL UNIQUE,
            marque VARCHAR(100) NOT NULL,
            modele VARCHAR(100),
            immatriculation VARCHAR(100) NOT NULL UNIQUE,
            nombre_places INT DEFAULT 0,
            statut VARCHAR(30) DEFAULT 'Disponible',
            date_enregistrement TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (agence_id)
            REFERENCES agences(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       10. TABLE DES VOYAGEURS
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voyageurs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agence_id INT NOT NULL,
            nom_complet VARCHAR(200) NOT NULL,
            genre VARCHAR(20),
            telephone VARCHAR(30),
            date_enregistrement TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (agence_id)
            REFERENCES agences(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       11. TABLE DES TYPES DE COLIS
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS types_colis (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom_type VARCHAR(100) NOT NULL UNIQUE,
            mode_tarification VARCHAR(30) NOT NULL,
            description TEXT,
            statut VARCHAR(30) DEFAULT 'Actif'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       12. TABLE DES TARIFICATIONS
       Prix voyageur et prix au kilogramme.
       Le tarif fragile n'est PAS enregistré ici.
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tarifs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            milieu_depart_id INT NOT NULL,
            milieu_arrivee_id INT NOT NULL,
            prix_voyageur DECIMAL(15,2) NOT NULL DEFAULT 0,
            prix_kg_non_fragile DECIMAL(15,2) NOT NULL DEFAULT 0,
            devise VARCHAR(10) NOT NULL DEFAULT 'FC',
            statut VARCHAR(30) DEFAULT 'Actif',
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (milieu_depart_id)
            REFERENCES milieux(id),

            FOREIGN KEY (milieu_arrivee_id)
            REFERENCES milieux(id),

            UNIQUE KEY unique_trajet_devise
            (milieu_depart_id, milieu_arrivee_id, devise)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       13. TABLE DES EXPEDITEURS
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS expediteurs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom_complet VARCHAR(200) NOT NULL,
            telephone VARCHAR(30) NOT NULL,
            adresse TEXT,
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       14. TABLE DES DESTINATAIRES
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS destinataires (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom_complet VARCHAR(200) NOT NULL,
            telephone VARCHAR(30) NOT NULL,
            adresse TEXT,
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       15. TABLE DES VOYAGES
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voyages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agence_id INT NOT NULL,
            chauffeur_id INT NOT NULL,
            vehicule_id INT NOT NULL,
            milieu_depart_id INT NOT NULL,
            milieu_arrivee_id INT NOT NULL,
            code_voyage VARCHAR(80) NOT NULL UNIQUE,
            date_depart DATETIME NOT NULL,
            date_arrivee_prevue DATETIME NULL,
            date_arrivee_reelle DATETIME NULL,
            statut VARCHAR(30) DEFAULT 'Programme',
            observations TEXT,
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (agence_id)
            REFERENCES agences(id),

            FOREIGN KEY (chauffeur_id)
            REFERENCES chauffeurs(id),

            FOREIGN KEY (vehicule_id)
            REFERENCES vehicules(id),

            FOREIGN KEY (milieu_depart_id)
            REFERENCES milieux(id),

            FOREIGN KEY (milieu_arrivee_id)
            REFERENCES milieux(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       16. TABLE DES BILLETS
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS billets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agence_id INT NOT NULL,
            voyage_id INT NOT NULL,
            voyageur_id INT NOT NULL,
            numero_billet VARCHAR(80) NOT NULL UNIQUE,
            prix DECIMAL(15,2) NOT NULL,
            devise VARCHAR(10) NOT NULL DEFAULT 'FC',
            statut_paiement VARCHAR(30) DEFAULT 'Non paye',
            statut VARCHAR(30) DEFAULT 'Reserve',
            date_enregistrement TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (agence_id)
            REFERENCES agences(id),

            FOREIGN KEY (voyage_id)
            REFERENCES voyages(id),

            FOREIGN KEY (voyageur_id)
            REFERENCES voyageurs(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       17. TABLE DES COLIS
       Fragile : prix forfaitaire saisi directement.
       Non fragile : poids x tarif au kilogramme.
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS colis (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agence_id INT NOT NULL,
            voyage_id INT NULL,
            type_colis_id INT NOT NULL,
            milieu_depart_id INT NOT NULL,
            milieu_arrivee_id INT NOT NULL,
            expediteur_id INT NOT NULL,
            destinataire_id INT NOT NULL,
            code_colis VARCHAR(80) NOT NULL UNIQUE,
            description TEXT,
            poids DECIMAL(10,2) DEFAULT 0,
            prix_unitaire_kg DECIMAL(15,2) DEFAULT 0,
            prix DECIMAL(15,2) NOT NULL DEFAULT 0,
            devise VARCHAR(10) NOT NULL DEFAULT 'FC',
            statut VARCHAR(30) DEFAULT 'Enregistre',
            date_enregistrement TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            date_retrait DATETIME NULL,
            observations TEXT,

            FOREIGN KEY (agence_id)
            REFERENCES agences(id),

            FOREIGN KEY (voyage_id)
            REFERENCES voyages(id)
            ON DELETE SET NULL,

            FOREIGN KEY (type_colis_id)
            REFERENCES types_colis(id),

            FOREIGN KEY (milieu_depart_id)
            REFERENCES milieux(id),

            FOREIGN KEY (milieu_arrivee_id)
            REFERENCES milieux(id),

            FOREIGN KEY (expediteur_id)
            REFERENCES expediteurs(id),

            FOREIGN KEY (destinataire_id)
            REFERENCES destinataires(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       18. TABLE DES PAIEMENTS
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS paiements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agence_id INT NOT NULL,
            utilisateur_id INT NULL,
            type_operation VARCHAR(50) NOT NULL,
            reference_id INT NULL,
            reference_paiement VARCHAR(100),
            montant DECIMAL(15,2) NOT NULL,
            devise VARCHAR(10) NOT NULL DEFAULT 'FC',
            mode_paiement VARCHAR(50) DEFAULT 'Especes',
            date_paiement DATETIME DEFAULT CURRENT_TIMESTAMP,
            observations TEXT,

            FOREIGN KEY (agence_id)
            REFERENCES agences(id),

            FOREIGN KEY (utilisateur_id)
            REFERENCES utilisateurs(id)
            ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       19. TABLE DE LA CAISSE
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS caisse (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agence_id INT NOT NULL,
            utilisateur_id INT NULL,
            type_mouvement VARCHAR(30) NOT NULL,
            libelle VARCHAR(255) NOT NULL,
            montant DECIMAL(15,2) NOT NULL,
            devise VARCHAR(10) DEFAULT 'FC',
            reference VARCHAR(100),
            date_operation DATETIME DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (agence_id)
            REFERENCES agences(id),

            FOREIGN KEY (utilisateur_id)
            REFERENCES utilisateurs(id)
            ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       20. TABLE DES DEPENSES
       ====================================================== */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS depenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agence_id INT NOT NULL,
            utilisateur_id INT NULL,
            libelle VARCHAR(255) NOT NULL,
            categorie VARCHAR(100),
            montant DECIMAL(15,2) NOT NULL,
            devise VARCHAR(10) DEFAULT 'FC',
            beneficiaire VARCHAR(150),
            date_depense DATETIME DEFAULT CURRENT_TIMESTAMP,
            observations TEXT,

            FOREIGN KEY (agence_id)
            REFERENCES agences(id),

            FOREIGN KEY (utilisateur_id)
            REFERENCES utilisateurs(id)
            ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /* ======================================================
       21. INSERTION DES DONNEES DE BASE
       ====================================================== */

    // Création de l'agence principale

    $stmt = $pdo->prepare("
        SELECT id FROM agences
        WHERE code_agence = 'AG-PRINCIPALE'
        LIMIT 1
    ");
    $stmt->execute();

    $agence = $stmt->fetch();

    if (!$agence) {

        $stmt = $pdo->prepare("
            INSERT INTO agences
            (nom_agence, code_agence, type_agence, statut)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            "Agence Principale",
            "AG-PRINCIPALE",
            "Principale",
            "Actif"
        ]);

        $agence_id = $pdo->lastInsertId();

    } else {
        $agence_id = $agence['id'];
    }

    // Création des paramètres par défaut

    $stmt = $pdo->query("SELECT COUNT(*) FROM parametres");

    if ((int)$stmt->fetchColumn() === 0) {

        $stmt = $pdo->prepare("
            INSERT INTO parametres
            (nom_agence, devise_principale, devise_secondaire,
             pied_facture)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            "Agence de Voyage",
            "FC",
            "USD",
            "Merci pour votre confiance. Bon voyage !"
        ]);
    }

    // Création des types de colis

    $types = [
        ['Fragile', 'Forfait'],
        ['Non fragile', 'Kilogramme']
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO types_colis
        (nom_type, mode_tarification, description)
        VALUES (?, ?, ?)
    ");

    foreach ($types as $type) {
        $stmt->execute([
            $type[0],
            $type[1],
            $type[0] === 'Fragile'
                ? 'Tarification forfaitaire directe'
                : 'Tarification selon le poids et le trajet'
        ]);
    }

    // Création de l'administrateur initial

    $nom_utilisateur = "admin";

    $stmt = $pdo->prepare("
        SELECT id FROM utilisateurs
        WHERE nom_utilisateur = ?
        LIMIT 1
    ");

    $stmt->execute([$nom_utilisateur]);

    if (!$stmt->fetch()) {

        $mot_de_passe_admin = password_hash(
            "Admin@12345",
            PASSWORD_DEFAULT
        );

        $stmt = $pdo->prepare("
            INSERT INTO utilisateurs
            (agence_id, nom, prenom, nom_utilisateur,
             mot_de_passe, role, statut)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $agence_id,
            "Administrateur",
            "Principal",
            "admin",
            $mot_de_passe_admin,
            "Administrateur",
            "Actif"
        ]);
    }

    /* ======================================================
       22. FIN DE L'INSTALLATION
       ====================================================== */

    $message = "Installation effectuee avec succes !";
    $type_message = "success";

} catch (PDOException $e) {

    $message = "Erreur lors de l'installation : " . $e->getMessage();
    $type_message = "error";

}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Installation - Gestion Agence de Voyage</title>
    <link rel="icon" href="<?= securiser(URL_APPLICATION . LOGO_APPLICATION) ?>" type="image/jpeg">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body class="min-h-screen bg-sky-50 flex items-center justify-center p-4">

    <div class="bg-white w-full max-w-xl rounded-2xl shadow-xl p-8">

        <div class="text-center mb-6">

            <div class="w-16 h-16 bg-sky-100 text-sky-600
                        rounded-2xl flex items-center justify-center
                        mx-auto mb-4 text-3xl">
                <i data-lucide="bus" class="h-8 w-8" aria-hidden="true"></i>
            </div>

            <h1 class="text-2xl font-bold text-slate-800">
                Gestion Agence de Voyage
            </h1>

            <p class="text-slate-500 mt-2">
                Installation de la base de données
            </p>

        </div>

        <?php if ($type_message === "success"): ?>

            <div class="bg-green-50 border border-green-200
                        text-green-700 rounded-xl p-4 mb-6">

                <h2 class="font-bold text-lg mb-2">
                    Installation réussie !
                </h2>

                <?= htmlspecialchars($message) ?>

            </div>

            <div class="bg-sky-50 border border-sky-200
                        rounded-xl p-5 mb-6">

                <h3 class="font-bold text-sky-800 mb-3">
                    Informations de connexion
                </h3>

                <p class="text-sm text-slate-700 mb-2">
                    Identifiant :
                    <strong>admin</strong>
                </p>

                <p class="text-sm text-slate-700 mb-2">
                    Mot de passe :
                    <strong>Admin@12345</strong>
                </p>

                <p class="text-xs text-red-600 mt-3">
                    Changez ce mot de passe après votre première connexion.
                </p>

            </div>

            <a href="login.php"
               class="block text-center bg-sky-600 hover:bg-sky-700
                      text-white font-semibold py-3 rounded-xl
                      transition">

                Accéder à la connexion

            </a>

        <?php else: ?>

            <div class="bg-red-50 border border-red-200
                        text-red-700 rounded-xl p-4">

                <h2 class="font-bold mb-2">
                    Échec de l'installation
                </h2>

                <p class="text-sm break-words">
                    <?= htmlspecialchars($message) ?>
                </p>

            </div>

        <?php endif; ?>

        <p class="text-center text-xs text-slate-400 mt-6">
            Application de gestion de transport et de colis
        </p>

    </div>

<script src="icons.js"></script>
</body>
</html>

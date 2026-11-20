<?php

if (!defined('NOM_APPLICATION')) {
    define('NOM_APPLICATION', 'Gestion Agence de Voyage');
}

if (!defined('URL_APPLICATION')) {
    define('URL_APPLICATION', 'http://localhost/agence_voyage/');
}

if (!defined('DEVISE_PAR_DEFAUT')) {
    define('DEVISE_PAR_DEFAUT', 'FC');
}


/* ==========================================================
   2. CONFIGURATION DE LA BASE DE DONNEES
   ========================================================== */

$serveur = "localhost";
$base_de_donnees = "gestion_agence_voyage";
$utilisateur = "root";
$mot_de_passe = "";

/*
Pour InfinityFree, remplacez ces informations par
les informations MySQL de votre hébergement.
*/

/* ==========================================================
   3. CONNEXION PDO
   ========================================================== */

try {

    $pdo = new PDO(
        "mysql:host=$serveur;dbname=$base_de_donnees;charset=utf8mb4",
        $utilisateur,
        $mot_de_passe,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {

    error_log($e->getMessage());

    die("
        <div style='
            max-width:600px;
            margin:80px auto;
            padding:25px;
            background:#eff6ff;
            border:1px solid #bae6fd;
            border-radius:12px;
            font-family:Arial;
            color:#075985;
        '>
            <h2>Erreur de connexion</h2>
            <p>Impossible de se connecter à la base de données.</p>
            <p>Vérifiez votre fichier config.php et votre serveur MySQL.</p>
        </div>
    ");
}

/* ==========================================================
   4. GESTION DES SESSIONS
   ========================================================== */

if (session_status() === PHP_SESSION_NONE) {

    $https = isset($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

/* ==========================================================
   5. FONCTION DE SECURITE HTML
   ========================================================== */

if (!function_exists('securiser')) {

    function securiser($valeur): string {

        return htmlspecialchars(
            (string)($valeur ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

/* Alias pratique pour les anciennes pages */

if (!function_exists('h')) {

    function h($valeur): string {
        return securiser($valeur);
    }
}

/* ==========================================================
   6. VERIFICATION DE CONNEXION
   ========================================================== */

if (!function_exists('estConnecte')) {

    function estConnecte(): bool {

        return isset($_SESSION['id_utilisateur']);
    }
}

/* ==========================================================
   7. EXIGER UNE CONNEXION
   ========================================================== */

if (!function_exists('exigerConnexion')) {

    function exigerConnexion(): void {

        if (!estConnecte()) {

            header("Location: login.php");
            exit;
        }
    }
}

/* Alias de compatibilité */

if (!function_exists('verifierConnexion')) {

    function verifierConnexion(): void {

        exigerConnexion();
    }
}

/* ==========================================================
   8. RECUPERATION DE L'UTILISATEUR CONNECTE
   ========================================================== */

if (!function_exists('utilisateurConnecte')) {

    function utilisateurConnecte(): array {

        return [
            'id' => $_SESSION['id_utilisateur'] ?? null,
            'nom' => $_SESSION['nom'] ?? '',
            'postnom' => $_SESSION['postnom'] ?? '',
            'prenom' => $_SESSION['prenom'] ?? '',
            'nom_utilisateur' => $_SESSION['nom_utilisateur'] ?? '',
            'role' => $_SESSION['role'] ?? '',
            'agence_id' => $_SESSION['agence_id'] ?? null,
            'nom_agence' => $_SESSION['nom_agence'] ?? ''
        ];
    }
}

/* ==========================================================
   9. VERIFICATION DU ROLE
   ========================================================== */

if (!function_exists('exigerRole')) {

    function exigerRole(array $roles): void {

        exigerConnexion();

        $role = $_SESSION['role'] ?? '';

        if (!in_array($role, $roles, true)) {

            http_response_code(403);

            die("
                <div style='
                    max-width:550px;
                    margin:80px auto;
                    padding:25px;
                    text-align:center;
                    font-family:Arial;
                    background:#eff6ff;
                    border:1px solid #bae6fd;
                    border-radius:15px;
                '>
                    <h2 style='color:#0369a1'>
                        Accès refusé
                    </h2>

                    <p>
                        Vous n'avez pas l'autorisation
                        d'accéder à cette page.
                    </p>

                    <a href='dashboard.php'
                       style='
                        display:inline-block;
                        background:#0284c7;
                        color:white;
                        padding:12px 20px;
                        text-decoration:none;
                        border-radius:8px;
                       '>
                        Retour au tableau de bord
                    </a>
                </div>
            ");

        }
    }
}

/* ==========================================================
   10. RECUPERATION DE L'AGENCE DE L'UTILISATEUR
   ========================================================== */

if (!function_exists('agenceConnectee')) {

    function agenceConnectee(): ?int {

        if (!estConnecte()) {
            return null;
        }

        $id = $_SESSION['agence_id'] ?? null;

        return $id !== null ? (int)$id : null;
    }
}

/* ==========================================================
   11. VERIFICATION ADMINISTRATEUR
   ========================================================== */

if (!function_exists('estAdministrateur')) {

    function estAdministrateur(): bool {

        return isset($_SESSION['role'])
            && $_SESSION['role'] === 'Administrateur';
    }
}

/* ==========================================================
   12. FORMATAGE DES MONTANTS
   ========================================================== */

if (!function_exists('formaterMontant')) {

    function formaterMontant(
        $montant,
        string $devise = 'FC'
    ): string {

        $devise = strtoupper($devise);

        if (!in_array($devise, ['FC', 'USD'], true)) {
            $devise = 'FC';
        }

        return number_format(
            (float)$montant,
            2,
            ',',
            ' '
        ) . ' ' . $devise;
    }
}

/* ==========================================================
   13. VALIDATION DE LA DEVISE
   ========================================================== */

if (!function_exists('deviseValide')) {

    function deviseValide(string $devise): bool {

        return in_array(
            strtoupper(trim($devise)),
            ['FC', 'USD'],
            true
        );
    }
}

/* ==========================================================
   14. GENERATION DE REFERENCES
   ========================================================== */

if (!function_exists('genererReference')) {

    function genererReference(string $prefixe): string {

        return strtoupper($prefixe)
            . '-' . date('Ymd')
            . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

/* ==========================================================
   15. DATE FORMATEE
   ========================================================== */

if (!function_exists('dateFormatee')) {

    function dateFormatee($date): string {

        if (empty($date)) {
            return '-';
        }

        return date(
            'd/m/Y H:i',
            strtotime($date)
        );
    }
}

/* ==========================================================
   16. PROTECTION CONTRE LES REQUETES CSRF
   ========================================================== */

if (!function_exists('genererTokenCSRF')) {

    function genererTokenCSRF(): string {

        if (empty($_SESSION['csrf_token'])) {

            $_SESSION['csrf_token'] = bin2hex(
                random_bytes(32)
            );
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verifierTokenCSRF')) {

    function verifierTokenCSRF(): void {

        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token)
            || empty($_SESSION['csrf_token'])
            || !hash_equals($_SESSION['csrf_token'], $token)
        ) {

            http_response_code(403);

            die("Requête invalide. Veuillez actualiser la page.");
        }
    }
}

/* ==========================================================
   17. RECUPERATION DES PARAMETRES DE L'AGENCE
   ========================================================== */

if (!function_exists('parametresAgence')) {

    function parametresAgence(PDO $pdo): array {

        static $parametres = null;

        if ($parametres !== null) {
            return $parametres;
        }

        $stmt = $pdo->query("
            SELECT *
            FROM parametres
            ORDER BY id ASC
            LIMIT 1
        ");

        $parametres = $stmt->fetch() ?: [];

        return $parametres;
    }
}

/* ==========================================================
   18. RECUPERATION DU NOM DE L'AGENCE
   ========================================================== */

if (!function_exists('nomAgence')) {

    function nomAgence(PDO $pdo): string {

        $parametres = parametresAgence($pdo);

        return $parametres['nom_agence']
            ?? NOM_APPLICATION;
    }
}

/* ==========================================================
   19. FONCTION DE REDIRECTION
   ========================================================== */

if (!function_exists('rediriger')) {

    function rediriger(string $page): void {

        header("Location: " . $page);
        exit;
    }
}


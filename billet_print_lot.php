<?php
require_once "config.php";
exigerConnexion();

$agenceId      = (int)($_SESSION['agence_id'] ?? 0);
$utilisateurId = (int)($_SESSION['id_utilisateur'] ?? 0);

/* =====================================================
   1. SELECTION DES BILLETS
===================================================== */

$idsParam = trim($_GET['ids'] ?? '');
$voyageId = (int)($_GET['voyage_id'] ?? 0);

$billets = [];

try {

    if ($idsParam !== '') {

        // IDs fournis : 1,2,3,4
        $ids = array_filter(array_map('intval', explode(',', $idsParam)));

        if (empty($ids)) {
            die("Aucun billet sélectionné.");
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare("
            SELECT b.*,
                   v.code_voyage, v.date_depart,
                   d.nom_milieu AS depart, a.nom_milieu AS arrivee,
                   voy.nom_complet AS voyageur, voy.telephone AS voyageur_tel,
                   ch.nom AS ch_nom, ch.postnom AS ch_postnom, ch.prenom AS ch_prenom,
                   ve.code_vehicule, ve.immatriculation
            FROM billets b
            INNER JOIN voyages v ON v.id = b.voyage_id
            INNER JOIN milieux d ON d.id = v.milieu_depart_id
            INNER JOIN milieux a ON a.id = v.milieu_arrivee_id
            INNER JOIN voyageurs voy ON voy.id = b.voyageur_id
            INNER JOIN chauffeurs ch ON ch.id = v.chauffeur_id
            INNER JOIN vehicules ve ON ve.id = v.vehicule_id
            WHERE b.agence_id = ?
              AND b.id IN ($placeholders)
            ORDER BY b.id ASC
        ");

        $stmt->execute(array_merge([$agenceId], $ids));
        $billets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($voyageId > 0) {

        // Tous les billets d'un voyage
        $stmt = $pdo->prepare("
            SELECT b.*,
                   v.code_voyage, v.date_depart,
                   d.nom_milieu AS depart, a.nom_milieu AS arrivee,
                   voy.nom_complet AS voyageur, voy.telephone AS voyageur_tel,
                   ch.nom AS ch_nom, ch.postnom AS ch_postnom, ch.prenom AS ch_prenom,
                   ve.code_vehicule, ve.immatriculation
            FROM billets b
            INNER JOIN voyages v ON v.id = b.voyage_id
            INNER JOIN milieux d ON d.id = v.milieu_depart_id
            INNER JOIN milieux a ON a.id = v.milieu_arrivee_id
            INNER JOIN voyageurs voy ON voy.id = b.voyageur_id
            INNER JOIN chauffeurs ch ON ch.id = v.chauffeur_id
            INNER JOIN vehicules ve ON ve.id = v.vehicule_id
            WHERE b.agence_id = ? AND b.voyage_id = ?
              AND b.statut != 'Annule'
            ORDER BY b.id ASC
        ");
        $stmt->execute([$agenceId, $voyageId]);
        $billets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    die("Erreur : " . securiser($e->getMessage()));
}

if (empty($billets)) {
    die("Aucun billet à imprimer.");
}

/* =====================================================
   2. MISE A JOUR CAISSE POUR LES BILLETS PAYES
===================================================== */

foreach ($billets as $b) {

    if ($b['statut_paiement'] !== 'Paye') continue;

    $referenceCaisse = 'BIL-' . $b['numero_billet'];

    $stmt = $pdo->prepare("SELECT id FROM caisse WHERE agence_id = ? AND reference = ? LIMIT 1");
    $stmt->execute([$agenceId, $referenceCaisse]);

    if (!$stmt->fetch()) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO caisse
                (agence_id, utilisateur_id, type_mouvement,
                 libelle, montant, devise, reference, date_operation)
                VALUES (?, ?, 'Entree', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $agenceId, $utilisateurId ?: null,
                "Vente billet {$b['numero_billet']} - {$b['voyageur']}",
                (float)$b['prix'], $b['devise'], $referenceCaisse
            ]);
        } catch (PDOException $e) {
            // Silencieux
        }
    }
}

/* =====================================================
   3. PARAMETRES
===================================================== */

$parametres = parametresAgence($pdo);

$nomAgence     = $parametres['nom_agence']   ?? NOM_APPLICATION;
$adresseAgence = $parametres['adresse']      ?? '';
$tel1Agence    = $parametres['telephone1']   ?? '';
$piedFacture   = $parametres['pied_facture'] ?? 'Merci pour votre confiance.';
$logoAgence    = $parametres['logo']         ?? '';

$logoUrl = $logoAgence ? URL_APPLICATION . ltrim($logoAgence, '/') : '';

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impression par lot - <?= count($billets) ?> billets</title>
    <link rel="icon" href="<?= securiser(URL_APPLICATION . LOGO_APPLICATION) ?>" type="image/jpeg">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            background: #e2e8f0;
            margin: 0;
            padding: 20px;
        }

        .barre-actions {
            max-width: 210mm;
            margin: 0 auto 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: bold;
            color: white;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-print { background: #0284c7; }
        .btn-print:hover { background: #0369a1; }
        .btn-back { background: #475569; }
        .btn-back:hover { background: #334155; }

        .info {
            max-width: 210mm;
            margin: 0 auto 20px;
            padding: 12px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
            color: #065f46;
            font-size: 13px;
        }

        /* FEUILLE A4 */
        .feuille {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 20px;
            background: white;
            padding: 8mm;
            box-shadow: 0 4px 20px rgba(0,0,0,.15);
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: auto auto;
            gap: 4mm;
            align-content: start;
        }

        /* COUPON */
        .coupon {
            border: 1px dashed #999;
            border-radius: 4px;
            padding: 4mm;
            font-size: 10px;
            line-height: 1.3;
            font-family: 'Courier New', monospace;
            color: #111;
            background: white;
            page-break-inside: avoid;
            display: flex;
            flex-direction: column;
        }

        .coupon-header {
            text-align: center;
            margin-bottom: 2mm;
        }

        .coupon-header h2 {
            font-size: 13px;
            font-weight: bold;
            margin: 0 0 1mm;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .coupon-header .sub {
            font-size: 8px;
            color: #555;
            margin: 0;
        }

        .coupon .logo {
            max-width: 35px;
            max-height: 35px;
            display: block;
            margin: 0 auto 1mm;
            object-fit: contain;
        }

        .coupon-sep {
            border: none;
            border-top: 1px dashed #999;
            margin: 1.5mm 0;
        }

        .coupon-type {
            text-align: center;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 1mm 0;
        }

        .coupon-code {
            text-align: center;
            font-weight: bold;
            font-size: 9px;
            background: #f3f4f6;
            padding: 1mm;
            border-radius: 2px;
            letter-spacing: .5px;
            margin-bottom: 2mm;
        }

        .coupon-row {
            display: flex;
            justify-content: space-between;
            gap: 2mm;
            padding: 0.5mm 0;
            font-size: 9px;
        }

        .coupon-row .label { color: #555; }
        .coupon-row .value { text-align: right; font-weight: bold; word-break: break-word; }

        .coupon-total {
            text-align: center;
            font-size: 13px;
            font-weight: bold;
            border: 1px solid #111;
            border-radius: 3px;
            padding: 1.5mm;
            margin: 2mm 0;
        }

        .coupon-statut {
            text-align: center;
            font-size: 8px;
            font-weight: bold;
            padding: 1mm;
            border-radius: 999px;
            background: #e0f2fe;
            color: #075985;
            margin-bottom: 1mm;
        }

        .coupon-statut.paye { background: #d1fae5; color: #065f46; }
        .coupon-statut.partiel { background: #fef3c7; color: #92400e; }
        .coupon-statut.non-paye { background: #fee2e2; color: #991b1b; }

        .coupon-qr {
            text-align: center;
            margin: 1mm 0;
        }

        .coupon-qr img {
            width: 22mm;
            height: 22mm;
        }

        .coupon-footer {
            text-align: center;
            font-size: 7px;
            color: #555;
            font-style: italic;
            margin-top: auto;
            padding-top: 1mm;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 0;
            }

            body {
                background: white;
                padding: 0;
                margin: 0;
            }

            .barre-actions, .info, .no-print {
                display: none !important;
            }

            .feuille {
                box-shadow: none;
                margin: 0;
                page-break-after: always;
            }

            .feuille:last-child {
                page-break-after: auto;
            }

            .coupon {
                border-color: #999;
            }
        }
    </style>
</head>
<body>

    <div class="barre-actions no-print">
        <button class="btn btn-print" onclick="window.print()">
            <i data-lucide="printer" class="inline-block h-4 w-4 mr-1 align-middle" aria-hidden="true"></i>
            Imprimer <?= count($billets) ?> billet(s)
        </button>
        <a href="billets.php" class="btn btn-back">← Retour aux billets</a>
    </div>

    <div class="info no-print">
        <i data-lucide="circle-check" class="inline-block h-4 w-4 mr-1 align-middle" aria-hidden="true"></i>
        <strong><?= count($billets) ?></strong> billet(s) à imprimer —
        Soit <strong><?= ceil(count($billets) / 4) ?></strong> feuille(s) A4
        (4 coupons par feuille).
        Les paiements payés ont été ajoutés à la caisse automatiquement.
    </div>

    <?php
    // Découpage en groupes de 4
    $groupes = array_chunk($billets, 4);

    foreach ($groupes as $groupe):
    ?>
        <div class="feuille">

            <?php foreach ($groupe as $billet): ?>

                <?php
                $lienSuivi = URL_APPLICATION . 'suivi_colis.php?code=' . urlencode($billet['numero_billet']);
                $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($lienSuivi);
                ?>

                <div class="coupon">

                    <div class="coupon-header">
                        <?php if ($logoUrl): ?>
                            <img src="<?= securiser($logoUrl) ?>" alt="Logo" class="logo">
                        <?php endif; ?>
                        <h2><?= securiser($nomAgence) ?></h2>
                        <?php if ($adresseAgence !== ''): ?>
                            <p class="sub"><?= securiser($adresseAgence) ?></p>
                        <?php endif; ?>
                        <?php if ($tel1Agence !== ''): ?>
                            <p class="sub">Tél : <?= securiser($tel1Agence) ?></p>
                        <?php endif; ?>
                    </div>

                    <hr class="coupon-sep">

                    <p class="coupon-type">Billet de voyage</p>
                    <div class="coupon-code"><?= securiser($billet['numero_billet']) ?></div>

                    <div class="coupon-row">
                        <span class="label">Voyageur</span>
                        <span class="value"><?= securiser($billet['voyageur']) ?></span>
                    </div>

                    <?php if (!empty($billet['voyageur_tel'])): ?>
                        <div class="coupon-row">
                            <span class="label">Tél</span>
                            <span class="value"><?= securiser($billet['voyageur_tel']) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="coupon-row">
                        <span class="label">Trajet</span>
                        <span class="value">
                            <?= securiser($billet['depart']) ?> → <?= securiser($billet['arrivee']) ?>
                        </span>
                    </div>

                    <div class="coupon-row">
                        <span class="label">Départ</span>
                        <span class="value">
                            <?= date('d/m/Y H:i', strtotime($billet['date_depart'])) ?>
                        </span>
                    </div>

                    <div class="coupon-row">
                        <span class="label">Voyage</span>
                        <span class="value"><?= securiser($billet['code_voyage']) ?></span>
                    </div>

                    <div class="coupon-row">
                        <span class="label">Véhicule</span>
                        <span class="value"><?= securiser($billet['code_vehicule']) ?></span>
                    </div>

                    <hr class="coupon-sep">

                    <div class="coupon-total">
                        <?= number_format((float)$billet['prix'], 2, ',', ' ') ?>
                        <?= securiser($billet['devise']) ?>
                    </div>

                    <div class="coupon-statut <?= match($billet['statut_paiement']) {
                        'Paye' => 'paye', 'Partiel' => 'partiel', default => 'non-paye',
                    } ?>">
                        <?= securiser($billet['statut_paiement']) ?>
                    </div>

                    <div class="coupon-qr">
                        <img src="<?= securiser($qrCodeUrl) ?>" alt="QR">
                    </div>

                    <div class="coupon-footer">
                        <?= securiser($piedFacture) ?>
                    </div>

                </div>

            <?php endforeach; ?>

            <?php
            // Compléter la feuille avec des cases vides si moins de 4
            $restant = 4 - count($groupe);
            for ($i = 0; $i < $restant; $i++):
            ?>
                <div class="coupon" style="border-color: transparent;"></div>
            <?php endfor; ?>

        </div>
    <?php endforeach; ?>

<script src="icons.js"></script>
</body>
</html>
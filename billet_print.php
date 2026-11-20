<?php
require_once "config.php";
exigerConnexion();

$agenceId      = (int)($_SESSION['agence_id'] ?? 0);
$utilisateurId = (int)($_SESSION['id_utilisateur'] ?? 0);

$billetId = (int)($_GET['id'] ?? 0);

if ($billetId <= 0) {
    die("Identifiant de billet invalide.");
}

try {
    $stmt = $pdo->prepare("
        SELECT b.*,
               v.code_voyage, v.date_depart, v.date_arrivee_prevue,
               d.nom_milieu AS depart, a.nom_milieu AS arrivee,
               voy.nom_complet AS voyageur, voy.telephone AS voyageur_tel,
               ch.nom AS ch_nom, ch.postnom AS ch_postnom, ch.prenom AS ch_prenom,
               ve.code_vehicule, ve.marque, ve.immatriculation
        FROM billets b
        INNER JOIN voyages v ON v.id = b.voyage_id
        INNER JOIN milieux d ON d.id = v.milieu_depart_id
        INNER JOIN milieux a ON a.id = v.milieu_arrivee_id
        INNER JOIN voyageurs voy ON voy.id = b.voyageur_id
        INNER JOIN chauffeurs ch ON ch.id = v.chauffeur_id
        INNER JOIN vehicules ve ON ve.id = v.vehicule_id
        WHERE b.id = ? AND b.agence_id = ?
        LIMIT 1
    ");
    $stmt->execute([$billetId, $agenceId]);
    $billet = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur : " . securiser($e->getMessage()));
}

if (!$billet) {
    die("Billet introuvable ou non autorisé.");
}

/* =====================================================
   MISE A JOUR CAISSE (une seule fois par paiement)
===================================================== */

$infoCaisse = "";

if ($billet['statut_paiement'] === 'Paye') {

    $referenceCaisse = 'BIL-' . $billet['numero_billet'];

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
                "Vente billet {$billet['numero_billet']} - {$billet['voyageur']}",
                (float)$billet['prix'], $billet['devise'], $referenceCaisse
            ]);
            $infoCaisse = "Paiement encaissé et ajouté à la caisse.";
        } catch (PDOException $e) {
            $infoCaisse = "Attention : impossible de mettre à jour la caisse.";
        }
    }
}

/* =====================================================
   PARAMETRES AGENCE
===================================================== */

$parametres = parametresAgence($pdo);

$nomAgence     = $parametres['nom_agence']   ?? NOM_APPLICATION;
$sloganAgence  = $parametres['slogan']       ?? '';
$adresseAgence = $parametres['adresse']      ?? '';
$tel1Agence    = $parametres['telephone1']   ?? '';
$piedFacture   = $parametres['pied_facture'] ?? 'Merci pour votre confiance. Bon voyage !';
$logoAgence    = $parametres['logo']         ?? '';

$logoUrl = $logoAgence ? URL_APPLICATION . ltrim($logoAgence, '/') : '';

/* =====================================================
   QR CODE + LIEN SUIVI
===================================================== */

$lienSuivi = URL_APPLICATION . 'suivi_colis.php?code=' . urlencode($billet['numero_billet']);
$qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($lienSuivi);

/* =====================================================
   TEXTE PARTAGE
===================================================== */

$textePartage = "BILLET DE VOYAGE\n"
    . "N° : {$billet['numero_billet']}\n"
    . "Agence : {$nomAgence}\n"
    . "Voyageur : {$billet['voyageur']}\n"
    . "Trajet : {$billet['depart']} → {$billet['arrivee']}\n"
    . "Départ : " . date('d/m/Y H:i', strtotime($billet['date_depart'])) . "\n"
    . "Véhicule : {$billet['code_vehicule']} ({$billet['immatriculation']})\n"
    . "Prix : " . number_format((float)$billet['prix'], 2, ',', ' ') . " {$billet['devise']}\n"
    . "Statut : {$billet['statut_paiement']}\n"
    . "Présentez ce billet à l'embarquement.";

/* =====================================================
   NUMERO WHATSAPP (nettoyage)
===================================================== */

function nettoyerTelephone(string $tel): string
{
    // Garder uniquement les chiffres
    $tel = preg_replace('/\D+/', '', $tel);

    // Si commence par 0 (RDC), convertir en 243
    if (str_starts_with($tel, '0') && strlen($tel) >= 9) {
        $tel = '243' . substr($tel, 1);
    }

    return $tel;
}

$telVoyageur = nettoyerTelephone($billet['voyageur_tel'] ?? '');

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billet <?= securiser($billet['numero_billet']) ?></title>
    <link rel="icon" href="<?= securiser(URL_APPLICATION . LOGO_APPLICATION) ?>" type="image/jpeg">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            background: #e2e8f0; margin: 0; padding: 16px;
            display: flex; flex-direction: column; align-items: center;
            min-height: 100vh;
        }
        .ticket {
            width: 80mm; max-width: 100%; background: white; color: #111;
            padding: 12px 10px; box-shadow: 0 4px 20px rgba(0,0,0,.15);
            border-radius: 6px; font-size: 12px; line-height: 1.35;
        }
        .ticket h1 { font-size: 16px; text-align: center; margin: 0 0 4px; font-weight: bold; text-transform: uppercase; }
        .ticket .sub { text-align: center; font-size: 10px; color: #555; margin-bottom: 8px; }
        .ticket .logo { display: block; max-width: 60px; max-height: 60px; margin: 0 auto 6px; object-fit: contain; }
        .sep { border: none; border-top: 1px dashed #999; margin: 6px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; padding: 2px 0; }
        .row .label { color: #555; flex-shrink: 0; }
        .row .value { text-align: right; font-weight: bold; word-break: break-word; }
        .valeur-total { font-size: 16px; font-weight: bold; text-align: center; padding: 6px 0; border: 1px solid #111; border-radius: 4px; margin: 6px 0; }
        .statut { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: bold; background: #e0f2fe; color: #075985; }
        .statut.paye { background: #d1fae5; color: #065f46; }
        .statut.partiel { background: #fef3c7; color: #92400e; }
        .statut.non-paye { background: #fee2e2; color: #991b1b; }
        .code-billet { font-family: 'Courier New', monospace; letter-spacing: 1px; font-weight: bold; font-size: 11px; text-align: center; padding: 4px; background: #f3f4f6; border-radius: 4px; margin: 4px 0; }
        .footer-ticket { text-align: center; font-size: 10px; color: #555; margin-top: 8px; font-style: italic; }
        .qr-zone { text-align: center; margin: 6px 0; }
        .qr-zone img { width: 100px; height: 100px; }
        .qr-zone p { font-size: 9px; color: #555; margin: 2px 0 0; }
        .barre-actions { width: 80mm; max-width: 100%; display: flex; gap: 8px; margin-top: 14px; }
        .btn {
            flex: 1; padding: 12px; border: none; border-radius: 8px;
            font-weight: bold; font-size: 13px; cursor: pointer; color: white;
            text-align: center; text-decoration: none;
            display: flex; align-items: center; justify-content: center; gap: 4px;
        }
        .btn-print { background: #0284c7; } .btn-print:hover { background: #0369a1; }
        .btn-share { background: #059669; } .btn-share:hover { background: #047857; }
        .btn-wa    { background: #25D366; } .btn-wa:hover    { background: #1ebe5b; }
        .btn-back  { background: #475569; } .btn-back:hover  { background: #334155; }
        .info-caisse { width: 80mm; max-width: 100%; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 10px; border-radius: 8px; font-size: 12px; margin-bottom: 12px; text-align: center; }

        @media print {
            @page { size: 80mm auto; margin: 0; }
            body { background: white; padding: 0; display: block; }
            .barre-actions, .info-caisse, .no-print { display: none !important; }
            .ticket { box-shadow: none; border-radius: 0; padding: 8px 6px; width: 80mm; font-size: 11px; }
        }
    </style>
</head>
<body>

    <?php if ($infoCaisse !== ''): ?>
        <div class="info-caisse no-print"><i data-lucide="circle-check" class="inline-block h-4 w-4 mr-1 align-middle" aria-hidden="true"></i><?= securiser($infoCaisse) ?></div>
    <?php endif; ?>

    <div class="ticket">

        <?php if ($logoUrl): ?>
            <img src="<?= securiser($logoUrl) ?>" alt="Logo" class="logo">
        <?php endif; ?>

        <h1><?= securiser($nomAgence) ?></h1>
        <?php if ($sloganAgence !== ''): ?><p class="sub"><?= securiser($sloganAgence) ?></p><?php endif; ?>
        <?php if ($adresseAgence !== ''): ?><p class="sub"><?= securiser($adresseAgence) ?></p><?php endif; ?>
        <?php if ($tel1Agence !== ''): ?><p class="sub">Tél : <?= securiser($tel1Agence) ?></p><?php endif; ?>

        <hr class="sep">
        <h1 style="font-size:14px;">BILLET DE VOYAGE</h1>
        <div class="code-billet"><?= securiser($billet['numero_billet']) ?></div>
        <hr class="sep">

        <div class="row"><span class="label">Voyageur</span><span class="value"><?= securiser($billet['voyageur']) ?></span></div>
        <?php if (!empty($billet['voyageur_tel'])): ?>
            <div class="row"><span class="label">Téléphone</span><span class="value"><?= securiser($billet['voyageur_tel']) ?></span></div>
        <?php endif; ?>
        <div class="row"><span class="label">Trajet</span><span class="value"><?= securiser($billet['depart']) ?> → <?= securiser($billet['arrivee']) ?></span></div>
        <div class="row"><span class="label">Départ</span><span class="value"><?= date('d/m/Y H:i', strtotime($billet['date_depart'])) ?></span></div>
        <?php if (!empty($billet['date_arrivee_prevue'])): ?>
            <div class="row"><span class="label">Arrivée prévue</span><span class="value"><?= date('d/m/Y H:i', strtotime($billet['date_arrivee_prevue'])) ?></span></div>
        <?php endif; ?>
        <div class="row"><span class="label">Voyage</span><span class="value"><?= securiser($billet['code_voyage']) ?></span></div>
        <div class="row"><span class="label">Véhicule</span><span class="value"><?= securiser($billet['code_vehicule']) ?> · <?= securiser($billet['immatriculation']) ?></span></div>
        <div class="row"><span class="label">Chauffeur</span><span class="value"><?= securiser(trim($billet['ch_nom'] . ' ' . $billet['ch_postnom'] . ' ' . $billet['ch_prenom'])) ?></span></div>

        <hr class="sep">
        <div class="valeur-total">
            <?= number_format((float)$billet['prix'], 2, ',', ' ') ?>
            <?= securiser($billet['devise']) ?>
        </div>
        <div class="row" style="justify-content:center; margin-top:4px;">
            <span class="statut <?= match($billet['statut_paiement']) {
                'Paye' => 'paye', 'Partiel' => 'partiel', default => 'non-paye',
            } ?>">
                <?= securiser($billet['statut_paiement']) ?>
            </span>
        </div>

        <hr class="sep">
        <div class="row"><span class="label">Émis le</span><span class="value"><?= date('d/m/Y H:i', strtotime($billet['date_enregistrement'])) ?></span></div>

        <!-- QR CODE -->
        <div class="qr-zone">
            <img src="<?= securiser($qrCodeUrl) ?>" alt="QR Code">
            <p>Scanner pour vérifier le billet</p>
        </div>

        <div class="footer-ticket"><?= securiser($piedFacture) ?></div>
        <p class="footer-ticket" style="font-size:9px; margin-top:6px;">
            Présentez ce billet à l'embarquement.<br>
            Conservez-le jusqu'à destination.
        </p>
    </div>

    <!-- ACTIONS -->
    <div class="barre-actions no-print">
        <button type="button" class="btn btn-print" onclick="window.print()">Imprimer</button>
        <button type="button" class="btn btn-share" onclick="partager()">Partager</button>
    </div>

    <?php if ($telVoyageur !== ''): ?>
        <div class="barre-actions no-print">
            <a href="https://wa.me/<?= securiser($telVoyageur) ?>?text=<?= urlencode($textePartage) ?>"
               target="_blank"
               class="btn btn-wa">
                Envoyer au voyageur (WhatsApp)
            </a>
        </div>
    <?php endif; ?>

    <div class="barre-actions no-print">
        <a href="billets.php" class="btn btn-back">← Retour aux billets</a>
    </div>

<script src="notifications.js"></script>
<script src="icons.js"></script>
<script>
    function partager() {
        const texte = <?= json_encode($textePartage, JSON_UNESCAPED_UNICODE) ?>;

        if (navigator.share) {
            navigator.share({ title: 'Billet de voyage', text: texte }).catch(() => {});
        } else {
            navigator.clipboard.writeText(texte).then(() => {
                window.appNotify('Billet copié dans le presse-papier.', 'success');
            }).catch(() => {
                window.appNotify('Impossible de partager automatiquement.', 'error');
            });
        }
    }
</script>

</body>
</html>
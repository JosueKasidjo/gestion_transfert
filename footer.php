<?php
/**
 * footer.php — Ferme le contenu principal + scripts communs
 */
?>
        <footer class="text-center text-xs text-slate-400 mt-8 pb-5">
            &copy; <?= date('Y') ?>
            <?= securiser($nomAgenceH ?? NOM_APPLICATION) ?> -
            <?= securiser(NOM_APPLICATION) ?>
        </footer>
    </div>
</main>

<script src="notifications.js"></script>
<script>if (window.lucide) lucide.createIcons();</script>
<script>
    function ouvrirMenu() {
        document.getElementById('sidebar').classList.add('menu-ouvert');
        document.getElementById('overlayMenu').classList.add('visible');
        document.body.classList.add('menu-bloque');
    }
    function fermerMenu() {
        document.getElementById('sidebar').classList.remove('menu-ouvert');
        document.getElementById('overlayMenu').classList.remove('visible');
        document.body.classList.remove('menu-bloque');
    }
    document.querySelectorAll('#sidebar a').forEach(function(lien) {
        lien.addEventListener('click', fermerMenu);
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') fermerMenu();
    });
</script>

</body>
</html>
<hr class="border-secondary-subtle my-4">

<div class="row align-items-center pb-3">
    <div class="col-md-6 text-center text-md-start m-3">
        <p class="text-light opacity-50 mb-0 small">
            &copy;
            <?php echo date('Y'); ?> Auralis. Todos os direitos reservados.
        </p>
        <p class="text-light opacity-50 mb-0 small mt-1">
            <?php
            // Procura o arquivo gerado pelo GitHub na raiz do projeto
            $arquivoVersao = __DIR__ . '/../version.txt';
            if (file_exists($arquivoVersao)) {
                $versaoAtual = trim(file_get_contents($arquivoVersao));
            } else {
                $versaoAtual = 'Modo de Desenvolvimento (Local)';
            }
            echo htmlspecialchars($versaoAtual);
            ?>
        </p>
        <p class="text-light opacity-50 mb-0 small mt-1">
            Desenvolvido por <strong class="text-primary">Gustavo Veronezi</strong>.
        </p>

    </div>
    <div class="col-md-6 text-center text-md-end">
        <a href="#" class="text-light opacity-50 text-decoration-none me-3 small custom-link">Termos de Uso</a>
        <a href="#" class="text-light opacity-50 text-decoration-none small custom-link" style="margin-right: 10px;">Política de Privacidade</a>
    </div>
</div>
</footer>

<script src="/geral/js/bootstrap.bundle.min.js"></script>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('mostrar');
                }
            });
        }, {
            threshold: 0.1
        });

        const elementosOcultos = document.querySelectorAll('.card-animado');
        elementosOcultos.forEach((el) => observer.observe(el));
    });
</script>
</body>

</html>
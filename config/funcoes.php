<?php
// config/funcoes.php
// =========================================================================
// SISTEMA DE PERMISSÕES E PLANOS — AURALIS
// Nível de acesso: 0 = Deslogado | 1 = titular | 2 = admin | 3 = supremo
// Plano:          free | pro | vip
// =========================================================================

// ── Nível de acesso (admin/supremo) ──────────────────────────────────────
function obterNivelAcesso(): int {
    if (!isset($_SESSION['usuario_id'])) return 0;
    $nivel = strtolower($_SESSION['nivel_acesso'] ?? 'titular');
    if ($nivel === 'supremo') return 3;
    if ($nivel === 'admin')   return 2;
    return 1;
}

function exigirAcessoMinimo(int $nivelNecessario): void {
    $nivelAtual = obterNivelAcesso();
    if ($nivelAtual < $nivelNecessario) {
        $nivelAtual === 0
            ? header("Location: /usuario/login.php?erro=autenticacao")
            : header("Location: /dashboard.php?erro=sem_permissao");
        exit;
    }
}

// ── Planos ────────────────────────────────────────────────────────────────
function obterPlanoAtual(): string {
    return $_SESSION['plano'] ?? 'free';
}

/**
 * Hierarquia dos planos: free=0, pro=1, vip=2.
 * Se o plano atual for menor que o exigido, redireciona para /planos.php.
 */
function exigirPlano(string $planoMinimo): void {
    $hierarquia = ['free' => 0, 'pro' => 1, 'vip' => 2];
    $atual      = $hierarquia[obterPlanoAtual()] ?? 0;
    $necessario = $hierarquia[$planoMinimo]       ?? 0;

    if ($atual < $necessario) {
        header("Location: /planos.php?upgrade=" . urlencode($planoMinimo));
        exit;
    }
}

/**
 * Retorna true se o usuário tem acesso a determinada feature.
 * Use para exibir/ocultar blocos sem redirecionar.
 */
function temPlano(string $planoMinimo): bool {
    $hierarquia = ['free' => 0, 'pro' => 1, 'vip' => 2];
    $atual      = $hierarquia[obterPlanoAtual()] ?? 0;
    $necessario = $hierarquia[$planoMinimo]       ?? 0;
    return $atual >= $necessario;
}

/**
 * Retorna o HTML do badge do plano para usar na navbar ou onde quiser.
 * Ex: badgePlano() → '<span class="auralis-badge-pro">PRO</span>'
 */
function badgePlano(string $plano = ''): string {
    $plano = $plano ?: obterPlanoAtual();
    return match($plano) {
        'pro' => '<span style="display:inline-flex;align-items:center;background:#7c3aed22;color:#a78bfa;border:1px solid #7c3aed55;border-radius:999px;padding:1px 8px;font-size:0.65rem;font-weight:700;letter-spacing:0.05em;">PRO</span>',
        'vip' => '<span style="display:inline-flex;align-items:center;background:#d4af3722;color:#d4af37;border:1px solid #d4af3766;border-radius:999px;padding:1px 8px;font-size:0.65rem;font-weight:700;letter-spacing:0.05em;">⭐ VIP</span>',
        default => '', // free não exibe badge
    };
}

/**
 * Limits por plano — fonte central da verdade.
 * Use assim: $limites = limitesDoPLano(); if ($total > $limites['transacoes']) ...
 */
function limitesDoPlano(): array {
    return match(obterPlanoAtual()) {
        'pro'  => [
            'transacoes_mes'  => PHP_INT_MAX,
            'carteiras'       => PHP_INT_MAX,
            'categorias'      => PHP_INT_MAX,
            'parcelas_max'    => 48,
            'historico_meses' => 12,
            'exportacao'      => true,
        ],
        'vip'  => [
            'transacoes_mes'  => PHP_INT_MAX,
            'carteiras'       => PHP_INT_MAX,
            'categorias'      => PHP_INT_MAX,
            'parcelas_max'    => 48,
            'historico_meses' => PHP_INT_MAX,
            'exportacao'      => true,
            'compartilhamento' => true,
            'cartao_credito'  => true,
        ],
        default => [ // free
            'transacoes_mes'  => 35,
            'carteiras'       => 1,
            'categorias'      => 10,
            'parcelas_max'    => 3,
            'historico_meses' => 1,
            'exportacao'      => false,
        ],
    };
}

/**
 * Verifica e expira assinaturas vencidas.
 * Chame no dashboard.php — roda uma vez por sessão (usa flag de sessão).
 */
function verificarExpiracao(PDO $pdo): void {
    if (!isset($_SESSION['usuario_id'])) return;
    if ($_SESSION['plano'] === 'free') return;

    // Roda no máximo 1x por sessão para não bater no banco a cada página
    if (isset($_SESSION['expiracao_verificada'])) return;
    $_SESSION['expiracao_verificada'] = true;

    try {
        $stmt = $pdo->prepare("
            SELECT Status, DataExpiracao FROM Assinatura
            WHERE FKUsuario = :uid
              AND Plano     = :plano
            ORDER BY DataExpiracao DESC
            LIMIT 1
        ");
        $stmt->execute([':uid' => $_SESSION['usuario_id'], ':plano' => $_SESSION['plano']]);
        $assinatura = $stmt->fetch();

        if (!$assinatura) {
            // Assinatura sumiu — rebaixa para free
            _rebaixarParaFree($pdo, $_SESSION['usuario_id']);
            return;
        }

        if ($assinatura['Status'] !== 'ativa') {
            _rebaixarParaFree($pdo, $_SESSION['usuario_id']);
            return;
        }

        if (new DateTime($assinatura['DataExpiracao']) < new DateTime()) {
            // Expirou — marca como expirada e rebaixa
            $pdo->prepare("
                UPDATE Assinatura SET Status = 'expirada'
                WHERE FKUsuario = :uid AND Status = 'ativa'
            ")->execute([':uid' => $_SESSION['usuario_id']]);
            _rebaixarParaFree($pdo, $_SESSION['usuario_id']);
        }
    } catch (PDOException $e) {
        // Silencia para não quebrar a UX; o cron irá corrigir depois
    }
}

function _rebaixarParaFree(PDO $pdo, string $uid): void {
    try {
        $pdo->prepare("UPDATE Usuario SET Plano = 'free' WHERE IDUsuario = :uid")
            ->execute([':uid' => $uid]);
        $_SESSION['plano'] = 'free';
        unset($_SESSION['expiracao_verificada']); // próxima página re-verifica
    } catch (PDOException $e) {}
}

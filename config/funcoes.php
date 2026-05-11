<?php
// config/funcoes.php
// Compatível com PHP 7.4+
// Usa function_exists() em tudo — seguro contra include duplo.

if (!function_exists('obterNivelAcesso')) {
    function obterNivelAcesso() {
        if (!isset($_SESSION['usuario_id'])) return 0;
        $nivel = strtolower($_SESSION['nivel_acesso'] ?? 'titular');
        if ($nivel === 'supremo') return 3;
        if ($nivel === 'admin')   return 2;
        return 1;
    }
}

if (!function_exists('exigirAcessoMinimo')) {
    function exigirAcessoMinimo($nivelNecessario) {
        $nivelAtual = obterNivelAcesso();
        if ($nivelAtual < $nivelNecessario) {
            if ($nivelAtual === 0) {
                header("Location: /usuario/login.php?erro=autenticacao");
            } else {
                header("Location: /dashboard.php?erro=sem_permissao");
            }
            exit;
        }
    }
}

if (!function_exists('obterPlanoAtual')) {
    function obterPlanoAtual() {
        return $_SESSION['plano'] ?? 'free';
    }
}

if (!function_exists('exigirPlano')) {
    function exigirPlano($planoMinimo) {
        $hierarquia = ['free' => 0, 'pro' => 1, 'vip' => 2];
        $atual      = $hierarquia[obterPlanoAtual()] ?? 0;
        $necessario = $hierarquia[$planoMinimo]       ?? 0;
        if ($atual < $necessario) {
            header("Location: /planos.php?upgrade=" . urlencode($planoMinimo));
            exit;
        }
    }
}

if (!function_exists('temPlano')) {
    function temPlano($planoMinimo) {
        $hierarquia = ['free' => 0, 'pro' => 1, 'vip' => 2];
        $atual      = $hierarquia[obterPlanoAtual()] ?? 0;
        $necessario = $hierarquia[$planoMinimo]       ?? 0;
        return $atual >= $necessario;
    }
}

if (!function_exists('badgePlano')) {
    function badgePlano($plano = '') {
        if (!$plano) $plano = obterPlanoAtual();
        if ($plano === 'pro') {
            return '<span style="display:inline-flex;align-items:center;background:#7c3aed22;color:#a78bfa;border:1px solid #7c3aed55;border-radius:999px;padding:1px 8px;font-size:0.65rem;font-weight:700;letter-spacing:0.05em;">PRO</span>';
        }
        if ($plano === 'vip') {
            return '<span style="display:inline-flex;align-items:center;background:#d4af3722;color:#d4af37;border:1px solid #d4af3766;border-radius:999px;padding:1px 8px;font-size:0.65rem;font-weight:700;letter-spacing:0.05em;">&#11088; VIP</span>';
        }
        return ''; // free não exibe badge
    }
}

if (!function_exists('limitesDoPlano')) {
    function limitesDoPlano() {
        $plano = obterPlanoAtual();
        if ($plano === 'pro') {
            return [
                'transacoes_mes'  => PHP_INT_MAX,
                'carteiras'       => PHP_INT_MAX,
                'categorias'      => PHP_INT_MAX,
                'parcelas_max'    => 48,
                'historico_meses' => 12,
                'exportacao'      => true,
            ];
        }
        if ($plano === 'vip') {
            return [
                'transacoes_mes'   => PHP_INT_MAX,
                'carteiras'        => PHP_INT_MAX,
                'categorias'       => PHP_INT_MAX,
                'parcelas_max'     => 48,
                'historico_meses'  => PHP_INT_MAX,
                'exportacao'       => true,
                'compartilhamento' => true,
                'cartao_credito'   => true,
            ];
        }
        // free
        return [
            'transacoes_mes'  => 35,
            'carteiras'       => 1,
            'categorias'      => 10,
            'parcelas_max'    => 3,
            'historico_meses' => 1,
            'exportacao'      => false,
        ];
    }
}

if (!function_exists('verificarExpiracao')) {
    function verificarExpiracao($pdo) {
        if (!isset($_SESSION['usuario_id'])) return;
        if (($_SESSION['plano'] ?? 'free') === 'free') return;
        if (isset($_SESSION['expiracao_verificada'])) return;

        $_SESSION['expiracao_verificada'] = true;

        try {
            $stmt = $pdo->prepare("
                SELECT Status, DataExpiracao FROM Assinatura
                WHERE FKUsuario = :uid AND Plano = :plano
                ORDER BY DataExpiracao DESC LIMIT 1
            ");
            $stmt->execute([
                ':uid'   => $_SESSION['usuario_id'],
                ':plano' => $_SESSION['plano'],
            ]);
            $assinatura = $stmt->fetch();

            if (!$assinatura || $assinatura['Status'] !== 'ativa') {
                _rebaixarParaFree($pdo, $_SESSION['usuario_id']);
                return;
            }

            if (strtotime($assinatura['DataExpiracao']) < time()) {
                $pdo->prepare("UPDATE Assinatura SET Status = 'expirada' WHERE FKUsuario = :uid AND Status = 'ativa'")
                    ->execute([':uid' => $_SESSION['usuario_id']]);
                _rebaixarParaFree($pdo, $_SESSION['usuario_id']);
            }
        } catch (PDOException $e) {
            // Silencia para não quebrar a UX
        }
    }
}

if (!function_exists('_rebaixarParaFree')) {
    function _rebaixarParaFree($pdo, $uid) {
        try {
            $pdo->prepare("UPDATE Usuario SET Plano = 'free' WHERE IDUsuario = :uid")
                ->execute([':uid' => $uid]);
            $_SESSION['plano'] = 'free';
            unset($_SESSION['expiracao_verificada']);
        } catch (PDOException $e) {}
    }
}
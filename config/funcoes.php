<?php
// config/funcoes.php — Compatível com PHP 7.4+

if (!defined('AURALIS_COOKIE_SECRET')) {
    define('AURALIS_COOKIE_SECRET', 'Auralis2026_UltraSecretKey');
}

if (!function_exists('gerarUuid')) {
    function gerarUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}

if (!function_exists('obterNivelAcesso')) {
    function obterNivelAcesso()
    {
        if (!isset($_SESSION['usuario_id'])) return 0;
        $nivel = strtolower($_SESSION['nivel_acesso'] ?? 'titular');
        if ($nivel === 'supremo') return 3;
        if ($nivel === 'admin')   return 2;
        return 1;
    }
}

if (!function_exists('exigirAcessoMinimo')) {
    function exigirAcessoMinimo($nivelNecessario)
    {
        $nivelAtual = obterNivelAcesso();
        if ($nivelAtual < $nivelNecessario) {
            $nivelAtual === 0
                ? header("Location: /usuario/login.php?erro=autenticacao")
                : header("Location: /dashboard.php?erro=sem_permissao");
            exit;
        }
    }
}

if (!function_exists('obterPlanoAtual')) {
    function obterPlanoAtual()
    {
        return $_SESSION['plano'] ?? 'free';
    }
}

if (!function_exists('exigirPlano')) {
    function exigirPlano($planoMinimo)
    {
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
    function temPlano($planoMinimo)
    {
        $hierarquia = ['free' => 0, 'pro' => 1, 'vip' => 2];
        $atual      = $hierarquia[obterPlanoAtual()] ?? 0;
        $necessario = $hierarquia[$planoMinimo]       ?? 0;
        return $atual >= $necessario;
    }
}

if (!function_exists('badgePlano')) {
    function badgePlano($plano = '')
    {
        if (!$plano) $plano = obterPlanoAtual();
        if ($plano === 'pro') {
            return '<span style="display:inline-flex;align-items:center;background:#7c3aed22;color:#a78bfa;border:1px solid #7c3aed55;border-radius:999px;padding:1px 8px;font-size:0.65rem;font-weight:700;letter-spacing:0.05em;">PRO</span>';
        }
        if ($plano === 'vip') {
            return '<span style="display:inline-flex;align-items:center;background:#d4af3722;color:#d4af37;border:1px solid #d4af3766;border-radius:999px;padding:1px 8px;font-size:0.65rem;font-weight:700;letter-spacing:0.05em;">&#11088; VIP</span>';
        }
        return '';
    }
}

if (!function_exists('limitesDoPlano')) {
    function limitesDoPlano($planoExplicito = null)
    {
        global $pdo;
        static $cache = [];

        $plano = $planoExplicito ?? obterPlanoAtual();

        if (isset($cache[$plano])) return $cache[$plano];

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM config_limites_plano WHERE plano = ? LIMIT 1");
                $stmt->execute([$plano]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $cache[$plano] = [
                        'transacoes_mes' => $row['transacoes_mes'] == -1 ? PHP_INT_MAX : (int)$row['transacoes_mes'],
                        'carteiras'      => $row['carteiras']      == -1 ? PHP_INT_MAX : (int)$row['carteiras'],
                        'cartoes'        => isset($row['cartoes']) ? ($row['cartoes'] == -1 ? PHP_INT_MAX : (int)$row['cartoes']) : PHP_INT_MAX,
                        'categorias'     => $row['categorias']     == -1 ? PHP_INT_MAX : (int)$row['categorias'],
                        'parcelas_max'   => (int)$row['parcelas_max'],
                        'horas_teste'    => (int)$row['horas_teste'],
                    ];
                    return $cache[$plano];
                }
            } catch (PDOException $e) {
            }
        }

        // Fallback hardcoded se a tabela ainda não existir
        $defaults = [
            'pro'  => ['transacoes_mes' => PHP_INT_MAX, 'carteiras' => 3,           'cartoes' => 3,           'categorias' => PHP_INT_MAX, 'parcelas_max' => 48, 'horas_teste' => 0],
            'vip'  => ['transacoes_mes' => PHP_INT_MAX, 'carteiras' => PHP_INT_MAX, 'cartoes' => PHP_INT_MAX, 'categorias' => PHP_INT_MAX, 'parcelas_max' => 48, 'horas_teste' => 0],
            'free' => ['transacoes_mes' => 35,          'carteiras' => 1,           'cartoes' => 1,           'categorias' => 10,          'parcelas_max' => 3,  'horas_teste' => 50],
        ];
        $cache[$plano] = $defaults[$plano] ?? $defaults['free'];
        return $cache[$plano];
    }
}

if (!function_exists('verificarExpiracao')) {
    function verificarExpiracao($pdo)
    {
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
        }
    }
}

if (!function_exists('_rebaixarParaFree')) {
    function _rebaixarParaFree($pdo, $uid)
    {
        try {
            $pdo->prepare("UPDATE Usuario SET Plano = 'free' WHERE IDUsuario = :uid")
                ->execute([':uid' => $uid]);
            $_SESSION['plano'] = 'free';
            unset($_SESSION['expiracao_verificada']);
        } catch (PDOException $e) {
        }
    }
}

// ── Trial de 50 horas para novos usuários ─────────────────────────────────

if (!function_exists('obterPlanoEfetivo')) {
    function obterPlanoEfetivo()
    {
        global $pdo;
        $uid = $_SESSION['usuario_id'] ?? '';
        if (!$uid || !$pdo) return obterPlanoAtual();

        $plano = obterPlanoAtual();
        if ($plano !== 'free') return $plano;

        $horasTrial = limitesDoPlano('free')['horas_teste'] ?? 50;
        if ($horasTrial <= 0) return 'free';

        try {
            $stmt = $pdo->prepare("SELECT MomentoCriacao FROM Usuario WHERE IDUsuario = ?");
            $stmt->execute([$uid]);
            $user = $stmt->fetch();

            if ($user && !empty($user['MomentoCriacao'])) {
                $criacao = new DateTime($user['MomentoCriacao']);
                $diff    = (new DateTime())->diff($criacao);
                $horas   = ($diff->days * 24) + $diff->h;

                if ($horas < $horasTrial) return 'vip_trial';
            }
        } catch (PDOException $e) {
        }

        return 'free';
    }
}

if (!function_exists('obterHorasRestantesTeste')) {
    function obterHorasRestantesTeste()
    {
        global $pdo;
        $uid = $_SESSION['usuario_id'] ?? '';
        if (!$uid || !$pdo || obterPlanoAtual() !== 'free') return 0;

        $horasTrial = limitesDoPlano('free')['horas_teste'] ?? 50;
        if ($horasTrial <= 0) return 0;

        try {
            $stmt = $pdo->prepare("SELECT MomentoCriacao FROM Usuario WHERE IDUsuario = ?");
            $stmt->execute([$uid]);
            $user = $stmt->fetch();

            if ($user && !empty($user['MomentoCriacao'])) {
                $criacao = new DateTime($user['MomentoCriacao']);
                $diff    = (new DateTime())->diff($criacao);
                $horas   = ($diff->days * 24) + $diff->h;
                if ($horas < $horasTrial) return $horasTrial - $horas;
            }
        } catch (PDOException $e) {
        }

        return 0;
    }
}

// ── Configuração dinâmica de recursos por plano ──────────────────────────

if (!function_exists('recursoDisponivelParaPlano')) {
    function recursoDisponivelParaPlano($slug, $plano = null)
    {
        global $pdo;
        static $cache = [];

        $plano = $plano ?? obterPlanoAtual();
        $key   = "{$slug}:{$plano}";

        if (isset($cache[$key])) return $cache[$key];

        $colMap = ['free' => 'disponivel_free', 'pro' => 'disponivel_pro', 'vip' => 'disponivel_vip'];
        $col    = $colMap[$plano] ?? null;

        if ($col && $pdo) {
            try {
                $stmt = $pdo->prepare("SELECT `{$col}` FROM config_recursos WHERE slug = ? LIMIT 1");
                $stmt->execute([$slug]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row !== false) {
                    $cache[$key] = (bool)$row[$col];
                    return $cache[$key];
                }
            } catch (PDOException $e) {
            }
        }

        // Fallback se tabela não existir
        $restrito = ['agenda', 'analises', 'comprovantes'];
        $cache[$key] = in_array($slug, $restrito) ? ($plano !== 'free') : true;
        return $cache[$key];
    }
}

if (!function_exists('nivelMinimoRecurso')) {
    // Retorna o menor plano com acesso — usado para exibição de badges no nav
    function nivelMinimoRecurso($slug)
    {
        global $pdo;
        static $cache = [];

        if (isset($cache[$slug])) return $cache[$slug];

        if ($pdo) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT disponivel_free, disponivel_pro, disponivel_vip
                     FROM config_recursos WHERE slug = ? LIMIT 1"
                );
                $stmt->execute([$slug]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    if ($row['disponivel_free'])     $nivel = 'free';
                    elseif ($row['disponivel_pro'])  $nivel = 'pro';
                    elseif ($row['disponivel_vip'])  $nivel = 'vip';
                    else                             $nivel = 'vip';
                    $cache[$slug] = $nivel;
                    return $nivel;
                }
            } catch (PDOException $e) {
            }
        }

        $defaults = ['agenda' => 'pro', 'analises' => 'pro', 'comprovantes' => 'pro'];
        $cache[$slug] = $defaults[$slug] ?? 'pro';
        return $cache[$slug];
    }
}

if (!function_exists('recursosParaExibicao')) {
    function recursosParaExibicao()
    {
        global $pdo;
        if (!$pdo) return [];
        try {
            return $pdo->query(
                "SELECT slug, label, disponivel_free, disponivel_pro, disponivel_vip
                 FROM config_recursos WHERE mostrar_nos_planos = 1 ORDER BY ordem ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}

// ── Constante central de credenciais MP ──────────────────────────────────
// Usada pelo webhook e pelo sucesso_pagamento
if (!defined('MP_ACCESS_TOKEN')) {
    define('MP_ACCESS_TOKEN', 'APP_USR-3265675594930667-051414-05a766f55f35ec3d0b8749d7f65c0206-3401629357');
}

// Mapa de preapproval_plan_id → plano Auralis (fonte única da verdade)
if (!defined('MP_PLANOS')) {
    define('MP_PLANOS', [
        '9c7869b02a884962a185a44dee6c16f8' => ['plano' => 'pro', 'ciclo' => 'mensal', 'dias' => 32],
        '98c6343b478e4efcad77ab56fe6f5948' => ['plano' => 'pro', 'ciclo' => 'anual',  'dias' => 370],
        '55856961da8d49d09b4ccded59a56810' => ['plano' => 'vip', 'ciclo' => 'mensal', 'dias' => 32],
        '3ed445df740c439884e8ebc71ddbdb69' => ['plano' => 'vip', 'ciclo' => 'anual',  'dias' => 370],
    ]);
}

// ── Helper MP: consulta API com cURL (saída do servidor → sem bloqueio) ──
if (!function_exists('mpConsultarApi')) {
    function mpConsultarApi($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . MP_ACCESS_TOKEN],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$httpCode, json_decode($resp, true)];
    }
}

// ── Sistema de Temas ──────────────────────────────────────────────────────

if (!function_exists('temasDisponiveis')) {
    function temasDisponiveis()
    {
        return [
            'dark'    => ['nome' => 'Dark',    'bs_mode' => 'dark',  'conquista' => null],
            'white'   => ['nome' => 'White',   'bs_mode' => 'light', 'conquista' => null],
            'sistema' => ['nome' => 'Sistema', 'bs_mode' => 'auto',  'conquista' => null],
        ];
    }
}

if (!function_exists('temaDoUsuario')) {
    function temaDoUsuario()
    {
        $temas = temasDisponiveis();
        $tema  = $_SESSION['tema'] ?? 'dark';
        return isset($temas[$tema]) ? $tema : 'dark';
    }
}

if (!function_exists('usuarioPossuiConquista')) {
    function usuarioPossuiConquista($slug)
    {
        global $pdo;
        $uid = $_SESSION['usuario_id'] ?? null;
        if (!$uid || !$pdo) return false;
        try {
            $stmt = $pdo->prepare("
                SELECT 1 FROM usuario_conquista uc
                JOIN conquista c ON c.IDConquista = uc.FKConquista
                WHERE uc.FKUsuario = :uid AND c.Slug = :slug LIMIT 1
            ");
            $stmt->execute([':uid' => $uid, ':slug' => $slug]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('concederConquista')) {
    function concederConquista($slug)
    {
        global $pdo;
        $uid = $_SESSION['usuario_id'] ?? null;
        if (!$uid || !$pdo) return false;
        try {
            $stmt = $pdo->prepare("SELECT IDConquista FROM conquista WHERE Slug = :slug AND Ativo = 1 LIMIT 1");
            $stmt->execute([':slug' => $slug]);
            $cid = $stmt->fetchColumn();
            if (!$cid) return false;

            $check = $pdo->prepare("SELECT 1 FROM usuario_conquista WHERE FKUsuario = :uid AND FKConquista = :cid LIMIT 1");
            $check->execute([':uid' => $uid, ':cid' => $cid]);
            if ($check->fetchColumn()) return false;

            $pdo->prepare("
                INSERT INTO usuario_conquista (IDUsuarioConquista, FKUsuario, FKConquista)
                VALUES (:id, :uid, :cid)
            ")->execute([':id' => gerarUuid(), ':uid' => $uid, ':cid' => $cid]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}

// ── Helper MP: ativa plano no banco a partir de dados da assinatura ───────
if (!function_exists('mpAtivarPlano')) {
    function mpAtivarPlano($pdo, $emailComprador, $planId, $gwId, $valorPago = 0)
    {
        $planos = MP_PLANOS;
        if (!isset($planos[$planId])) return false;

        $config = $planos[$planId];

        $stmtU = $pdo->prepare("SELECT IDUsuario FROM Usuario WHERE Email = :email LIMIT 1");
        $stmtU->execute([':email' => strtolower(trim($emailComprador))]);
        $usuario = $stmtU->fetch();
        if (!$usuario) return false;

        $uid           = $usuario['IDUsuario'];
        $dataInicio    = (new DateTime())->format('Y-m-d H:i:s');
        $dataExpiracao = (new DateTime())->modify("+{$config['dias']} days")->format('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE Assinatura SET Status = 'cancelada' WHERE FKUsuario = :uid AND Plano = :plano AND Status = 'ativa'")
                ->execute([':uid' => $uid, ':plano' => $config['plano']]);

            $novoId = function_exists('gerarUuid') ? gerarUuid() : bin2hex(random_bytes(16));
            $pdo->prepare("
                INSERT INTO Assinatura
                    (IDAssinatura, FKUsuario, Plano, Status, Ciclo, ValorPago,
                     DataInicio, DataExpiracao, IDAssinaturaGW, EmailGateway, FontePagamento)
                VALUES (:id, :uid, :plano, 'ativa', :ciclo, :valor, :inicio, :exp, :gwid, :email, 'mercadopago')
            ")->execute([
                ':id'    => $novoId,
                ':uid'   => $uid,
                ':plano' => $config['plano'],
                ':ciclo' => $config['ciclo'],
                ':valor' => $valorPago,
                ':inicio' => $dataInicio,
                ':exp'   => $dataExpiracao,
                ':gwid'  => $gwId,
                ':email' => strtolower(trim($emailComprador)),
            ]);

            $pdo->prepare("UPDATE Usuario SET Plano = :plano WHERE IDUsuario = :uid")
                ->execute([':plano' => $config['plano'], ':uid' => $uid]);

            $pdo->commit();
            return $config['plano'];
        } catch (PDOException $e) {
            $pdo->rollBack();
            return false;
        }
    }
}

// Função universal para selos de recursos bloqueados/em teste
function badgePremium($nivelExigido = 'pro', $emTeste = false)
{
    // PRO = Roxo (#7c3aed) | VIP = Dourado (#D4AF37)
    $cor = (strtolower($nivelExigido) === 'vip') ? '#D4AF37' : '#7c3aed';
    $texto = strtoupper($nivelExigido);

    if ($emTeste) {
        $texto .= ' (Teste)';
    }

    return "<span class=\"badge ms-1\" style=\"background: {$cor}22; color: {$cor}; border: 1px solid {$cor}66; font-size: 0.55rem; padding: 2px 5px; vertical-align: middle;\"><i class=\"bi bi-star-fill\"></i> {$texto}</span>";
}

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
    function exigirAcessoMinimo(int $nivelNecessario): void
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
    function exigirPlano(string $planoMinimo): void
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
    function temPlano(string $planoMinimo): bool
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

if (!function_exists('exibirLimite')) {
    function exibirLimite(int $valor): string {
        return $valor === PHP_INT_MAX ? 'ilimitado' : (string)$valor;
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
                        'parcelas_max'   => $row['parcelas_max'] == -1 ? PHP_INT_MAX : (int)$row['parcelas_max'],
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
    function verificarExpiracao(PDO $pdo): void
    {
        if (!isset($_SESSION['usuario_id'])) return;
        if (($_SESSION['plano'] ?? 'free') === 'free') return;
        if (isset($_SESSION['expiracao_verificada'])) return;

        // Admin/supremo: plano atribuído manualmente, sem assinatura obrigatória
        if (in_array(strtolower($_SESSION['nivel_acesso'] ?? ''), ['admin', 'supremo'])) return;

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
                $planoAnterior = strtoupper($_SESSION['plano']);
                criarNotificacaoSistema(
                    $pdo, $_SESSION['usuario_id'],
                    "Seu plano {$planoAnterior} foi encerrado",
                    "Seu acesso ao plano {$planoAnterior} foi encerrado e você voltou para o plano gratuito.\n\nVocê ainda pode usar os recursos do plano Free. Para continuar com todos os recursos, considere renovar sua assinatura.",
                    3
                );
                _rebaixarParaFree($pdo, $_SESSION['usuario_id']);
                return;
            }

            $expTimestamp = strtotime($assinatura['DataExpiracao']);

            if ($expTimestamp < time()) {
                $pdo->prepare("UPDATE Assinatura SET Status = 'expirada' WHERE FKUsuario = :uid AND Status = 'ativa'")
                    ->execute([':uid' => $_SESSION['usuario_id']]);
                $planoAnterior = strtoupper($_SESSION['plano']);
                criarNotificacaoSistema(
                    $pdo, $_SESSION['usuario_id'],
                    "Seu plano {$planoAnterior} expirou",
                    "Seu plano {$planoAnterior} expirou e você foi automaticamente movido para o plano gratuito.\n\nRenove sua assinatura para recuperar o acesso a todos os recursos!",
                    3
                );
                _rebaixarParaFree($pdo, $_SESSION['usuario_id']);
            } else {
                $diasRestantes = (int) ceil(($expTimestamp - time()) / 86400);
                if ($diasRestantes <= 3) {
                    $planoNome = strtoupper($_SESSION['plano']);
                    $dataFmt   = date('d/m/Y', $expTimestamp);
                    $plural    = $diasRestantes > 1 ? 's' : '';
                    criarNotificacaoSistema(
                        $pdo, $_SESSION['usuario_id'],
                        "Seu plano {$planoNome} expira em {$diasRestantes} dia{$plural}!",
                        "Atenção: seu plano {$planoNome} expira em {$diasRestantes} dia{$plural} ({$dataFmt}).\n\nRenove agora para não perder o acesso aos seus recursos.",
                        1
                    );
                }
            }
        } catch (PDOException $e) {
        }
    }
}

if (!function_exists('_rebaixarParaFree')) {
    function _rebaixarParaFree(PDO $pdo, string $uid): void
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
    function recursoDisponivelParaPlano(string $slug, ?string $plano = null): bool
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
    function nivelMinimoRecurso(string $slug): string
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
    function mpConsultarApi(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . MP_ACCESS_TOKEN],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$httpCode, json_decode($resp, true)];
    }
}

// ── Sistema de Temas ──────────────────────────────────────────────────────

if (!function_exists('temasDisponiveis')) {
    function temasDisponiveis()
    {
        return [
            'dark'    => ['nome' => 'Dark',    'bs_mode' => 'dark',  'conquista' => null, 'plano_minimo' => null,  'secao' => 'padrao'],
            'white'   => ['nome' => 'White',   'bs_mode' => 'light', 'conquista' => null, 'plano_minimo' => null,  'secao' => 'padrao'],
            'sistema' => ['nome' => 'Sistema', 'bs_mode' => 'auto',  'conquista' => null, 'plano_minimo' => null,  'secao' => 'padrao'],
            'oceano'  => ['nome' => 'Oceano',  'bs_mode' => 'dark',  'conquista' => null, 'plano_minimo' => 'pro', 'secao' => 'adicional'],
            'ambar'   => ['nome' => 'Âmbar',   'bs_mode' => 'dark',  'conquista' => null, 'plano_minimo' => 'pro', 'secao' => 'adicional'],
            'aurora'  => ['nome' => 'Aurora',  'bs_mode' => 'dark',  'conquista' => null, 'plano_minimo' => 'pro', 'secao' => 'adicional'],
            'cosmos'  => ['nome' => 'Cosmos',  'bs_mode' => 'dark',  'conquista' => null, 'plano_minimo' => 'pro', 'secao' => 'adicional'],
            'fortune' => ['nome' => 'Fortune', 'bs_mode' => 'dark',  'conquista' => null, 'plano_minimo' => 'vip', 'secao' => 'adicional'],
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
    function usuarioPossuiConquista(string $slug): bool
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

if (!function_exists('concederConquistaParaUsuario')) {
    // Versão direta — funciona sem sessão (webhooks, ativação de conta, etc.)
    function concederConquistaParaUsuario(PDO $pdo, string $uid, string $slug): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT IDConquista FROM conquista WHERE Slug = :slug AND Ativo = 1 LIMIT 1");
            $stmt->execute([':slug' => $slug]);
            $cid = $stmt->fetchColumn();
            if (!$cid) return false;

            $check = $pdo->prepare("SELECT 1 FROM usuario_conquista WHERE FKUsuario = :uid AND FKConquista = :cid LIMIT 1");
            $check->execute([':uid' => $uid, ':cid' => $cid]);
            if ($check->fetchColumn()) return false;

            $pdo->prepare("
                INSERT INTO usuario_conquista (IDUsuarioConquista, FKUsuario, FKConquista, DataConquista)
                VALUES (:id, :uid, :cid, NOW())
            ")->execute([':id' => gerarUuid(), ':uid' => $uid, ':cid' => $cid]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('concederConquista')) {
    function concederConquista(string $slug): bool
    {
        global $pdo;
        $uid = $_SESSION['usuario_id'] ?? null;
        if (!$uid || !$pdo) return false;
        return concederConquistaParaUsuario($pdo, $uid, $slug);
    }
}

if (!function_exists('verificarConquistasAutomaticas')) {
    /**
     * Verifica e concede conquistas automáticas de um tipo para um usuário.
     * Os thresholds e slugs são definidos em config/conquistas_regras.php.
     * Para adicionar novos tipos de gatilho, adicione um 'case' aqui e
     * a entrada correspondente em conquistas_regras.php.
     */
    function verificarConquistasAutomaticas(PDO $pdo, string $uid, string $tipo): void
    {
        try {
            $regras = require __DIR__ . '/conquistas_regras.php';
            if (!isset($regras[$tipo])) return;

            $thresholds = $regras[$tipo]['thresholds'] ?? [];
            if (empty($thresholds)) return;

            // Calcula o valor atual do usuário para o tipo solicitado
            switch ($tipo) {
                case 'registros':
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) FROM Usuario
                        WHERE FKIndicadoPor = :uid AND StatusConta != 'pendente'
                    ");
                    $stmt->execute([':uid' => $uid]);
                    $total = (int)$stmt->fetchColumn();
                    break;

                // case 'dias_membro':
                //     $stmt = $pdo->prepare("
                //         SELECT DATEDIFF(NOW(), DataCadastro) FROM Usuario WHERE IDUsuario = :uid
                //     ");
                //     $stmt->execute([':uid' => $uid]);
                //     $total = (int)$stmt->fetchColumn();
                //     break;

                default:
                    return;
            }

            foreach ($thresholds as $minimo => $slug) {
                if ($total >= $minimo) {
                    concederConquistaParaUsuario($pdo, $uid, $slug);
                }
            }
        } catch (Throwable $e) {
            // silencia — conquistas nunca devem quebrar o fluxo principal
        }
    }
}

if (!function_exists('verificarConquistasRegistros')) {
    function verificarConquistasRegistros(PDO $pdo, string $uid): void
    {
        verificarConquistasAutomaticas($pdo, $uid, 'registros');
    }
}

// ── Helper MP: cancela assinatura no Mercado Pago via API ────────────────
if (!function_exists('mpCancelarNoMP')) {
    function mpCancelarNoMP(string $gwId): void
    {
        if (empty($gwId)) return;
        $ch = curl_init("https://api.mercadopago.com/preapproval/{$gwId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => json_encode(['status' => 'cancelled']),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . MP_ACCESS_TOKEN,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
    }
}

// ── Helper MP: ativa plano no banco a partir de dados da assinatura ───────
if (!function_exists('mpAtivarPlano')) {
    function mpAtivarPlano(PDO $pdo, string $emailComprador, string $planId, string $gwId, float $valorPago = 0): string|false
    {
        $planos = MP_PLANOS;
        if (!isset($planos[$planId])) return false;

        $config = $planos[$planId];

        // 1. Localiza o usuário pelo e-mail
        $stmtU = $pdo->prepare("SELECT IDUsuario FROM Usuario WHERE Email = :email LIMIT 1");
        $stmtU->execute([':email' => strtolower(trim($emailComprador))]);
        $usuario = $stmtU->fetch();
        if (!$usuario) return false;
        $uid = $usuario['IDUsuario'];

        // 2. Idempotência: se este gwId já está ativo, retorna o plano sem duplicar
        $stmtIdem = $pdo->prepare("SELECT Plano FROM Assinatura WHERE IDAssinaturaGW = :gw AND Status = 'ativa' LIMIT 1");
        $stmtIdem->execute([':gw' => $gwId]);
        $jaAtiva = $stmtIdem->fetchColumn();
        if ($jaAtiva) return $jaAtiva;

        // 3. Busca assinaturas ativas anteriores para crédito de dias e cancelamento no MP
        $stmtAntigas = $pdo->prepare("
            SELECT IDAssinaturaGW, DataExpiracao
            FROM Assinatura
            WHERE FKUsuario = :uid AND Status = 'ativa'
        ");
        $stmtAntigas->execute([':uid' => $uid]);
        $assinaturasAntigas = $stmtAntigas->fetchAll();

        // 4. Calcula dias restantes para aplicar como crédito na nova assinatura
        //    Ex: tinha 15 dias de PRO → VIP dura 32 + 15 = 47 dias. Paga só o VIP.
        $agora       = new DateTime();
        $diasCredito = 0;
        foreach ($assinaturasAntigas as $antiga) {
            if (!empty($antiga['DataExpiracao'])) {
                $exp  = new DateTime($antiga['DataExpiracao']);
                $diff = $agora->diff($exp);
                if ($diff->invert === 0 && $diff->days > 0) {
                    $diasCredito += $diff->days;
                }
            }
        }

        $dataInicio    = $agora->format('Y-m-d H:i:s');
        $totalDias     = $config['dias'] + $diasCredito;
        $dataExpiracao = (new DateTime())->modify("+{$totalDias} days")->format('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            // 5. Cancela TODAS as assinaturas ativas no banco (qualquer plano)
            $pdo->prepare("UPDATE Assinatura SET Status = 'cancelada' WHERE FKUsuario = :uid AND Status = 'ativa'")
                ->execute([':uid' => $uid]);

            // 6. Insere a nova assinatura
            $novoId = function_exists('gerarUuid') ? gerarUuid() : bin2hex(random_bytes(16));
            $pdo->prepare("
                INSERT INTO Assinatura
                    (IDAssinatura, FKUsuario, Plano, Status, Ciclo, ValorPago,
                     DataInicio, DataExpiracao, IDAssinaturaGW, EmailGateway, FontePagamento)
                VALUES (:id, :uid, :plano, 'ativa', :ciclo, :valor, :inicio, :exp, :gwid, :email, 'mercadopago')
            ")->execute([
                ':id'     => $novoId,
                ':uid'    => $uid,
                ':plano'  => $config['plano'],
                ':ciclo'  => $config['ciclo'],
                ':valor'  => $valorPago,
                ':inicio' => $dataInicio,
                ':exp'    => $dataExpiracao,
                ':gwid'   => $gwId,
                ':email'  => strtolower(trim($emailComprador)),
            ]);

            // 7. Atualiza o plano no registro do usuário
            $pdo->prepare("UPDATE Usuario SET Plano = :plano WHERE IDUsuario = :uid")
                ->execute([':plano' => $config['plano'], ':uid' => $uid]);

            $pdo->commit();

            concederConquistaParaUsuario($pdo, $uid, $config['plano'] === 'vip' ? 'plano_vip' : 'plano_pro');

            // 8. Cancela as assinaturas antigas no Mercado Pago (fora da transação BD
            //    para que uma falha de rede não desfaça a ativação já confirmada)
            foreach ($assinaturasAntigas as $antiga) {
                if (!empty($antiga['IDAssinaturaGW'])) {
                    mpCancelarNoMP($antiga['IDAssinaturaGW']);
                }
            }

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

// ── Notificações automáticas do sistema ──────────────────────────────────────

if (!function_exists('criarNotificacaoSistema')) {
    function criarNotificacaoSistema(PDO $pdo, string $uid, string $titulo, string $conteudo, int $dedupeJanelaDias = 0): void
    {
        try {
            if ($dedupeJanelaDias > 0) {
                $chk = $pdo->prepare("
                    SELECT 1 FROM Notificacao n
                    JOIN NotificacaoDestinatario nd ON nd.FKNotificacao = n.IDNotificacao
                    WHERE nd.FKUsuario = :uid AND n.Titulo = :titulo
                      AND n.DataCriacao >= DATE_SUB(NOW(), INTERVAL :dias DAY)
                    LIMIT 1
                ");
                $chk->execute([':uid' => $uid, ':titulo' => $titulo, ':dias' => $dedupeJanelaDias]);
                if ($chk->fetchColumn()) return;
            }

            $nid = gerarUuid();
            $pdo->prepare("
                INSERT INTO Notificacao (IDNotificacao, Titulo, Conteudo, DestinatarioTipo, TipoInteracao)
                VALUES (:id, :titulo, :conteudo, 'selecionado', 'nenhuma')
            ")->execute([':id' => $nid, ':titulo' => $titulo, ':conteudo' => $conteudo]);

            $pdo->prepare("
                INSERT IGNORE INTO NotificacaoDestinatario (FKNotificacao, FKUsuario)
                VALUES (:nid, :uid)
            ")->execute([':nid' => $nid, ':uid' => $uid]);
        } catch (Throwable $e) { /* silent — notificações não devem quebrar o fluxo principal */ }
    }
}

if (!function_exists('verificarAvisosAutomaticos')) {
    function verificarAvisosAutomaticos(PDO $pdo): void
    {
        if (!isset($_SESSION['usuario_id'])) return;
        if (isset($_SESSION['avisos_auto_verificados'])) return;
        $_SESSION['avisos_auto_verificados'] = true;

        if (strtolower($_SESSION['plano'] ?? 'free') !== 'free') return;

        $horasRestantes = function_exists('obterHorasRestantesTeste') ? obterHorasRestantesTeste() : 0;
        if ($horasRestantes > 0 && $horasRestantes <= 24) {
            $plural = $horasRestantes > 1 ? 's' : '';
            criarNotificacaoSistema(
                $pdo,
                $_SESSION['usuario_id'],
                'Seu período de teste termina em breve!',
                "Atenção: seu período gratuito de teste termina em menos de {$horasRestantes} hora{$plural}.\n\nApós esse período, você continuará com o plano gratuito com recursos limitados. Considere fazer o upgrade para não perder o acesso a todos os recursos!",
                1
            );
        }
    }
}

// ── Indicações & Revendedores ─────────────────────────────────────────────────

/**
 * Gera um código de indicação único no formato AUR-XXXXXX.
 * Usa apenas caracteres sem ambiguidade visual (sem O/0, I/1).
 */
function gerarCodigoIndicacao(PDO $pdo): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = 'AUR-';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $existe = $pdo->prepare("SELECT 1 FROM Usuario WHERE CodigoIndicacao = :c");
        $existe->execute([':c' => $code]);
    } while ($existe->fetchColumn());
    return $code;
}

/**
 * Chamado após mpAtivarPlano() confirmar uma conversão.
 * - Se o indicador é revendedor → cria registro de comissão monetária.
 * - Se o indicador é usuário comum → conta conversões e aplica recompensas configuradas.
 */
function processarIndicacaoConversao(PDO $pdo, string $emailComprador, float $valorPago, string $plano): void
{
    try {
        // Encontra o comprador e quem o indicou
        $stmt = $pdo->prepare("SELECT IDUsuario, FKIndicadoPor FROM Usuario WHERE LOWER(Email) = LOWER(:e) LIMIT 1");
        $stmt->execute([':e' => $emailComprador]);
        $comprador = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$comprador || empty($comprador['FKIndicadoPor'])) return;

        $compradorId  = $comprador['IDUsuario'];
        $indicadorId  = $comprador['FKIndicadoPor'];

        // ── Caminho A: indicador é revendedor ────────────────────────────────
        $stmtRev = $pdo->prepare("SELECT * FROM Revendedor WHERE FKUsuario = :uid AND Ativo = 1 LIMIT 1");
        $stmtRev->execute([':uid' => $indicadorId]);
        $revendedor = $stmtRev->fetch(PDO::FETCH_ASSOC);

        if ($revendedor) {
            // Idempotência: só uma comissão por comprador
            $jaExiste = $pdo->prepare("SELECT 1 FROM ComissaoRevendedor WHERE FKUsuarioComprador = :uid LIMIT 1");
            $jaExiste->execute([':uid' => $compradorId]);
            if ($jaExiste->fetchColumn()) return;

            concederConquistaParaUsuario($pdo, $indicadorId, 'indicou_amigo');
            verificarConquistasRegistros($pdo, $indicadorId);
            $perc  = (float)$revendedor['ComissaoPercentual'];
            $valor = round($valorPago * $perc / 100, 2);
            $pdo->prepare(
                "INSERT INTO ComissaoRevendedor
                     (IDComissao, FKRevendedor, FKUsuarioComprador, ValorVenda, PercentualAplicado, ValorComissao, Plano)
                 VALUES (:id, :rev, :comp, :venda, :perc, :com, :plano)"
            )->execute([
                ':id'    => gerarUuid(),
                ':rev'   => $revendedor['IDRevendedor'],
                ':comp'  => $compradorId,
                ':venda' => $valorPago,
                ':perc'  => $perc,
                ':com'   => $valor,
                ':plano' => $plano,
            ]);
            return;
        }

        // ── Caminho B: usuário comum — verifica recompensas por indicação ────
        concederConquistaParaUsuario($pdo, $indicadorId, 'indicou_amigo');
        verificarConquistasRegistros($pdo, $indicadorId);
        // Conta quantos usuários o indicador trouxe que agora têm plano ativo
        $stmtCnt = $pdo->prepare(
            "SELECT COUNT(DISTINCT u.IDUsuario)
             FROM Usuario u
             JOIN Assinatura a ON a.FKUsuario = u.IDUsuario AND a.Status IN ('ativa','trial')
             WHERE u.FKIndicadoPor = :uid"
        );
        $stmtCnt->execute([':uid' => $indicadorId]);
        $totalConversoes = (int)$stmtCnt->fetchColumn();

        // Busca regra de recompensa que o indicador ainda não recebeu
        $stmtCfg = $pdo->prepare(
            "SELECT c.* FROM indicacao_recompensa_config c
             WHERE c.Ativo = 1 AND c.MinIndicacoes <= :total
               AND NOT EXISTS (
                   SELECT 1 FROM indicacao_recompensa_concedida irc
                   WHERE irc.FKUsuario = :uid AND irc.FKConfig = c.IDConfig
               )
             ORDER BY c.MinIndicacoes DESC LIMIT 1"
        );
        $stmtCfg->execute([':total' => $totalConversoes, ':uid' => $indicadorId]);
        $recompensa = $stmtCfg->fetch(PDO::FETCH_ASSOC);
        if (!$recompensa) return;

        // Aplica recompensa: cria assinatura de bônus para o indicador
        $dataInicio = date('Y-m-d H:i:s');
        // Se já tem assinatura ativa, acumula dias por cima
        $stmtAtual = $pdo->prepare("SELECT DataExpiracao FROM Assinatura WHERE FKUsuario = :uid AND Status = 'ativa' LIMIT 1");
        $stmtAtual->execute([':uid' => $indicadorId]);
        $expAtual = $stmtAtual->fetchColumn();
        $base     = ($expAtual && $expAtual > $dataInicio) ? $expAtual : $dataInicio;
        $dataExp  = date('Y-m-d H:i:s', strtotime($base . " +" . (int)$recompensa['DuracaoDias'] . " days"));

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE Assinatura SET Status = 'cancelada' WHERE FKUsuario = :uid AND Status = 'ativa'")
            ->execute([':uid' => $indicadorId]);
        $pdo->prepare(
            "INSERT INTO Assinatura
                 (IDAssinatura, FKUsuario, Plano, Status, Ciclo, ValorPago, DataInicio, DataExpiracao, FontePagamento)
             VALUES (:id, :uid, :plano, 'ativa', 'manual', 0, :ini, :exp, 'indicacao')"
        )->execute([
            ':id'    => gerarUuid(),
            ':uid'   => $indicadorId,
            ':plano' => $recompensa['PlanoRecompensa'],
            ':ini'   => $dataInicio,
            ':exp'   => $dataExp,
        ]);
        $pdo->prepare("UPDATE Usuario SET Plano = :p WHERE IDUsuario = :uid")
            ->execute([':p' => $recompensa['PlanoRecompensa'], ':uid' => $indicadorId]);
        $pdo->prepare(
            "INSERT INTO indicacao_recompensa_concedida (IDConcessao, FKUsuario, FKConfig, TotalNaEpoca)
             VALUES (:id, :uid, :cfg, :total)"
        )->execute([
            ':id'    => gerarUuid(),
            ':uid'   => $indicadorId,
            ':cfg'   => $recompensa['IDConfig'],
            ':total' => $totalConversoes,
        ]);
        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

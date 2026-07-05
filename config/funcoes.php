<?php
// config/funcoes.php — Compatível com PHP 7.4+

if (!defined('AURALIS_COOKIE_SECRET')) {
    define('AURALIS_COOKIE_SECRET', 'Auralis2026_UltraSecretKey');
}

// ── Anti-força-bruta / throttling genérico (login, redefinição de senha, etc) ──
// Requer migrations/add_tentativa_seguranca.sql. Se a tabela ainda não existir,
// falha "aberta" (não bloqueia ninguém) — mesmo padrão defensivo usado em
// outras checagens de schema do projeto.
if (!function_exists('registrarTentativaSeguranca')) {
    function registrarTentativaSeguranca(PDO $pdo, string $contexto, string $chave): void
    {
        try {
            $pdo->prepare("INSERT INTO TentativaSeguranca (IDTentativa, Contexto, Chave) VALUES (:id, :ctx, :chave)")
                ->execute([':id' => gerarUuid(), ':ctx' => $contexto, ':chave' => mb_strtolower($chave)]);
        } catch (PDOException $e) {
        }
    }
}

if (!function_exists('contarTentativasSeguranca')) {
    function contarTentativasSeguranca(PDO $pdo, string $contexto, string $chave, int $minutos): int
    {
        try {
            $cutoff = date('Y-m-d H:i:s', time() - $minutos * 60);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM TentativaSeguranca WHERE Contexto = :ctx AND Chave = :chave AND Momento > :cutoff");
            $stmt->execute([':ctx' => $contexto, ':chave' => mb_strtolower($chave), ':cutoff' => $cutoff]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
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
                        'carteiras_compartilhadas_membros' => isset($row['carteiras_compartilhadas_membros'])
                            ? ($row['carteiras_compartilhadas_membros'] == -1 ? PHP_INT_MAX : (int)$row['carteiras_compartilhadas_membros'])
                            : ($plano === 'free' ? 0 : ($plano === 'pro' ? 2 : PHP_INT_MAX)),
                    ];
                    return $cache[$plano];
                }
            } catch (PDOException $e) {
            }
        }

        // Fallback hardcoded se a tabela ainda não existir
        $defaults = [
            'pro'  => ['transacoes_mes' => PHP_INT_MAX, 'carteiras' => 3,           'cartoes' => 3,           'categorias' => PHP_INT_MAX, 'parcelas_max' => 48, 'horas_teste' => 0,  'carteiras_compartilhadas_membros' => 2],
            'vip'  => ['transacoes_mes' => PHP_INT_MAX, 'carteiras' => PHP_INT_MAX, 'cartoes' => PHP_INT_MAX, 'categorias' => PHP_INT_MAX, 'parcelas_max' => 48, 'horas_teste' => 0,  'carteiras_compartilhadas_membros' => 8],
            'free' => ['transacoes_mes' => 35,          'carteiras' => 1,           'cartoes' => 1,           'categorias' => 10,          'parcelas_max' => 3,  'horas_teste' => 50, 'carteiras_compartilhadas_membros' => 0],
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
        if (function_exists('ehAdmin') && ehAdmin()) return;

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

// ── Credenciais MP — arquivo separado, não versionado (config/mercadopago_keys.php) ──
// Usada pelo webhook e pelo sucesso_pagamento. Carrega de forma defensiva: se o
// arquivo ainda não existir nesse ambiente (ex: acabou de dar deploy e falta criar
// o arquivo no servidor), o site continua no ar — só os recursos de pagamento MP
// ficam inativos até o arquivo ser criado, em vez do site inteiro cair.
$_mpKeysFile = __DIR__ . '/mercadopago_keys.php';
if (file_exists($_mpKeysFile)) {
    require_once $_mpKeysFile;
}
if (!defined('MP_ACCESS_TOKEN')) {
    define('MP_ACCESS_TOKEN', '');
}
if (!defined('MP_WEBHOOK_SECRET')) {
    define('MP_WEBHOOK_SECRET', '');
}

// Mapa de preapproval_plan_id → plano Auralis (fonte única da verdade)
// 'valor' é usado pelo fluxo de Pix avulso (gerar_pagamento_pix.php), que não tem
// preapproval — precisa saber quanto cobrar a partir só do ID do plano.
if (!defined('MP_PLANOS')) {
    define('MP_PLANOS', [
        '9c7869b02a884962a185a44dee6c16f8' => ['plano' => 'pro', 'ciclo' => 'mensal', 'dias' => 32,  'valor' => 19.90],
        '98c6343b478e4efcad77ab56fe6f5948' => ['plano' => 'pro', 'ciclo' => 'anual',  'dias' => 370, 'valor' => 179.90],
        '55856961da8d49d09b4ccded59a56810' => ['plano' => 'vip', 'ciclo' => 'mensal', 'dias' => 32,  'valor' => 29.90],
        '3ed445df740c439884e8ebc71ddbdb69' => ['plano' => 'vip', 'ciclo' => 'anual',  'dias' => 370, 'valor' => 239.90],
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

// ── Helper MP: verifica a assinatura (header x-signature) de uma notificação ──
// Segue o esquema oficial do Mercado Pago: manifest = "id:{id};request-id:{reqId};ts:{ts};",
// HMAC-SHA256 com a chave secreta do webhook, comparado em tempo constante.
// Retorna true se: (a) a assinatura bate, OU (b) MP_WEBHOOK_SECRET ainda não foi
// configurada (pula a checagem — modo compatível, não quebra notificações antigas/IPN
// que não têm esse header). Retorna false só quando a chave está configurada E a
// assinatura enviada não bate — nesse caso é pra rejeitar a notificação.
if (!function_exists('mpVerificarAssinatura')) {
    function mpVerificarAssinatura(?string $xSignature, ?string $xRequestId, string $dataId): bool
    {
        if (empty(MP_WEBHOOK_SECRET)) {
            return true; // Chave ainda não configurada — não bloqueia (ver mercadopago_keys.php)
        }
        if (empty($xSignature)) {
            return true; // Notificação legada (IPN) não tem esse header — sem como verificar
        }

        $ts = null;
        $v1 = null;
        foreach (explode(',', $xSignature) as $parte) {
            [$chave, $valor] = array_pad(explode('=', trim($parte), 2), 2, null);
            if ($chave === 'ts') $ts = $valor;
            if ($chave === 'v1') $v1 = $valor;
        }
        if (!$ts || !$v1) return false;

        $manifest = "id:" . mb_strtolower($dataId) . ";request-id:" . ($xRequestId ?? '') . ";ts:" . $ts . ";";
        $hashCalculado = hash_hmac('sha256', $manifest, MP_WEBHOOK_SECRET);

        return hash_equals($hashCalculado, $v1);
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

            try {
                $stmtInfo = $pdo->prepare("SELECT Nome FROM conquista WHERE IDConquista = :cid LIMIT 1");
                $stmtInfo->execute([':cid' => $cid]);
                $nomeConquista = (string)$stmtInfo->fetchColumn();

                $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM usuario_conquista WHERE FKConquista = :cid");
                $stmtTotal->execute([':cid' => $cid]);
                $totalUsuarios = (int)$stmtTotal->fetchColumn();

                if ($nomeConquista && function_exists('criarNotificacaoSistema')) {
                    $plural = $totalUsuarios === 1 ? 'usuário possui' : 'usuários possuem';
                    criarNotificacaoSistema(
                        $pdo,
                        $uid,
                        'Nova conquista desbloqueada!',
                        "Parabéns! Você recebeu a conquista \"{$nomeConquista}\". {$totalUsuarios} {$plural} esta conquista.",
                        0
                    );
                }
            } catch (Throwable $e) { /* silencia — notificação nunca deve bloquear a concessão */ }

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
                        SELECT
                            (SELECT COUNT(*) FROM Registro WHERE FKUsuario = :uid AND GrupoParcela IS NULL)
                            +
                            (SELECT COUNT(DISTINCT GrupoParcela) FROM Registro WHERE FKUsuario = :uid AND GrupoParcela IS NOT NULL)
                    ");
                    $stmt->execute([':uid' => $uid]);
                    $total = (int)$stmt->fetchColumn();
                    break;

                case 'dias_membro':
                    $stmt = $pdo->prepare("
                        SELECT DATEDIFF(NOW(), MomentoCriacao) FROM Usuario WHERE IDUsuario = :uid
                    ");
                    $stmt->execute([':uid' => $uid]);
                    $total = (int)$stmt->fetchColumn();
                    break;

                case 'comprovantes':
                    $stmt = $pdo->prepare("
                        SELECT COUNT(DISTINCT FKRegistro) FROM Comprovante WHERE FKUsuario = :uid
                    ");
                    $stmt->execute([':uid' => $uid]);
                    $total = (int)$stmt->fetchColumn();
                    break;

                case 'categorias':
                    $stmt = $pdo->prepare("
                        SELECT COUNT(DISTINCT FKCategoria) FROM Registro
                        WHERE FKUsuario = :uid AND FKCategoria IS NOT NULL
                          AND TipoRegistro IN ('receita','despesa')
                    ");
                    $stmt->execute([':uid' => $uid]);
                    $total = (int)$stmt->fetchColumn();
                    break;

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

if (!function_exists('verificarConquistasDiasMembro')) {
    function verificarConquistasDiasMembro(PDO $pdo, string $uid): void
    {
        verificarConquistasAutomaticas($pdo, $uid, 'dias_membro');
    }
}

if (!function_exists('verificarConquistasComprovantes')) {
    function verificarConquistasComprovantes(PDO $pdo, string $uid): void
    {
        verificarConquistasAutomaticas($pdo, $uid, 'comprovantes');
    }
}

if (!function_exists('verificarConquistasCategorias')) {
    function verificarConquistasCategorias(PDO $pdo, string $uid): void
    {
        verificarConquistasAutomaticas($pdo, $uid, 'categorias');
    }
}

// Conquista "carteira_comp" (evento único, não é ladder de threshold — mesmo padrão de
// 'metabatida'/'sempendencias'): participar de uma carteira compartilhada com pelo menos
// 2 pessoas. Dono (Carteira.FKUsuarioDono) + 1 convidado com StatusConvite=1 já basta, já
// que o dono não tem linha própria em MembroCarteira. Concede pra quem chamar (dono ou
// convidado) se ele se enquadrar em qualquer um dos dois papéis em qualquer carteira.
if (!function_exists('verificarConquistaCarteiraCompartilhada')) {
    function verificarConquistaCarteiraCompartilhada(PDO $pdo, string $uid): void
    {
        try {
            $stmtDono = $pdo->prepare("
                SELECT 1 FROM Carteira c
                JOIN MembroCarteira mc ON mc.FKCarteira = c.IDCarteira AND mc.StatusConvite = 1
                WHERE c.FKUsuarioDono = :uid AND c.Compartilhada = 1
                LIMIT 1
            ");
            $stmtDono->execute([':uid' => $uid]);
            if ($stmtDono->fetchColumn()) {
                concederConquistaParaUsuario($pdo, $uid, 'carteira_comp');
                return;
            }

            $stmtConv = $pdo->prepare("SELECT 1 FROM MembroCarteira WHERE FKUsuario = :uid AND StatusConvite = 1 LIMIT 1");
            $stmtConv->execute([':uid' => $uid]);
            if ($stmtConv->fetchColumn()) {
                concederConquistaParaUsuario($pdo, $uid, 'carteira_comp');
            }
        } catch (PDOException $e) {
        }
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
    function mpAtivarPlano(PDO $pdo, string $emailComprador, string $planId, string $gwId, float $valorPago = 0, ?string $paymentRef = null): string|false
    {
        // Garante que a tabela PagamentoProcessado já existe antes de usá-la abaixo — numa
        // instalação nova, sem isso o INSERT falharia por "tabela não existe" e seria
        // confundido com "referência duplicada", bloqueando a própria primeira ativação.
        if ($paymentRef !== null) {
            garantirEstruturaComissaoRevendedor($pdo);
        }

        $planos = MP_PLANOS;
        if (!isset($planos[$planId])) return false;

        $config = $planos[$planId];

        // 1. Localiza o usuário pelo e-mail
        $stmtU = $pdo->prepare("SELECT IDUsuario FROM Usuario WHERE Email = :email LIMIT 1");
        $stmtU->execute([':email' => strtolower(trim($emailComprador))]);
        $usuario = $stmtU->fetch();
        if (!$usuario) return false;
        $uid = $usuario['IDUsuario'];

        // 1.5. Dedup por evento de pagamento específico (ex: uma renovação distinta da outra).
        // Se já processamos essa referência antes (o webhook do MP pode reenviar o mesmo evento),
        // não faz nada de novo — só devolve o plano atual.
        if ($paymentRef !== null) {
            try {
                $pdo->prepare("INSERT INTO PagamentoProcessado (Referencia) VALUES (:ref)")
                    ->execute([':ref' => $paymentRef]);
            } catch (PDOException $e) {
                $stmtAtualRef = $pdo->prepare("SELECT Plano FROM Assinatura WHERE FKUsuario = :uid AND Status = 'ativa' LIMIT 1");
                $stmtAtualRef->execute([':uid' => $uid]);
                return $stmtAtualRef->fetchColumn() ?: false;
            }
        }

        // 2. Já existe assinatura ativa com esse MESMO gwId? Isso é uma RENOVAÇÃO (cartão
        // recorrente cobrando de novo a mesma assinatura) — estende a expiração em vez de
        // travar sem fazer nada. Só estende se vier uma referência de pagamento nova (acima),
        // pra um simples reload da página de sucesso não esticar a data de novo.
        $stmtIdem = $pdo->prepare("SELECT IDAssinatura, Plano, DataExpiracao FROM Assinatura WHERE IDAssinaturaGW = :gw AND Status = 'ativa' LIMIT 1");
        $stmtIdem->execute([':gw' => $gwId]);
        $ativaExistente = $stmtIdem->fetch(PDO::FETCH_ASSOC);

        if ($ativaExistente) {
            if ($paymentRef !== null) {
                $agora    = new DateTime();
                $expAtual = new DateTime($ativaExistente['DataExpiracao']);
                $base     = ($expAtual > $agora) ? $expAtual : $agora;
                $novaExp  = (clone $base)->modify("+{$config['dias']} days")->format('Y-m-d H:i:s');
                $pdo->prepare("UPDATE Assinatura SET DataExpiracao = :exp, ValorPago = :valor WHERE IDAssinatura = :id")
                    ->execute([':exp' => $novaExp, ':valor' => $valorPago, ':id' => $ativaExistente['IDAssinatura']]);
            }
            return $ativaExistente['Plano'];
        }

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

// Constrói a URL do avatar DiceBear a partir do array de configuração salvo no DB
function getAvatarUrl(array $cfg): string
{
    $base = 'https://api.dicebear.com/9.x/avataaars/svg';
    $p    = [];
    $p[] = 'skinColor[]='    . urlencode($cfg['skinColor'] ?? 'd08b5b');
    if (!empty($cfg['hair'])) $p[] = 'top[]=' . urlencode($cfg['hair']);
    $p[] = 'hairColor[]='    . urlencode($cfg['hairColor']    ?? '2c1b18');
    $p[] = 'eyes[]='         . urlencode($cfg['eyes']         ?? 'default');
    $p[] = 'eyebrows[]='     . urlencode($cfg['eyebrows']     ?? 'default');
    $p[] = 'mouth[]='        . urlencode($cfg['mouth']        ?? 'smile');
    $p[] = 'clothing[]='     . urlencode($cfg['clothing']     ?? 'hoodie');
    $p[] = 'clothesColor[]=' . urlencode($cfg['clothingColor'] ?? '3c4f5c');
    if (!empty($cfg['accessories'])) {
        $p[] = 'accessories[]='         . urlencode($cfg['accessories']);
        $p[] = 'accessoriesProbability=100';
    } else {
        $p[] = 'accessoriesProbability=0';
    }
    if (!empty($cfg['facialHair'])) {
        $p[] = 'facialHair[]='          . urlencode($cfg['facialHair']);
        $p[] = 'facialHairColor[]='     . urlencode($cfg['facialHairColor'] ?? '2c1b18');
        $p[] = 'facialHairProbability=100';
    } else {
        $p[] = 'facialHairProbability=0';
    }
    if (($cfg['backgroundColor'] ?? '') !== 'transparent') {
        $p[] = 'backgroundColor[]=' . urlencode($cfg['backgroundColor'] ?? 'transparent');
    }
    return $base . '?' . implode('&', $p);
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
 * Decide (e trava pra sempre) qual % de comissão vale pra um cliente específico de um
 * revendedor. Na comissão "fixa", é sempre o mesmo %. Na "em 2 partes", os N primeiros
 * clientes distintos (por ordem de 1ª compra) ficam na faixa 1, o resto na faixa 2 — e
 * essa decisão não muda depois, mesmo que o revendedor ganhe mais clientes com o tempo.
 */
function determinarPercentualRevendedor(PDO $pdo, array $revendedor, string $compradorId): float
{
    $stmt = $pdo->prepare("SELECT PercentualAplicado FROM RevendedorCliente WHERE FKRevendedor = :rev AND FKUsuarioComprador = :comp LIMIT 1");
    $stmt->execute([':rev' => $revendedor['IDRevendedor'], ':comp' => $compradorId]);
    $percExistente = $stmt->fetchColumn();
    if ($percExistente !== false) return (float)$percExistente;

    // Primeira compra desse cliente com esse revendedor — decide a faixa agora e trava
    if (($revendedor['TipoComissao'] ?? 'fixa') === 'duas_partes') {
        $stmtOrdem = $pdo->prepare("SELECT COUNT(*) FROM RevendedorCliente WHERE FKRevendedor = :rev");
        $stmtOrdem->execute([':rev' => $revendedor['IDRevendedor']]);
        $numeroOrdem = (int)$stmtOrdem->fetchColumn() + 1;

        $limite = (int)($revendedor['LimiteClientesParte1'] ?? 0);
        $perc = ($numeroOrdem <= $limite)
            ? (float)$revendedor['ComissaoPercentual']
            : (float)$revendedor['ComissaoPercentualParte2'];
    } else {
        $numeroOrdem = 0;
        $perc = (float)$revendedor['ComissaoPercentual'];
    }

    try {
        $pdo->prepare(
            "INSERT INTO RevendedorCliente (IDRevendedorCliente, FKRevendedor, FKUsuarioComprador, NumeroOrdem, PercentualAplicado)
             VALUES (:id, :rev, :comp, :ordem, :perc)"
        )->execute([
            ':id'    => gerarUuid(),
            ':rev'   => $revendedor['IDRevendedor'],
            ':comp'  => $compradorId,
            ':ordem' => $numeroOrdem,
            ':perc'  => $perc,
        ]);
    } catch (PDOException $e) {
        // Corrida rara (2 pagamentos simultâneos na 1ª compra) — usa o que já ficou gravado
        $stmt2 = $pdo->prepare("SELECT PercentualAplicado FROM RevendedorCliente WHERE FKRevendedor = :rev AND FKUsuarioComprador = :comp LIMIT 1");
        $stmt2->execute([':rev' => $revendedor['IDRevendedor'], ':comp' => $compradorId]);
        $percRace = $stmt2->fetchColumn();
        if ($percRace !== false) return (float)$percRace;
    }

    return $perc;
}

/**
 * Chamado após mpAtivarPlano() confirmar uma conversão (1ª compra OU renovação).
 * - Se o indicador é revendedor → cria registro de comissão monetária a cada pagamento
 *   (recorrente — não só a 1ª venda), na faixa de % já travada pra esse cliente.
 * - Se o indicador é usuário comum → conta conversões e aplica recompensas configuradas.
 *
 * $paymentRef identifica o pagamento específico (payment_id do MP) — evita gerar duas
 * comissões pro mesmo evento se o webhook reenviar a notificação.
 */
function processarIndicacaoConversao(PDO $pdo, string $emailComprador, float $valorPago, string $plano, ?string $paymentRef = null): void
{
    try {
        garantirEstruturaComissaoRevendedor($pdo);

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
            // Idempotência por pagamento: cada compra/renovação gera sua própria comissão,
            // mas o MESMO pagamento nunca gera duas. Chamadas antigas sem paymentRef caem no
            // comportamento anterior (só 1 comissão por comprador, pra não quebrar nada).
            if ($paymentRef === null) {
                $jaExiste = $pdo->prepare("SELECT 1 FROM ComissaoRevendedor WHERE FKUsuarioComprador = :uid LIMIT 1");
                $jaExiste->execute([':uid' => $compradorId]);
            } else {
                $jaExiste = $pdo->prepare("SELECT 1 FROM ComissaoRevendedor WHERE ReferenciaPagamento = :ref LIMIT 1");
                $jaExiste->execute([':ref' => $paymentRef]);
            }
            if ($jaExiste->fetchColumn()) return;

            concederConquistaParaUsuario($pdo, $indicadorId, 'indicou_amigo');

            $perc  = determinarPercentualRevendedor($pdo, $revendedor, $compradorId);
            $valor = round($valorPago * $perc / 100, 2);
            $pdo->prepare(
                "INSERT INTO ComissaoRevendedor
                     (IDComissao, FKRevendedor, FKUsuarioComprador, ValorVenda, PercentualAplicado, ValorComissao, Plano, ReferenciaPagamento)
                 VALUES (:id, :rev, :comp, :venda, :perc, :com, :plano, :ref)"
            )->execute([
                ':id'    => gerarUuid(),
                ':rev'   => $revendedor['IDRevendedor'],
                ':comp'  => $compradorId,
                ':venda' => $valorPago,
                ':perc'  => $perc,
                ':com'   => $valor,
                ':plano' => $plano,
                ':ref'   => $paymentRef,
            ]);
            criarNotificacaoSistema(
                $pdo,
                $indicadorId,
                'Nova comissão de indicação!',
                "Você ganhou R$ " . number_format($valor, 2, ',', '.') . " de comissão — alguém que você indicou pagou o plano " . strtoupper($plano) . ". Confira no seu painel de revendedor."
            );
            return;
        }

        // ── Caminho B: usuário comum — verifica recompensas por indicação ────
        concederConquistaParaUsuario($pdo, $indicadorId, 'indicou_amigo');
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

        criarNotificacaoSistema(
            $pdo,
            $indicadorId,
            'Você ganhou uma recompensa por indicação!',
            "Suas indicações renderam " . (int)$recompensa['DuracaoDias'] . " dias do plano " . strtoupper($recompensa['PlanoRecompensa']) . " — já está ativo na sua conta!"
        );

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

// Garante a tabela de metas/orçamento por categoria (auto-migração, sem precisar de SSH)
function garantirTabelaMetaCategoria(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS MetaCategoria (
              IDMeta       CHAR(36) NOT NULL PRIMARY KEY,
              FKUsuario    CHAR(36) NOT NULL,
              FKCategoria  CHAR(36) NOT NULL,
              ValorMeta    DECIMAL(12,2) NOT NULL,
              CriadoEm     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              AtualizadoEm DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_meta_usuario_categoria (FKUsuario, FKCategoria),
              KEY idx_meta_usuario (FKUsuario)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
    }
}

// Garante a tabela de configuração financeira (percentual de poupança mensal do usuário)
function garantirTabelaConfiguracaoFinanceira(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ConfiguracaoFinanceira (
              FKUsuario          CHAR(36) NOT NULL PRIMARY KEY,
              PercentualPoupanca DECIMAL(5,2) NOT NULL DEFAULT 0,
              AtualizadoEm       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
    }
}

// Garante o schema de comissão em 2 partes + comissão recorrente (auto-migração, sem SSH)
function garantirEstruturaComissaoRevendedor(PDO $pdo): void
{
    // Colunas novas em Revendedor: tipo de comissão + parâmetros da faixa 2
    try {
        $chk = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Revendedor'
              AND COLUMN_NAME IN ('TipoComissao', 'ComissaoPercentualParte2', 'LimiteClientesParte1')
        ")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('TipoComissao', $chk, true)) {
            $pdo->exec("ALTER TABLE Revendedor ADD COLUMN TipoComissao ENUM('fixa','duas_partes') NOT NULL DEFAULT 'fixa' AFTER ComissaoPercentual");
        }
        if (!in_array('ComissaoPercentualParte2', $chk, true)) {
            $pdo->exec("ALTER TABLE Revendedor ADD COLUMN ComissaoPercentualParte2 DECIMAL(5,2) NULL AFTER TipoComissao");
        }
        if (!in_array('LimiteClientesParte1', $chk, true)) {
            $pdo->exec("ALTER TABLE Revendedor ADD COLUMN LimiteClientesParte1 INT NULL AFTER ComissaoPercentualParte2");
        }
    } catch (PDOException $e) {
    }

    // Trava de qual faixa (%) cada cliente indicado ficou — decidido na 1ª compra e vale
    // pra sempre, mesmo em compras/renovações futuras (não muda se o revendedor ganhar mais clientes depois)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS RevendedorCliente (
              IDRevendedorCliente CHAR(36) NOT NULL PRIMARY KEY,
              FKRevendedor        CHAR(36) NOT NULL,
              FKUsuarioComprador  CHAR(36) NOT NULL,
              NumeroOrdem         INT NOT NULL,
              PercentualAplicado  DECIMAL(5,2) NOT NULL,
              CriadoEm            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_revendedor_comprador (FKRevendedor, FKUsuarioComprador),
              KEY idx_revendedor (FKRevendedor)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
    }

    // ComissaoRevendedor passa a ter 1 linha por COMPRA/RENOVAÇÃO (não só a 1ª) — precisa de
    // uma referência única do pagamento pra nunca duplicar comissão do mesmo evento
    try {
        $chkCom = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ComissaoRevendedor'
              AND COLUMN_NAME = 'ReferenciaPagamento'
        ")->fetchColumn();
        if (!$chkCom) {
            $pdo->exec("ALTER TABLE ComissaoRevendedor ADD COLUMN ReferenciaPagamento VARCHAR(64) NULL AFTER Plano");
            $pdo->exec("ALTER TABLE ComissaoRevendedor ADD UNIQUE KEY uq_comissao_referencia (ReferenciaPagamento)");
        }
    } catch (PDOException $e) {
    }

    // Dedup genérico de eventos de pagamento já processados — usado tanto pra extensão de
    // assinatura em renovações por cartão quanto pra evitar comissão duplicada
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS PagamentoProcessado (
              Referencia   VARCHAR(64) NOT NULL PRIMARY KEY,
              ProcessadoEm DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
    }
}

// ─────────────────────────────────────────────────────────────────────────
// CARTEIRAS COMPARTILHADAS — múltiplas pessoas numa mesma carteira, com
// hierarquia dono/convidado, categorias próprias da carteira (só quando
// compartilhada) e log de atividade pro dono.
// ─────────────────────────────────────────────────────────────────────────

// Garante o schema completo de carteiras compartilhadas (auto-migração, sem SSH)
function garantirEstruturaCarteirasCompartilhadas(PDO $pdo): void
{
    // Carteira.Compartilhada — decidido na criação, não muda depois nesta versão
    try {
        $chk = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Carteira' AND COLUMN_NAME = 'Compartilhada'
        ")->fetchColumn();
        if (!$chk) {
            $pdo->exec("ALTER TABLE Carteira ADD COLUMN Compartilhada TINYINT(1) NOT NULL DEFAULT 0 AFTER FKUsuarioDono");
        }
    } catch (PDOException $e) {
    }

    // Categoria.FKCarteira — NULL = categoria pessoal (comportamento de sempre, intocado);
    // preenchida = a categoria pertence à carteira compartilhada, só o dono mexe nela
    try {
        $chkCat = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Categoria' AND COLUMN_NAME = 'FKCarteira'
        ")->fetchColumn();
        if (!$chkCat) {
            $pdo->exec("ALTER TABLE Categoria ADD COLUMN FKCarteira CHAR(36) NULL AFTER FKUsuario");
        }
    } catch (PDOException $e) {
    }

    // Usuario.CodigoConvite — código pessoal permanente pra adicionar alguém numa carteira
    // compartilhada (tipo "adicionar amigo"). Propositalmente distinto do CodigoIndicacao
    // (esse é do programa de indicação/revenda) pra nunca confundir os dois na UI.
    try {
        $chkUsu = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Usuario' AND COLUMN_NAME = 'CodigoConvite'
        ")->fetchColumn();
        if (!$chkUsu) {
            $pdo->exec("ALTER TABLE Usuario ADD COLUMN CodigoConvite VARCHAR(12) NULL AFTER CodigoIndicacao");
            $pdo->exec("ALTER TABLE Usuario ADD UNIQUE KEY uq_usuario_codigo_convite (CodigoConvite)");
        }
    } catch (PDOException $e) {
    }

    // MembroCarteira — StatusConvite: 0 = pendente (aguardando aceite), 1 = ativo.
    // O dono NÃO tem linha aqui (ele já é identificado por Carteira.FKUsuarioDono).
    //
    // Essa tabela já existia no banco (resquício de uma tentativa anterior não documentada
    // nesta sessão, referenciada em nova_transacao.php e admin/usuarios.php antes de tudo
    // isso ser construído). O CREATE TABLE IF NOT EXISTS abaixo, por isso, nunca roda em
    // produção — o schema real de lá usa IDMembroCarteira (não IDMembro) e MomentoCriacao
    // (não DataConvite). Pra instalação nova ficar idêntica à que já existe, o CREATE usa
    // esses mesmos nomes; só a coluna DataResposta (que não existia) é adicionada via ALTER.
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS MembroCarteira (
              IDMembroCarteira CHAR(36) NOT NULL PRIMARY KEY,
              FKCarteira       CHAR(36) NOT NULL,
              FKUsuario        CHAR(36) NOT NULL,
              StatusConvite    TINYINT(1) NOT NULL DEFAULT 0,
              MomentoCriacao   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              DataResposta     DATETIME NULL,
              UNIQUE KEY uq_membro_carteira_usuario (FKCarteira, FKUsuario),
              KEY idx_membro_usuario (FKUsuario),
              KEY idx_membro_carteira (FKCarteira)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
    }

    // DataResposta não existe na tabela pré-existente de produção — adiciona sem mexer
    // em mais nada (coluna nova e opcional, não quebra os FKs/PK que já estavam lá).
    try {
        $chkResp = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'MembroCarteira' AND COLUMN_NAME = 'DataResposta'
        ")->fetchColumn();
        if (!$chkResp) {
            $pdo->exec("ALTER TABLE MembroCarteira ADD COLUMN DataResposta DATETIME NULL");
        }
    } catch (PDOException $e) {
    }

    // Log de atividade da carteira compartilhada — insert-only, visível só pro dono
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS LogAtividadeCarteira (
              IDLog      CHAR(36) NOT NULL PRIMARY KEY,
              FKCarteira CHAR(36) NOT NULL,
              FKUsuario  CHAR(36) NOT NULL,
              Acao       VARCHAR(40) NOT NULL,
              Detalhe    VARCHAR(255) NULL,
              Categoria  VARCHAR(20) NOT NULL DEFAULT 'membro',
              CriadoEm   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_log_carteira (FKCarteira, CriadoEm)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
    }

    // LogAtividadeCarteira.Categoria — separa "movimentação de membro" (convite,
    // entrou/saiu) de "movimentação na carteira" (criou/editou/excluiu lançamento), pro
    // filtro de 3 visões (Tudo/Movimentações na carteira/Movimentações de membro) na
    // página de administrar carteira. Backfill 'membro' porque só esse tipo de evento
    // existia antes dessa coluna existir.
    try {
        $chkCatLog = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'LogAtividadeCarteira' AND COLUMN_NAME = 'Categoria'
        ")->fetchColumn();
        if (!$chkCatLog) {
            $pdo->exec("ALTER TABLE LogAtividadeCarteira ADD COLUMN Categoria VARCHAR(20) NOT NULL DEFAULT 'membro'");
        }
    } catch (PDOException $e) {
    }

    // Carteira.PermiteConvidadoExcluir — dono controla se convidados podem excluir os
    // próprios lançamentos livremente (default 1 = mantém o comportamento de sempre).
    // Desligado, só o dono exclui — evita sumiço de lançamento sem o dono perceber.
    try {
        $chkPerm = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Carteira' AND COLUMN_NAME = 'PermiteConvidadoExcluir'
        ")->fetchColumn();
        if (!$chkPerm) {
            $pdo->exec("ALTER TABLE Carteira ADD COLUMN PermiteConvidadoExcluir TINYINT(1) NOT NULL DEFAULT 1 AFTER Compartilhada");
        }
    } catch (PDOException $e) {
    }

    // config_limites_plano.carteiras_compartilhadas_membros — quantas pessoas cabem numa
    // carteira compartilhada (dono incluso), por plano. Semeia os valores acordados
    // (free=0, pro=2, vip=8) só na primeira vez que a coluna é criada.
    try {
        $chkLim = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'config_limites_plano'
              AND COLUMN_NAME = 'carteiras_compartilhadas_membros'
        ")->fetchColumn();
        if (!$chkLim) {
            $pdo->exec("ALTER TABLE config_limites_plano ADD COLUMN carteiras_compartilhadas_membros INT NOT NULL DEFAULT 0 AFTER parcelas_max");
            $pdo->exec("UPDATE config_limites_plano SET carteiras_compartilhadas_membros = 0 WHERE plano = 'free'");
            $pdo->exec("UPDATE config_limites_plano SET carteiras_compartilhadas_membros = 2 WHERE plano = 'pro'");
            $pdo->exec("UPDATE config_limites_plano SET carteiras_compartilhadas_membros = 8 WHERE plano = 'vip'");
        }
    } catch (PDOException $e) {
    }

    // Recurso "Carteiras Compartilhadas" só pro gate de plano (recursoDisponivelParaPlano,
    // usado ao criar/marcar uma carteira como compartilhada). mostrar_nos_planos=0 porque
    // o número exato de pessoas já aparece como item de limite em planos.php (_itensLimite)
    // — mostrar os dois juntos seria redundante.
    try {
        $existeRec = $pdo->prepare("SELECT 1 FROM config_recursos WHERE slug = 'carteiras_compartilhadas'");
        $existeRec->execute();
        if (!$existeRec->fetchColumn()) {
            $maxOrdem = (int) $pdo->query("SELECT COALESCE(MAX(ordem), 0) FROM config_recursos")->fetchColumn();
            $pdo->prepare("
                INSERT INTO config_recursos (slug, label, disponivel_free, disponivel_pro, disponivel_vip, mostrar_nos_planos, ordem)
                VALUES ('carteiras_compartilhadas', 'Carteiras Compartilhadas', 0, 1, 1, 0, :ordem)
            ")->execute([':ordem' => $maxOrdem + 10]);
        }
    } catch (PDOException $e) {
    }
}

// Gera um código pessoal único no formato USR-XXXXXX (mesmo alfabeto sem ambiguidade
// visual do código de indicação, mas com prefixo diferente pra nunca confundir os dois).
function gerarCodigoConvite(PDO $pdo): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = 'USR-';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $existe = $pdo->prepare("SELECT 1 FROM Usuario WHERE CodigoConvite = :c");
        $existe->execute([':c' => $code]);
    } while ($existe->fetchColumn());
    return $code;
}

// Retorna o código de convite do usuário, gerando na hora se ele ainda não tiver um
// (cobre contas criadas antes dessa feature existir).
function obterOuGerarCodigoConvite(PDO $pdo, string $usuarioId): string
{
    $stmt = $pdo->prepare("SELECT CodigoConvite FROM Usuario WHERE IDUsuario = :uid");
    $stmt->execute([':uid' => $usuarioId]);
    $codigo = $stmt->fetchColumn();
    if (!empty($codigo)) return $codigo;

    $novo = gerarCodigoConvite($pdo);
    try {
        $pdo->prepare("UPDATE Usuario SET CodigoConvite = :c WHERE IDUsuario = :uid")
            ->execute([':c' => $novo, ':uid' => $usuarioId]);
    } catch (PDOException $e) {
    }
    return $novo;
}

// Todas as carteiras que o usuário pode acessar: as que ele é dono + as compartilhadas
// em que foi aceito como membro. Cada linha ganha 'papel' ('dono'|'convidado').
function carteirasAcessiveisPorUsuario(PDO $pdo, string $usuarioId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT c.IDCarteira, c.TipoCarteira, c.Principal, c.Compartilhada, c.FKUsuarioDono,
                   CASE WHEN c.FKUsuarioDono = :uid THEN 'dono' ELSE 'convidado' END AS papel
            FROM Carteira c
            LEFT JOIN MembroCarteira mc
                   ON mc.FKCarteira = c.IDCarteira AND mc.FKUsuario = :uid2 AND mc.StatusConvite = 1
            WHERE c.FKUsuarioDono = :uid3 OR mc.FKCarteira IS NOT NULL
            ORDER BY c.Principal DESC, c.TipoCarteira ASC
        ");
        $stmt->execute([':uid' => $usuarioId, ':uid2' => $usuarioId, ':uid3' => $usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback pra base sem a migração ainda aplicada — só as carteiras próprias
        $stmt = $pdo->prepare("
            SELECT IDCarteira, TipoCarteira, Principal, 0 AS Compartilhada, FKUsuarioDono, 'dono' AS papel
            FROM Carteira WHERE FKUsuarioDono = :uid ORDER BY Principal DESC, TipoCarteira ASC
        ");
        $stmt->execute([':uid' => $usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// 'dono', 'convidado' ou null (sem acesso). Usado pra travar ações estruturais
// (renomear carteira, gerenciar membros/categorias) e pra permitir o dono mexer
// em lançamentos de qualquer membro dentro da própria carteira.
function carteiraPapelDoUsuario(PDO $pdo, string $carteiraId, string $usuarioId): ?string
{
    $stmt = $pdo->prepare("SELECT FKUsuarioDono FROM Carteira WHERE IDCarteira = :cid");
    $stmt->execute([':cid' => $carteiraId]);
    $dono = $stmt->fetchColumn();
    if ($dono === false) return null;
    if ($dono === $usuarioId) return 'dono';

    try {
        $stmtM = $pdo->prepare("SELECT 1 FROM MembroCarteira WHERE FKCarteira = :cid AND FKUsuario = :uid AND StatusConvite = 1");
        $stmtM->execute([':cid' => $carteiraId, ':uid' => $usuarioId]);
        return $stmtM->fetchColumn() ? 'convidado' : null;
    } catch (PDOException $e) {
        return null;
    }
}

// Plano de quem é dono da carteira — usado pra aplicar o teto de plano (parcelas,
// categorias, membros) a convidados dentro de uma carteira compartilhada, já que eles
// operam sob o plano de quem criou a carteira, não o próprio, enquanto estão nela.
// Lê direto de Usuario.Plano (mesma fonte de verdade usada em toda a aplicação via
// $_SESSION['plano'] no login) — NÃO da tabela Assinatura, porque planos atribuídos
// manualmente (admin/supremo, cortesia) não têm necessariamente uma linha de Assinatura
// com Status='ativa', o que fazia essa função sempre cair no fallback 'free' pra essas
// contas e bloquear o convite com "limite de pessoas do plano atingido" incorretamente.
function planoEfetivoDaCarteira(PDO $pdo, string $carteiraId): string
{
    $stmt = $pdo->prepare("SELECT FKUsuarioDono FROM Carteira WHERE IDCarteira = :cid");
    $stmt->execute([':cid' => $carteiraId]);
    $donoId = $stmt->fetchColumn();
    if (!$donoId) return 'free';

    $stmtPlano = $pdo->prepare("SELECT Plano FROM Usuario WHERE IDUsuario = :uid");
    $stmtPlano->execute([':uid' => $donoId]);
    return strtolower($stmtPlano->fetchColumn() ?: 'free');
}

// Confere o limite de membros (dono + convidados pendentes/ativos) contra o limite
// de plano ativo de QUEM CRIOU a carteira — os convidados operam sob esse teto.
function podeConvidarMaisMembros(PDO $pdo, string $carteiraId): bool
{
    $planoDono     = planoEfetivoDaCarteira($pdo, $carteiraId);
    $limites       = limitesDoPlano($planoDono);
    $limiteMembros = $limites['carteiras_compartilhadas_membros'] ?? 0;
    if ($limiteMembros === PHP_INT_MAX) return true;
    if ($limiteMembros <= 0) return false;

    try {
        $stmtCont = $pdo->prepare("SELECT COUNT(*) FROM MembroCarteira WHERE FKCarteira = :cid AND StatusConvite IN (0,1)");
        $stmtCont->execute([':cid' => $carteiraId]);
        $totalAtual = (int) $stmtCont->fetchColumn() + 1; // +1 pelo dono
    } catch (PDOException $e) {
        $totalAtual = 1;
    }

    return $totalAtual < $limiteMembros;
}

// Registra uma linha no log de atividade da carteira compartilhada. Nunca lança
// exceção pro chamador — log é auxiliar, não pode travar a ação principal.
function logAtividadeCarteira(PDO $pdo, string $carteiraId, string $usuarioId, string $acao, ?string $detalhe = null): void
{
    // 'membro' = convite/entrada/saída de pessoas; 'movimentacao' = criar/editar/excluir/
    // efetivar/transferir lançamento. Usado pro filtro de 3 visões na página de
    // administrar carteira (Tudo / Movimentações na carteira / Movimentações de membro).
    static $categoriasPorAcao = [
        'convite_enviado'       => 'membro',
        'removeu_membro'        => 'membro',
        'saiu'                  => 'membro',
        'aceitou_convite'       => 'membro',
        'recusou_convite'       => 'membro',
        'lancamento_criado'     => 'movimentacao',
        'lancamento_editado'    => 'movimentacao',
        'lancamento_excluido'   => 'movimentacao',
        'lancamento_efetivado'  => 'movimentacao',
        'lancamento_estornado'  => 'movimentacao',
        'lancamento_transferido' => 'movimentacao',
    ];
    $categoria = $categoriasPorAcao[$acao] ?? 'movimentacao';

    try {
        $pdo->prepare("
            INSERT INTO LogAtividadeCarteira (IDLog, FKCarteira, FKUsuario, Acao, Detalhe, Categoria)
            VALUES (:id, :cid, :uid, :acao, :detalhe, :categoria)
        ")->execute([
            ':id'        => gerarUuid(),
            ':cid'       => $carteiraId,
            ':uid'       => $usuarioId,
            ':acao'      => $acao,
            ':detalhe'   => $detalhe,
            ':categoria' => $categoria,
        ]);
    } catch (PDOException $e) {
    }
}

// Se convidados podem excluir os próprios lançamentos livremente (dono sempre pode).
// Default true (mesmo comportamento de sempre) até o dono desligar em Permissões.
function carteiraPermiteConvidadoExcluir(PDO $pdo, string $carteiraId): bool
{
    try {
        $stmt = $pdo->prepare("SELECT PermiteConvidadoExcluir FROM Carteira WHERE IDCarteira = :cid");
        $stmt->execute([':cid' => $carteiraId]);
        $val = $stmt->fetchColumn();
        return $val === false ? true : (int)$val === 1;
    } catch (PDOException $e) {
        return true;
    }
}

// Busca os dados do registro (tipo/valor/descrição/carteira) e, se a carteira dele for
// compartilhada, grava a linha de log com o texto padrão ("Receita de R$ X — descrição").
// Centraliza essa checagem porque excluir/efetivar/editar registro está espalhado em
// dashboard.php, agenda.php e geral/acao_registro.php — sem isso cada um duplicaria a
// mesma lógica. Chamar ANTES de excluir o registro (depois de excluído não dá pra buscar
// os dados dele mais).
function logAtividadeRegistroSeCompartilhada(PDO $pdo, string $registroId, string $usuarioId, string $acao): void
{
    try {
        $stmt = $pdo->prepare("
            SELECT r.TipoRegistro, r.Valor, r.Descricao, r.FKCarteira, c.Compartilhada
            FROM Registro r
            JOIN Carteira c ON c.IDCarteira = r.FKCarteira
            WHERE r.IDRegistro = :id
        ");
        $stmt->execute([':id' => $registroId]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$reg || (int)($reg['Compartilhada'] ?? 0) !== 1) return;

        $detalhe = ($reg['TipoRegistro'] === 'receita' ? 'Receita' : 'Despesa')
            . ' de R$ ' . number_format((float)$reg['Valor'], 2, ',', '.') . ' — ' . $reg['Descricao'];
        logAtividadeCarteira($pdo, $reg['FKCarteira'], $usuarioId, $acao, $detalhe);
    } catch (PDOException $e) {
    }
}

// Se o usuário pode excluir esse registro específico: dono da carteira sempre pode; quem
// lançou só pode se a carteira permitir (Permissões, na página de administrar carteira) —
// numa carteira que não é compartilhada, não existe essa trava (sempre true). Chamar antes
// de qualquer DELETE de Registro feito fora de geral/acao_registro.php (que já tem a sua).
function podeExcluirRegistro(PDO $pdo, string $registroId, string $usuarioId): bool
{
    try {
        $stmt = $pdo->prepare("SELECT FKCarteira FROM Registro WHERE IDRegistro = :id");
        $stmt->execute([':id' => $registroId]);
        $carteiraId = $stmt->fetchColumn();
        if (!$carteiraId) return true;

        $stmtCart = $pdo->prepare("SELECT Compartilhada FROM Carteira WHERE IDCarteira = :cid");
        $stmtCart->execute([':cid' => $carteiraId]);
        if ((int)($stmtCart->fetchColumn() ?: 0) !== 1) return true;

        if (carteiraPapelDoUsuario($pdo, $carteiraId, $usuarioId) === 'dono') return true;
        return carteiraPermiteConvidadoExcluir($pdo, $carteiraId);
    } catch (PDOException $e) {
        return true;
    }
}

// Kit inicial de categorias — mesmo conjunto usado no cadastro de um usuário novo
// (usuario/processa_cadastro.php), reaproveitado pra popular uma carteira compartilhada
// recém-criada com as mesmas categorias prontas, em vez de começar vazia.
function injetarKitCategoriasIniciais(PDO $pdo, string $usuarioId, ?string $carteiraId = null): void
{
    $kitInicial = [
        ['nome' => 'Alimentação', 'tipo' => 'despesa', 'icone' => 'bi-cart3'],
        ['nome' => 'Moradia',     'tipo' => 'despesa', 'icone' => 'bi-house-door'],
        ['nome' => 'Transporte',  'tipo' => 'despesa', 'icone' => 'bi-car-front'],
        ['nome' => 'Saúde',       'tipo' => 'despesa', 'icone' => 'bi-heart-pulse'],
        ['nome' => 'Educação',    'tipo' => 'despesa', 'icone' => 'bi-book'],
        ['nome' => 'Lazer',       'tipo' => 'despesa', 'icone' => 'bi-controller'],
        ['nome' => 'Assinaturas', 'tipo' => 'despesa', 'icone' => 'bi-play-btn'],
        ['nome' => 'Vestuário',   'tipo' => 'despesa', 'icone' => 'bi-bag'],
        ['nome' => 'Salário',       'tipo' => 'receita', 'icone' => 'bi-cash-stack'],
        ['nome' => 'Rendimentos',   'tipo' => 'receita', 'icone' => 'bi-graph-up-arrow'],
        ['nome' => 'Serviços/Free', 'tipo' => 'receita', 'icone' => 'bi-laptop'],
        ['nome' => 'Outros',        'tipo' => 'receita', 'icone' => 'bi-plus-circle-dotted'],
    ];

    $sqlCat = "INSERT INTO Categoria (IDCategoria, NomeCategoria, TipoCategoria, IconeCategoria, FKUsuario, FKCarteira)
               VALUES (:id, :nome, :tipo, :icone, :uid, :cid)";
    $stmtCat = $pdo->prepare($sqlCat);
    foreach ($kitInicial as $cat) {
        $stmtCat->execute([
            ':id'   => gerarUuid(),
            ':nome' => $cat['nome'],
            ':tipo' => $cat['tipo'],
            ':icone' => $cat['icone'],
            ':uid'  => $usuarioId,
            ':cid'  => $carteiraId,
        ]);
    }
}

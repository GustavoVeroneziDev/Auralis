<?php
// usuario/salvar_telefone.php
// Salva o telefone a partir do modal de onboarding do dashboard (ex: quem entrou
// pelo Google, que nunca passa pelo campo de telefone do cadastro normal).

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $telefone = sanitizarTelefone(trim($_POST['telefone'] ?? ''));

    if ($telefone) {
        try {
            $pdo->prepare("UPDATE Usuario SET Telefone = :tel WHERE IDUsuario = :uid")
                ->execute([':tel' => $telefone, ':uid' => $_SESSION['usuario_id']]);

            $nome = explode(' ', $_SESSION['usuario_nome'] ?? '')[0] ?: 'aí';
            $planoEfetivo = planoEfetivoUsuario($pdo, $_SESSION['usuario_id']);

            if ($planoEfetivo === 'vip_trial') {
                $horasRestantes = function_exists('obterHorasRestantesTeste') ? obterHorasRestantesTeste() : 0;
                $mensagem = "📱 Prontinho, {$nome}! Seu WhatsApp já está ativo no Auralis.\n\n"
                          . "Você ainda tem *{$horasRestantes}h de teste grátis* — aproveita pra testar à vontade! Com o Auralis você pode:\n\n"
                          . "• 💰 Registrar receitas e despesas\n"
                          . "• 📅 Não esquecer nenhuma conta (te aviso quando tiver vencendo)\n"
                          . "• 🐷 Guardar dinheiro em cofrinhos com metas\n"
                          . "• 📊 Ver pra onde seu dinheiro tá indo, com gráficos fáceis de entender\n\n"
                          . "E o melhor: *dá pra fazer tudo isso direto por aqui* — é só me mandar uma mensagem tipo \"gastei 50 reais no mercado\" que eu já registro pra você! 💪";
                enviarWhatsAppNotificacao($telefone, $mensagem);
            } elseif ($planoEfetivo === 'vip') {
                $mensagem = "📱 Prontinho, {$nome}! Seu WhatsApp já está ativo no Auralis.\n\n"
                          . "Como você é *VIP*, pode usar esse assistente sempre que quiser. Com o Auralis você pode:\n\n"
                          . "• 💰 Registrar receitas e despesas\n"
                          . "• 📅 Não esquecer nenhuma conta (te aviso quando tiver vencendo)\n"
                          . "• 🐷 Guardar dinheiro em cofrinhos com metas\n"
                          . "• 📊 Ver pra onde seu dinheiro tá indo, com gráficos fáceis de entender\n\n"
                          . "É só me mandar uma mensagem tipo \"gastei 50 reais no mercado\" que eu já registro pra você! 💪";
                enviarWhatsAppNotificacao($telefone, $mensagem);
            } else {
                $mensagem = "📱 Prontinho, {$nome}! Seu número foi salvo.\n\n"
                          . "A partir de agora você recebe por aqui os avisos de contas chegando perto do vencimento.\n\n"
                          . "O assistente de IA que registra tudo direto por mensagem é um recurso *VIP* — se quiser liberar, dá uma olhada em meuauralis.com/planos.php";
                enviarWhatsAppNotificacao($telefone, $mensagem);
            }
        } catch (Throwable $e) {
        }
    }
}

header("Location: ../dashboard.php");
exit;

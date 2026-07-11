<?php
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];

    try {
        // Busca se existe alguém com esse token específico
        $sql = "SELECT IDUsuario, Nome, Telefone FROM Usuario WHERE TokenAtivacao = :token AND StatusConta = 'pendente' LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':token' => $token]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usuario) {
            // Achou! Ativa a conta e apaga o token (para não ser usado de novo)
            $sqlUpdate = "UPDATE Usuario SET StatusConta = 'ativo', TokenAtivacao = NULL WHERE IDUsuario = :uid";
            $stmtUpdate = $pdo->prepare($sqlUpdate);
            $stmtUpdate->execute([':uid' => $usuario['IDUsuario']]);

            concederConquistaParaUsuario($pdo, $usuario['IDUsuario'], 'primeiro_acesso');

            // Notificação de boas-vindas com campo de resposta livre
            try {
                $primeiroNome = explode(' ', $usuario['Nome'])[0];
                $nidBV = gerarUuid();
                $itensBV = json_encode([[
                    'pergunta' => 'Tem alguma mensagem, sugestão ou dúvida para a nossa equipe?',
                    'tipo'     => 'texto',
                ]]);
                $pdo->prepare("
                    INSERT INTO Notificacao (IDNotificacao, Titulo, Conteudo, DestinatarioTipo, TipoInteracao, ItensPesquisa)
                    VALUES (:id, :titulo, :conteudo, 'selecionado', 'pesquisa', :itens)
                ")->execute([
                    ':id'      => $nidBV,
                    ':titulo'  => 'Bem-vindo ao Auralis!',
                    ':conteudo' => "Olá, {$primeiroNome}!\n\nÉ uma alegria ter você aqui. O Auralis foi criado para simplificar sua vida financeira — explore o painel, registre suas movimentações, crie metas e aproveite tudo que preparamos para você.\n\nSinta-se completamente à vontade para testar, explorar e, se quiser, nos enviar uma mensagem com dúvidas, sugestões ou qualquer feedback. Você também pode nos contatar pelos canais no rodapé do site.\n\nObrigado por confiar no Auralis!\n\n— Equipe Auralis",
                    ':itens'   => $itensBV,
                ]);
                $pdo->prepare("
                    INSERT IGNORE INTO NotificacaoDestinatario (FKNotificacao, FKUsuario)
                    VALUES (:nid, :uid)
                ")->execute([':nid' => $nidBV, ':uid' => $usuario['IDUsuario']]);
            } catch (Throwable $e) { /* silencia — boas-vindas não devem bloquear a ativação */ }

            // Boas-vindas por WhatsApp — só quem já cadastrou telefone no formulário de cadastro
            if (!empty($usuario['Telefone'])) {
                try {
                    $primeiroNome = explode(' ', $usuario['Nome'])[0];
                    $mensagem = "Oi, {$primeiroNome}! Bem-vindo(a) ao *Auralis* 🎉\n\n"
                              . "Aqui é bem simples: eu te ajudo a organizar sua vida financeira sem complicação. Com o Auralis você pode:\n\n"
                              . "• 💰 Registrar receitas e despesas\n"
                              . "• 📅 Não esquecer nenhuma conta (te aviso quando tiver vencendo)\n"
                              . "• 🐷 Guardar dinheiro em cofrinhos com metas\n"
                              . "• 📊 Ver pra onde seu dinheiro tá indo, com gráficos fáceis de entender\n\n"
                              . "E o melhor: *dá pra fazer tudo isso direto por aqui pelo WhatsApp* — é só me mandar uma mensagem tipo \"gastei 50 reais no mercado\" que eu já registro pra você, sem precisar abrir o site toda vez!\n\n"
                              . "Qualquer dúvida, é só chamar por aqui. Bora organizar essa grana? 💪";
                    enviarWhatsAppNotificacao($usuario['Telefone'], $mensagem);
                } catch (Throwable $e) { /* silencia — boas-vindas não devem bloquear a ativação */ }
            }

            // Manda pro login com sucesso
            header("Location: login.php?ativacao=sucesso");
            exit;
        } else {
            // Token não encontrado ou conta já ativada
            header("Location: login.php?ativacao=invalido");
            exit;
        }
    } catch (PDOException $e) {
        die("Erro ao processar ativação.");
    }
} else {
    header("Location: login.php");
    exit;
}
?>
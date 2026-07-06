<?php
// ==============================================================================
// GERAL/_MENU_USUARIO_ITENS.PHP — itens do dropdown do usuário (Perfil, Configurações
// etc.), compartilhados entre o menu da sidebar e o menu do nav superior. Antes esses
// itens existiam duplicados nos dois lugares e foram divergindo com o tempo (cores de
// ícone diferentes, item esquecido em um dos dois) — agora só existe uma lista.
//
// Cor dos ícones: neutra (text-secondary) por padrão pra tudo, cor só fica reservada
// pra sinalizar algo especial — "Sair" (vermelho, ação destrutiva) e "Painel do
// Revendedor" (dourado, recurso privilegiado). Usa as mesmas variáveis já presentes
// no escopo de geral/header.php ($_ehRevendedor).
// ==============================================================================
?>
<li>
    <a class="dropdown-item d-flex align-items-center py-2 transition-hover" href="/perfil.php" style="color:var(--text-main);">
        <i class="bi bi-person-circle me-2 text-secondary"></i> Perfil
    </a>
</li>
<li>
    <a class="dropdown-item d-flex align-items-center py-2 transition-hover" href="/configuracoes.php" style="color:var(--text-main);">
        <i class="bi bi-gear me-2 text-secondary"></i> Configurações
    </a>
</li>
<li>
    <a class="dropdown-item d-flex align-items-center py-2 transition-hover" href="/notificacoes.php" style="color:var(--text-main);">
        <i class="bi bi-bell me-2 text-secondary"></i> Notificações
    </a>
</li>
<?php if ($_ehRevendedor): ?>
<li>
    <a class="dropdown-item d-flex align-items-center py-2 fw-semibold transition-hover" href="/revendedor/dashboard.php" style="color:#d4af37;">
        <i class="bi bi-people-fill me-2" style="color:#d4af37;"></i> Painel do Revendedor
    </a>
</li>
<?php endif; ?>
<li>
    <a class="dropdown-item d-flex align-items-center py-2 transition-hover" href="/ajuda.php" target="_blank" rel="noopener" style="color:var(--text-main);">
        <i class="bi bi-mortarboard me-2 text-secondary"></i> Tutoriais
    </a>
</li>
<li>
    <a class="dropdown-item d-flex align-items-center py-2 transition-hover" href="/contato.php" style="color:var(--text-main);">
        <i class="bi bi-headset me-2 text-secondary"></i> Contato & Suporte
    </a>
</li>
<li class="btn-instalar-app" style="display:none;">
    <a class="dropdown-item d-flex align-items-center py-2 transition-hover" href="#" onclick="auralisInstalar(); return false;" style="color:var(--text-main);">
        <i class="bi bi-download me-2 text-secondary"></i> Instalar como App
    </a>
</li>
<li><hr class="dropdown-divider border-secondary-subtle"></li>
<li>
    <a class="dropdown-item d-flex align-items-center py-2 text-danger transition-hover" href="/usuario/logout.php">
        <i class="bi bi-box-arrow-right me-2"></i> Sair
    </a>
</li>

-- Remove o sufixo " (X/N)" da Descricao de lançamentos parcelados
-- que já têm ParcelaAtual e TotalParcelas preenchidos.
-- Rodar uma vez no phpMyAdmin.

UPDATE LancamentoCartao
SET Descricao = TRIM(REGEXP_REPLACE(Descricao, ' \\([0-9]+/[0-9]+\\)$', ''))
WHERE TotalParcelas IS NOT NULL
  AND Descricao REGEXP ' \\([0-9]+/[0-9]+\\)$';

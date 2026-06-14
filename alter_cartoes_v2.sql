-- Adiciona coluna para rastrear o Registro de preview da fatura aberta
-- Execute no phpMyAdmin antes de testar o preview de fatura
ALTER TABLE FaturaCartao
    ADD COLUMN FKRegistroPreview VARCHAR(36) DEFAULT NULL
    AFTER FKRegistroPagamento;

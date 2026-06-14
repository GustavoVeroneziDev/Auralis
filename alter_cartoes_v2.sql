-- Adiciona coluna FKRegistroPreview em FaturaCartao
-- Execute este script no phpMyAdmin antes de usar o sistema de cartões

ALTER TABLE FaturaCartao
    ADD COLUMN FKRegistroPreview VARCHAR(36) DEFAULT NULL
    AFTER FKRegistroPagamento;

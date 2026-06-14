-- Adiciona limite de cartões de crédito por plano
-- Rodar no phpMyAdmin (local e produção)

ALTER TABLE config_limites_plano
    ADD COLUMN cartoes INT NOT NULL DEFAULT 1 AFTER carteiras;

UPDATE config_limites_plano SET cartoes = 1  WHERE plano = 'free';
UPDATE config_limites_plano SET cartoes = 3  WHERE plano = 'pro';
UPDATE config_limites_plano SET cartoes = -1 WHERE plano = 'vip';

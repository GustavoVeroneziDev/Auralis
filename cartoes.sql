-- =====================================================================
-- SISTEMA DE CARTÕES DE CRÉDITO — Auralis
-- Execute este arquivo no phpMyAdmin para ativar a funcionalidade
-- =====================================================================

CREATE TABLE IF NOT EXISTS `CartaoCredito` (
    `IDCartao`          VARCHAR(36)   NOT NULL,
    `FKUsuario`         VARCHAR(36)   NOT NULL,
    `Nome`              VARCHAR(100)  NOT NULL,
    `Bandeira`          ENUM('visa','mastercard','elo','amex','hipercard','outro') NOT NULL DEFAULT 'outro',
    `Cor`               VARCHAR(7)    NOT NULL DEFAULT '#7c3aed',
    `Limite`            DECIMAL(10,2)          DEFAULT NULL,
    `DiaFechamento`     TINYINT       NOT NULL DEFAULT 1,
    `DiaVencimento`     TINYINT       NOT NULL DEFAULT 10,
    `FKCarteiraDebito`  VARCHAR(36)            DEFAULT NULL,
    `Ativo`             TINYINT(1)    NOT NULL DEFAULT 1,
    `CriadoEm`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`IDCartao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MesReferencia = mês do vencimento (YYYY-MM), ex: fatura que vence em julho = '2026-07'
CREATE TABLE IF NOT EXISTS `FaturaCartao` (
    `IDFatura`              VARCHAR(36)   NOT NULL,
    `FKCartao`              VARCHAR(36)   NOT NULL,
    `FKUsuario`             VARCHAR(36)   NOT NULL,
    `MesReferencia`         VARCHAR(7)    NOT NULL,
    `DataFechamento`        DATE          NOT NULL,
    `DataVencimento`        DATE          NOT NULL,
    `Status`                ENUM('aberta','fechada','paga') NOT NULL DEFAULT 'aberta',
    `ValorTotal`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `FKRegistroPagamento`   VARCHAR(36)            DEFAULT NULL,
    `CriadoEm`              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`IDFatura`),
    UNIQUE KEY `uk_cartao_mes` (`FKCartao`, `MesReferencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `LancamentoCartao` (
    `IDLancamento`      VARCHAR(36)   NOT NULL,
    `FKFatura`          VARCHAR(36)   NOT NULL,
    `FKCartao`          VARCHAR(36)   NOT NULL,
    `FKUsuario`         VARCHAR(36)   NOT NULL,
    `Descricao`         VARCHAR(255)  NOT NULL,
    `Valor`             DECIMAL(10,2) NOT NULL,
    `DataCompra`        DATE          NOT NULL,
    `FKCategoria`       VARCHAR(36)            DEFAULT NULL,
    `GrupoParcelamento` VARCHAR(36)            DEFAULT NULL,
    `ParcelaAtual`      TINYINT                DEFAULT NULL,
    `TotalParcelas`     TINYINT                DEFAULT NULL,
    `CriadoEm`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`IDLancamento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

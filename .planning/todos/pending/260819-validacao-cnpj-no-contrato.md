---
created: 2026-08-19T14:55:41.768Z
title: Validar dígito verificador do CNPJ no cadastro do contrato
area: contratos
files:
  - app/Http/Controllers/ContratoAdminController.php:355-365
  - resources/js/Pages/Admin/ContratoDetalhe.jsx:310-320
---

# Validar dígito verificador do CNPJ no cadastro do contrato

**Criado:** 2026-08-19
**Origem:** teste ponta-a-ponta do fluxo completo, com os setores responsáveis
**Criticidade:** média — CNPJ errado vai parar dentro de um contrato assinado

## Problema

O campo CNPJ do bloco "Cadastro da empresa" não tem verificação de dígito. Qualquer string
passa, e o valor vai direto para o `.docx` do contrato via
`ContratoVariaveisModeloService::montar()`. Um CNPJ digitado errado só seria descoberto depois
do contrato assinado — quando corrigir custa refazer o envelope inteiro.

Precedente na mesma tela: o nome do signatário também não era validado, e "teste" (sem
sobrenome) só falhou 6 minutos depois, num 400 da Clicksign. Mesmo padrão de problema — validar
tarde custa caro.

## Solução

TBD. Validação de dígito verificador de CNPJ, no **servidor** (o client pode espelhar para
feedback imediato, mas quem decide é o backend — mesma disciplina de
`T-131-04-03`: `disabled` no client não é controle).

Decidir:
- Se a validação é `required` ou `nullable` + válido quando presente (hoje é `nullable`)
- Se vale checar também o CNPJ que chega do HubSpot no handoff, ou só o digitado à mão
- Se a máscara do input deve normalizar antes de gravar (hoje grava com pontuação:
  `26.754.383/0001-87`)

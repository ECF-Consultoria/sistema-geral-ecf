---
created: 2026-08-19T14:55:41.768Z
title: Campos novos no contrato — razão social, endereço e vencimento das parcelas
area: contratos
files:
  - app/Http/Controllers/ContratoAdminController.php:355-390
  - resources/js/Pages/Admin/ContratoDetalhe.jsx
  - app/Services/Clicksign/ContratoVariaveisModeloService.php
  - app/Models/Company.php
---

# Campos novos no contrato: razão social, endereço e vencimento das parcelas

**Criado:** 2026-08-19
**Origem:** teste ponta-a-ponta do fluxo completo (HubSpot → Administrativo → Clicksign),
rodado com os setores responsáveis
**Criticidade:** média — hoje o contrato sai sem dados que o jurídico/financeiro espera

## Problema

O bloco "Cadastro da empresa" da tela `/administrativo/contratos/{company}` pede hoje apenas
CNPJ, e-mail do cliente, nome de quem assina e as datas por serviço. Faltam quatro campos que
o contrato precisa:

1. **Razão social** — hoje o contrato usa `companies.name`, que é nome fantasia/apelido
   ("Embralumi - Novo(a) Deal"), não a razão social registrada
2. **Endereço** da empresa
3. **Data de vencimento da PRIMEIRA parcela**
4. **Data de vencimento das DEMAIS parcelas**

O Comercial vai criar os mesmos campos no HubSpot, para que futuramente sejam puxados via API.

## Solução

TBD — mas duas restrições já definidas:

1. **O Administrativo precisa aceitar preenchimento manual AGORA** e ficar pronto para receber
   do HubSpot depois. Ou seja: colunas próprias + campos na tela, independentes da integração.
2. Quando a integração HubSpot chegar, ela deve seguir o **mesmo padrão "só preenche se vazio"**
   já usado no handoff comercial — nunca sobrescrever o que uma pessoa digitou. Exceção
   conhecida do padrão: `hubspot_notas`/`hubspot_observacao` são espelho e ficam de fora da regra.

Pontos de toque prováveis:
- Migration para as colunas novas (em `companies` ou em `contratos_servico`, decidir — vencimento
  de parcela é POR SERVIÇO, razão social e endereço são POR EMPRESA)
- Validação em `ContratoAdminController::salvarCadastro()` (hoje em ~linha 355-390)
- `ContratoDadosMinimosService::faltantes()` — decidir se os novos campos entram nos dados
  mínimos que bloqueiam a geração, ou se são opcionais
- `ContratoVariaveisModeloService::montar()` — as variáveis `{{chave}}` do `.docx` da Clicksign
  precisam existir no modelo antes de serem preenchidas
- O modelo `.docx` na Clicksign precisa ganhar os placeholders correspondentes

⚠️ Nomes de variável do modelo só aceitam `[a-z0-9_]` (guarda em
`ClicksignClient::anexarDocumentoPorModelo()`) — nada de acento ou espaço nas chaves.

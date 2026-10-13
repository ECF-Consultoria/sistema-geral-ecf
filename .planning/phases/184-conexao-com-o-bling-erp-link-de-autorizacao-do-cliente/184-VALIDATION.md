---
phase: 184
slug: conexao-com-o-bling-erp-link-de-autorizacao-do-cliente
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-10-13
---

# Fase 184 — Estratégia de validação

> Contrato de validação da fase, para amostragem de feedback durante a execução.
> A arquitetura em 5 camadas está em `184-RESEARCH.md` → seção **Validation Architecture**.
> **O planejador completa o mapa por tarefa abaixo** (os IDs só existem depois dos PLAN.md).

---

## Infraestrutura de teste

| Propriedade | Valor |
|---|---|
| **Framework (PHP)** | PHPUnit 11.x — config em `phpunit.xml`, suítes `Unit` e `Feature` |
| **Framework (JS)** | `node --test` (nativo) — `tests/js/**/*.test.js` |
| **Comando rápido** | `C:\xampp\php\php.exe artisan test --filter=Bling` |
| **Suíte completa (PHP)** | `C:\xampp\php\php.exe artisan test` |
| **Suíte completa (JS)** | `npm run test:js` |
| **Build do front** | `npm run build` — **obrigatório** após toda mudança de front (convenção do projeto) |
| **Banco dos testes** | SQLite |
| ⚠️ **Banco de verdade** | **MariaDB local** — a migration tem de ser provada nele, não só no SQLite (ver memórias: enum/CHECK, FK 1830, nome de índice 1059) |

⚠️ **PHP não está no PATH desta máquina**: usar `C:\xampp\php\php.exe`.
⚠️ Antes de qualquer comando que dependa do banco local, conferir `tasklist | grep mysqld` (o MariaDB
local já corrompeu uma vez).

---

## Taxa de amostragem

- **Depois de cada commit de tarefa:** `artisan test --filter=Bling`
- **Depois de cada onda:** `artisan test` + `npm run test:js`
- **Antes de `/gsd:verify-work`:** as duas suítes verdes, com **baseline medido ANTES de tocar em nada**
  (o projeto tem falhas pré-existentes conhecidas — ex.: `tests/js/estrutura-grade-glide.test.js:122`;
  contá-las como regressão desta fase é falso positivo)
- **Latência máxima de feedback:** ~60 s no filtro, alguns minutos na suíte completa

---

## Mapa de verificação por tarefa

*A completar pelo planejador: uma linha por tarefa, com o comando automatizado que a prova.*

| Task ID | Plano | Onda | Requisito | Ameaça | Comportamento seguro | Tipo | Comando | Status |
|---|---|---|---|---|---|---|---|---|
| (a completar) | | | | | | | | ⬜ |

*Status: ⬜ pendente · ✅ verde · ❌ vermelho · ⚠️ instável*

---

## Requisitos da Onda 0

- [ ] Arquivo de teste novo para o tradutor puro (unidade, sem rede)
- [ ] Arquivo de teste novo para o OAuth com `Http::fake`
- [ ] Arquivo de teste novo de Feature para o link assinado e a landing pública
- [ ] Fixtures com payloads do Bling (`formato S`, `formato V`, `dados-basicos`, `/estoques/saldos`)
      copiados da documentação — **nunca** capturados da conta real do cliente

*Nada a instalar: PHPUnit, Mockery, `Http::fake` e `node --test` já existem.*

---

## Asserções NEGATIVAS obrigatórias

Classe de prova que esta fase não pode dispensar — cada uma existe porque o erro correspondente é
silencioso:

| Afirmação | Por que é negativa |
|---|---|
| O `preco` do Bling **não** aparece no resultado do tradutor | O preço é da Precificação; se vazar, o bônus e a promoção automática leem preço errado **sem erro** |
| `client_id`/`client_secret` **não** aparecem no corpo da requisição de token | O Bling só aceita no header; no corpo, ou falha ou vaza em log |
| A linha crua de `bling_conexoes` **não** contém o token em claro | O cast `encrypted` quebra em silêncio se alguém trocar o tipo da coluna |
| Nenhum texto novo que o cliente lê cita a plataforma de venda (`assertSemOrigem`) | Regra de sigilo do projeto |
| A gravação do estoque **não** chama `PreparoIaAgenda::aoSalvar` | Risco 5: centenas de cadeias de IA na fila `high`, a mesma que publica |
| CNPJ divergente → **nenhuma** linha de token gravada | Conexão divergente que grava token é a falha do OAuth do ML nos Polos |
| `redirect_uri`/`scope` **não** são enviados na URL de authorize | O Bling os ignora; mandá-los esconde erro de cadastro do app |
| 429 **não** gera nova tentativa imediata | 600 req/10 s bloqueia o IP da VPS para **todos** os clientes |

⚠️ **Regra de gate herdada** (`.planning/learnings/`, gate vazio por construção): todo gate desta fase
tem de responder *"o que precisaria estar errado para este gate falhar?"*. `git diff --stat -- <dir>`
depois de commitar compara a **árvore** e sai vazio sempre — usar `<inicial>..HEAD`.

---

## Verificações só manuais

| Comportamento | Por que é manual | Instruções |
|---|---|---|
| Criar o app no Bling com os escopos da D-03 | Interface do Bling, exige permissão "Cadastro de aplicativos" | Central de Extensões > Área do Integrador > Criar aplicativo; registrar Client ID/Secret no `.env` |
| Autorização real pela landing | **Não existe sandbox** no Bling | Gerar o link, abrir, autorizar na conta de teste, conferir CNPJ e status "conectado" |
| As 8 medições da §5 do RESEARCH | Dependem da conta real | Registrar cada resultado; divergência da suposição vira correção |
| Teto de 10 contas / homologação | Revisão humana do Bling, sem prazo | Abrir item próprio; a fase só **declara** o teto |
| `.env` de produção | Só o usuário tem acesso | Sem as credenciais o fluxo degrada com mensagem clara, **nunca** 500 |

---
quick_id: 260824-mv3
slug: rubrica-automatica
date: 2026-08-24
status: in-progress
---

# O sistema configura a rubrica sozinho, em todo envelope

## O problema que isto resolve

O Administrativo marcava **"Posicionar campos de assinatura e rubrica"** no rascunho da Clicksign
e o posicionamento **não salvava**. Cada tentativa custava um rascunho descartado.

## Diagnóstico (2026-08-24) — confirmado por contraste

| caminho | posicionar pela UI |
|---|---|
| envelope criado **manualmente** na UI, PDF subido à mão | **funciona** |
| envelope criado **pela nossa API** | **não salva** |

Descartados por medição:

- **Não é conta/plano/permissão** — pela UI manual funciona.
- **Não é o modelo** — o usuário abriu `Modelos → contrato-gestao-novo → Editar modelo` e o editor
  não tem etapa de posicionamento (é só editor de texto).
- **Não é nada que a gente mande** — o payload de `criarEnvelope()` leva só `name`,
  `deadline_at` e `remind_interval`; o envelope nasce rascunho (`ativar: false`).

Nenhuma documentação da Clicksign declara essa limitação, mas o contraste acima é inequívoco.

## O que a API oferece (referência oficial)

[`criar-requisito-de-rubrica`](https://developers.clicksign.com/reference/criar-requisito-de-rubrica):

- `POST /envelopes/{envelopeId}/requirements`
- `action: "rubricate"` (obrigatório)
- `pages`: `"all"` ou números separados por vírgula
- `kind`: `"initials"` | `"manuscript"`
- `rubric_field`: tag de posicionamento (sintaxe **não publicada**)
- relacionamentos obrigatórios: `document` e `signer`

⚠️ **Posicionar a ASSINATURA por coordenada não existe na API** — só a rubrica. Decisão do
usuário (2026-08-24): rubrica automática basta; a assinatura fica onde a Clicksign põe por padrão,
como nos contratos que eles já assinaram antes.

## Tarefa 1 — método novo no client

`ClicksignClient`: método irmão de `criarRequisitoQualificacao()` / `criarRequisitoAutenticacao()`,
para o requisito de rubrica. Mesma disciplina dos vizinhos: monta o payload, delega ao
`criarRequisito()` privado, aceita o `$contexto` para a mensagem de erro.

Valores como constantes de classe com comentário pt-BR (não literais soltos):

- páginas: `"all"` — rubrica em todas as páginas
- tipo: `"initials"`

## Tarefa 2 — plugar na montagem do envelope

Em `montarEnvelopeComum()`, dentro do laço de signatários, **depois** do requisito de
autenticação: mais um passo, com o mesmo padrão de `$passoAtual = "..."` que os vizinhos usam
(essa string vai na mensagem de erro e é o que o operador lê).

**Falha na rubrica é fatal**, como os demais passos: cai no `catch`, dispara o rollback D-12
(`cancelarEnvelope()`) e propaga a exceção ORIGINAL. Um contrato sem a rubrica que o jurídico
espera é pior que um contrato que não foi criado.

⚠️ **Orçamento de chamadas** (o docblock da classe cita ~15 de 20 chamadas/min por envelope):
hoje são **8** por envelope (1 criar + 1 anexar + 2 signatários × 3). Passa a **10**. Continua
dentro da margem — mas **conferir o docblock da classe** e atualizar o número se ele estiver
escrito lá.

## Tarefa 3 — testes

Os testes de `montarEnvelope*` fixam a **sequência** de chamadas HTTP; um passo novo por
signatário muda essa sequência. Ajustar.

Cobrir:

- a rubrica é criada **para cada signatário**, com `action: rubricate`, `pages: all`,
  `kind: initials`, e os relacionamentos de `document` e `signer` certos
- falha na rubrica → rollback (`cancelarEnvelope()` chamado) e a exceção **original** propagada,
  não a do cancelamento
- regressão zero na ordem dos passos anteriores

## Fora de escopo

- `rubric_field` / posicionamento por tag — sintaxe não publicada, não inventar.
- Posicionamento de assinatura por coordenada — não existe na API.
- Tornar páginas/tipo configuráveis por serviço — não foi pedido; constante documentada basta.
- Contratos **já gerados** não ganham rubrica retroativa: o envelope carrega os requisitos do
  momento da criação. Para valer, apagar o rascunho e gerar de novo.

## Gate

`C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` verde.

## Restrições operacionais

- ⚠️ **Árvore compartilhada** (outro dev ativo): nunca `git add -A` / `git add .` /
  `git commit -a` / `git stash`. Commitar só os próprios paths.
- ⚠️ **Antes de commitar:** `git status --porcelain app/ tests/ resources/ database/` **sem**
  `--untracked-files=no`, e conferir os `??`. `tests/Feature/CompanyPortfolioAccessTest.php`
  **não é seu**.
- ⛔ **Não fazer deploy.** Não mexer em `.env`, VPS, Clicksign, `servicos.clicksign_template_id`.
- Sem mudança de JSX → não precisa `npm run build`. Comentários em pt-BR. Commits atômicos.

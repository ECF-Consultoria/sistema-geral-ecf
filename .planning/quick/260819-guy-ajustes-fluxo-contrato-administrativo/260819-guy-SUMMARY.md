---
quick_id: 260819-guy
slug: ajustes-fluxo-contrato-administrativo
created: 2026-08-19
completed: 2026-08-19
status: complete
---

# Ajustes no fluxo de contrato do Administrativo — SUMMARY

**Origem:** teste ponta-a-ponta do fluxo completo (HubSpot → Administrativo → Clicksign), rodado
em 2026-08-19 com os setores responsáveis. Feedback do usuário (5 itens) + 4 defeitos de UX
diagnosticados na mesma sessão.

## ⚠️ Consequência de produto — LEIA ANTES DE DEPLOYAR

Os quatro campos novos entraram como **obrigatórios** (decisão explícita do usuário, via
AskUserQuestion em 2026-08-19). Isso significa que **nenhuma empresa gera contrato até o
Administrativo preencher razão social, endereço, data da 1ª parcela e dia do vencimento das
demais** — inclusive as que hoje já estavam prontas.

O efeito mais importante é no disparo **automático**: o gatilho via Comercial e via webhook do
HubSpot passa a ficar sempre pendente do Administrativo. Três suítes foram ajustadas para medir
a **fiação do gate** em vez da geração automática — `GatilhoContratoComercialTest`,
`GatilhoContratoHubspotTest` e `ReavaliacaoAutomaticaTest`.

**Isso é decisão de produto, não bug.** Quem for deployar precisa saber que, no dia seguinte, a
fila de contratos para gerar vai parecer travada até alguém preencher os campos novos das
empresas em andamento.

## O que foi feito

### Descoberta que encurtou o trabalho

O modelo `.docx` da Clicksign **já tinha as quatro variáveis** (`razao_social`, `endereco`,
`data_primeira_parcela`, `dia_vencimento`) em `ContratoVariaveisModeloService::mapa()`. Saíam
`"A DEFINIR"` porque o job nunca passava `$complementos` e `data_primeira_parcela` era
`PLACEHOLDER` fixo, com o comentário *"território da Fase 131 (ADM-01)"* — trabalho já previsto
e adiado. Nenhuma mudança no `.docx` foi necessária, nenhuma variável foi renomeada (T-126-38).

### Commits

| Tarefa | Commit | O que entrou |
|---|---|---|
| 1 | `0f963524` | Migration das 4 colunas, **nullable** — a obrigatoriedade é na validação, não no schema, para não quebrar linha existente |
| 2 | `61afd807` | Campos na tela + validação no servidor + persistência + props |
| 3 | `b967b79a` | Os 4 campos entram em `ContratoDadosMinimosService::faltantes()` (regras 4, 5, 7b, 7c) |
| 4 | `1b6a7bb2` | `App\Support\Cnpj` (módulo 11, puro) + `App\Rules\CnpjValido` |
| 5 | `b9d5b88c` | `$complementos` fluindo do job até o documento |
| 6 | `573f3234` | Filename da Clicksign vira slug ASCII de razão social + serviço |
| 7 | (este) | Os quatro defeitos de UX da tela de contrato |

### Decisões tomadas durante a execução

**`dia_vencimento` é dia do mês, não data.** "Data de vencimento das demais parcelas" não é uma
data única num contrato mensal recorrente — e é exatamente o que o placeholder `dia_vencimento`
do `.docx` já esperava. Registrado em comentário na migration.

**`data_primeira_parcela` e `dia_vencimento` entram no `servicos_snapshot` na hora de CRIAR o
snapshot**, não na leitura — disciplina D-04 preservada (`ContratoClicksignService`). `endereco`
é lido ao vivo da empresa. `ContratoVariaveisModeloService` continua **puro** (T-126-40).

**`razao_social` tem fallback para `company->name`** — empresa sem razão social preenchida ainda
gera documento com o nome que existe, em vez de "A DEFINIR".

**"já tentou antes" é derivado no backend.** A linha mais antiga por serviço é a primeira
tentativa; qualquer outra do mesmo serviço já é tentativa seguinte. Substituiu o `useState({})`
que zerava a cada reload.

**Fixtures ajustadas em ~10 arquivos de teste** das fases 127/128/131/132 que dependiam da
antiga definição de "empresa completa", e CNPJs de checksum inválido corrigidos onde a validação
nova passou a exercitá-los.

### Os quatro defeitos de UX (Tarefa 7)

1. **`erro_mensagem` agora é prop.** O texto exato da recusa da Clicksign (caso real:
   `"[Clicksign] name não está em um formato válido"`) chega na tela. Já passava por `podarPii()`
   antes de gravar; a tela exibe cru, sem reprocessar.
2. **`abort(422)` virou `back()->with('error', ...)`**, igual ao ramo de emissão congelada dez
   linhas acima. A checagem no servidor continua igual (o `disabled` do client não é controle,
   T-131-04-03) — só a apresentação mudou, de página branca do Symfony para faixa dentro da tela.
3. **A tela conta que falta enviar pelo painel da Clicksign**, com link (`painel_clicksign_url`,
   prop que já existia). O rótulo de `rascunho` passou de **"Não enviado"** para **"Falta
   enviar"** — descreve a ação pendente, não uma falha. O sistema para no rascunho de propósito
   (D-02 da Fase 127-05, `ativar: false`). O mapa de `contratoStatus.js` continua com
   **exatamente 7 chaves**.
4. **`App\Rules\NomeCompletoValido`** — nome do signatário precisa de pelo menos duas palavras.
   Antes, `"teste"` só falhava ~6 minutos depois, num 400 da Clicksign.

## Testes

- Suítes afetadas (Phase126/127/131/133 + Cnpj + NomeCompleto): **318 testes, 1077 asserções, verde**
- Suíte completa após a Tarefa 5: **533 testes, 1767 asserções, verde**
- `npm run build` limpo

As "PHPUnit Deprecations" reportadas são do próprio framework, pré-existentes e não relacionadas
a este trabalho.

## Fora de escopo — continua aberto

⛔ **`260819-clicksign-erro-salvar-posicionamentos`** — o erro *"Ocorreu um erro ao salvar os
posicionamentos!"* no painel da Clicksign. Acontece dentro da UI deles, num fluxo que não passa
pela nossa API. Precisa de teste controlado em sandbox (4 hipóteses e um roteiro de isolamento
estão no todo) antes de qualquer mudança de código. Se nenhuma variação nossa reproduzir a
diferença, é chamado com o suporte da Clicksign — temos `envelope_id` e horário.

## Não deployado

Nada foi para produção. As migrations rodaram só no banco local. O deploy publica o trabalho de
todas as sessões que compartilham a árvore e precisa de autorização explícita.

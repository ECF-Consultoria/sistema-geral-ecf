# Phase 126: Client Clicksign + PDF do contrato - Discussion Log

> **Somente trilha de auditoria.** Não usar como entrada para agentes de planejamento, pesquisa ou
> execução — eles leem o `126-CONTEXT.md`. Este log preserva as alternativas consideradas e o que
> foi recusado.

**Data:** 2026-08-10
**Fase:** 126-client-clicksign-pdf-do-contrato-v22-0
**Áreas discutidas:** texto jurídico · congelamento · testes do client · origem dos dados ·
coluna de PDF · autenticação do signatário · signatários da ECF · ordem de assinatura ·
erro e retry · storage · envelope pela metade · prazo e lembrete · origem da chamada ·
conteúdo do PDF

---

## Onde mora o texto jurídico

| Opção | Selecionada |
|--------|----------|
| View Blade separada (recomendada) | ✓ |
| No banco, editável por tela | |
| Arquivo de config | |

**Notas:** o banco daria autonomia ao Administrativo, mas exigiria tela (escopo da 131) e tiraria
a revisão por diff. Config foi recusada porque texto jurídico longo em array PHP é ruim de revisar
e a formatação vira concatenação de HTML.

## Contrato assinado × texto que mudou

| Opção | Selecionada |
|--------|----------|
| Guardar o PDF gerado em disco (recomendada) | ✓ |
| Versionar o texto e re-renderizar | |
| Sempre o texto atual | |

**Notas:** re-renderizar exigiria garantir saída byte-idêntica, o que raramente se sustenta.
"Sempre o texto atual" foi recusada por mostrar ao cliente cláusulas que ele nunca assinou.

## Como testar o client

| Opção | Selecionada |
|--------|----------|
| `Http::fake()` com fixtures copiadas literalmente do sandbox (recomendada) | ✓ |
| Só sandbox real | |
| Fake + smoke manual | |

**Notas:** o argumento decisivo foi um incidente do projeto — no HubSpot, um mock inventado usava
`toObjectId` e passava verde enquanto a API real devolvia `id`, zerando empresa em produção. Fixture
copiada da resposta real remove essa classe de erro.

## De onde saem serviços e valores

| Opção | Selecionada |
|--------|----------|
| Do `servicos_snapshot` da Fase 125 (recomendada) | ✓ |
| Ao vivo de `contratos_servico` | |
| Ao vivo + grava snapshot na geração | |

**Notas:** a terceira opção foi recusada por escopo — quem grava o snapshot é a Fase 127.

## Coluna para o caminho do PDF

Levantado pelo orquestrador, não pelo usuário: a tabela criada na Fase 125 não tem onde anotar o
caminho do arquivo, e as decisões acima exigem isso.

| Opção | Selecionada |
|--------|----------|
| Migration nesta fase (recomendada) | ✓ |
| Deixar para a Fase 129 | |
| Sem coluna, caminho por convenção | |

## Como o signatário se autentica

| Opção | Selecionada |
|--------|----------|
| Só e-mail (recomendada) | ✓ |
| E-mail + CPF validado na Receita | |
| E-mail + selfie/biometria | |
| Configurável por contrato | |

**Notas:** CPF validado exigiria coletar CPF antes, dado que a empresa não traz do Comercial.
Biometria tem atrito alto e possível custo por assinatura.

## Quem assina pela ECF

**Resposta em texto livre do usuário:** *"Normalmente são 3 pessoas que assina pela ECF os dois
donos Emerson e Thiago e o Comercial Jessica"*.

Isso não estava previsto em nenhuma opção — o orquestrador tinha oferecido "pessoa fixa em config",
"quem gerou o contrato" e "escolhido na hora", todas assumindo **um** signatário interno. A
resposta mudou o desenho e abriu três perguntas de acompanhamento:

| Pergunta | Resposta |
|---|---|
| As três assinam sempre? | **Sim, as três sempre** (recusadas: depender de tipo/valor; só um dono + Jessica) |
| Papéis | **Donos = `contratada`, Jessica = `testemunha`** (recusadas: as três como contratada; adiar para o jurídico) |
| Ordem | **Todos ao mesmo tempo** (recusadas: ECF primeiro; cliente primeiro) |

**Consequência:** todo contrato tem 4 assinaturas, o que levou à contabilidade das 15 chamadas por
envelope registrada no CONTEXT.

## Erro, retry e rate limit

| Pergunta | Resposta |
|---|---|
| Erro | **`ClicksignException` própria** (recusadas: objeto de resultado; exceção só em 5xx) |
| Retry | **Só 429 e 5xx**, com espera crescente (recusadas: nunca retentar; deixar para a fila) |

## Envelope montado pela metade

| Opção | Selecionada |
|--------|----------|
| Cancela o que criou (recomendada) | ✓ |
| Deixa e guarda o id para retomar | |
| Deixa e ignora | |

**Notas:** esta decisão responde o **A2** do `REQUIREMENTS-v22.md`, que estava listado como
pergunta em aberto da Fase 127.

## Prazo e lembrete

| Opção | Selecionada |
|--------|----------|
| 30 dias, lembrete a cada 3 — enviados explicitamente (recomendada) | ✓ |
| Prazo mais curto | |
| Claude decide | |

## De onde o client é chamado

| Opção | Selecionada |
|--------|----------|
| Job de fila (recomendada) | ✓ |
| Síncrono na request | |
| Client agnóstico, a 127 decide | |

## Onde o PDF fica guardado

| Opção | Selecionada |
|--------|----------|
| `storage/app/` privado (recomendada) | ✓ |
| S3 | |
| Claude decide | |

**Notas:** S3 recusado porque as chaves AWS estão vazias hoje — viraria dependência externa nova
numa fase de fundação.

## O que mais vai no PDF

| Opção | Selecionada |
|--------|----------|
| Vigência e pagamento | ✓ |
| Foro e dados jurídicos | |
| Logo e identidade | |
| Só o mínimo do critério | |

**Notas:** conferido depois contra o schema — vigência existe (`contratos_servico.data_contratacao`
/ `data_vencimento`), mas **dia de vencimento e forma de pagamento não existem em lugar nenhum**.
Registrado no CONTEXT como `<tensao_de_dados>` para o planner resolver.

## Claude's Discretion

- Nomes de classe, método e arquivo; estrutura das fixtures; formato do `ClicksignException`
- Implementação do mapa D-08 → vocabulário da API (`sign`/`party`/`contractor`)
- Layout e CSS do PDF, reusando o precedente do `RelatorioMensalPdfService`
- Caminho relativo × chave de Storage no `pdf_path`

## Achados do orquestrador durante a conversa

1. **`Http::withToken()` é armadilha do próprio molde** — `HubspotApiClient` usa em todos os
   métodos, e é exatamente o que quebra na Clicksign (401 medido).
2. **15 chamadas por envelope contra rate limit de 20** — um contrato consome 3/4 da janela.
3. **Faltava coluna de caminho de PDF** na tabela criada pela Fase 125.
4. **Metade dos campos de "vigência e pagamento" não existe** no banco hoje.

## Deferred Ideas

- Foro, qualificação das partes e endereço → texto padrão no Blade por ora
- Logo e numeração de página no PDF
- Prazo configurável por contrato → DADOS-06, Fase 127
- Podar PII da resposta bruta antes de gravar em `erro_mensagem` → Fase 127 (WR-11 da 125)
- Método de autenticação configurável → sem caso concreto hoje

## Todos revisados e não dobrados

`todo.match-phase 126` devolveu 7 candidatos, todos 0.4–0.6 por palavra genérica ("phase", "por",
"fase"). Nenhum trata de contrato, Clicksign ou PDF. Não apresentados ao usuário, para não gastar
tempo com ruído de keyword.

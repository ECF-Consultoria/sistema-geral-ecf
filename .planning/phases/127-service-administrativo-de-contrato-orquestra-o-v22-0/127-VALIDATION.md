---
phase: 127
slug: service-administrativo-de-contrato-orquestra-o-v22-0
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-08-12
---

# Fase 127 — Estratégia de Validação

> Contrato de validação da fase: com que frequência amostrar o feedback durante a execução.
> Derivado da seção `## Validation Architecture` de `127-RESEARCH.md`, **com duas correções**
> registradas em "Divergências" no fim.

**A particularidade desta fase:** o artefato final — um contrato jurídico preenchido — é produzido
por um **terceiro** (a Clicksign) a partir de um arquivo que não versionamos. Isso muda a natureza
da prova: `Http::fake()` consegue provar **o que decidimos enviar**, nunca **o que a Clicksign
aceita**. As duas coisas divergiram 4 vezes na Fase 126, sempre em favor da segunda.

## Régua por bloco

| Bloco | Como se prova | Onde |
|---|---|---|
| Schema da D-06 | migration roda nos dois drivers; índice composto exercitado por insert cru | 127-01 |
| Recusa por dado mínimo | unit puro, sem I/O — a prova é a **ausência** de chamada HTTP | 127-03 |
| Payload do envelope | `Http::fake()` + asserção sobre o corpo enviado | 127-02, 127-05 |
| Espaçamento da fila | unit sobre `middleware()` do job, não teste de tempo real | 127-05 |
| Idempotência | força `QueryException` 23000 de verdade, não mock | 127-06 |
| **O que só a API real responde** | medição contra o sandbox, com gate humano | **127-07** |

## Requisitos → Teste

| Req | Comportamento | Tipo | Plano |
|---|---|---|---|
| REDE-05 | Recusa sem chamar a Clicksign quando falta e-mail/CNPJ/nome/data | unit | 127-03 |
| CLICK-02 | Envelope com documento + signatários + requisitos, **sem ativar** | feature (`Http::fake`) | 127-02, 127-05 |
| CLICK-08 | `remind_interval` presente **na criação** do envelope | feature (asserta payload) | 127-04, 127-05 |
| DADOS-06 | Prazo customizado por contrato refletido no envelope | feature | 127-01, 127-04, 127-05 |
| SC-5 | 2ª chamada não cria 2º envelope | feature (`QueryException` 23000) | 127-06 |
| D-01 | `RateLimited` + `WithoutOverlapping` aplicados | unit (`middleware()`) | 127-05 |

## Frequência de amostragem

- **Por task:** `"C:\xampp\php\php.exe" artisan test --filter=<Classe>`
- **Por wave:** `artisan test tests/Feature/Phase125 tests/Feature/Phase126 tests/Feature/Phase127`
- **Gate de fase:** suíte completa verde + os 3 gates do 127-07

**Baseline a não regredir:** `Phase125` + `Phase126` = **147 verdes**.
⚠️ Diferente da Fase 126, aqui **não há queda esperada** — nenhum código é removido nesta fase. Se o
número cair, é regressão de verdade.

## Wave 0 — o que não existe ainda

- [ ] `tests/Feature/Phase127/MigrationsFase127ConvencoesTest.php` — guarda estática de schema
- [ ] `tests/Feature/Phase127/ContratoAssinaturaServicoTest.php` — chave composta da D-06
- [ ] Ajuste dos **2 testes da Fase 125** que a D-06 quebra por construção (Task 4 do 127-01)
- [ ] Teste do `$ativar = false` (D-02) e do rollback preservado (D-04)
- [ ] `ContratoDadosMinimosService` — a recusa do REDE-05
- [ ] `GerarContratoAssinaturaJobTest` — payload, prazo, lembrete, middlewares
- [ ] Idempotência por `QueryException` 23000

## Divergências em relação ao RESEARCH.md

**1. Os arquivos vão para `tests/Feature/Phase127/`, não para a raiz de `tests/`.**
O RESEARCH propôs `tests/Unit/DadosMinimosContratoTest.php` e `tests/Feature/GerarContrato...`. A
convenção deste projeto é agrupar por fase (`tests/Feature/Phase125/`, `Phase126/`, …) e é o que
permite rodar a baseline por fase. Seguir a convenção, não o RESEARCH.

**2. O RESEARCH listou a migration da D-06 como condicional** ("se a Opção A for confirmada"). **Foi
confirmada pelo usuário** e virou a D-06 do CONTEXT — não é mais hipótese.

## O que NENHUM teste automatizado prova nesta fase

Vai para o gate humano do **127-07**, e não pode ser marcado como fechado sem medição:

1. Que o **prazo definido na criação sobrevive** a uma ativação feita pela interface da Clicksign
   (a sonda do discuss deu 422 porque o envelope estava vazio — limitação do teste, não da API).
2. Que a **conta de produção** enxerga o modelo — ela **nunca foi consultada**, nem uma vez. Exige
   autorização explícita do usuário.
3. Que as variáveis do modelo **de produção** batem com as que o código emite — o modelo cadastrado
   lá é a versão sem os nomes no rodapé.

⚠️ **Por que isso não é preciosismo:** a §10.5 do empírico mediu que **variável faltando vira campo
em branco, sem erro nenhum**. Não existe resposta HTTP que denuncie um contrato incompleto. O
confronto de variáveis é a única rede, e ele é humano.

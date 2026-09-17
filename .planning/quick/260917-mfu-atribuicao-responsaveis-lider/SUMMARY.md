---
quick_id: 260917-mfu
slug: atribuicao-responsaveis-lider
date: 2026-09-17
status: complete
commit: 84ac5e36, a12f921c
deployed: true
---

# Quick 260917-mfu — Atribuição de responsáveis: o 403 do líder e a troca que não valia

## O que estava quebrado

**Bug 1 — "acesso negado".** `PUT /companies/{company}` e
`POST /companies/bulk-assign` estavam dentro do grupo `role:admin` de
`routes/web.php`. O botão de lápis em `Companies/Index.jsx` era renderizado
**sem condição nenhuma**, então o líder do setor Performance — e também o
analista e o estrategista, que alcançam a tela por `permission:core.empresas`
com a visão da própria carteira — viam o botão, preenchiam o modal inteiro e
levavam 403 no salvar.

**Bug 2 — "salvou" e não salvou.** Escrita e leitura tinham escopos diferentes:

- leem (`Company::analistaPerformance()` / `estrategistaPerformance()`):
  `servico_id` de serviço `setor='performance'` **OU** `servico_id NULL`;
- escreviam (`CompanyController::update()` / `bulkAssign()`): apagavam só o
  `servico_id` devolvido por `servicoPerformanceAtivoId()` — `MIN()` dos
  contratos performance ativos.

Com uma linha legada `servico_id NULL` e um contrato Gestão ativo, o delete
mirava `servico_id = 6` (que não existia), a linha NULL sobrevivia e o attach
criava uma segunda. A leitura enxergava as duas e `->first()`, sem `ORDER BY`,
devolvia a mais antiga.

Medido na empresa **251 (ALCOMERCIOEIMPORTACAO)** — a edição do próprio usuário
naquele dia:

| id  | role         | user          | servico_id | created_at       |
|-----|--------------|---------------|-----------:|------------------|
| 220 | consultor    | Danilo (15)   |       NULL | 2026-06-11 18:17 |
| 500 | consultor    | Gustavo (16)  |          6 | 2026-09-17 15:54 |
| 221 | estrategista | Nathalia (11) |       NULL | 2026-06-11 18:17 |
| 501 | estrategista | Nathalia (11) |          6 | 2026-09-17 15:54 |

A troca Danilo → Gustavo **estava gravada** (linha 500) e invisível atrás da 220.

## O que mudou

- `routes/web.php` — `companies.update` e `companies.bulk-assign` saem do
  `role:admin` (mesmo padrão `withoutMiddleware` já usado em
  `companies.portal.abrir`), com `permission:core.empresas` como primeira
  barreira. `companies.destroy`, `companies.ativar` e `bulk-destroy` **seguem
  admin-only**, de propósito.
- `CompanyController::podeGerirEmpresa()` — admin **ou** líder do setor
  Performance. É o mesmo predicado que já montava `pode_distribuir`, agora
  usado pelos dois (`abort_unless` no topo de `update()` e `bulkAssign()`).
- `CompanyController::limparSlotPerformance()` — apaga o slot performance
  **inteiro** (serviço de setor performance **OU** `servico_id NULL`),
  espelhando linha por linha o que a leitura enxerga. Shopee e os demais
  setores continuam intocados (o que a Fase 76 / DEC-A3 protegia).
- `update()` — a troca de responsáveis roda sempre que o formulário traz os
  campos, mesmo vazios. Antes, `if (!empty($sync))` fazia de "limpar os dois
  responsáveis" um no-op silencioso.
- `Companies/Index.jsx` — prop `pode_editar_empresa` gateia o lápis; o
  excluir/reativar passa a seguir `isAdmin` (variável que já existia e estava
  sem uso).

## Prova

`tests/Feature/Quick260917Mfu/AtribuicaoResponsaveisTest.php` — 8 testes.

Rodados **contra o HEAD anterior** (arquivos revertidos, suíte mantida) para
provar que pegam os bugs: 5 falham, com exatamente os dois sintomas —
`actual size 2 matches expected size 1` (a linha NULL sobrevivente) e
`403 is identical to 302` (o líder). Com o fix: **8/8 OK**.

Regressão: `V16 + Phase150 + Phase154 + Phase157 + Phase75` → 303 testes,
**as mesmas 8 falhas antes e depois** (medido revertendo os arquivos): 5 de
desempenho/elegibilidade (V16) e 3 de MLB que morrem em
`[MLB Coleta] Falha ao obter app token: HTTP 400` — rede, não código.
`V16/AtribuicaoPorServicoIsolamentoTest` (o guarda do isolamento ML×Shopee da
Fase 76) segue verde.

`npm run build` OK.

## Pendências

- **Deploy não executado** (CLAUDE.md exige autorização explícita). Há 2
  commits de outra sessão (`quick-260917-jol`, Shopee) à frente do
  `origin/main` — o push/deploy publica os três juntos.
- **Empresa 251**: as linhas 220 e 221 continuam lá. Depois do deploy, **basta
  reabrir a empresa e salvar** que o próprio fix as remove — não é preciso
  mexer no banco à mão.
- As outras três empresas com linha `servico_id NULL` (184, 188, 189) têm uma
  linha só por papel: aparecem certo hoje e não precisam de nada.

## Bug 3 — a distribuição gravava um papel que ninguém lê (corrigido a pedido)

`DistribuicaoService` (Fases 154/157) gravava `company_users.role = 'analista'`
— valor que o enum da coluna aceita e que **nenhum leitor consulta**:
`/companies`, a carteira, o bônus e o NPS leem `'consultor'`. A empresa
distribuída pela aba do líder saía da fila e nascia, em toda a operação, como
empresa **sem analista**. A invariante está escrita em
`RelatorioImpactoFonteDesempenho` ("a pivot nunca grava 'analista'").

A raiz: **uma constante servindo a duas coisas diferentes** — o slug do cargo
(quem PODE ser escolhido, em `elegiveis()`) e o papel da pivot (o que fica
gravado, em `vincular()`). Agora são `CARGO_*` e `ROLE_*`.

`LEGADO_ROLE_ANALISTA = 'analista'` entra nas LEITURAS — `fila()`,
`TimelineEntradaService`, `OnboardingEngineService::empresaTemResponsavelOperacional()`
e `FluxoReconciliarOnboarding::donosDistribuidos()` — para que qualquer linha
gravada errado continue reconhecida: empresa já distribuída não pode voltar
para a fila. Em produção **não havia nenhuma linha `'analista'`**: o bug estava
adormecido e nada precisou ser reparado no banco.

`AbaDistribuicaoTest::test_empresa_distribuida_aparece_com_analista_na_listagem`
fecha o circuito pela relação que a tela usa de fato. A fixture de papel legado
em `DistribuicaoServiceTest` ficou como estava, de propósito — passou a ser ela
que exercita o ramo de compatibilidade.

`Phase154 + Phase157 + Onboarding`: 179/179 OK.


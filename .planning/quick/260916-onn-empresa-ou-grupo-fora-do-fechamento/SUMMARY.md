---
quick_id: 260916-onn
slug: empresa-ou-grupo-fora-do-fechamento
date: 2026-09-16
status: complete
commits:
  - 44a0a6e4
  - 40edc7ed
---

# Quick 260916-onn — SUMMARY

Empresa ou grupo marcado como "não participa do fechamento" sai do fechamento, caso a caso.

> O executor não conseguiu gravar este arquivo (a ferramenta recusou `.md` em subagente). O
> orquestrador gravou a partir do relatório final, depois de conferir os commits, a migration e os
> dois gates (508 e 39 passando, exit 0).

## O que mudou

**T1 — marcação** (`44a0a6e4`)
- Migration `2026_09_16_180000_add_fora_do_fechamento_to_companies_and_company_groups.php`, em
  `companies` e `company_groups`: `fora_do_fechamento` (boolean, default false),
  `fora_do_fechamento_motivo` (text, nullable), `fora_do_fechamento_por`
  (`foreignId->nullable()->constrained('users')->nullOnDelete()`), `fora_do_fechamento_em`
  (timestamp, nullable). Sem índice nomeado à mão. Roda em SQLite (testado).
- `Company`: fillable/casts; `fora_do_fechamento` e o motivo no `logOnly`.
- `CompanyGroup`: fillable/casts; trilha gravada à mão no controller (`log_name = fora_do_fechamento`).
- `CompanyGatilhoContratoObserver::CAMPOS_GATILHO` intocado — teste trava isso.

**T2 — regra num lugar só** (`44a0a6e4`)
- `FechamentoEmpresasDoMes::separar()` olha a marcação **antes** da data de início e devolve uma
  quarta lista, `fora_por_decisao` (`company`, `origem` empresa/grupo, `grupo_id`, `grupo_nome`,
  `motivo`).
- Sai: empresa marcada; empresa com o grupo marcado; empresa cujo grupo de cobrança acima (um nível,
  Fase 143) está marcado. Marcação da empresa vence a do grupo.
- Marcação lida em lote direto das tabelas (até 3 consultas por chamada) — a tela carrega o grupo com
  colunas selecionadas e a regra falharia sem avisar se dependesse da relação carregada.
- `situacao()` (data de início) idêntica. Empresa marcada e com início depois do mês aparece uma vez,
  por decisão. `$idsSempreDentro` vale também para a marcação: mês fechado mantém quem está gravado até
  ser refeito.
- `fechamento:consolidar-mes` imprime linha própria:
  `[Fechamento] Fora do fechamento por decisão: N empresa(s) — Nome (#id, motivo); Nome (#id, grupo X: motivo).`
  Sem ninguém marcado: `0 empresa(s).`
- Grupo que perde todas as empresas some ao refazer (writer intocado, só testado).

**T3 — telas** (`40edc7ed`)
- `ForaDoFechamentoController` (novo): marcar/desmarcar empresa e grupo; motivo obrigatório para marcar
  (10–1000 caracteres, 422 em pt-BR); `_por`/`_em` da sessão, corpo só aceita o motivo; permissão
  `admin.contratos` reconferida no controller (a ficha também abre para quem só tem a de Entrada).
- Rotas (grupo `permission:admin.contratos`):
  - `POST|DELETE /administrativo/contratos/empresa/{company}/fora-do-fechamento`
    (`admin.contratos.fora-fechamento.empresa.marcar|desmarcar`)
  - `POST|DELETE /administrativo/contratos/grupos/{grupo}/fora-do-fechamento`
    (`admin.contratos.fora-fechamento.grupo.marcar|desmarcar`)
- **Porta da empresa:** bloco "Participação no fechamento" em `ContratoDetalhe.jsx`, abaixo de "Tabela
  de cobrança". Prop `fora_do_fechamento` (`null` para quem não tem a permissão). Quando é o grupo que
  tira a empresa, o bloco avisa e leva à tela de grupos.
- **Porta do grupo:** `GruposCobranca.jsx` (escolhida no lugar de `TabelaGrupo.jsx`), nos cartões de
  grupo de cobrança e nas linhas de grupos sozinhos. Grupo que está dentro de outro só mostra o aviso e
  "Voltar a participar".
- Componente compartilhado `resources/js/Components/Fechamento/ParticipacaoFechamento.jsx`.
- **Tela do fechamento** (`Financeiro.jsx`): prop de página `nao_participam_do_fechamento` alimenta a
  lista `NaoParticipamAviso` (abaixo de `SemDataInicioAviso`), com nome, motivo, empresas e link. Mostra
  as marcações **de hoje**; em mês fechado explica que quem já estava gravado só sai quando o mês for
  refeito. Nenhuma chave nova nos literais de linha.
- Marcar não apaga tabela — a tela de grupos segue mostrando a tabela e a cobrança do grupo marcado.

## Gates (exit capturado antes do pipe)

| filtro | antes | depois (executor) | reconferido (orquestrador) |
|---|---|---|---|
| `Phase137\|Phase140\|Phase142\|Phase143\|Quick260915\|Quick260916` | 484 (2209), 0 falhas | 508 (2366), 0 falhas | **508 (2366), exit 0** |
| `Phase74\|Phase110` | 39 (170) | 39 (170) | **39 (170), exit 0** |

+24 = testes novos (`ForaDoFechamentoRegraTest` 11, `ForaDoFechamentoTelaTest` 13). `npm run build`
passou; classes novas conferidas com `grep -F`. Resolver, writer, rollup e observers sem nenhuma linha
alterada (conferido por `git diff --stat`). NPS, desempenho, carteira e bônus não leem a marcação.

## Desvios do plano

1. Marcação lida em lote das tabelas, não das relações carregadas (motivo acima).
2. A lista da tela mostra as marcações de hoje, com a ressalva de mês fechado.
3. A porta do grupo só marca grupos de cima; grupo de dentro só pode ser desmarcado pela tela (a regra
   aceita os dois casos).
4. Descartado um teste extra (apagar usuário zera quem marcou): o SQLite dos testes não aplicou a FK.
   A migration mantém `nullable()->nullOnDelete()`.
5. A mensagem do primeiro commit saiu sem acentos.

## Estado em produção

**Nada deployado, nenhum dado alterado.** Depois do deploy: marcar a Rações Soldera (#253) e o grupo
Wenus (#8), e refazer julho e agosto uma vez só.

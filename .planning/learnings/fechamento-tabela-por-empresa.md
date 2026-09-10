# Fechamento — tabela progressiva por empresa/grupo, e a virada de 2026-09-09/10

Leitura obrigatória antes de tocar em **tabela progressiva**, **faixa de faturamento** ou
**mensalidade** do fechamento (Fases 137-141). Registra o que só existe porque alguém olhou o
fechamento em produção — a decisão de modelagem (Fase 141), a ordem de virada e os números reais
medidos na hora de ligar a chave.

---

## 1. A tabela do SERVIÇO deixou de ser régua — hoje ela é só semente

Até a Fase 141, `servico_faixas_faturamento` (Gestão, Gestão de ADS Shopee, Brigada) era a régua
que classificava **127 das 201 empresas** do fechamento — todo mundo herdava a tabela do serviço,
sem contrato nem cadastro que confirmasse nada. O usuário corrigiu essa premissa em 2026-09-09: a
tabela é **da empresa ou do grupo, nunca do serviço**. Motivo concreto — BARAOSHOP VARIEDADES
faturou R$ 488.262,90 em agosto, caiu na faixa 1 (R$ 3.000), e a tela cobrava R$ 5.500 porque a
fórmula antiga era "faixa + soma dos contratos mensais" (R$ 3.000 + R$ 2.500 do contrato Shopee).

`servico_faixas_faturamento` **não foi removida**. Ela virou duas coisas:

1. **Modelo de partida do cadastro** — quando alguém cadastra a tabela de uma empresa à mão, a
   tabela do serviço é o ponto de partida sugerido (mesmo espírito de faixa que o time já usava).
2. **Semente do comando `fechamento:materializar-tabelas`** — para toda empresa hoje classificada
   pela tabela do serviço (e só essas — quem já tem tabela própria de qualquer origem, ou é
   classificada por grupo, nunca é tocada), o comando copia essa mesma tabela para
   `empresa_faixas_faturamento`, com os **mesmos valores** (D-03 do 141-03: "nenhuma cobrança muda
   por causa dele"). É assim que a transição não esvazia o fechamento no dia da virada.

Se você está tentando entender por que uma tabela de empresa existe sem ninguém ter cadastrado
nada: é isso — ela nasceu da materialização, não de um humano digitando faixas.

## 2. `origem = 'presumida_servico'` nunca é "confirmada" — e por quê

`empresa_faixas_faturamento.origem` tem três valores (`EmpresaFaixaFaturamento::ORIGEM_MANUAL` /
`ORIGEM_CONTRATO` / `ORIGEM_PRESUMIDA_SERVICO`). Os dois primeiros são tabela que um humano
confirmou (cadastro manual, ou leitura de contrato assinado via `TabelasContratoController`). O
terceiro é o que a materialização grava — e ele é, por definição, uma **suposição**: é a tabela do
serviço copiada, não uma tabela que alguém olhou e confirmou pertencer àquela empresa.

`AdminController::fechamentoTabelaConfirmada()` trata isso — antes da Fase 141 ele devolvia `true`
para qualquer tabela `'propria'`, o que faria as tabelas materializadas aparecerem como
confirmadas na tela, reintroduzindo em silêncio o mesmo problema que a fase existe para resolver.
`TabelaPresumidaBadge`/`TabelaPresumidaAviso` (já existentes na tela) marcam visualmente qualquer
linha com essa origem. Ao escrever qualquer código novo que leia tabela de empresa: **nunca trate
`presumida_servico` como equivalente a `manual`/`contrato`** — é exatamente a distinção que motivou
a coluna existir.

Das 168 empresas materializadas na virada real (ver §4), **todas as 168** têm `origem =
'presumida_servico'` — nenhuma delas foi conferida contra o contrato real ainda. Isso é trabalho
pendente, não bug: a tela `/administrativo/contratos/tabelas` (Fase 140, 85 propostas lidas do
Clicksign — 49 com tabela, 29 de valor fixo) existe justamente para essa conferência.

## 3. A ordem é materializar → conferir → comparar → ligar. Inverter esvazia o fechamento

Ligar `fechamento_tabela_por_empresa_ativa` **antes** de materializar as tabelas presumidas faz o
`FechamentoFaixaResolver` parar de olhar a tabela do serviço (é literalmente o que a flag faz) sem
que nenhuma tabela própria exista ainda para 127+ empresas — elas caem direto em `sem_tabela`. Uma
rodada real em produção em 2026-09-09, feita **antes** da materialização (por engano de ordem, não
de propósito — servia para testar `fechamento:comparar-mensalidade`), mostrou exatamente esse
cenário: praticamente toda empresa caía. Não era defeito do comparador; era a ordem errada.

A sequência que funciona, na prática, com checkpoint humano em cada degrau (task 1 e 2 do
`141-07-PLAN.md`):

1. **Materializar** (`fechamento:materializar-tabelas`, dry-run primeiro, depois `--aplicar`) —
   cria a tabela própria presumida para quem hoje só tem a do serviço.
2. **Conferir por reconsulta ao banco** — nunca pela saída impressa do comando (disciplina já
   registrada em `desempenho-bonificacao.md` para o fechamento de bônus; vale igual aqui).
3. **Comparar** (`fechamento:comparar-mensalidade --mes=`) — ANTES × DEPOIS, empresa a empresa,
   feito **depois** da materialização, nunca antes.
4. **Decidir e ligar** — só depois de um humano olhar o delta.

## 4. Os números reais da virada (2026-09-09/10)

Deploy: commit `e98e25ed` em produção, 0 migrations pendentes, workers de pé.

**Materialização** (`fechamento:materializar-tabelas --aplicar`), exit 0:

```
materializar ......... 168
ja_tem_propria ....... 1
pelo_grupo ........... 0
continua_sem_tabela .. 32
gravadas ............. 168
falhas ............... 0
```

Conferido por reconsulta ao banco (nunca pela saída impressa): `empresa_faixas_faturamento` foi de
**7 linhas / 1 empresa** para **1.207 linhas / 169 empresas** — 1.200 linhas em 168 empresas com
`origem = 'presumida_servico'`, mais as 7 originais com `origem = 'manual'`, intactas.

⚠️ **A cobrança não mudou com a materialização.** A soma congelada de agosto seguiu em
R$ 460.500,00 com as mesmas 127 empresas — era o objetivo: criar o dado sem tocar em valor (§1).

**Comparativo ANTES × DEPOIS** (`fechamento:comparar-mensalidade --mes=2026-08`), rodado **depois**
da materialização:

```
Total a receber — ANTES R$ 2.486.700,91 · DEPOIS R$ 736.450,97 · diferença R$ -1.750.249,94
Sobem: 0 · Descem: 71 · Ficam iguais: 128 · Mudam de faixa: 0 · Ficariam SEM TABELA: 32
```

Maior queda isolada: o grupo Camillo Parts, de R$ 522.500,00 para R$ 12.000,00 — faixa 7 para
faixa 7, **sem mudança de faixa**. A queda de R$ 1,75 milhão veio quase toda de **parar de somar
contrato à faixa** (D-03 do 141-CONTEXT), não de empresa mudando de faixa — o próprio comparativo
confirma "Mudam de faixa: 0".

⚠️ **O usuário confirmou que essa queda está certa** (2026-09-09): *"Está certo essa queda no total
a receber, essa queda do exemplo que vc trouxe da camillo é esperada, o total a receber de antes da
camillo era exorbitante"*. Isso é a validação humana de que o **"antes" é que estava errado**, não
o depois — registrar sempre que alguém questionar a queda de R$ 1,75 milhão olhando só o número.

**A chave foi ligada** (`fechamento_tabela_por_empresa_ativa = '1'`), confirmada por reconsulta e
pelo serviço (`FechamentoRegraTabela::ativa()` devolveu `true`).

**Agosto foi refeito sob a regra nova**, a pedido explícito do usuário — *"está fechado mas fechado
do jeito errado, ou seja, não adianta nada, precisa está certo"* — com motivo registrado em
`fechamento:consolidar-mes --motivo=`. Exit 0:

```
201 linhas de empresa (podadas: 0) · 15 grupos (podados: 0) · RECONSOLIDADO
aviso de faixa: 19 empresa(s) / 0 grupo(s) / 8 notificação(ões)
```

Agosto depois do refazer, por reconsulta ao banco:

```
soma de valor_faixa ..... R$ 466.500,00   (era R$ 460.500,00)
empresas com faixa ...... 129             (eram 127)
estados: ok 129 · sem_integracao 69 · valor_fixo 2 · sem_faturamento 1
origem da tabela: própria 169 · sem tabela 32
```

Trilha: **4 reconsolidações** registradas, a última com o motivo da regra nova e
`snapshot_anterior` de **158.215 bytes** — o fechamento antigo está recuperável ali dentro, não
apagado.

⚠️ **A soma das FAIXAS subiu R$ 6.000** (R$ 460.500 → R$ 466.500) **enquanto o total a receber caiu
R$ 1,75 milhão. Não é contradição.** São duas contas diferentes: a soma de faixas é só quem tem
régua aplicada (129 empresas, quase as mesmas 127 de antes — as duas a mais ganharam faixa porque o
faturamento das duas plataformas passou a somar e cruzou um limiar novo); o total a receber inclui
todo mundo, e é ali que a soma de contrato-à-faixa deixou de acontecer.

**Gate de testes:** `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Quick260909"`
em **553 testes / 2642 asserções / 0 falhas**.

## 5. Como desligar de volta — sem deploy

O rollback da flag **não exige deploy**, porque o caminho antigo (tabela do serviço como régua,
`CobrancaCalculator::novo()` somando faixa + contratos) continua no código, só deixa de ser
alcançado enquanto a flag está ligada:

```
php artisan tinker --execute="App\Models\Configuracao::set('fechamento_tabela_por_empresa_ativa','0');"
```

Depois de desligar, `FechamentoFaixaResolver` volta a olhar a tabela do serviço para quem não tem
tabela própria, e `ConsolidarMesFechamento` volta a usar `novo()`. As tabelas de empresa
materializadas (`origem = 'presumida_servico'`) **não somem** — ficam gravadas, sem efeito enquanto
a flag estiver desligada, prontas para quando ligar de novo.

## 6. Competência congelada não muda sozinha com a virada

Ligar a flag muda o **cálculo ao vivo** (mês corrente e futuro) e a tela `/financeiro` desses
meses. Um mês já fechado lê o **snapshot congelado** (`fechamento_snapshots` /
`fechamento_grupo_snapshots`) — a mesma disciplina D-11 da Fase 137, registrada também em
`desempenho-bonificacao.md` para o snapshot de bônus. A virada de chave, sozinha, não reescreve
nenhum mês passado.

Se um mês fechado precisa passar a valer pela regra nova (foi o caso de agosto/2026 nesta virada,
§4), isso é **reconsolidação explícita**: `fechamento:consolidar-mes --mes=<AAAA-MM>
--motivo="<texto>"`. A trava de congelamento (D-12 da Fase 137) **exige** o `--motivo=` — sem ele o
comando recusa reconsolidar, mesmo com a flag ligada. Cada reconsolidação grava
`snapshot_anterior` (o estado antes de reconsolidar), então o fechamento anterior nunca é perdido,
só substituído com trilha.

## 7. A armadilha do gate de cobertura — `valor_fixo` fora do denominador

O fechamento tem um gate que recusa persistir se a cobertura de margem/faturamento cair abaixo de
um limiar (mesma família de disciplina do gate FIXMARG-03 registrado em
`desempenho-bonificacao.md`, mas este é do fechamento, não do bônus). A Fase 141-04 precisou
excluir `ESTADO_VALOR_FIXO` do denominador desse gate, junto com `ESTADO_SEM_INTEGRACAO` — sem essa
exclusão, o punhado de empresas sem plataforma elegível (Mentoria e afins, que sob a regra nova
ficam em `valor_fixo` por não teren tabela progressiva) derrubaria a cobertura abaixo do limiar e
recusaria o fechamento de **todo mundo**, efeito colateral puro de mudar a regra, não sinal de dado
faltando. Ao mexer nesse gate de novo: `valor_fixo` é estado **normal**, não pendência — não deve
voltar a contar no denominador.

## 8. Pendências abertas depois desta virada

- As **168 tabelas presumidas** continuam presumidas (§2) — aparecem marcadas na tela esperando
  conferência contra o contrato real. A tela `/administrativo/contratos/tabelas` tem 85 propostas
  lidas do Clicksign (49 com tabela, 29 de valor fixo) prontas para isso.
- As **32 empresas sem tabela** são, pela inspeção de nome, quase todas cadastro de teste ou
  incompleto ("Tobias teste", "teste23", "Empresa teste shopee", "JF assessoria", "aThshop") —
  poucas parecem cliente real, mas isso não foi confirmado empresa a empresa.
- **Três contratos com valor de R$ 250.000** (GENUINEAUTOMOTIVE, Lenonn Milani, CAMILLOPARTS FILIAL
  RS) — provável erro de cadastro, reportado ao usuário, não investigado nesta fase.

---
*Registrado em 2026-09-10, ao fechar o plano 141-07. Números medidos em produção pelo orquestrador
(subagente de execução não alcança produção — `plink`/`pscp`/`deploy.sh` bloqueados por desenho).*

# 140-03 — SUMMARY

**Plano:** 03 — Palpite de empresa, comando `clicksign:extrair-tabelas` e o relatório
**Status:** tarefas de código concluídas; **rodada real executada em produção** (2026-09-08)
**Data:** 2026-09-08

---

## Tarefas 1 e 2 (código)

| Entrega | Onde |
|---|---|
| `EmpresaPalpiteService` — a régua de confiança | `app/Services/Contratos/` |
| `ClicksignExtrairTabelas` — o comando do relatório | `app/Console/Commands/` |

**A régua, como ficou:** semelhança de nome **nunca** produz confiança `certo` — só CNPJ ou razão
social exata. Nenhum candidato é descartado por corte, porque a medição do D-05 mostrou
`GRUPO LUCCAUTO → LUCCAUTO.COM` pontuando 61% e estando **certo**; um corte em 70 teria jogado fora
um acerto.

O relatório grava apenas em `storage/app/private/relatorios/` — conferido por `git check-ignore`, que
apontou `storage/app/private/.gitignore` ignorando o caminho. Ele pareia nome de empresa com valor de
mensalidade e **não pode ser commitado**.

---

## Quatro defeitos encontrados pela rodada real, não pelos testes

Todos apareceram só ao rodar contra a conta de produção. Os testes usam `Http::fake()`, e um mock não
conhece as regras do servidor nem a variedade dos documentos reais.

| # | defeito | correção |
|---|---|---|
| 1 | `page[size]=100` — a API aceita no máximo **50** | teto com *clamping* em código + constante única (140-01) |
| 2 | CNPJ e razão social extraídos eram os da **ECF**, não do cliente | leitura por rótulo CONTRATANTE/CONTRATADA, com rede de segurança contra os dois CNPJs da ECF (140-02) |
| 3 | data vazia — o código lia `created_at`, o atributo é **`created`** | corrigido na origem, no serviço de coleta; célula sem data agora diz que faltou, nunca traço mudo |
| 4 | 19 arquivos "sem PDF dentro" eram **`.docx`** (OOXML) | reconhecimento por conteúdo + extração de `word/document.xml` (140-02) |

> O defeito 1 é a razão de o plano ter previsto um ensaio com `--limite=10` antes da varredura
> completa. Custou dois minutos e evitou uma rodada de sete que teria falhado no meio.

Depois disso, mais uma rodada de parser ensinou **quatro formatos** que ainda escapavam: dois de
valor fixo (pagamento escalonado com duas parcelas diferentes; valor anual dividido em 12) e duas
notações de tabela (sinais `-`/`+`; intervalo fechado `De X a Y`).

---

## A rodada real — 2026-09-08

`php artisan clicksign:extrair-tabelas` · **exit code 0** · 85 contratos (situação `closed`)

| classificação | contratos |
|---|---|
| **com tabela progressiva** | **49** |
| **valor fixo** | **29** |
| não deu para entender | 4 |
| números ilegíveis | 3 |
| **casaram com segurança** | **0** |

Evolução ao longo das correções: tabelas lidas 33 → 47 → **49**; ilegíveis 19 → **3**; "não deu para
entender" 28 → **4**. Conferido contrato a contrato entre as rodadas: **zero regressões** — nenhum
que tinha tabela lida a perdeu.

---

## O achado que justifica a fase

**As 49 tabelas usam 18 réguas distintas.** Não é uma tabela com exceções — é um conjunto de tabelas.

| contratos | régua (primeiras faixas) |
|---|---|
| 15 | até 500k → R$ 3.000 · 1M → R$ 4.500 · **3M** → R$ 6.000 |
| 9 | **até 100k → R$ 2.250** · 500k → R$ 3.000 · 1M → R$ 4.500 |
| 7 | até 500k → R$ 3.000 · 1M → R$ 4.500 · **2M** → R$ 6.000 |
| 2+2+2 | começando em 50k, com R$ 2.000 / R$ 1.750 / R$ 1.500 |
| 1 cada | mais 11 variações |

As duas primeiras divergem **já na terceira faixa**: quem fatura R$ 2,5 milhões paga R$ 6.000 numa e
R$ 7.500 na outra. A régua que o sistema aplica hoje a todas as empresas é a terceira — usada por 7
contratos.

**E 29 contratos são de valor fixo:** um terço das empresas contratadas não deveria estar em faixa
nenhuma, e hoje o sistema coloca todas.

---

## O que a automação não resolve

**Nenhum casamento com segurança**, nas 85 linhas. Só 10 das 201 empresas têm CNPJ cadastrado, então
a chave forte quase nunca dispara. O vínculo contrato→empresa é trabalho humano.

O relatório é honesto quanto a isso: `GRAFICA ADHARA → Filipe Adada` sai como *"só um palpite —
confira — há outra empresa parecida"*, com o mesmo peso de qualquer outro palpite. Era o requisito
mais importante do plano e está cumprido.

---

## Um erro que está no contrato, não no código

O contrato da **CAMILLO PARTS** traz limites de **R$ 15.000.000.000** — quinze bilhões, quase
certamente um ponto a mais na digitação. O parser **sinaliza e não corrige**: alterar o número seria
o código inventando o que o contrato diz.

É o melhor argumento a favor da conferência humana que a fase produziu.

---

## Pendente

- [ ] Decisão do usuário sobre construir a tela de conferência (140-05). O 140-04 (guardar as
      propostas) já foi executado e **não toca em cobrança**.
- [ ] Investigar os 4 que ainda não foram entendidos e os 3 ilegíveis — resto pequeno.

> Disciplina de privacidade: este SUMMARY registra contagens, réguas e nomes estruturais, e **não
> pareia nome de empresa com valor de mensalidade**. O relatório que faz esse pareamento vive só em
> `storage/app/private/` e não é versionado.

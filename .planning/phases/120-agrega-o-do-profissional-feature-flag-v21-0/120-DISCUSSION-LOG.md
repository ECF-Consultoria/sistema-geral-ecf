# Phase 120: Agregação do profissional + feature flag - Discussion Log

> Trilha de auditoria. Decisões em `120-CONTEXT.md`.

**Date:** 2026-07-29
**Areas discussed:** as 4 oferecidas

---

## Q1 — Empresa incompleta no denominador

| Opção | Selecionada |
|---|---|
| Sai do denominador + guarda de cobertura | ✓ |
| Entra com `nota_empresa_parcial` | |
| Qualquer incompleta puxa o profissional para `partial` | |

**Notas:** resolve a decisão que estava aberta desde o início da milestone. Entrar com a parcial foi descartado porque a incompleta poderia tirar nota **maior** que a completa (4,80 contra 4,53). A leitura literal do plano §3.4 foi descartada porque empresa sem baseline é caso comum — quase toda carteira cairia em `partial` e o status perderia função. A guarda de cobertura é o que impede julgar alguém por 2 de 10 empresas com aparência de nota oficial.

---

## Q2 — `score_status` sob a flag

| Opção | Selecionada |
|---|---|
| Derivado da cobertura de empresas completas | ✓ |
| Manter a lógica atual de componentes | |

**Notas:** manter a lógica antiga faria o status descrever componentes agregados enquanto a nota vem da média por empresa — duas semânticas no mesmo payload, fonte provável de confusão na auditoria da Fase 121. A trava da Fase 109 fica preservada sem código especial, porque empresa Shopee é `complete`.

---

## Q3 — Custo do shadow

| Opção | Selecionada |
|---|---|
| Só no warm e no snapshot mensal | ✓ |
| Sempre, nos dois modos | |
| Só sob demanda explícita | |

**Notas:** a memória do projeto registra dashboard de 70s por chamada HTTP síncrona à Adman. Rodar o caminho novo inteiro em toda leitura de tela dobraria o custo com a flag ainda desligada. Nos comandos o custo é aceitável e a auditoria fica preservada.

---

## Q4 — `DesempenhoShopeeScoreTest`

| Opção | Selecionada |
|---|---|
| Cenários novos para flag-ligada, mantendo os atuais | ✓ |
| Reescrever os invariantes | |

**Notas:** enquanto a flag estiver desligada, o caminho antigo **é** produção. Reescrever validaria um caminho que não roda.

---

## Claude's Discretion

**D-03 — patamar de cobertura = 70%.** O usuário não fixou o número. Adotei 0,7 por ser exatamente o de `ConsolidarMesDesempenho::MARGEM_COBERTURA_MINIMA_CONGELAMENTO`, que já governa a recusa de congelar snapshot degradado — evitando que o sistema passe a ter dois conceitos concorrentes de "cobertura suficiente", que foi o problema que a C-01 da Fase 119 apontou na constante órfã de 0,8.

---

## Alerta registrado para o plano

Esta é a **primeira fase que modifica `DesempenhoScoreService`**. As Fases 117-119 mantiveram o arquivo byte-a-byte intocado com gate de hash, e essa rede pegou vários erros reais (literal errado de régua, contagem de rodadas, cobertura agregada). Aqui o gate cai — o plano precisa compensar com cobertura de teste equivalente.

# Itens fora do escopo, achados durante a Fase 187 — registrados, NÃO consertados

> Regra do executor: só se conserta o que o próprio trabalho causou. Achado em arquivo alheio é
> **registrado aqui** e deixado para quem é dono dele.

---

## 2026-10-17 — plano 187-08 — DUAS falhas de `CockpitDaContaTest` (Fase 179), dependentes do RELÓGIO

**Onde:** `tests/Feature/Publicador/CockpitDaContaTest.php`
**Serviço:** `app/Services/Publicador/CockpitDaContaService.php`
**Fase dona:** **179** (Visão geral / cockpit da conta) — **não é território do 187**.

| caso | asserção | sintoma medido |
|---|---|---|
| `publicacoes criadas mede as duas janelas e a serie de trinta dias` | `:966` `assertSame(12, $kpi['serie'][29], 'a última posição é HOJE')` | obteve **0** em vez de 12 |
| `evento sem data vai para o ultimo grupo nunca para o topo` | `:1318` `assertSame(['hoje', …], array_column($grupos,'chave'))` | obteve **`'ontem'`** no lugar de `'hoje'` |

### Por que NÃO é regressão deste plano — medido, não suposto

1. **Nenhum dos arquivos tocados pelo plano 187-08 tem relação com o cockpit.** `git diff
   44d0a364..HEAD --name-only | grep -i cockpit` → **vazio**. Os 12 arquivos do escopo estão listados
   no `187-08-SUMMARY.md` §13.
2. **Os últimos commits a tocar esses dois arquivos são da Fase 179:** `43fa4d4a` (2026-10-12,
   *"feat(179-03): Publicacoes recentes e a Atividade agrupada"*) e — significativo —
   **`0edc4462` (2026-10-13, `test(179): ancora o relogio no caso de agrupamento da Atividade`)**:
   alguém **já** teve de ancorar o relógio em UM caso desse arquivo.
3. **O arquivo usa `now()` 52 vezes.** As duas asserções que falham comparam o agrupamento contra
   `'hoje'` / `serie[29]`, que é a última posição de uma série que **termina hoje**.
4. As duas apareceram na **virada de 2026-10-16 para 2026-10-17** e **continuam** falhando depois da
   virada — então não é só a corrida da meia-noite: é fragilidade de FRONTEIRA DE DIA que o caso
   `0edc4462` consertou para um irmão e não para estes dois.

### O conserto certo (para quem é dono)

Ancorar o relógio nos dois casos, como `0edc4462` fez no terceiro — `Carbon::setTestNow()` numa data
fixa, em vez de semear com `now()->subDays(...)` e comparar contra `'hoje'`. ⚠️ **Não mexer no
serviço**: o serviço está certo; é o teste que depende da hora em que roda.

**Registrado em:** `187-08-SUMMARY.md` §1 (baselines) e §15.

---
quick_id: 260814-cro
type: quick
mode: fix
phase_ref: 130-rede-de-seguranca
autonomous: true
files_modified:
  - app/Services/Contratos/ContratosPresosService.php
  - app/Console/Commands/ClicksignAlertarPresos.php
  - tests/Feature/Phase130/ContratosPresosServiceTest.php
  - tests/Feature/Phase130/AlertaCooldownTest.php
  - tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php
  - tests/Feature/Phase130/AlertaContratoPresoTest.php
migration_required: false
must_haves:
  truths:
    - "Um contrato preso continua preso DEPOIS de ser alertado (a D-04 volta a valer)."
    - "Qualquer escrita no contrato (retry de PDF, sync de signatário, gravação do cooldown) NÃO zera o contador de dias parado."
    - "Passado o intervalo de repetição, o mesmo contrato alerta de novo."
    - "O cooldown da D-04 continua impedindo alerta diário."
  artifacts:
    - path: app/Services/Contratos/ContratosPresosService.php
      provides: "dataBase() por estado sem nenhuma referência a updated_at"
    - path: app/Console/Commands/ClicksignAlertarPresos.php
      provides: "gravação de ultimo_alerta_em sem bumpar updated_at"
  key_links:
    - from: ClicksignAlertarPresos
      to: ContratosPresosService::estaPreso()
      via: "listar() na execução seguinte ainda devolve o contrato alertado"
---

<objective>
Corrigir o relógio do alerta de contrato preso.

`ContratosPresosService::dataBase()` devolve `$c->updated_at` no ramo `default` (estados
`recusado`, `expirado`, `cancelado`, `erro`) e como fallback de `assinado`. Como o comando
`clicksign:alertar-presos` grava `ultimo_alerta_em` via `$contrato->update()`, o Eloquent bumpa
`updated_at` — e `diasParado()` volta a **0** logo após alertar. O contrato deixa de estar preso,
**nunca mais alerta** e ainda some da tela de liberação manual.

Evidência medida (produção-local): contrato id 9, `recusado`, `created_at = 2026-08-07 17:27:31`,
`updated_at = 2026-08-13 17:27:45` — idêntico ao segundo ao `ultimo_alerta_em`. `diasParado()`
devolve 0 para um contrato de 7 dias.

Isso destrói a **D-04** ("o alerta REPETE em intervalo até resolver"), que existe justamente
porque avisar uma vez só faz o aviso morrer se ninguém viu. É o silêncio que a Fase 130 inteira
existe para impedir.

Purpose: devolver estabilidade ao contador e criar o teste que faltou (o que deixou o bug passar).
Output: `dataBase()` sem `updated_at`, gravação do cooldown sem sujar timestamps, e teste
end-to-end provando que preso continua preso depois de alertar.
</objective>

<decisao_de_schema>
**Sem migration.** A tabela `contrato_assinaturas` NÃO tem `recusado_em`/`expirado_em`/`cancelado_em`
(medido). As colunas de data existentes são `enviado_em`, `assinado_em`, `liberado_em`,
`ultimo_alerta_em`, `created_at`, `updated_at`.

A data estável para os estados sem coluna própria sai de `enviado_em ?? created_at`, e isso é
**semanticamente mais correto** para a D-03/D-05: a regra é "empresa sem liberação há tempo demais",
contada desde que o processo começou — não desde a última mexida no registro. Uma coluna nova
(`recusado_em` etc.) só mudaria o marco de "processo começou" para "estado mudou", que NÃO é a
pergunta da D-05, e ainda exigiria backfill dos contratos já em produção. Não compensa.
</decisao_de_schema>

<context>
@CLAUDE.md
@.planning/STATE.md
@.planning/phases/130-rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22-/130-CONTEXT.md
@app/Services/Contratos/ContratosPresosService.php
@app/Console/Commands/ClicksignAlertarPresos.php
@app/Models/ContratoAssinatura.php
</context>

<interfaces>
Contratos que o executor precisa — já existentes, não reinventar:

`App\Models\ContratoAssinatura`
- casts `datetime`: `enviado_em`, `assinado_em`, `liberado_em`, `ultimo_alerta_em`
- `STATUS_RASCUNHO`, `STATUS_AGUARDANDO_ASSINATURAS`, `STATUS_ASSINADO`, `STATUS_RECUSADO`,
  `STATUS_EXPIRADO`, `STATUS_CANCELADO`, `STATUS_ERRO`, `STATUS_TODOS`
- hook `booted()::saving` — mantém `company_id_em_andamento`/`servico_id_em_andamento`.
  ⚠️ O docblock do model avisa: `updateQuietly()` / `saveQuietly()` / update de query builder
  **desligam esse hook em silêncio** e podem travar a empresa para sempre. Por isso a Task 2 usa
  `$contrato->timestamps = false` (que preserva os eventos do model) e **não** `updateQuietly()`.

`App\Services\Contratos\ContratosPresosService`
- `dataBase(ContratoAssinatura): CarbonInterface`
- `limiarDias(ContratoAssinatura): int` — "o que vier primeiro" (D-03), nunca < 1
- `diasParado(ContratoAssinatura): int`
- `estaPreso(ContratoAssinatura): bool` — `liberado_em === null && diasParado >= limiarDias`
- `listar(): Collection`

Consumidores de `listar()`/`diasParado()` (não alterar nesta correção):
`app/Console/Commands/ClicksignAlertarPresos.php`, `app/Http/Controllers/ContratoLiberacaoManualController.php`
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: dataBase() para de usar updated_at (data estável por estado)</name>

  <read_first>
    - `app/Services/Contratos/ContratosPresosService.php` (linhas 69-104 — `dataBase()`, `diasParado()`)
    - `app/Models/ContratoAssinatura.php` (casts e constantes de status)
    - `tests/Feature/Phase130/ContratosPresosServiceTest.php` (o novo teste convive com estes)
    - `tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php` (linhas 39-89 — as duas fixtures que hoje dependem de `updated_at`)
    - `tests/Feature/Phase130/AlertaContratoPresoTest.php` (linhas 152-169 — docblock do helper `contratoPresoAntigo()` fica desatualizado)
  </read_first>

  <files>
    app/Services/Contratos/ContratosPresosService.php
    tests/Feature/Phase130/ContratosPresosServiceTest.php
    tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php
    tests/Feature/Phase130/AlertaContratoPresoTest.php
  </files>

  <behavior>
    Escrever PRIMEIRO os dois testes de regressão em `ContratosPresosServiceTest`, ver falhar,
    depois corrigir o serviço:

    - `test_bump_de_updated_at_nao_zera_o_relogio_de_contrato_recusado`: contrato `recusado` com
      `enviado_em = now()->subDays(10)`; `estaPreso()` é `true`; então `$contrato->update([...])`
      (qualquer escrita, ex.: `ultimo_alerta_em => now()`) bumpa `updated_at`; reconsultar o banco
      fresco (`ContratoAssinatura::find($id)`) e `estaPreso()` **continua** `true` e
      `diasParado()` continua ≥ 10.
    - `test_estado_sem_enviado_em_conta_desde_created_at`: contrato `erro` **sem** `enviado_em`,
      com `created_at` envelhecido 10 dias via `forceFill(['created_at' => now()->subDays(10)])->save()`;
      `diasParado()` ≥ 10 e `estaPreso()` é `true`.
  </behavior>

  <action>
    1. Em `ContratosPresosService::dataBase()`, eliminar TODA referência a `updated_at`:
       - `STATUS_RASCUNHO` → `created_at` (inalterado)
       - `STATUS_AGUARDANDO_ASSINATURAS` → `enviado_em ?? created_at` (inalterado)
       - `STATUS_ASSINADO` → `assinado_em ?? enviado_em ?? created_at` (era `assinado_em ?? updated_at`)
       - `default` (`recusado`/`expirado`/`cancelado`/`erro`) → `enviado_em ?? created_at`
         (era `updated_at`)
       Trocar o docblock do método para explicar em pt-BR **por que** `updated_at` está proibido
       aqui: é instável por natureza — qualquer escrita no contrato (gravação do cooldown da D-04,
       retry de download de PDF, sync de signatário) zeraria o contador e faria o alerta morrer.
       E a pergunta da D-05 é "há quanto tempo esta empresa está sem liberação", contada desde que
       o processo começou. Deixar o aviso explícito para quem vier depois: **não reintroduzir
       `updated_at` neste match**.

    2. Ajustar as duas fixtures de `LiberacaoManualEstadoRealTest` que hoje envelhecem
       `updated_at` via `forceFill()` (linhas ~54 e ~78) — elas quebram com a correção acima:
       - `test_get_com_contrato_recusado_traz_status_e_causa_certos`: trocar o `forceFill` por
         `'enviado_em' => now()->subDays(10)` no `create()` (é o caso realista: o contrato foi
         enviado e o cliente recusou).
       - `test_get_com_contrato_em_erro_distingue_de_recusado`: manter `enviado_em` nulo e
         envelhecer `created_at` via `forceFill(['created_at' => now()->subDays(10)])->save()`
         (caso realista: a integração falhou antes do envio) — assim a tela cobre os DOIS ramos
         da nova `dataBase()`.
       Atualizar os comentários pt-BR dessas fixtures: hoje dizem "dataBase() de um estado default
       é `updated_at`", o que passa a ser falso e reensinaria o bug.

    3. Em `AlertaContratoPresoTest`, corrigir o docblock do helper `contratoPresoAntigo()`
       (linhas ~152-158) pelo mesmo motivo e remover o `forceFill(['updated_at' => ...])->save()`
       da linha ~166, que deixa de ter função (o helper já cria com `enviado_em` de 10 dias).
       Não mexer nas asserções desses testes.

    NÃO tocar em `limiarDias()`, `causa()`, `estaPreso()` nem `listar()`.
  </action>

  <verify>
    <automated>C:\xampp\php\php.exe artisan test --filter=Phase130</automated>
  </verify>

  <acceptance_criteria>
    - `grep -n "updated_at" app/Services/Contratos/ContratosPresosService.php` só retorna linhas de
      COMENTÁRIO (o aviso de "não reintroduzir"), nenhuma de código — conferir à mão a saída, não
      usar contagem crua.
    - Os 2 testes novos de `ContratosPresosServiceTest` passam e falhavam antes da correção
      (rodar o teste antes do passo 1 e registrar a falha).
    - `C:\xampp\php\php.exe artisan test --filter=Phase130` verde, com contagem de testes
      **maior** que os 79 atuais (nenhum teste existente removido).
  </acceptance_criteria>

  <done>
    Nenhum estado calcula "dias parado" a partir de `updated_at`; contrato recusado/expirado/
    cancelado/erro conta desde `enviado_em ?? created_at`; suíte Phase130 verde.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: gravar ultimo_alerta_em sem sujar timestamps + provar a repetição da D-04</name>

  <read_first>
    - `app/Console/Commands/ClicksignAlertarPresos.php` (linhas 47-112 — cooldown e a gravação da linha ~90)
    - `app/Models/ContratoAssinatura.php` (docblock de `booted()` — por que `updateQuietly()` é proibido aqui)
    - `tests/Feature/Phase130/AlertaCooldownTest.php` (os 4 testes de cooldown precisam continuar verdes)
  </read_first>

  <files>
    app/Console/Commands/ClicksignAlertarPresos.php
    tests/Feature/Phase130/AlertaCooldownTest.php
  </files>

  <behavior>
    Escrever PRIMEIRO, em `AlertaCooldownTest`, o teste end-to-end que faltou e deixou o bug passar
    — ver falhar (contra o comando ainda não corrigido, mas já com a Task 1 aplicada ele deve
    passar; se passar de cara, isso confirma que o item 2 é defesa em profundidade e o teste deve
    ser mantido mesmo assim, com asserção adicional sobre `updated_at`):

    - `test_contrato_preso_continua_preso_depois_de_alertar_e_repete_apos_o_intervalo`:
      1. admin ativo + contrato preso (`aguardando_assinaturas`, `enviado_em = now()->subDays(10)`).
      2. `Notification::fake()`; roda `clicksign:alertar-presos`; assert enviado.
      3. Reconsulta o banco fresco: `ContratosPresosService::estaPreso()` **continua** `true` e
         `diasParado()` continua ≥ 10 — este é o coração do teste.
      4. Assert que `updated_at` do contrato NÃO foi bumpado pela gravação do carimbo (comparar
         com o valor lido antes da execução).
      5. Recua `ultimo_alerta_em` para `now()->subDays(4)` (além do default de 3 dias da D-04),
         roda de novo com `Notification::fake()` novo, e assert que **alertou outra vez**.
  </behavior>

  <action>
    No loop de `ClicksignAlertarPresos::handle()`, trocar a gravação da linha ~90
    (`$contrato->update(['ultimo_alerta_em' => now()])`) por uma gravação que **não toca os
    timestamps**: setar `$contrato->timestamps = false;` imediatamente antes do `update()`.

    ⚠️ NÃO usar `updateQuietly()` nem update de query builder: o docblock de
    `ContratoAssinatura::booted()` registra que isso desliga o hook `saving` em silêncio e pode
    deixar `company_id_em_andamento`/`servico_id_em_andamento` dessincronizados — o que trava a
    empresa para gerar contrato novo. `timestamps = false` preserva os eventos do model.

    Comentar em pt-BR o porquê: mesmo com a `dataBase()` já corrigida (Task 1), o alerta não tem
    motivo para sujar `updated_at` — é defesa em profundidade para que uma futura mudança de
    critério não ressuscite o bug do relógio.

    Não alterar a ordem "grava SÓ depois do envio bem-sucedido" nem o `try/catch` de "log e
    continua". Não alterar a lógica do cooldown nem a leitura de `Configuracao`.
  </action>

  <verify>
    <automated>C:\xampp\php\php.exe artisan test --filter=Phase130</automated>
  </verify>

  <acceptance_criteria>
    - O novo teste de repetição passa e cobre as 3 asserções: segue preso, `updated_at` intacto,
      alerta de novo após o intervalo.
    - Os 4 testes existentes de `AlertaCooldownTest` continuam verdes (a D-04 não passou a alertar
      todo dia).
    - `test_audiencia_vazia_nao_envia_e_nao_grava_ultimo_alerta_em` (em `AlertaContratoPresoTest`)
      continua verde — a gravação segue condicionada ao envio.
    - `C:\xampp\php\php.exe artisan test --filter=Phase130` verde, contagem ≥ 82.
  </acceptance_criteria>

  <done>
    Alertar um contrato preso não altera mais o relógio nem o `updated_at`; a repetição da D-04
    está provada por teste automatizado.
  </done>
</task>

</tasks>

<fora_de_escopo>
Não tocar: `EmpresaOperacionalRouter`, `ClicksignReconciliar` / varredura de reconciliação,
`ClicksignClient`, a página `Admin/ContratosLiberacaoManual` (apenas as fixtures dos testes dela),
`ContratoLiberacaoManualController`. Nenhuma migration.
</fora_de_escopo>

<verification>
1. `C:\xampp\php\php.exe artisan test --filter=Phase130` — verde, contagem ≥ 82 (era 79).
   ⚠️ NÃO rodar a suíte completa sem filtro: estoura timeout num ponto pré-existente do
   `MercadoLivreAdsService`, alheio a esta correção.
2. Conferir à mão que `app/Services/Contratos/ContratosPresosService.php` não tem `updated_at`
   em nenhuma linha de código (só no comentário de aviso).
3. Confirmação por reconsulta ao banco (disciplina do projeto — nunca por stdout): as asserções
   dos testes novos leem `ContratoAssinatura::find($id)` fresco, não a instância em memória.
</verification>

<success_criteria>
- `dataBase()` não usa `updated_at` em nenhum estado.
- Contrato preso continua preso depois de alertado (D-04 restaurada), provado por teste.
- Cooldown da D-04 segue funcionando: não alerta todo dia, mas repete passado o intervalo.
- Suíte `Phase130` inteira verde, sem nenhum teste existente removido ou enfraquecido.
</success_criteria>

<output>
Ao concluir, escrever `.planning/quick/260814-cro-corrigir-relogio-do-alerta-de-contrato-p/260814-cro-SUMMARY.md`
com: o que mudou em cada arquivo, a saída da suíte `--filter=Phase130` (antes/depois), e o
registro de que a correção NÃO exigiu migration (e por quê) — para quem for mexer em
`ContratosPresosService` depois não reabrir a discussão.
</output>

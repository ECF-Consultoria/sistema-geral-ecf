# Fase 133 — Roteiro da ativação do bloqueio administrativo em produção

**Preparado em:** 2026-08-18, plano 133-04 (Task 1).
**Quem lê este documento:** qualquer pessoa que execute a virada — não precisa ser quem
planejou. O padrão segue o mesmo molde do roteiro da Fase 132
(`132-GATE.md`): campos de resultado nascem em branco, comandos escritos por extenso,
critério de parar explícito.

**Como usar:** o plano 133-04 (Task 2 e Task 3, se autorizada) e o plano 133-05 preenchem os
campos de resultado abaixo, na ordem em que os passos acontecem. Nada aqui é preenchido de
antemão.

---

## O que muda quando a chave liga

Em linguagem simples: quando `administrativo_bloqueio_ativo` está ligada, toda empresa cujo
serviço **exige contrato** passa a aguardar a etapa administrativa antes de entrar na
operação — ela só é liberada quando o contrato é assinado (confirmado pelo aviso automático
da Clicksign, reconsultado) ou quando um admin faz a liberação manual, com motivo registrado.

Empresa de **Polos continua entrando direto**, exatamente como hoje — Polos é isento de
contrato (D9) e a exceção por serviço, feita nos planos 133-01 e 133-02, garante isso mesmo
com a chave ligada.

⚠️ **Aviso honesto (D-01 do `133-CONTEXT.md`): hoje o efeito prático é nulo.** Medido em
produção em 2026-08-18: não existe nenhuma ficha de Assessoria ou Incubadora na base — só
fichas de Polos. Logo, ligar a chave agora **não retém nenhuma empresa de imediato**. A prova
de sucesso desta ativação é **"nada quebrou"**, não **"algo foi bloqueado"**. Quem esperar ver
uma empresa retida vai concluir, errado, que a fase falhou.

---

## Pré-condições (checkpoint humano do ROADMAP)

Texto copiado literalmente do `.planning/ROADMAP.md`, seção "Phase 133":

> 🚦 CHECKPOINT HUMANO — bloqueia a ATIVAÇÃO, não a escrita. A flag
> `administrativo_bloqueio_ativo` só pode ser ligada em produção depois de confirmar, com o
> usuário: (a) o webhook chegou de forma confiável durante o período de observação (Fase
> 128/129 rodando em produção por tempo suficiente); (b) o alerta de contrato preso já
> disparou pelo menos uma vez em sandbox (Fase 130); (c) a liberação manual foi testada em
> produção ao menos uma vez (Fase 130); (d) o cutover para produção Clicksign foi concluído e
> aprovado (Fase 132). Rollback de código sozinho nunca é o plano de saída — desligar a flag é.

As quatro caixas abaixo só podem ser marcadas depois de confirmação individual do usuário —
nunca por inferência:

- [ ] **(a)** O webhook chegou de forma confiável durante o período de observação (Fases
      128/129 rodando em produção por tempo suficiente).
      Confirmado por: ______ · Quando: ______
- [ ] **(b)** O alerta de contrato preso já disparou pelo menos uma vez em sandbox (Fase 130).
      Confirmado por: ______ · Quando: ______
- [ ] **(c)** A liberação manual foi testada em produção ao menos uma vez (Fase 130).
      Confirmado por: ______ · Quando: ______
- [ ] **(d)** O cutover para produção Clicksign foi concluído e aprovado (Fase 132).
      Confirmado por: ______ · Quando: ______

---

## Antes de ligar

- [ ] Os três planos anteriores (133-01, 133-02, 133-03) estão commitados.
- [ ] A suíte da fase está verde: `tests/Feature/Phase133`, `Phase124KillSwitchTest`,
      `tests/Feature/Phase128` — resultado registrado em "Campos de resultado".
- [ ] `npm run build` rodado, sem erro.
- [ ] Deploy autorizado explicitamente nesta conversa e concluído (a árvore é compartilhada
      com outra sessão/dev — o deploy publica o trabalho de todos).
- [ ] A contagem-baseline de `mlb_empresas` (total e `POLO`) registrada por reconsulta, com o
      comando abaixo.

Comando de contagem-baseline (reconsulta, nunca a tela):

```
php artisan tinker --execute="echo App\Models\MlbEmpresa::count(), '|', App\Models\MlbEmpresa::where('tipo','POLO')->count();"
```

---

## Ligar

Comando de tinker por extenso, usando a constante `EmpresaOperacionalRouter::CHAVE_BLOQUEIO`
(nunca digitar o nome da chave à mão):

```
php artisan tinker --execute="App\Models\Configuracao::set(App\Services\Operacional\EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');"
```

Comando de conferência, imediatamente em seguida:

```
php artisan tinker --execute="echo app(App\Services\Operacional\EmpresaOperacionalRouter::class)->bloqueioAtivo() ? 'ligado' : 'desligado';"
```

⛔ A conferência é sempre por **reconsulta** ao banco (`bloqueioAtivo()`), nunca pela tela e
nunca pela ausência de mensagem de erro do comando de ligar.

⛔ **Proibido rodar `cache:clear` no VPS em qualquer passo deste roteiro.** Incidente real de
2026-07-30: apaga o cache aquecido da Adman, o dashboard passa a esperar a API, os workers do
php-fpm ficam ocupados e até o login para. Ligar/desligar esta chave não exige limpar cache
nenhum — se algo parecer preso, a alternativa correta é `systemctl reload php8.2-fpm`.

---

## Conferir depois de ligar

Três conferências imediatas, nessa ordem:

1. `bloqueioAtivo()` devolve `ligado` (comando de conferência da seção "Ligar").
2. A contagem de `mlb_empresas` (comando da seção "Antes de ligar") **não mudou** — ligar a
   chave não cria nem apaga ficha nenhuma.
3. A tela `/administrativo/contratos` mostra a faixa de aviso (Fase 133-03). Conferência
   visual formal fica para o plano 133-05.

---

## Critério de parar (e desligar na hora)

Três gatilhos concretos. Qualquer um deles manda desligar imediatamente, com o comando de
"Como voltar atrás" logo abaixo:

1. **Um cadastro real de Polos não gerou ficha.** É o sinal de que a exceção por serviço
   (D-02, plano 133-01) não está funcionando em produção como funcionou nos testes.
2. **Apareceu recusa de ativação manual que não deveria existir** — procurar no log a
   mensagem `[Administrativo] Ativação manual retida` (plano 133-02) para um caso que era para
   ser isento.
3. **Qualquer erro 500 nos caminhos de cadastro** (webhook HubSpot, cadastro do Comercial,
   ativação manual de `/mlb/empresas`).

```
php artisan tinker --execute="App\Models\Configuracao::set(App\Services\Operacional\EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0');"
```

**Rollback de código nunca é o plano de saída — a chave é.** Não existe cenário nesta fase em
que reverter deploy ou migration seja a resposta ao critério de parar; a saída é sempre
desligar a chave em segundos e investigar depois.

---

## Como voltar atrás

O comando de desligar é o mesmo da seção "Critério de parar", acima. O efeito é **imediato**:
não exige deploy, não exige migration, não exige reiniciar worker — a próxima leitura de
`bloqueioAtivo()` já devolve `false`.

⚠️ **Empresas retidas enquanto a chave esteve ligada NÃO são roteadas retroativamente ao
desligar.** Quem ficou de fora entra pela liberação manual (Fase 130, admin com motivo
registrado) ou pelo próximo aviso automático de assinatura da Clicksign — desligar a chave só
para de reter empresas **novas**, não resolve sozinho quem já ficou parado.

---

## Campos de resultado

Preencher no plano 133-04 (Task 2/3) e no plano 133-05, sempre com o que o **banco** devolveu,
nunca a tela nem o stdout de um comando isolado sem reconsulta.

### Suíte da fase (colhida antes da Task 2)

- `tests/Feature/Phase133` — Tests: ______
- `tests/Feature/Phase124KillSwitchTest.php` — Tests: ______
- `tests/Feature/Phase128` — Tests: ______

### Pré-condições — ver seção "Pré-condições" acima (registro é lá, não aqui)

### Decisão do checkpoint (Task 2)

- Resposta escolhida: ______ (`ligar-agora` / `adiar` / `parar`)
- Motivo, se `adiar` ou `parar`: ______
- Autorização de deploy dada explicitamente nesta conversa: ______

### Ativação (Task 3, só se `ligar-agora`)

- Data/hora da ativação (com fuso): ______
- Quem autorizou: ______
- Quem executou: ______
- Commit implantado (`git rev-parse --short HEAD`): ______
- Contagem de `mlb_empresas` antes: ______ (total) / ______ (`POLO`)
- Contagem de `mlb_empresas` depois: ______ (total) / ______ (`POLO`)
- Resultado da reconsulta de `bloqueioAtivo()`: ______

### Primeiro cadastro real de Polos (plano 133-05)

- Empresa: ______
- Ficha gerada: ______
- Data/hora: ______

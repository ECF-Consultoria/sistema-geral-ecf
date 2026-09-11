# Fase 152: Checklist administrativo + trava de finalização — Mapa de Padrões

**Mapeado em:** 2026-09-09
**Arquivos analisados:** 21 (7 a criar em backend + 11 testes Wave 0 + 2 React + 4 modificados + 2 config/docs)
**Análogos encontrados:** 19 / 21 (2 sem análogo direto — marcados abaixo)

Todo trecho de código abaixo foi copiado literalmente de um arquivo real do worktree
`C:\xampp\htdocs\ecf_fluxo_entrada`, com `arquivo:linha`. Nenhum trecho é inventado.

---

## Classificação de Arquivos

| Arquivo (a criar/modificar) | Papel | Fluxo de dados | Análogo mais próximo | Qualidade |
|---|---|---|---|---|
| `database/migrations/2026_09_09_HHMMSS_create_checklist_administrativo_itens_table.php` | migration | CRUD | `database/migrations/2026_08_11_120100_create_onboardings_tables.php` (tabela `onboarding_passos`) | exato (mesmo shape, âncora trocada) |
| `app/Models/ChecklistAdministrativoItem.php` | model | CRUD | `app/Models/OnboardingPasso.php` | role-match (motor mais simples — 3 estados, não 6) |
| `app/Contracts/ChecklistResolver.php` | contract | request-response (síncrono) | `app/Contracts/OnboardingResolver.php` | role-match (mais simples — sem `assincrono()`) |
| `app/Services/ChecklistAdministrativo/ChecklistResolverResultado.php` | value object | transform | `app/Services/Onboarding/OnboardingResolverResultado.php` | exato (mesmo shape de 3 estados) |
| `app/Services/ChecklistAdministrativo/ChecklistAdministrativoDefinicao.php` | config/catálogo em código | transform | `app/Support/Onboarding/DefinicaoOnboarding.php` (citado no RESEARCH, não lido linha a linha nesta passada — ver nota) | role-match |
| `app/Services/ChecklistAdministrativo/ChecklistAdministrativoService.php` | service | CRUD + event-driven (reavalia ao marcar) | `app/Services/Onboarding/OnboardingEngineService.php` | exato (molde, D-10) |
| `app/Services/ChecklistAdministrativo/Resolvers/MlOAuthConectadoResolver.php` | resolver | request-response (leitura de coluna local) | `app/Services/Onboarding/Resolvers/MlTokenAtivoResolver.php` | **exato — cópia quase literal, o próprio RESEARCH recomenda** |
| `app/Services/ChecklistAdministrativo/Resolvers/ConexaoEcfResolver.php` | resolver | request-response | `app/Services/Onboarding/OnboardingLinkService::paraEmpresa()` | exato |
| `app/Services/ChecklistAdministrativo/Resolvers/ContratoEnviadoResolver.php` | resolver | request-response | leitura direta de `ContratoAssinatura.enviado_em` (sem resolver-análogo — ver nota) | parcial |
| `app/Services/ChecklistAdministrativo/Resolvers/ContratoAssinadoResolver.php` | resolver | request-response | `app/Models/ContratoLiberacao::existeParaServico()` + `ContratoAssinatura` (D-16) | parcial (lógica nova: OR entre 2 fontes) |
| `app/Services/ChecklistAdministrativo/FinalizarEntradaAdministrativaService.php` | service | request-response (gate puro + efeito) | `app/Services/FluxoEntrada/EtapaTransicaoService.php` (`podeTransicionar()`/`transicionar()`) | exato (mesmo par régua-pura/efeito) |
| `app/Http/Controllers/ContratoAdminController.php` (modificado) | controller | request-response | ele mesmo, `show()` (`app/Http/Controllers/ContratoAdminController.php:507-693`) + `liberarManual()` (`:1201-1244`) | exato |
| `routes/web.php` (modificado) | rota | request-response | o próprio grupo `admin.contratos.*` (`routes/web.php:1443-1462`) + o padrão OR de `EnsurePermission` | exato |
| `resources/js/Pages/Admin/ContratoDetalhe.jsx` (modificado) | página React | request-response | ele mesmo (estrutura de Cards + `useForm`) | exato |
| `resources/js/Pages/Comercial/Entrada.jsx` (modificado) | página React | request-response | `resources/js/Pages/Admin/Contratos.jsx:308-315` (link "Abrir") | exato |
| `resources/js/Components/ChecklistAdministrativo/LinhaChecklistItem.jsx` | componente React | request-response | `resources/js/Components/Onboarding/Painel/DetalheOnboarding.jsx` (`LinhaPasso`) | exato |
| `resources/js/Components/ChecklistAdministrativo/ChecklistProgresso.jsx` (opcional, ou inline) | componente React | transform | `resources/js/Components/Onboarding/Painel/ProgressoBarra.jsx` | exato |
| `config/services.php` (modificado) | config | — | bloco `'hubspot' => [...]` (`config/services.php:120-133`) + `'adman' => [...]` (`:38-41`) | exato |
| `.env.example` (modificado) | config | — | bloco `HUBSPOT_PROP_*` (`.env.example:102-135`) | role-match (ADMAN nem está no `.env.example` hoje — ver Armadilha) |
| `.planning/REQUIREMENTS-v23.md` (modificado à mão) | docs | — | SEM ANÁLOGO DE CÓDIGO — é edição de prosa, ver D-19 do CONTEXT.md | n/a |
| 11 arquivos de teste (Wave 0) | test | request-response / unit | `tests/Feature/Phase131/ContratoAdminDetalheTest.php`, `tests/Feature/Phase151/ComercEntradaPermissaoRotaTest.php`, `tests/Feature/Phase151/ComercEtapaNascimentoCadastroManualTest.php`, `tests/Unit/Phase150/EtapaTransicaoServiceTest.php` | exato (ver seção dedicada) |

---

## Atribuições de Padrão

### `database/migrations/2026_09_09_HHMMSS_create_checklist_administrativo_itens_table.php`

**Papel:** migration · **Fluxo:** CRUD
**Análogo:** `database/migrations/2026_08_11_120100_create_onboardings_tables.php:80-136` (tabela `onboarding_passos`)

**Trecho a copiar** (shape da tabela de itens, adaptando a FK):
```php
// Source: database/migrations/2026_08_11_120100_create_onboardings_tables.php:80-136
if (! Schema::hasTable('onboarding_passos')) {
    Schema::create('onboarding_passos', function (Blueprint $table) {
        $table->id();

        $table->foreignId('onboarding_id')
            ->constrained('onboardings')
            ->cascadeOnDelete();

        $table->foreignId('template_passo_id')
            ->constrained('template_passos')
            ->restrictOnDelete();

        $table->string('chave', 60)
            ->comment('Denormalizada de template_passos.chave; ...');

        $table->string('status', 24)
            ->default('bloqueado')
            ->comment('bloqueado | aberto | ... — App\\Models\\OnboardingPasso::STATUSES (D-11)');

        $table->json('valor')->nullable();

        $table->foreignId('feito_por')
            ->nullable()
            ->constrained('users')
            ->nullOnDelete();

        $table->timestamp('feito_em')->nullable();

        $table->timestamp('auto_em')
            ->nullable()
            ->comment('Momento em que um resolver automático concluiu o passo sozinho ...');

        $table->timestamps();

        $table->unique(['onboarding_id', 'template_passo_id']);
        $table->index(['status', 'disponivel_em']);
        $table->index(['chave']);
    });
}
```

**O que muda:**
- FK âncora vira `company_id` (`constrained('companies')->cascadeOnDelete()`), não `onboarding_id` (D-10 — não hospedar no motor de Onboarding).
- Sem `template_passo_id`/`template_id`: os 9 itens são um **catálogo fechado em código** (`ChecklistAdministrativoDefinicao`), não linhas de uma tabela de template — D-01/D-03 já travaram a lista, não há CRUD de item.
- `status` tem **3 valores**, não 6: `aberto | concluido` chega no mínimo; **não existe** `bloqueado`, `nao_aplicavel` (D-02 proíbe), `aguardando_coleta`, `indeterminado` — os 4 resolvers desta fase são **síncronos** (leitura de coluna local, sem rede), então os 2 estados assíncronos de `OnboardingPasso` não se aplicam. Confirme com o planejador se 2 estados bastam ou se `indeterminado` é mantido "morto" por disciplina do D-10 (a decisão cita `OnboardingResolverResultado` de 3 estados como shape a copiar — a tabela pode ter só as colunas que os resolvers síncronos realmente usam, mesmo que o **objeto de retorno do resolver** continue com 3 estados no código).
- Índice único precisa de **nome explícito** (ver Armadilha abaixo).
- `unique(['company_id', 'chave'])` substitui `unique(['onboarding_id', 'template_passo_id'])` — uma linha por item por empresa.

**Armadilha:**
- **MariaDB, não SQLite.** Nome de índice autogerado > 64 chars falha com erro 1059 no MariaDB (não falha nos testes, que usam SQLite). Nomear explicitamente:
  `$table->unique(['company_id', 'chave'], 'cai_company_chave_unique');` — mesma disciplina já registrada em `152-RESEARCH.md` (Pitfall 6) e em `.planning/learnings/desempenho-bonificacao.md` §6.
- `nullOnDelete()` em `feito_por` **exige** a coluna `nullable()` (erro 1830 no MariaDB) — a migration análoga já mostra a ordem certa (`->nullable()->constrained('users')->nullOnDelete()`), copiar exatamente essa ordem.
- Rodar a migration contra o MariaDB local (`ecf_admin` via XAMPP) antes de considerar o Wave 0 fechado — SQLite não pega nenhuma das duas armadilhas acima.

---

### `app/Models/ChecklistAdministrativoItem.php`

**Papel:** model · **Fluxo:** CRUD
**Análogo:** `app/Models/OnboardingPasso.php` (arquivo completo) + `app/Models/Pendencia.php:84-97` (para o `belongsTo(...)->withTrashed()`)

**Trecho a copiar** (fillable/casts, forma geral):
```php
// Source: app/Models/OnboardingPasso.php:57-72
protected $fillable = [
    'onboarding_id',
    // ...
    'chave',
    'status',
    'valor',
    'feito_por',
    'feito_em',
    'auto_em',
    // ...
];

protected $casts = [
    'valor'              => 'array',
    'feito_em'           => 'datetime',
    'auto_em'            => 'datetime',
];
```

**Trecho a copiar** (relação de autoria com `withTrashed()` — D-11, ponto 1):
```php
// Source: app/Models/Pendencia.php:84-97
public function abertaPor(): BelongsTo
{
    return $this->belongsTo(User::class, 'aberta_por')->withTrashed();
}

public function corrigidaPor(): BelongsTo
{
    return $this->belongsTo(User::class, 'corrigida_por')->withTrashed();
}
```
> ⚠️ `OnboardingPasso::feitoPor()` (linha 233-236 do arquivo análogo) **não** usa
> `->withTrashed()`. O `Pendencia.php` é o análogo correto para este detalhe
> específico — copiar dali, não do `OnboardingPasso`.

**O que muda:**
- `$fillable` troca `onboarding_id` por `company_id`; sem `ordem`/`etapa`/`titulo`/`dono`/`setor_id`/`depende_de`/`sla_dias`/`condicao`/`tentativas`/`ultimo_erro`/`coleta_iniciada_em`/`disponivel_em` — nenhum desses conceitos existe nesta fase (sem dependência entre itens, sem SLA, sem coleta assíncrona, sem condição por item — a condicionalidade é a nível de **grupo**, D-07, resolvida na montagem, não por item).
- Catálogo fechado de `status`: só `STATUS_ABERTO`/`STATUS_CONCLUIDO` (ver nota da migration acima).
- `chave` continua string curta e é o identificador estável — mas aqui não precisa "sobreviver a troca de versão de template" (não há template): é só o slug do item do catálogo fixo em código.
- Relação `company()` substitui `onboarding()`.

**Armadilha:**
- **Renomear o RÓTULO de um item nunca pode trocar a `chave`.** Ver seção "Como o denominador do progresso é calculado" mais abaixo — chave órfã (linha gravada com uma `chave` que saiu do catálogo) some do numerador mas pode continuar contando no denominador dependendo de como o service for escrito. Ver nota específica na seção do `ChecklistAdministrativoService`.

---

### `app/Contracts/ChecklistResolver.php`

**Papel:** contract · **Fluxo:** request-response (síncrono)
**Análogo:** `app/Contracts/OnboardingResolver.php` (arquivo completo, 56 linhas)

**Trecho a copiar:**
```php
// Source: app/Contracts/OnboardingResolver.php:22-55 (adaptado)
interface OnboardingResolver
{
    /**
     * Chave estável que bate 1:1 com `template_passos.auto_fonte` e com uma
     * constante de `OnboardingPasso::AUTO_FONTES`. Não traduzir.
     */
    public function chave(): string;

    public function label(): string;

    public function ajuda(): string;

    public function assincrono(): bool;

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado;
}
```

**O que muda:**
- Assinatura de `resolver()` recebe `Company $company` (e opcionalmente `ChecklistAdministrativoItem $item`), não `Onboarding`/`OnboardingPasso` — não há entidade "onboarding" nesta fase (D-10: ancorado em `company_id` direto).
- `assincrono(): bool` pode ser **removido do contrato** — todos os 4 resolvers desta fase são síncronos (RESEARCH.md, Pattern 1: "os itens 7/8 desta fase são síncronos... o item 7 não precisa da complexidade assíncrona"). Se mantido por uniformidade com o motor de Onboarding, todo resolver devolve `false` sempre — decisão do planejador, mas **manter o método morto tem custo zero e evita duas interfaces divergentes se a fase 153+ precisar de um resolver assíncrono no futuro**.
- `chave()` bate com uma constante em `ChecklistAdministrativoItem` (catálogo fechado dos 9 itens), não com `OnboardingPasso::AUTO_FONTES`.

**Armadilha:** nenhuma nova — a mesma disciplina do original vale aqui: nunca aceitar uma `chave` de resolver fora do catálogo fechado.

---

### `app/Services/ChecklistAdministrativo/ChecklistResolverResultado.php`

**Papel:** value object · **Fluxo:** transform
**Análogo:** `app/Services/Onboarding/OnboardingResolverResultado.php` (arquivo completo, 84 linhas)

**Trecho a copiar** (praticamente literal — o RESEARCH.md já usa este nome exato):
```php
// Source: app/Services/Onboarding/OnboardingResolverResultado.php:26-84
final readonly class OnboardingResolverResultado
{
    public const CONCLUIDO = 'concluido';
    public const NAO_COLETADO = 'nao_coletado';
    public const INDETERMINADO = 'indeterminado';

    private function __construct(
        public string $estado,
        public array $valor = [],
        public ?string $motivo = null,
    ) {
    }

    public static function concluido(array $valor = []): self
    {
        return new self(self::CONCLUIDO, $valor);
    }

    public static function naoColetado(?string $motivo = null, array $valor = []): self
    {
        return new self(self::NAO_COLETADO, $valor, $motivo);
    }

    public static function indeterminado(string $motivo): self
    {
        return new self(self::INDETERMINADO, [], $motivo);
    }

    public function ehConcluido(): bool { return $this->estado === self::CONCLUIDO; }
    public function ehNaoColetado(): bool { return $this->estado === self::NAO_COLETADO; }
    public function ehIndeterminado(): bool { return $this->estado === self::INDETERMINADO; }
}
```

**O que muda:**
- Renomeia para `ChecklistResolverResultado` (nome já usado no RESEARCH.md, "Pattern 1").
- Pode **remover** `CHAVE_COLETA_EM_ANDAMENTO`/`sinalizouColetaEmAndamento()` — não existe coleta assíncrona nesta fase.
- `INDETERMINADO` fica no código como estado do catálogo fechado mesmo sem nenhum resolver desta fase o produzir hoje — D-10 pede o shape de 3 estados **por disciplina**, não porque algum resolver síncrono vá usá-lo (nunca conclua a partir de um estado indeterminado é a regra que sobrevive mesmo sem uso ativo).

---

### `app/Services/ChecklistAdministrativo/Resolvers/MlOAuthConectadoResolver.php` (item 7)

**Papel:** resolver · **Fluxo:** request-response (leitura síncrona de coluna local)
**Análogo:** `app/Services/Onboarding/Resolvers/MlTokenAtivoResolver.php` (arquivo completo, 69 linhas) — **o RESEARCH.md recomenda cópia quase literal**

**Trecho a copiar:**
```php
// Source: app/Services/Onboarding/Resolvers/MlTokenAtivoResolver.php:30-69
class MlTokenAtivoResolver implements OnboardingResolver
{
    public function chave(): string
    {
        return OnboardingPasso::AUTO_FONTE_ML_TOKEN;
    }

    public function label(): string
    {
        return 'Token Mercado Livre ativo (OAuth)';
    }

    public function ajuda(): string
    {
        return 'Confere se ml_tokens.status = active para a empresa (síncrono, sem reautenticação).';
    }

    public function assincrono(): bool
    {
        return false;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        $token = $onboarding->company->mlToken;

        if ($token?->status === 'active') {
            return OnboardingResolverResultado::concluido([
                'ml_user_id'   => $token->ml_user_id,
                'conectado_em' => optional($token->connected_at)->toIso8601String(),
            ]);
        }

        if ($token !== null) {
            return OnboardingResolverResultado::naoColetado('Autorização do cliente foi revogada');
        }

        return OnboardingResolverResultado::naoColetado('Cliente ainda não autorizou o acesso');
    }
}
```

**O que muda:**
- `$onboarding->company->mlToken` vira `$company->mlToken` direto (recebe `Company $company`, não `Onboarding`) — `Company::mlToken()` já existe (`app/Models/Company.php:597-600`, `hasOne(MlToken::class)`).
- Fora isso, **é cópia literal** — mesma condição `$token?->status === 'active'`, mesmo `$token !== null` para distinguir revogado de nunca-conectado.
- **NÃO** chamar `MercadoLivreService::buildAuthUrl()` nem qualquer geração de link dentro deste resolver — o resolver só LÊ `ml_tokens`, nunca gera nada (D-05: geração é ação separada, via `POST /companies/{company}/ml/initiate`).

**Armadilha:**
- **Botão "copiar link", nunca `<a href>` que abre direto** (Pitfall 1 do RESEARCH.md — clique interno de usuário ECF logado autoriza a PRÓPRIA conta ML). Esta armadilha é da UI que consome o resultado deste resolver, não do resolver em si, mas o resolver é o lugar errado para "corrigir" isso com uma trava de servidor: a trava correta é de UI (`navigator.clipboard.writeText`, nunca `window.open`).
- O endpoint que gera o link é `POST /companies/{company}/ml/initiate` → `app/Http/Controllers/MercadoLivreOAuthController.php:52-57`:
  ```php
  // Source: app/Http/Controllers/MercadoLivreOAuthController.php:52-57
  public function initiate(Company $company)
  {
      $url = $this->ml->buildAuthUrl($company);
      return response()->json(['url' => $url]);
  }
  ```
  Este endpoint **já existe** — nada a criar aqui, só consumir do frontend do checklist (fetch/axios, exibir + botão copiar).

---

### `app/Services/ChecklistAdministrativo/Resolvers/ConexaoEcfResolver.php` (item 8)

**Papel:** resolver · **Fluxo:** request-response
**Análogo:** `app/Services/Onboarding/OnboardingLinkService.php:42-48` (`paraEmpresa()`)

**Trecho a copiar:**
```php
// Source: app/Services/Onboarding/OnboardingLinkService.php:42-48
public function paraEmpresa(Company $company): OnboardingLink
{
    return OnboardingLink::firstOrCreate(
        ['company_id' => $company->id],
        ['token' => Str::random(48)]
    );
}
```

**O que muda:** o resolver do item 8 **chama** este método existente (injeta `OnboardingLinkService`) e traduz a existência da linha para `ChecklistResolverResultado::concluido()` — não recria a lógica de `firstOrCreate`. D-14 já decidiu: "existência é sinal suficiente", então:
```php
// Forma esperada (não copiada — é a composição que o planejador escreve):
public function resolver(Company $company): ChecklistResolverResultado
{
    $link = $this->linkService->paraEmpresa($company); // firstOrCreate idempotente

    return ChecklistResolverResultado::concluido([
        'token_existe' => true,
    ]);
}
```

**Armadilha:** nenhuma — o método já é idempotente e sem rede (D-14 já mediu isso). O único cuidado é **não** chamar `paraEmpresa()` dentro de um contexto que renderiza a cada request sem necessidade (é `firstOrCreate`, barato, mas não precisa ser chamado 2x na mesma request se o controller já tiver o resultado).

---

### `app/Services/ChecklistAdministrativo/Resolvers/ContratoEnviadoResolver.php` (item 2) e `ContratoAssinadoResolver.php` (item 3)

**Papel:** resolver · **Fluxo:** request-response
**Análogo:** SEM resolver-análogo direto (não existe hoje um resolver do motor de Onboarding que leia `ContratoAssinatura`). O código a ler é citado literalmente no RESEARCH.md e no `ContratoAdminController::show()`.

**Trecho a copiar** (fonte do dado, item 2 — nunca escrever, só ler):
```php
// Source: 152-RESEARCH.md "Code Examples" (citando app/Jobs/ProcessarEventoClicksignJob.php:193-199/227-239
// e app/Jobs/ReconciliarContratoClicksignJob.php:108-115 como ÚNICOS pontos de escrita)
$contrato = ContratoAssinatura::where('company_id', $company->id)
    ->where('servico_id', $servicoQueExigeContrato->id)
    ->latest('id')
    ->first();
```

**Trecho a copiar** (fonte do dado, item 3 — D-16, OR entre 2 fontes):
```php
// Source: app/Models/ContratoLiberacao.php:129-134
public static function existeParaServico(int $companyId, int $servicoId): ?self
{
    return self::where('company_id', $companyId)
        ->where('servico_id', $servicoId)
        ->first();
}
```

**O que muda:**
- Item 2 (`ContratoEnviadoResolver`): `concluido` quando `enviado_em !== null` para **TODOS** os envelopes ativos do(s) serviço(s) que exigem contrato (D-18 — múltiplos envelopes, manda o mais atrasado).
- Item 3 (`ContratoAssinadoResolver`): `concluido` quando (`assinado_em !== null` OU `status === ContratoAssinatura::STATUS_ASSINADO`) **OU** `ContratoLiberacao::existeParaServico($company->id, $servico->id) !== null` (D-16 — a via `manual` de liberação nunca grava `contrato_assinaturas`). Constantes de status em `app/Models/ContratoAssinatura.php:113-119` (`STATUS_RASCUNHO`, `STATUS_AGUARDANDO_ASSINATURAS`, `STATUS_ASSINADO`, `STATUS_RECUSADO`, `STATUS_EXPIRADO`, `STATUS_CANCELADO`, `STATUS_ERRO`).
- D-18 (múltiplos envelopes): o resolver não pode pegar só `->latest('id')->first()` sem checar TODOS — precisa agregar por "pior estado entre os envelopes ativos do(s) serviço(s) que exigem contrato desta empresa".

**Armadilha:**
- **D5 da milestone: nunca escrever em `contrato_assinaturas` a partir deste resolver ou de qualquer código desta fase.** Os únicos pontos de escrita permitidos continuam sendo `ProcessarEventoClicksignJob` e `ReconciliarContratoClicksignJob` — a suíte de testes precisa ter uma asserção explícita disto (ver `ChecklistGrupoContratoAutoTest.php` na Wave 0).
- Pitfall 3 do RESEARCH (múltiplos envelopes) é caso raro (1 empresa de teste em ~190 no banco local) mas o schema permite — não assumir "1 serviço com contrato por empresa" sem testar o caso de 2+.
- Pitfall 2 do RESEARCH — CONFLITO já resolvido pelo D-16 do CONTEXT.md (leia as duas fontes, não só uma). Não implementar o item 3 lendo só `contrato_assinaturas` — regressão direta contra D-16.

---

### `app/Services/ChecklistAdministrativo/ChecklistAdministrativoDefinicao.php`

**Papel:** catálogo em código · **Fluxo:** transform
**Análogo:** citado por nome no RESEARCH.md (`app/Support/Onboarding/DefinicaoOnboarding.php`) como o molde de "os itens em código", mas **não foi lido linha a linha nesta passada** — ver nota.

**O que muda:** diferente de `DefinicaoOnboarding`, que é parametrizada por `Servico` e devolve passos de um template versionado em banco (`template_passos`), esta classe é **estática e fixa**: os 9 itens do D-03 (3 do grupo Contrato + 6 do grupo Entrada), cada um com `chave`, `titulo`, `grupo` (`contrato`|`entrada`), `natureza` (`manual`|`auto`), e — quando `auto` — a `chave` do resolver correspondente. Não há versionamento, não há tabela de template: mudar um rótulo é mudar uma constante em código e fazer deploy (D-02 já aceitou esse custo — não existe "não aplicável" configurável em runtime).

**SEM ANÁLOGO lido nesta passada** — recomendo ao planejador ler `app/Support/Onboarding/DefinicaoOnboarding.php` por completo antes de escrever esta classe, especificamente o método que devolve a lista de passos por serviço (`paraServico()`, citado no CONTEXT.md D-10), para decidir se o formato de array associativo por item é reaproveitável.

---

### `app/Services/ChecklistAdministrativo/ChecklistAdministrativoService.php`

**Papel:** service · **Fluxo:** CRUD + event-driven (reavalia ao marcar)
**Análogo:** `app/Services/Onboarding/OnboardingEngineService.php:506-664` (`aplicarResultado()`, `concluirManualmente()`, `reabrirPasso()`) + `app/Services/Onboarding/OnboardingSituacaoService.php:255-267` (`progresso()`)

**Trecho a copiar** (conclusão manual com recusa se tiver auto_fonte — D-10):
```php
// Source: app/Services/Onboarding/OnboardingEngineService.php:587-621
public function concluirManualmente(OnboardingPasso $passo, User $usuario, bool $forcar = false): void
{
    if ($passo->auto_fonte !== null && ! $forcar) {
        throw new \DomainException(
            "O passo \"{$passo->titulo}\" tem verificação automática — conclusão manual não é permitida (D-19)."
        );
    }

    if ($passo->auto_fonte !== null) {
        $passo->valor = array_merge($passo->valor ?? [], [
            'concluido_manualmente' => true,
            'override_por'          => $usuario->id,
            'override_em'           => now()->toISOString(),
        ]);
    }

    $passo->status = OnboardingPasso::STATUS_CONCLUIDO;
    $passo->feito_por = $usuario->id;
    $passo->feito_em = now();
    $passo->save();

    $this->reavaliar($passo->onboarding);
}
```

**Trecho a copiar** (desmarcar — limpa os DOIS campos juntos, D-11 ponto 2):
```php
// Source: app/Services/Onboarding/OnboardingEngineService.php:638-664
public function reabrirPasso(OnboardingPasso $passo, User $usuario): void
{
    if ($passo->status !== OnboardingPasso::STATUS_CONCLUIDO) {
        throw new \DomainException(
            "O passo \"{$passo->titulo}\" não está concluído — não há o que desmarcar."
        );
    }

    $passo->status = OnboardingPasso::STATUS_ABERTO;
    $passo->feito_por = null;
    $passo->feito_em = null;
    $passo->save();

    activity('onboarding')
        ->performedOn($passo->onboarding)
        ->withProperties(['passo_id' => $passo->id, 'chave' => $passo->chave, 'por' => $usuario->id])
        ->log("Passo \"{$passo->titulo}\" desmarcado");

    $this->reavaliar($passo->onboarding);
}
```

**Trecho a copiar** (aplicar resultado de resolver automático):
```php
// Source: app/Services/Onboarding/OnboardingEngineService.php:506-538 (versão simplificada — sem os ramos de coleta assíncrona)
public function aplicarResultado(OnboardingPasso $passo, OnboardingResolverResultado $resultado): void
{
    if ($resultado->ehConcluido()) {
        $passo->status = OnboardingPasso::STATUS_CONCLUIDO;
        $passo->valor = $resultado->valor;
        $passo->auto_em = now();
    } else {
        $passo->status = OnboardingPasso::STATUS_ABERTO;
    }

    $passo->save();
}
```

**Trecho a copiar — CÁLCULO DO DENOMINADOR DO PROGRESSO** (essencial para não repetir a armadilha "chave órfã"):
```php
// Source: app/Services/Onboarding/OnboardingSituacaoService.php:218-267
public function contadores(Collection $passos): array
{
    return [
        'abertos'    => $passos->where('status', OnboardingPasso::STATUS_ABERTO)->count(),
        'concluidos' => $passos->where('status', OnboardingPasso::STATUS_CONCLUIDO)->count(),
        // ... demais estados
    ];
}

public function progresso(Collection $passos): array
{
    $contadores = $this->contadores($passos);

    $total = $passos->count() - $contadores['nao_aplicaveis'];
    $feitos = $contadores['concluidos'];

    return [
        'feitos'     => $feitos,
        'total'      => $total,
        'percentual' => $total > 0 ? (int) round($feitos / $total * 100) : 0,
    ];
}
```

**O que muda:**
- `concluirManualmente()`: troca `OnboardingPasso $passo` por `ChecklistAdministrativoItem $item`; `$passo->onboarding` não existe — não há `reavaliar()` de dependências (sem `depende_de` nesta fase). O que precisa acontecer no lugar do `reavaliar()`: **disparar a transição de etapa condizente (D-15)** — ver seção do `FinalizarEntradaAdministrativaService` abaixo. Isso é uma diferença estrutural real: no Onboarding, `reavaliar()` só destrava passos dependentes; aqui, marcar um item precisa também avançar `companies.etapa` (1º item concluído → etapa 2; ver D-15).
- **`auto_fonte` não existe como coluna em `ChecklistAdministrativoItem`** (a menos que o planejador decida manter o campo por simetria) — a distinção manual/auto é decidida na `ChecklistAdministrativoDefinicao` (catálogo fixo, D-03 já diz quais dos 9 itens são manuais/auto), não por coluna de instância. A recusa de `concluirManualmente()` em item automático (D-10, "a recusa... em passo que tem auto_fonte") deve consultar o catálogo (`ChecklistAdministrativoDefinicao::item($chave)->natureza === 'auto'`), não uma coluna da linha.
- **O `progresso()` desta fase É MAIS SIMPLES que o do Onboarding**, e essa simplicidade é a resposta direta à armadilha "chave órfã" citada no `pattern_mapping_context`: como D-02 proíbe `nao_aplicavel` e D-07 resolve a isenção por **montagem condicional** (o item simplesmente não é instanciado para empresa isenta), o denominador do progresso não precisa subtrair nada — é `count()` da lista de itens **que a `ChecklistAdministrativoDefinicao` decidiu que existem** para aquela empresa (6 ou 9, conforme `Servico::exigeContrato()`), nunca `count()` das linhas gravadas na tabela `checklist_administrativo_itens`. Se a montagem for **lazy** (linha só existe quando o item é resolvido/marcado pela 1ª vez — RESEARCH.md, assumption A2), o denominador vem do **catálogo em código**, não da tabela — uma linha ausente é lida como "aberto", nunca como "não existe mais". Isto é a proteção equivalente à do Onboarding (que subtrai `nao_aplicaveis` do total), só que resolvida no nascimento em vez de na contagem.

**Armadilha:**
- **Renomear o item no catálogo NÃO pode trocar a `chave`.** É a mesma armadilha já registrada em `project_onboarding_observacoes_publicacao_260902` (ver MEMORY.md do usuário): "Renomear item do checklist NÃO pode trocar o `id`: chave órfã entra no denominador de `progresso()` e a ficha nunca mais fecha 100%." Aqui a consequência é diferente do Onboarding (que lê da tabela) porque o denominador desta fase vem do catálogo em código — mas o RISCO INVERSO existe: se uma linha **já gravada** em `checklist_administrativo_itens` tiver uma `chave` que saiu do catálogo (ex.:Chave renomeada em deploy), essa linha vira lixo órfão que nunca aparece na tela (porque a tela itera pelo catálogo, não pela tabela) — não trava o progresso, mas deixa uma linha morta no banco. Não é a mesma falha, mas é a mesma FAMÍLIA de bug: nunca tratar `chave` como cosmético.
- Não confundir "resolver automático roda e não altera nada" com erro — `aplicarResultado()` pode ser chamado toda vez que a tela carrega (os 4 resolvers automáticos são baratos, leitura de coluna local) sem problema de performance, mas **decida explicitamente se roda a cada `show()` ou só sob demanda** (botão "reavaliar") — o RESEARCH não resolveu isso, e rodar 4 resolvers síncronos a cada carregamento da ficha é aceitável (são leituras de coluna, não chamadas de API — diferente do `AdmanGrantResolver`, que faz rede e por isso é assíncrono no motor de Onboarding).

---

### `app/Services/ChecklistAdministrativo/FinalizarEntradaAdministrativaService.php`

**Papel:** service · **Fluxo:** request-response (régua pura + efeito, mesmo par que `EtapaTransicaoService`)
**Análogo:** `app/Services/FluxoEntrada/EtapaTransicaoService.php` (arquivo completo, 333 linhas) — citado no CONTEXT.md D-15 como "toda transição passa por `EtapaTransicaoService::transicionar()` — nunca `update()` direto"

**Trecho a copiar** (o par régua-pura / efeito, `podeTransicionar()`):
```php
// Source: app/Services/FluxoEntrada/EtapaTransicaoService.php:96-158
public function podeTransicionar(Company $company, string $etapaDestino): array
{
    if (! in_array($etapaDestino, Company::ETAPAS, true)) {
        return ['permitido' => false, 'retrocesso' => false, 'requisito_faltante' => "..."];
    }
    if ($company->etapa === $etapaDestino) {
        return ['permitido' => false, 'retrocesso' => false, 'requisito_faltante' => "..."];
    }
    // ... índice atual/destino, retrocesso, tabela de transições permitidas
    return ['permitido' => true, 'retrocesso' => false, 'requisito_faltante' => null];
}
```

**Trecho a copiar** (chamador de produção — como disparar `transicionar()` corretamente, ator = `$request->user()`, nunca `user_id` do corpo):
```php
// Source: app/Http/Controllers/ComercialController.php:683-690
$resultado = app(EtapaTransicaoService::class)->transicionar(
    $company,
    Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
    $request->user(),
);
if ($resultado['status'] !== 'transicionado') {
    Log::warning('[Comercial] transição de nascimento inesperada', ['company_id' => $company->id, 'resultado' => $resultado]);
}
```

**Trecho a copiar** (as 9 etapas + a tabela de transições permitidas — decide onde o FINALIZAR encaixa, D-15):
```php
// Source: app/Services/FluxoEntrada/EtapaTransicaoService.php:58-75
private const TRANSICOES_PERMITIDAS = [
    ''                                        => [Company::ETAPA_AGUARDANDO_ADMINISTRATIVO],
    Company::ETAPA_AGUARDANDO_ADMINISTRATIVO  => [Company::ETAPA_ADMINISTRATIVO_ANDAMENTO],
    Company::ETAPA_ADMINISTRATIVO_ANDAMENTO   => [
        Company::ETAPA_AGUARDANDO_ASSINATURA,
        Company::ETAPA_ADMINISTRATIVO_CONCLUIDO, // salto 2→4, empresa isenta (D-07)
    ],
    Company::ETAPA_AGUARDANDO_ASSINATURA      => [Company::ETAPA_ADMINISTRATIVO_CONCLUIDO],
    Company::ETAPA_ADMINISTRATIVO_CONCLUIDO   => [Company::ETAPA_AGUARDANDO_DISTRIBUICAO],
    // ...
];
```

**O que muda:**
- Este service NÃO reimplementa `podeTransicionar()`/`transicionar()` — **chama** `EtapaTransicaoService` (injetado), como `ComercialController` já faz. O que é NOVO aqui é a **régua de negócio específica do FINALIZAR** (ADMIN-05): "todos os itens obrigatórios concluídos E contrato assinado" — isso é checado **antes** de chamar `EtapaTransicaoService::transicionar($company, Company::ETAPA_AGUARDANDO_DISTRIBUICAO, $request->user())`, e é uma régua PURA própria (`podeFinalizarar(Company $company): array`, mesma forma de retorno `{permitido, requisito_faltante}` que `EtapaTransicaoService::podeTransicionar()` já usa) — a mesma função serve ao botão desabilitado no frontend E à recusa programática no backend (nunca duas fontes de verdade, mesmo princípio do docblock de `EtapaTransicaoService.php:16-17`).
- D-15 (progressão da etapa DIRIGIDA pelo checklist, não só pelo clique final) significa que este service **não é o único chamador** de `transicionar()` nesta fase: `ChecklistAdministrativoService::concluirManualmente()`/`aplicarResultado()` também chamam `transicionar()` (1º item → etapa 2; envelope enviado → etapa 3; tudo pronto + assinado → etapa 4). O FINALIZAR só cobre o ÚLTIMO salto (4 → 5).
- Destino final: `Company::primaryMarketplace()` (`app/Models/Company.php:775-782`) — leitura pura, sem escrita adicional. **Este service NÃO decide o roteamento do módulo do marketplace** (isso é responsabilidade de outra camada/fase, conforme `primaryMarketplace()` já devolve o slug) — o service só GARANTE que a transição de etapa aconteça; o "mesmo cadastro, sem novo registro" (ADMIN-06) já é uma propriedade de `EtapaTransicaoService::transicionar()`, que só faz `update(['etapa' => ...])` na MESMA linha `Company`.

**Armadilha:**
- **`is_primary` é guard de Model, não constraint de banco** (D-12) — nada impede 2 linhas `is_primary = true` na pivot `company_marketplaces`. `primaryMarketplace()` usa `->where('is_primary', true)->value('marketplace')` — com 2 linhas, o Eloquent `value()` devolve a PRIMEIRA da query (ordem não garantida sem `orderBy` explícito). O comportamento precisa ser **determinístico**, ainda que arbitrário (teste `FinalizarTransicaoEtapaTest.php` cobre isso, D-12 defesa) — não é bug a corrigir aqui, é comportamento a **documentar e testar**, não silenciar.
- **Nunca escrever `companies.etapa` fora de `EtapaTransicaoService::transicionar()`** — nenhum `Company::whereKey(...)->update(['etapa' => ...])` solto em lugar nenhum desta fase. `EtapaTransicaoService.php:261-271` já documenta por que (o `Observer` de gatilho de contrato reage a `wasChanged(CAMPOS_GATILHO)`, e `etapa` foi deliberadamente mantida fora dessa lista — só é seguro porque a escrita passa exclusivamente por `update(['etapa' => $etapaDestino])` sozinho, nunca junto de outro campo no mesmo `save()`).
- `transicionar()` já é `DB::transaction()` + `lockForUpdate()` — **não envolver a chamada a `transicionar()` dentro de OUTRA transação externa que também escreva em `Company`** sem entender a interação de locks (o service já resolve concorrência internamente).

---

### `app/Http/Controllers/ContratoAdminController.php` (modificado)

**Papel:** controller · **Fluxo:** request-response
**Análogo:** ele mesmo — `show()` (`app/Http/Controllers/ContratoAdminController.php:507-693`) e `liberarManual()` (`:1201-1244`)

**Trecho a copiar** (como o controller já injeta serviços de leitura pura e monta props achatadas — mesmo padrão a seguir para o checklist):
```php
// Source: app/Http/Controllers/ContratoAdminController.php:507-530
public function show(
    Company $company,
    ContratoDadosMinimosService $dados,
    GatilhoContratoAdministrativoService $gatilho,
    ContratoClicksignService $clicksign,
    ContratosPresosService $presos,
    CongelamentoEmissaoService $congelamento,
    ContratoPdfService $pdfDados,
): \Inertia\Response {
    $company->loadMissing('contratosServico.servico');

    $faltantes = $dados->faltantes($company);
    $avaliacao = $gatilho->avaliar($company);
    // ...
    $podeGerarContrato = $dados->estaPronta($company) && $avaliacao['status'] === 'elegivel' && ! $temServicoDuplicado;
```

**Trecho a copiar** (endpoint de ação — mesma forma de `liberarManual()` para os endpoints marcar/desmarcar/finalizar):
```php
// Source: app/Http/Controllers/ContratoAdminController.php:1201-1243
public function liberarManual(Request $request, EmpresaOperacionalRouter $router): RedirectResponse
{
    $data = $request->validate([
        'company_id'             => ['required', 'integer', 'exists:companies,id'],
        'servico_id'             => ['required', 'integer', 'exists:servicos,id'],
        // ...
    ]);

    $company = Company::findOrFail($data['company_id']);
    // ...

    $router->liberarEmpresa(
        $company,
        $servico,
        ContratoLiberacao::VIA_MANUAL,
        // ...
        liberadoPorUserId: $request->user()->id,
    );

    return back()->with('success', 'Empresa liberada para o operacional.');
}
```

**O que muda:**
- `show()` ganha a injeção de `ChecklistAdministrativoService $checklist` e uma nova chave `'checklist' => $checklist->paraEmpresa($company)` no array `Inertia::render('Admin/ContratoDetalhe', [...])` (`:583`).
- Novos métodos: algo como `marcarItemChecklist(Request $request, ChecklistAdministrativoItem $item)`, `desmarcarItemChecklist(...)`, `finalizarEntradaAdministrativa(Request $request, Company $company)` — todos seguindo o padrão de `liberarManual()`: `$request->validate()` com catálogo fechado, `$request->user()` como ator (NUNCA `user_id` do corpo — mesma disciplina T-150-02/D-17 citada no `EtapaTransicaoService`), `return back()->with('success', ...)`.
- **IDOR (Security Domain do RESEARCH.md, V4):** todo endpoint de marcar/desmarcar precisa validar que o `ChecklistAdministrativoItem` recebido por rota (route-model-binding) pertence à `company_id` correta — mesmo padrão de `liberarManual()` linhas 1220-1225 (`abort(422, ...)` quando o contrato não bate com a empresa/serviço do POST). Se o item for acessado por rota aninhada `companies/{company}/checklist/{item}`, o binding de rota já ajuda, mas ainda assim validar `$item->company_id === $company->id` explicitamente.

**Armadilha:** nenhuma nova além das já documentadas no arquivo (ver comentários de `show()` sobre `$temServicoDuplicado`, `$congelamento->ativo()` — a lógica de bloqueio de geração de contrato é ortogonal ao checklist e não deve ser tocada).

---

### `routes/web.php` (modificado)

**Papel:** rota · **Fluxo:** request-response
**Análogo:** o próprio grupo `admin.contratos.*` (`routes/web.php:1430-1462`) + `EnsurePermission` (OR nativo)

**Trecho a copiar** (a linha exata a mudar, D-17):
```php
// Source: routes/web.php:1443 (ANTES)
Route::middleware(['auth', 'verified', 'permission:admin.contratos'])->prefix('administrativo/contratos')->name('admin.contratos.')->group(function () {
    // ...
    Route::get('/empresa/{company}', [ContratoAdminController::class, 'show'])->name('show');
```

**Trecho a copiar** (o middleware que já aceita OR nativamente — nenhuma mudança de middleware necessária, só de string):
```php
// Source: app/Http/Middleware/EnsurePermission.php:22-38
public function handle(Request $request, Closure $next, string ...$keys): Response
{
    $user = $request->user();
    if (!$user) {
        abort(403, 'Acesso não autorizado.');
    }
    foreach ($keys as $key) {
        if ($user->hasPermission($key)) {
            return $next($request);
        }
    }
    abort(403, 'Você não tem permissão para acessar esta área.');
}
```

**O que muda:**
- A rota `show` (**só ela**, não o grupo inteiro `admin.contratos.*`) precisa aceitar `permission:admin.contratos,comercial.entrada` (D-17). Como `Route::middleware(['auth','verified','permission:admin.contratos'])->group(...)` aplica o middleware a TODAS as rotas do grupo, a forma mais simples é **tirar a rota `show` do grupo** e declará-la fora, com seu próprio middleware:
  ```php
  // Forma esperada (composição, não cópia literal):
  Route::middleware(['auth', 'verified', 'permission:admin.contratos,comercial.entrada'])
      ->get('/administrativo/contratos/empresa/{company}', [ContratoAdminController::class, 'show'])
      ->name('admin.contratos.show');
  ```
  As demais rotas do grupo (`cadastro`, `gerar`, `reenviar`, `cancelamento`, `refazer`, `liberacao-manual`) continuam só com `admin.contratos` — só a SHOW é compartilhada (D-17: "a permissão de rota abre a ficha; a permissão de módulo decide o que aparece nela").
- Novas rotas de ação do checklist (marcar/desmarcar/finalizar) — a permissão de CADA uma depende de qual grupo o item pertence: itens de Entrada exigem `comercial.entrada` OU `admin.contratos` (D-09: "comercial.entrada para os itens de Entrada... admin.contratos para os itens de Contrato" — mas como as duas abrem a mesma ficha via D-17, a régua real de quem pode CLICAR em cada botão pode ser OR também, com a UI escondendo os botões da seção que o usuário não tem permissão de ver).

**Armadilha:**
- **Pitfall 4 do RESEARCH — não estava coberto por nenhuma das 14 decisões originais do CONTEXT.md; foi fechado pelo D-17.** Não reintroduzir o bug: se a rota `show` continuar dentro do grupo com só `permission:admin.contratos`, um usuário só-`comercial.entrada` recebe 403 ao clicar "Abrir" na listagem Entrada — regressão direta contra D-08.
- Testar com `Route::getRoutes()->getByName('admin.contratos.show')->gatherMiddleware()` — é a asserção por NOME de rota que `ComercEntradaPermissaoRotaTest.php` já usa (ver seção de testes), não por prefixo/texto do arquivo de rotas.

---

### `resources/js/Pages/Admin/ContratoDetalhe.jsx` (modificado)

**Papel:** página React · **Fluxo:** request-response
**Análogo:** ele mesmo (imports, `useForm`, estrutura de Cards) — `resources/js/Pages/Admin/ContratoDetalhe.jsx:1-90, 270-340`

**Trecho a copiar** (padrão de import + `useForm` + submit para uma ação):
```jsx
// Source: resources/js/Pages/Admin/ContratoDetalhe.jsx:1-14, 283-289
import { Fragment, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Link, router, useForm, usePage } from '@inertiajs/react';
// ...

function confirmarLiberacao(e) {
    e.preventDefault();
    liberarForm.post(route('admin.contratos.liberacao-manual'), {
        preserveScroll: true,
        onSuccess: () => setLiberarContratoId(null),
    });
}
```

**Trecho a copiar** (ponto de inserção — a seção fica dentro de `<div className="space-y-6 max-w-4xl">`, junto das demais Cards):
```jsx
// Source: resources/js/Pages/Admin/ContratoDetalhe.jsx:297-338 (estrutura do retorno)
return (
    <AppLayout title={`Adm · Contrato — ${company.name}`}>
        <main className="p-6">
            <div className="space-y-6 max-w-4xl">
                {/* Cabeçalho */}
                <div>...</div>
                {flash?.success && (...)}
                {flash?.error && (...)}
                {/* NOVO — Card do Checklist Administrativo entra aqui, ANTES ou
                    DEPOIS do bloco de "pode_gerar_contrato" conforme decisão de
                    UI (não travada no CONTEXT.md — o planejador decide a ordem
                    visual) */}
```

**O que muda:**
- Recebe a nova prop `checklist` de `ContratoAdminController::show()` e renderiza uma seção nova usando `LinhaChecklistItem` (componente novo, ver abaixo) para cada item, agrupado em duas colunas/blocos "Contrato" e "Entrada" — Contrato só aparece se `checklist.grupos.contrato` existir (D-07: pode nem vir no payload para empresa isenta).
- Botão FINALIZAR: espelha exatamente `disabled={!pode_finalizar}` vindo do backend — nunca calcular a condição de habilitação no client (mesmo princípio do "Ponto focal da tela" já documentado no comentário de linha 334-337 do arquivo).

**Armadilha:** `npm run build` ao final — convenção obrigatória do projeto para qualquer mudança de frontend.

---

### `resources/js/Pages/Comercial/Entrada.jsx` (modificado)

**Papel:** página React · **Fluxo:** request-response
**Análogo:** `resources/js/Pages/Admin/Contratos.jsx:308-315` (link "Abrir" para a MESMA rota)

**Trecho a copiar (literal, é exatamente o padrão que D-08 pede):**
```jsx
// Source: resources/js/Pages/Admin/Contratos.jsx:308-315
<TableCell>
    <Link
        href={route('admin.contratos.show', linha.company_id)}
        onClick={(e) => e.stopPropagation()}
        className="text-[12px] text-white/50 hover:text-white/80 hover:underline"
    >
        Abrir
    </Link>
</TableCell>
```

**O que muda:** troca `linha.company_id` por `c.id` (a variável de linha em `Entrada.jsx` é `c`, não `linha` — ver `resources/js/Pages/Comercial/Entrada.jsx:206-256`); adiciona `import { Link } from '@inertiajs/react'` (hoje `Entrada.jsx:6` só importa `router`, não `Link` — `resources/js/Pages/Comercial/Entrada.jsx:6`); adiciona uma nova `<TableHead>Ações</TableHead>` e a célula correspondente na tabela existente.

**Armadilha:** o comentário de linha 86-89 do próprio arquivo já avisa: *"o checklist dos 8 itens... chega na Fase 152. Nenhum controle desta tela finge que esses itens já existem"* — esse comentário fica **obsoleto** com esta fase e precisa ser atualizado/removido, senão passa a mentir sobre o próprio código.

---

### `resources/js/Components/ChecklistAdministrativo/LinhaChecklistItem.jsx`

**Papel:** componente React · **Fluxo:** request-response
**Análogo:** `resources/js/Components/Onboarding/Painel/DetalheOnboarding.jsx` (exporta `LinhaPasso`, linhas 120-209)

**Trecho a copiar (forma geral — marcar/desmarcar com useForm + botões condicionais por estado):**
```jsx
// Source: resources/js/Components/Onboarding/Painel/DetalheOnboarding.jsx:120-181
export function LinhaPasso({ passo, onboardingId, confirmacao }) {
    const form = useForm({});

    const concluirManualmente = () => {
        form.post(route('onboarding.passos.concluir', passo.id), { preserveScroll: true });
    };

    const desmarcar = () => {
        form.post(route('onboarding.passos.reabrir', passo.id), { preserveScroll: true });
    };

    const podeConcluir = passo.status === 'aberto';
    const ehOverride = passo.status === 'aberto' && passo.tem_auto_fonte;
    const podeDesmarcar = passo.status === 'concluido';

    return (
        <div className="rounded-xl border border-white/[0.06] bg-white/[0.015] p-4 space-y-2">
            <div className="flex items-center justify-between gap-3 flex-wrap">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-white font-semibold text-[14px]">{passo.titulo}</span>
                    {passo.tem_auto_fonte && (
                        <Zap size={14} className="text-ecf-yellow shrink-0" title="Passo verificado automaticamente pelo sistema" />
                    )}
                </div>
                <div className="flex items-center gap-2">
                    {podeConcluir && (
                        <Button size="sm" variant="outline" onClick={concluirManualmente} disabled={form.processing}>
                            {ehOverride ? 'Concluir mesmo assim' : 'Marcar como concluído'}
                        </Button>
                    )}
                    {podeDesmarcar && (
                        <button onClick={desmarcar} disabled={form.processing} className="text-white/40 hover:text-white text-[12px]">
                            Desmarcar
                        </button>
                    )}
                </div>
            </div>
            {/* estado + autoria */}
        </div>
    );
}
```

**O que muda:**
- `passo.id`/`passo.titulo`/`passo.status`/`passo.tem_auto_fonte` viram `item.id`/`item.titulo`/`item.status`/`item.natureza === 'auto'`.
- Rotas `onboarding.passos.concluir`/`reabrir` viram as rotas novas desta fase (ex.: `checklist.itens.concluir`/`checklist.itens.reabrir` — nomes exatos ficam a critério do plano).
- **Não existe estado `bloqueado`/`aguardando_coleta`/`indeterminado`/`nao_aplicavel`** — a função `EstadoPasso` do análogo (linhas 52-117) precisa ser MUITO mais simples: só 2-3 ramos (`aberto`/`concluido`, e talvez um terceiro visual para "auto, ainda não confirmado" vs "auto, confirmado automaticamente").
- Exibir autoria (`item.feito_por_nome`/`item.feito_em`) quando concluído manualmente — não há componente análogo pronto para isso especificamente; compor a partir do padrão de exibição de outros badges de autoria do projeto (`resources/js/Components/Onboarding/Painel/DonoBadge.jsx` é o mais próximo, mas mostra "de quem é a bola", não "quem concluiu" — pode precisar de um badge novo, pequeno, tipo "Concluído por {nome} em {data}").

**Armadilha:** botão do item 7 (Grant OAuth) **NÃO pode ser um link de abrir direto** — precisa ser "copiar link" (ver Pitfall 1, já detalhado na seção do `MlOAuthConectadoResolver` acima). Este é o único item cujo botão de ação não é um simples "marcar/desmarcar" — ele dispara `POST /companies/{company}/ml/initiate` e copia a URL da resposta para a área de transferência.

---

### `resources/js/Components/ChecklistAdministrativo/ChecklistProgresso.jsx` (opcional)

**Papel:** componente React · **Fluxo:** transform
**Análogo:** `resources/js/Components/Onboarding/Painel/ProgressoBarra.jsx` (arquivo completo, 67 linhas)

**Trecho a copiar (praticamente literal — mesma barra, mesmo contrato de props `{feitos, total, percentual}`):**
```jsx
// Source: resources/js/Components/Onboarding/Painel/ProgressoBarra.jsx:20-67
export default function ProgressoBarra({ progresso, className, compacto = false }) {
    if (!progresso || progresso.total === 0) {
        return <span className="text-white/30 text-[13px]">—</span>;
    }
    const { percentual, feitos, total } = progresso;
    const completo = percentual >= 100;
    return (
        <div className={cn('min-w-[92px]', className)}>
            <div className="flex items-baseline gap-1.5">
                <span className={cn('text-[13px] font-semibold tabular-nums', completo ? 'text-emerald-300' : 'text-white/80')}>
                    {percentual}%
                </span>
                {!compacto && <span className="text-[11px] text-white/35 tabular-nums">{feitos} de {total}</span>}
            </div>
            <div className="mt-1 h-1.5 w-full rounded-full bg-white/[0.07] overflow-hidden" role="progressbar" aria-valuenow={percentual}>
                <div className={cn('h-full rounded-full transition-[width] duration-500', completo ? 'bg-emerald-400/80' : 'bg-ecf-yellow/80')}
                     style={{ width: `${Math.min(100, Math.max(0, percentual))}%` }} />
            </div>
        </div>
    );
}
```

**O que muda:** nada na lógica visual — o contrato `{feitos, total, percentual}` já bate exatamente com o que `ChecklistAdministrativoService::progresso()` (se copiado como recomendado acima) devolve. **Pode ser reusado por IMPORT direto do componente existente**, sem duplicar o arquivo, se o planejador preferir — `ProgressoBarra` não tem nenhuma dependência específica de Onboarding no corpo do componente.

---

### `config/services.php` (modificado)

**Papel:** config · **Fluxo:** —
**Análogo:** bloco `'hubspot' => [...]` (`config/services.php:120-133`), citado no CONTEXT.md D-04 como "o mesmo padrão que a Fase 151 usou para as properties do HubSpot"

**Trecho a copiar (padrão de chave configurável com default seguro, mesmo grupo `adman` já existente):**
```php
// Source: config/services.php:38-41 (bloco 'adman' já existente, HOJE só com base_url/api_key)
'adman' => [
    'base_url' => env('ADMAN_BASE_URL', 'https://api.adman.com.br/v1'),
    'api_key'  => env('ADMAN_API_KEY', ''),
],
```

**O que muda:** adicionar a chave `register_url` dentro do MESMO array `'adman'` já existente (não um array novo):
```php
// Forma exata dada pelo CONTEXT.md D-04 (citação literal, não paráfrase):
'adman' => [
    'base_url'     => env('ADMAN_BASE_URL', 'https://api.adman.com.br/v1'),
    'api_key'      => env('ADMAN_API_KEY', ''),
    'register_url' => env('ADMAN_REGISTER_URL', 'https://app.ad-man.io/register?ref=588D0DD78C4F'),
],
```

**Armadilha:** nenhuma — trocar o `ref` em produção vira mudança de `.env` na VPS, sem deploy (é exatamente o motivo da decisão D-04).

---

### `.env.example` (modificado)

**Papel:** config · **Fluxo:** —
**Análogo:** bloco `HUBSPOT_PROP_*` (`.env.example:102-135`) — role-match, não exato

**O que muda:** adicionar a linha `ADMAN_REGISTER_URL=` (comentada ou com o valor padrão) perto de onde outras chaves `ADMAN_*` estariam.

**Armadilha:** ⚠️ **`ADMAN_BASE_URL`/`ADMAN_API_KEY` (já usadas por `config/services.php:39-40` desde antes desta fase) NÃO ESTÃO em `.env.example` hoje** — busca por `grep -in adman .env.example` não retornou nenhuma linha. Isso é uma lacuna **pré-existente**, não introduzida por esta fase, mas significa que não há um bloco `ADMAN_*` já pronto para replicar o padrão de formatação — o planejador precisa criar o bloco do zero (ou decidir se aproveita a oportunidade para também documentar `ADMAN_BASE_URL`/`ADMAN_API_KEY`, que é trabalho ligeiramente fora do escopo literal de D-04 mas resolveria uma inconsistência real). Registrar a decisão no PLAN, não assumir silenciosamente.

---

### `.planning/REQUIREMENTS-v23.md` (modificado à mão)

**Papel:** docs · **Fluxo:** —
**SEM ANÁLOGO DE CÓDIGO.** É edição de prosa: registrar D-06 e D-16 como exceções explícitas ao ADMIN-02, junto ao próprio requisito ADMIN-02 no arquivo. ⚠️ Editar `REQUIREMENTS-v23.md` diretamente — os verbos `gsd-sdk query requirements.*` escrevem no `REQUIREMENTS.md` sem sufixo (arquivo da v17, stale), conforme o próprio CONTEXT.md avisa.

---

## Testes — Wave 0 (11 arquivos)

**Análogos:** `tests/Feature/Phase131/ContratoAdminDetalheTest.php` (setup de empresa+serviço+contrato via factory/`Servico::create`/`ContratoServico::withoutEvents`), `tests/Feature/Phase151/ComercEntradaPermissaoRotaTest.php` (permissão OR por nome de rota), `tests/Feature/Phase151/ComercEtapaNascimentoCadastroManualTest.php` (asserção de etapa + histórico), `tests/Unit/Phase150/EtapaTransicaoServiceTest.php` (unit puro sobre a régua de transição).

### Setup de empresa com serviço que exige contrato — copiar literalmente

```php
// Source: tests/Feature/Phase131/ContratoAdminDetalheTest.php:56-133
private function admin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

private function servicoComContrato(string $nome = 'Gestão de Tráfego (detalhe admin)'): Servico
{
    return Servico::create([
        'nome'           => $nome,
        'valor_padrao'   => 100,
        'tipo_cobranca'  => Servico::TIPO_MENSAL,
        'ativo'          => true,
        'setor'          => Servico::SETOR_PERFORMANCE,
        'exige_contrato' => true,
    ]);
}

private function empresaCompleta(array $overrides = []): Company
{
    return Company::factory()->create(array_merge([
        'active'        => true,
        'cnpj'          => '11.222.333/0001-81',
        'email_cliente' => 'cliente@example.com',
        'nome_contato'  => 'Contato de Teste',
        'razao_social'  => 'Contato de Teste LTDA',
        'endereco'      => 'Rua de Teste, 123',
        'bairro'        => 'Bairro de Teste',
        'cidade'        => 'Cidade de Teste',
        'estado'        => 'TS',
        'cep'           => '00000-000',
    ], $overrides));
}

/**
 * `withoutEvents`: sem isto, `ContratoServico::create()` dispara o
 * `ContratoServicoGatilhoObserver` como efeito colateral do SETUP, antes
 * da chamada explícita que cada teste está medindo.
 */
private function vincularServico(Company $c, Servico $s, array $overrides = []): ContratoServico
{
    return ContratoServico::withoutEvents(fn () => ContratoServico::create(array_merge([
        'company_id'       => $c->id,
        'data_primeira_parcela' => now()->addMonth()->toDateString(),
        'dia_vencimento'        => 10,
        'servico_id'       => $s->id,
        'valor_contratado' => 100,
        'data_contratacao' => now()->toDateString(),
        'ativo'            => true,
    ], $overrides)));
}
```

**Armadilha (crítica para TODOS os 11 testes que criam `ContratoServico`):** sempre usar `ContratoServico::withoutEvents(fn () => ...)` — sem isso, `ContratoServicoGatilhoObserver` roda como efeito colateral do SETUP (não do que o teste está de fato medindo), podendo disparar geração de contrato de verdade dentro do teste. `ContratoAdminDetalheTest::setUp()` (linhas 40-51) também faz `Http::fake()`, `Queue::fake()` e `config(['services.clicksign.signatarios_ecf' => []])` como blindagem — replicar em qualquer teste desta fase que toque `ContratoServico`/`ContratoAssinatura`.

### Setup de permissão via setor — copiar literalmente (D-17)

```php
// Source: tests/Feature/Phase151/ComercEntradaPermissaoRotaTest.php:36-55
private function userComPermissaoViaSetor(string $permissionKey): User
{
    $setor = Setor::create([
        'nome'   => 'Setor Entrada Teste',
        'slug'   => 'entrada-teste-'.uniqid(),
        'active' => true,
    ]);
    SetorPermissao::create([
        'setor_id'       => $setor->id,
        'permission_key' => $permissionKey,
    ]);
    $user = User::factory()->create(['role' => 'consultor']);
    $setor->membros()->attach($user->id, [
        'is_principal' => true,
        'assigned_at'  => now(),
    ]);

    return $user;
}
```

**Asserção por nome de rota (não por texto do arquivo de rotas):**
```php
// Source: tests/Feature/Phase151/ComercEntradaPermissaoRotaTest.php:58-79
$rota = Route::getRoutes()->getByName('admin.contratos.show');
$middlewares = $rota->gatherMiddleware();
$temPermissionCerta = collect($middlewares)->contains(
    fn (string $m) => $m === 'permission:'.Permissions::ADMIN_CONTRATOS.','.Permissions::COMERCIAL_ENTRADA
);
```
> ⚠️ Atenção ao formato exato da string de middleware — `permission:a,b` é UMA string com vírgula, não duas entradas separadas em `$middlewares`. Testar contra a string exata que a rota vai declarar.

### Setup de transição de etapa pura — copiar literalmente

```php
// Source: tests/Unit/Phase150/EtapaTransicaoServiceTest.php:24-34
protected function setUp(): void
{
    parent::setUp();
    $this->service = new EtapaTransicaoService();
}

private function empresaNaEtapa(?string $etapa): Company
{
    return Company::factory()->create(['etapa' => $etapa]);
}
```

### Asserção de etapa + histórico por reconsulta ao banco — copiar literalmente

```php
// Source: tests/Feature/Phase151/ComercEtapaNascimentoCadastroManualTest.php:65-91
public function test_post_cria_empresa_ja_na_etapa_1(): void
{
    // ... ação ...
    $company = Company::where('name', $payload['nome'])->first();
    $this->assertNotNull($company);
    $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $company->etapa);
}

public function test_historico_tem_user_id_do_usuario_logado_nao_da_conta_de_sistema(): void
{
    // ...
    $transicao = CompanyEtapaTransicao::where('company_id', $company->id)->first();
    $this->assertNotNull($transicao);
    $this->assertSame($admin->id, $transicao->user_id);
    $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $transicao->etapa_nova);
}
```

### Mapa arquivo de teste → análogo específico

| Arquivo de teste (Wave 0) | Análogo de setup | O que copiar dali |
|---|---|---|
| `tests/Feature/Phase152/ChecklistContagemItensTest.php` | `ContratoAdminDetalheTest.php` (`servicoComContrato`/`vincularServico`) | empresa com/sem serviço que exige contrato → 9 vs 6 itens |
| `tests/Feature/Phase152/ChecklistGrupoContratoAutoTest.php` | idem + `tests/Feature/Phase151/ComercEtapaNascimentoWebhookTest.php` (para ver como o webhook grava `ContratoAssinatura` num teste, se precisar simular envelope enviado/assinado) | grava `enviado_em`/`assinado_em` direto via `ContratoAssinatura::create()` — nunca via job real (D5) |
| `tests/Feature/Phase152/ChecklistGrupoEntradaAutoTest.php` | criar `MlToken`/`OnboardingLink` diretamente (não há teste-análogo específico; usar `MlToken::factory()` se existir, senão `MlToken::create()`) | ver `app/Models/MlToken.php` antes de escrever |
| `tests/Feature/Phase152/ChecklistMarcacaoManualAutoriaTest.php` | `OnboardingEngineService` não tem teste Feature citado nesta pesquisa — usar o padrão de `abertaPor()`/`withTrashed()` de `Pendencia.php` como referência de asserção (`$item->feitoPor->trashed()`) | soft-delete do usuário autor, checar que a relação sobrevive |
| `tests/Feature/Phase152/FinalizarTravaTest.php` | `EtapaTransicaoServiceTest.php` (regra pura) | testar `podeFinalizar()` isoladamente, sem passar pelo controller |
| `tests/Feature/Phase152/FinalizarTransicaoEtapaTest.php` | `ComercEtapaNascimentoCadastroManualTest.php` (reconsulta ao banco) + `CompanyMarketplaceFactory` (para o cenário `is_primary` duplicado, D-12) | 2 linhas `is_primary=true` não quebra — asserção determinística |
| `tests/Feature/Phase152/ChecklistDirigeEtapaTest.php` | `EtapaTransicaoServiceTest.php` (tabela de transições) | 1º item → etapa 2; envelope enviado → etapa 3; tudo pronto → etapa 4 |
| `tests/Feature/Phase152/ContratoAssinadoPorLiberacaoTest.php` | `app/Services/Operacional/EmpresaOperacionalRouter.php:284-361` (`liberarEmpresa()`) — chamar direto no teste, via `VIA_MANUAL` | empresa liberada por via manual sem `ContratoAssinatura.status` gravado ainda finaliza |
| `tests/Feature/Phase152/ChecklistAcessoPorEntradaTest.php` | `ComercEntradaPermissaoRotaTest.php` (arquivo inteiro é o molde) | copiar quase literal, trocando a rota alvo |
| `tests/Feature/Phase152/MultiplosEnvelopesTest.php` | nenhum teste-análogo existente cobre 2 envelopes simultâneos — criar 2 `ContratoAssinatura` para a mesma empresa/serviços diferentes que exigem contrato, com `withoutEvents` | Pitfall 3 do RESEARCH — caso não medido em produção, só no schema |
| `tests/Unit/Phase152/ChecklistProgressoTest.php` | `app/Services/Onboarding/OnboardingSituacaoService.php:255-267` (`progresso()`) como referência de fórmula, adaptada | provar que item com `chave` fora do catálogo não entra no denominador |
| `152-BASELINE-TESTES.md` | `150-BASELINE-TESTES.md`/`151-VALIDATION.md` (formato) | comando: `C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Phase150 tests/Feature/Phase150 tests/Feature/Phase151 --colors=never`, capturado ANTES do primeiro commit de código |

---

## Padrões Compartilhados (cross-cutting)

### Autoria `<verbo>_por` / `<verbo>_em`
**Fonte:** `app/Models/Pendencia.php:84-97` (`withTrashed()`) + `app/Services/Onboarding/OnboardingEngineService.php:587-664` (gravar/limpar os dois campos juntos)
**Aplica a:** `ChecklistAdministrativoItem` (model) e `ChecklistAdministrativoService::concluirManualmente()`/`reabrirPasso()`.
```php
// Gravar (marcar manualmente):
$item->status = 'concluido';
$item->feito_por = $usuario->id;
$item->feito_em = now();
$item->save();

// Limpar (desmarcar) — SEMPRE os dois juntos, nunca um sem o outro:
$item->status = 'aberto';
$item->feito_por = null;
$item->feito_em = null;
$item->save();
```

### Permissão em OR nativa
**Fonte:** `app/Http/Middleware/EnsurePermission.php:22-38`
**Aplica a:** a rota `admin.contratos.show` (D-17).
```php
Route::middleware('permission:admin.contratos,comercial.entrada')
```

### Transição de etapa — único ponto de escrita
**Fonte:** `app/Services/FluxoEntrada/EtapaTransicaoService.php` (arquivo completo)
**Aplica a:** `ChecklistAdministrativoService` (transições 1→2→3→4, D-15) e `FinalizarEntradaAdministrativaService` (4→5).
```php
$resultado = app(EtapaTransicaoService::class)->transicionar($company, $etapaDestino, $request->user());
if ($resultado['status'] !== 'transicionado') {
    Log::warning('[Checklist] transição inesperada', ['company_id' => $company->id, 'resultado' => $resultado]);
}
```
Nunca `Company::whereKey(...)->update(['etapa' => ...])` em nenhum lugar desta fase.

### Ator sempre da sessão, nunca do corpo da requisição
**Fonte:** `app/Http/Controllers/ComercialController.php:683` (`$request->user()`, nunca `$request->input('user_id')`) + docblock de `EtapaTransicaoService.php:166-170` (T-150-02)
**Aplica a:** todo endpoint novo desta fase que grava autoria ou dispara transição de etapa.

### Reconsulta ao banco para confirmar consolidação
**Fonte:** disciplina geral do projeto (`.planning/learnings/desempenho-bonificacao.md`), reforçada em `ContratoAdminDetalheTest.php` (comentário linha 29-30: "conferência por RECONSULTA ao banco, nunca por stdout")
**Aplica a:** todos os 11 testes da Wave 0 — nunca assertar sobre a resposta HTTP sozinha quando o comportamento em prova é persistência.

---

## Sem Análogo Encontrado

| Arquivo | Papel | Razão | O que existe mais perto |
|---|---|---|---|
| `.planning/REQUIREMENTS-v23.md` (edição) | docs | é prosa, não código — D-19 do CONTEXT.md já dá o texto a escrever | o próprio bloco ADMIN-02 já existente no arquivo (não lido nesta passada — o CONTEXT.md já cita a localização) |
| `app/Services/ChecklistAdministrativo/ChecklistAdministrativoDefinicao.php` | catálogo em código | nenhum arquivo do motor de Onboarding foi lido linha a linha nesta passada para confirmar o shape exato de `DefinicaoOnboarding::paraServico()` | `app/Support/Onboarding/DefinicaoOnboarding.php` — citado pelo CONTEXT.md (D-10) e pelo RESEARCH.md como o molde; **ler antes de escrever esta classe** |

---

## Metadados

**Escopo da busca de análogos:** `app/Models/`, `app/Services/Onboarding/`, `app/Services/FluxoEntrada/`, `app/Services/Operacional/`, `app/Services/Contratos/`, `app/Http/Controllers/`, `app/Http/Middleware/`, `app/Contracts/`, `database/migrations/`, `resources/js/Pages/Admin/`, `resources/js/Pages/Comercial/`, `resources/js/Components/Onboarding/`, `tests/Feature/Phase131/`, `tests/Feature/Phase151/`, `tests/Unit/Phase150/`, `routes/web.php`, `config/services.php`, `.env.example`.
**Arquivos lidos por completo ou em trechos substanciais:** 25.
**Data do mapeamento:** 2026-09-09.

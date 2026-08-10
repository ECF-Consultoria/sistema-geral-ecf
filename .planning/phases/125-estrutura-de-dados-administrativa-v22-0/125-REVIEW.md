---
phase: 125-estrutura-de-dados-administrativa-v22-0
reviewed: 2026-08-10T00:00:00Z
depth: standard
files_reviewed: 12
files_reviewed_list:
  - app/Models/Company.php
  - app/Models/ContratoAssinatura.php
  - app/Models/ContratoAssinaturaSignatario.php
  - database/factories/ContratoAssinaturaFactory.php
  - database/factories/ContratoAssinaturaSignatarioFactory.php
  - database/migrations/2026_08_10_100000_create_contrato_assinaturas_table.php
  - database/migrations/2026_08_10_100001_create_contrato_assinatura_signatarios_table.php
  - tests/Feature/Phase125/ContratoAssinaturaModelTest.php
  - tests/Feature/Phase125/ContratoAssinaturaSchemaTest.php
  - tests/Feature/Phase125/ContratoAssinaturaSignatarioModelTest.php
  - tests/Feature/Phase125/ContratoAssinaturaSignatarioSchemaTest.php
  - tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php
findings:
  critical: 2
  warning: 13
  info: 5
  total: 20
status: issues_found
---

# Fase 125: Relatório de Code Review

**Revisado:** 2026-08-10
**Profundidade:** standard
**Arquivos revisados:** 12
**Status:** issues_found

## Resumo

Fase de dados pura (2 migrations, 2 models, 2 factories, 5 arquivos de teste). As decisões
travadas em `125-CONTEXT.md` (D-04 string+constantes, D-05 `liberado_em` como data, D-07
duplicação intencional de PII, D-08 vocabulário próprio, D-10 congelamento em JSON, ausência
de `LogsActivity` no signatário) foram respeitadas e **não** são reportadas aqui.

O que a revisão encontrou vai em duas direções:

1. **A invariante central da fase (D-01) não está garantida pelo banco.** O docblock do
   `ContratoAssinatura` afirma que "a garantia FINAL da D-01 continua sendo o índice único do
   banco" e que o hook "é conveniência, não a trava". Isso está **invertido**: o índice único
   só dispara se a coluna espelho estiver preenchida, e quem a preenche é exclusivamente o hook
   `saving()`. Todo caminho que não passa por evento Eloquent (`saveQuietly`, `updateQuietly`,
   `Model::withoutEvents`, `->update()` de query builder, `insert()`, `upsert()`) deixa a
   invariante desligada em silêncio. A Fase 127 (expiração por prazo) é exatamente o consumidor
   que costuma usar bulk update.

2. **A evidência jurídica que a D-07 protege contra a morte do `User` é destruída pela morte da
   `Company`.** `Company` não usa `SoftDeletes` e `CompanyController::destroy()` faz
   `$company->delete()` físico; com `cascadeOnDelete` nas duas migrations, apagar uma empresa
   apaga em cascata contratos assinados e todos os signatários (nome, CPF, IP, `evidencia_signer`).

Além disso: a guarda estática `MigrationsFase125ConvencoesTest` tem um ponto cego exatamente no
caminho mais provável da armadilha 1059 (`foreignId()->constrained()`, que a própria migration 1
usa), e as factories versionaram no git IP e chave de signer reais de uma sessão sandbox.

Sobre a pergunta direta do `activity_log`: **não há vazamento de PII pelo `logOnly` do
`ContratoAssinatura`** — `logOnly(['status','enviado_em','assinado_em','liberado_em'])` é
fechado, exclui `servicos_snapshot`, `erro_mensagem` e os `clicksign_*`, e vale também para o
evento `deleted` (o spatie respeita o `logOnly` ao serializar atributos do registro apagado).
A ressalva fica em WR-11, sobre `erro_mensagem` como futura segunda cópia de PII no banco (não
no log).

## Narrative Findings (AI reviewer)

## Critical Issues (BLOCKER)

### CR-01: Excluir uma empresa apaga fisicamente contratos assinados e toda a evidência jurídica

**Arquivos:**
`database/migrations/2026_08_10_100000_create_contrato_assinaturas_table.php:54`
`database/migrations/2026_08_10_100001_create_contrato_assinatura_signatarios_table.php:124-126`
`app/Http/Controllers/CompanyController.php:797-802`
`app/Models/Company.php:11-13`

**Severidade:** BLOCKER — risco de perda de dados irreversível.

**Issue:**
A cadeia completa hoje é:

- `companies` **não** usa `SoftDeletes` (`app/Models/Company.php:11-13` — só `HasFactory` e
  `LogsActivity`);
- `CompanyController::destroy()` chama `$company->delete()`, que nesse model é `DELETE` físico,
  exposto por rota de UI;
- `contrato_assinaturas.company_id` é `->cascadeOnDelete()` (migration 1, linha 54);
- `contrato_assinatura_signatarios.contrato_assinatura_id` é `cascadeOnDelete` (migration 2,
  linhas 124-126).

Resultado: um clique em "excluir empresa" destrói, sem confirmação específica e sem trilha,
contratos com `status = assinado`, `assinado_em` preenchido, e **todos** os registros de
signatário com `nome`, `email`, `cpf`, `ip_address`, `auths` e `evidencia_signer`.

Isso contradiz frontalmente o racional da própria D-07, que está escrito na migration 2
(linhas 15-18): *"Evidência jurídica não pode depender de FK viva"*. A fase blindou o caso do
`User` apagado e deixou aberto o caso — muito mais provável na operação — da empresa apagada.
O `activity_log` do `ContratoAssinatura` também não salva nada útil aqui: cascade de banco não
dispara eventos Eloquent, então nem o registro de `deleted` é gravado.

O comentário da migration 2 (linhas 118-123) reconhece o risco de cascade **apenas** no eixo
contrato→signatários (T-125-16). O eixo empresa→contrato foi justificado como "contrato sem
empresa não faz sentido" (migration 1, linhas 52-53), o que é verdade para a *leitura* mas não
autoriza destruir a *prova*.

**Fix:** escolher uma das duas linhas (a primeira é a mais barata e não muda o schema atual):

```php
// Opção A — proteger no nível do banco: a empresa não pode ser apagada
// enquanto houver contrato com valor jurídico.
// migration 1
$table->foreignId('company_id')->constrained()->restrictOnDelete();
```

```php
// Opção B — soft delete no contrato + bloqueio no controller.
// migration 1 (adicionar):
$table->softDeletes();

// app/Http/Controllers/CompanyController.php::destroy()
public function destroy(Company $company)
{
    abort_if(
        $company->contratoAssinaturas()
            ->whereIn('status', [
                ContratoAssinatura::STATUS_ASSINADO,
                ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            ])->exists(),
        409,
        'Esta empresa possui contrato de assinatura com valor jurídico e não pode ser excluída.'
    );

    $name = $company->name;
    $company->delete();
    return back()->with('success', "Empresa {$name} excluída.");
}
```

Qualquer que seja a opção, adicionar teste: empresa com contrato `assinado` + signatário
`assinou` sobrevive (ou é barrada) na exclusão.

---

### CR-02: A garantia da D-01 depende só do hook `saving()` — o índice único não a sustenta sozinho, ao contrário do que o docblock afirma

**Arquivo:** `app/Models/ContratoAssinatura.php:98-113` (hook), `120-129` (guard),
`database/migrations/2026_08_10_100000_create_contrato_assinaturas_table.php:103`

**Severidade:** BLOCKER — corrupção silenciosa de invariante de negócio, nos dois sentidos.

**Issue:**
O comentário nas linhas 98-105 diz:

> "Isso torna impossível a coluna auxiliar desincronizar do `status` — mas a garantia FINAL da
> D-01 continua sendo o índice único do banco (`ca_company_andamento_uniq`); este hook é
> conveniência, não a trava."

As duas frases são falsas, e a segunda é perigosa porque será lida como licença pelas Fases
126/127/129/130.

O índice único é sobre `company_id_em_andamento`, uma coluna **derivada**. Ele só consegue
recusar um segundo contrato se o primeiro tiver a coluna preenchida — e o único mecanismo que
preenche é o hook `saving()`. Logo: **o hook é a trava; o índice é a rede que só existe se o
hook rodou.** Onde o hook não roda, a D-01 simplesmente não existe.

Caminhos reais que não disparam `saving()` (todos legítimos e comuns em Laravel 12):

| Caminho | Efeito |
|---|---|
| `$contrato->saveQuietly()` / `updateQuietly([...])` | usa `withoutEvents()` — hook não roda |
| `Model::withoutEvents(fn () => ...)` | idem |
| `ContratoAssinatura::where(...)->update(['status' => 'expirado'])` | query builder — hook não roda |
| `DB::table('contrato_assinaturas')->insert([...])` | idem (é o que os próprios testes de schema fazem) |
| `ContratoAssinatura::upsert([...], ...)` | idem |
| Seeder/import/backfill com `insert()` em lote | idem |

Isso produz **duas falhas simétricas**, ambas silenciosas:

**(a) Invariante desligada.** Um `update()` em massa que leve contratos para
`aguardando_assinaturas` (retry de fila, correção de status, backfill) deixa
`company_id_em_andamento` NULL. O índice não dispara, `estaEmAndamento()` responde `true`, e a
empresa passa a ter **dois ou mais contratos em andamento** — exatamente o que a D-01 proíbe.

**(b) Empresa travada para sempre.** O inverso é o caminho mais provável na Fase 127: expirar
contratos vencidos é um `update()` em massa por natureza —
`ContratoAssinatura::where('expira_em','<',now())->update(['status' => 'expirado'])`. A coluna
espelho continua apontando para a empresa, o índice único continua ocupado, e **nenhum novo
contrato pode ser criado para aquela empresa**, com um `QueryException` opaco de constraint na
cara do usuário. Nada no código atual sinaliza isso ao autor da Fase 127.

Note que `emAndamentoDaEmpresa()` (linha 128) consulta justamente a coluna espelho, então o
guard de código herda o defeito e responde `null` para uma empresa que, pelo `status`, tem
contrato em andamento.

**Fix:** três camadas, todas baratas.

1. **Corrigir o docblock** (linhas 98-105) — é a parte mais importante, porque é o que a Fase
   127 vai ler:

```php
/**
 * Sincroniza `company_id_em_andamento` com o `status` a cada save.
 *
 * ⚠️ ATENÇÃO: este hook É a trava da D-01, não conveniência. O índice
 * único `ca_company_andamento_uniq` é sobre uma coluna DERIVADA — ele só
 * consegue recusar o segundo contrato se o primeiro tiver passado por
 * aqui. Qualquer caminho que pule eventos Eloquent
 * (saveQuietly/updateQuietly/withoutEvents, ->update() de query builder,
 * insert(), upsert(), seeders) desliga a D-01 em silêncio, nos dois
 * sentidos: pode permitir dois contratos em andamento, ou travar a
 * empresa para sempre com a coluna presa a um contrato encerrado.
 *
 * NUNCA mudar `status` por bulk update. Use `each(fn ($c) => $c->update(...))`
 * ou a scope helper abaixo.
 */
```

2. **Dar ao consumidor o caminho seguro**, para que a Fase 127 não precise de bulk update:

```php
/**
 * Muda o status de uma seleção de contratos passando pelo hook `saving()`
 * — substituto obrigatório de `->update(['status' => ...])` em massa.
 */
public static function mudarStatusEmLote(\Illuminate\Database\Eloquent\Builder $query, string $novoStatus): int
{
    abort_unless(in_array($novoStatus, self::STATUS_TODOS, true), 500, "Status inválido: {$novoStatus}");

    $afetados = 0;
    $query->each(function (self $contrato) use ($novoStatus, &$afetados) {
        $contrato->status = $novoStatus;
        $contrato->save();
        $afetados++;
    });

    return $afetados;
}
```

3. **Tornar o guard independente da coluna espelho** (assim ele continua correto mesmo se a
   coluna dessincronizar, e vira o detector da inconsistência):

```php
public static function emAndamentoDaEmpresa(int $companyId): ?self
{
    return self::where('company_id', $companyId)
        ->whereIn('status', self::STATUS_EM_ANDAMENTO)
        ->first();
}
```

4. **(Opcional, mas é a única solução real de banco)** transformar a coluna em coluna gerada,
   e aí sim o índice único passa a ser a garantia final de verdade, imune a qualquer caminho de
   escrita:

```php
$table->unsignedBigInteger('company_id_em_andamento')
    ->storedAs("CASE WHEN status IN ('rascunho','aguardando_assinaturas') THEN company_id ELSE NULL END")
    ->nullable();
```

(validar a sintaxe no MariaDB **antes** do deploy — é exatamente a classe de coisa que o SQLite
dos testes não prova, ver a cicatriz 1059/1830 já registrada no projeto.)

Cobertura de teste faltante em qualquer cenário: um teste que faça
`ContratoAssinatura::where(...)->update(['status' => 'expirado'])` e prove o comportamento
esperado (hoje ele deixaria a empresa travada e passaria verde).

---

## Warnings

### WR-01: A guarda estática é cega para `foreignId()->constrained()` — e a migration 1 usa exatamente isso

**Arquivos:** `tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php:117` e `:145`,
`database/migrations/2026_08_10_100000_create_contrato_assinaturas_table.php:54` e `:44`

**Issue:**
Os dois testes que protegem contra a armadilha 1059 usam o regex
`/\$table->(unique|index|foreign)\(([^)]*)\)/`. Como o padrão exige `(` logo depois de
`foreign`, a chamada `$table->foreignId('company_id')->constrained()->cascadeOnDelete()` **não
casa** e passa invisível pelas duas guardas.

Duas consequências:

- O teste `nenhum_indice_ou_chave_e_anonimo` afirma no nome que nenhuma chave é anônima, mas a
  migration 1 **tem** uma chave anônima: a FK gerada por `->constrained()`, cujo nome o Laravel
  monta como `contrato_assinaturas_company_id_foreign` (39 chars — passa hoje, mas por sorte de
  comprimento, não por verificação).
- O comentário da migration 1, linha 44, afirma: *"Todo índice desta migration é nomeado à
  mão."* É falso. A afirmação é a mesma classe de erro do CR-02: documentação que promete uma
  garantia que o código não entrega.

Isso importa porque o nome **autogerado** é justamente o vetor do erro 1059 (falha silenciosa:
tabela nasce sem o índice e a migration fica `Pending`). A guarda protege o caso já seguro
(nome explícito) e ignora o caso perigoso.

**Fix:** cobrir `foreignId` no regex e checar o nome que o Laravel geraria:

```php
// nenhum_indice_ou_chave_e_anonimo
preg_match_all('/\$table->(unique|index|foreign|foreignId)\(([^)]*)\)([^;]*);/', $codigo, $matches, PREG_SET_ORDER);

foreach ($matches as $match) {
    [$chamada, $tipo, $args, $cauda] = $match;

    if ($tipo === 'foreignId' && ! str_contains($cauda, 'constrained')) {
        continue; // só cria coluna, não cria constraint
    }

    if ($tipo === 'foreignId') {
        // ->constrained() sem nome explícito: medir o nome que o Laravel geraria.
        $coluna = trim($args, "'\" ");
        $nomeGerado = $tabela . '_' . $coluna . '_foreign';
        $this->assertLessThanOrEqual(64, strlen($nomeGerado), "FK autogerada `{$nomeGerado}` ...");
        continue;
    }
    // ... resto igual
}
```

E corrigir o comentário da migration 1 (linha 44) para dizer a verdade: *"Todo índice é nomeado
à mão; a única exceção é a FK de `company_id`, cujo nome autogerado tem 39 chars."*

---

### WR-02: `migrationSemComentarios()` — remoção por regex tem falso negativo e falso positivo

**Arquivo:** `tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php:45-53`

**Issue:** a pergunta feita na revisão ("a remoção é correta?") tem resposta: **para os dois
arquivos de hoje sim, por construção; como guarda de regressão, não.** Três buracos:

1. **`//` dentro de string literal trunca a linha (falso negativo).**
   `preg_replace('/\/\/.*$/m', ...)` não distingue comentário de conteúdo de string. Uma linha
   como
   `$table->string('url')->comment('https://sandbox.clicksign.com/x'); $table->enum('x', [...]);`
   perde tudo a partir de `//` — inclusive um `->enum(` real na mesma linha. A Fase 126 vai
   trazer URLs da Clicksign para perto deste código.

2. **`/*` dentro de um comentário `//` engole código real (falso negativo).**
   Os blocos são removidos **primeiro**, sobre o texto cru. Uma linha
   `// isto usa /* algum formato` faz o regex `/\/\*.*?\*\//s` casar dali até o próximo `*/`
   do arquivo, apagando tudo no meio — inclusive declarações de coluna.

3. **`#` e `#[...]` não são removidos (falso positivo).**
   PHP aceita `#` como comentário. Um `# nunca usar ->enum( aqui` faria o teste 1 **falhar**
   acusando o comentário — que é exatamente a auto-invalidação que o helper existe para evitar,
   resolvida só pela metade.

**Fix:** usar o tokenizer do PHP, que não tem nenhum desses casos:

```php
private function migrationSemComentarios(string $caminho): string
{
    $this->assertFileExists($caminho, "Migration esperada não encontrada em {$caminho}.");

    $tokens = token_get_all(file_get_contents($caminho));

    return collect($tokens)
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');
}
```

---

### WR-03: Se uma migration for renomeada, dois testes da guarda passam verdes sobre string vazia

**Arquivo:** `tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php:45-53`, `71-83`, `171-189`

**Issue:** `file_get_contents()` de arquivo inexistente devolve `false` (com warning), que é
coagido a `''` nos `preg_replace` seguintes. Com conteúdo vazio:

- `nenhuma_migration_da_fase_usa_coluna_de_tipo_restrito` → `assertStringNotContainsString`
  passa;
- `nenhuma_migration_da_fase_cria_coluna_de_prazo` → passa (duas asserções);

ou seja, a guarda inteira vira decorativa e **o único sinal é o teste
`as_duas_migrations_da_fase_existem` falhando isoladamente** — que é fácil de "consertar"
atualizando `caminhosMigrations()` sem perceber que os outros nunca leram nada.
`phpunit.xml` não define `failOnRisky`/`beStrictAboutTestsThatDoNotTestAnything`, então a
vacuidade não aparece na saída.

**Fix:** o `assertFileExists($caminho)` dentro de `migrationSemComentarios()` (já incluído no
snippet de WR-02) resolve os três testes de uma vez. Complementarmente, ligar
`failOnRisky="true"` e `beStrictAboutTestsThatDoNotTestAnything="true"` no `phpunit.xml`.

---

### WR-04: `user()` está sob o global scope de SoftDeletes — o caminho comum de exclusão de usuário devolve `null` sem `user_id` nulo

**Arquivo:** `app/Models/ContratoAssinaturaSignatario.php:102-112`
**Referência:** `app/Http/Controllers/UserController.php:469` (`$user->delete()`, soft) vs `:489`
(`$user->forceDelete()`)

**Issue:** o docblock lista dois casos para `user` nulo: nunca existiu, ou foi apagado
(`nullOnDelete`). Falta o **terceiro caso, que é o caminho comum na operação**: `User` usa
`SoftDeletes` e o fluxo padrão do `UserController` é `$user->delete()` — soft. Nesse cenário:

- `user_id` continua **preenchido** (a FK `nullOnDelete` só observa `DELETE` físico, como o
  próprio teste `apagar_o_usuario_preserva_a_evidencia_de_quem_assinou` documenta na linha 100);
- `$signatario->user` devolve **`null`**, porque o global scope filtra `deleted_at`.

Ou seja, a Fase 131 vai encontrar registros com `user_id = 42` cujo `->user` é `null`, e
qualquer `$sig->user->name` explode com "Attempt to read property on null". O consumidor não
tem como saber disso lendo o docblock atual.

**Fix:**

```php
/**
 * Pode ser NULL — nunca existiu usuário interno, OU o usuário foi apagado
 * DEFINITIVAMENTE (forceDelete → FK `nullOnDelete`).
 *
 * ⚠️ `withTrashed()`: `User` usa SoftDeletes e o fluxo comum de exclusão
 * (UserController::destroy) é soft. Sem isto, `user_id` fica preenchido
 * e a relação devolve null — inconsistência que quebraria `$sig->user->name`
 * na tela. Em QUALQUER caso, `nome`/`email`/`cpf` do próprio registro são
 * a fonte de exibição (D-07); esta relação é só o vínculo.
 */
public function user(): BelongsTo
{
    return $this->belongsTo(User::class)->withTrashed();
}
```

Adicionar teste: `$user->delete()` (soft) → `$signatario->fresh()->user` não é null e
`user_id` segue preenchido.

---

### WR-05: CPF, e-mail, IP e evidência em texto puro, num projeto que já usa cast `encrypted`

**Arquivo:** `app/Models/ContratoAssinaturaSignatario.php:65-69`,
`database/migrations/2026_08_10_100001_...:72-110`

**Issue:** a tabela concentra o conjunto mais sensível de PII do sistema — nome + e-mail + CPF +
IP + JSON de evidência com flags biométricas — tudo em claro. O projeto já tem o padrão de
cifragem em repouso estabelecido (`MlToken::$casts['access_token'] => 'encrypted'`,
`ShopeeToken` idem), então não é uma exigência importada de fora: é uma inconsistência com a
convenção interna, num dado de categoria mais sensível que um token de API.

O racional da D-07 (evidência não pode depender de FK viva) é **compatível** com cifragem: o
dado continua no próprio registro; ninguém consulta por CPF nem faz `WHERE cpf = ?` neste
schema (não há índice em `cpf` nem em `email`).

**Fix:** no mínimo o CPF, que é o identificador nacional:

```php
protected $casts = [
    'assinado_em'      => 'datetime',
    'auths'            => 'array',
    'evidencia_signer' => 'array',
    // PII em repouso — mesmo padrão de MlToken/ShopeeToken. Não há
    // consulta por CPF neste schema (sem índice), então cifrar não
    // custa nada em leitura.
    'cpf'              => 'encrypted',
];
```

Atenção: `encrypted` infla o valor muito além de 14 chars — a coluna precisa virar `text` na
migration (ver também WR-12). Se a decisão for **não** cifrar, registrar explicitamente em
`125-CONTEXT.md` com o racional, para não virar achado recorrente nas próximas revisões.

---

### WR-06: `evidencia_signer.url` é uma capability acionável, gravada "sem poda" e sem regra de não-renderização

**Arquivo:** `database/migrations/2026_08_10_100001_...:104-114`,
`database/factories/ContratoAssinaturaSignatarioFactory.php:70`

**Issue:** a decisão de gravar o bloco `data.signer` inteiro é do Gate #9 e não está em questão.
O problema é que esse bloco contém
`url = https://.../notarial/widget/signatures/{signer_key}/redirect` e o mesmo `key` também vai
para a coluna `clicksign_signer_key`. Essa URL não é um metadado: é o **link de assinatura**,
uma credencial portadora — quem a tem chega na tela de assinatura daquele signatário.

Hoje isso está apenas num JSON de banco, sem consumidor. Mas a Fase 131 é uma tela de
diagnóstico, e o padrão do projeto em telas dev é despejar payloads (`hubspot_snapshot`,
`evidencia_signer` seguem o mesmo formato). Um `<pre>{JSON.stringify(evidencia_signer)}</pre>`
publica o link. Idem para qualquer `Log::info('[Clicksign] signer', $evidencia)`.

**Fix:** deixar a regra escrita **agora**, enquanto não há consumidor, e dar o acessor pronto:

```php
/**
 * Evidência para exibição — mesma do banco, MENOS os campos que são
 * credencial portadora. `url` é o link de assinatura do signatário:
 * quem tem o link assina. NUNCA renderizar nem logar `evidencia_signer`
 * cru (Fases 129/131).
 */
public function getEvidenciaExibivelAttribute(): array
{
    return collect($this->evidencia_signer ?? [])
        ->except(['url', 'key', 'phone_number', 'phone_number_hash'])
        ->all();
}
```

---

### WR-07: A factory versionou IP real e chave de signer real de uma sessão sandbox

**Arquivo:** `database/factories/ContratoAssinaturaSignatarioFactory.php:47-71`

**Issue:** respondendo diretamente à pergunta da revisão ("as factories geram dado realista sem
vazar PII de verdade?") — **não**. O state `assinou()` fixa valores literais copiados de uma
sessão real do sandbox:

- `'ip_address' => '187.56.206.108'` e `'address' => '187.56.206.108'` — endereço IP público
  real (faixa de ISP brasileiro), quase certamente o da máquina que rodou o Gate #9. IP é dado
  pessoal sob a LGPD e agora está no histórico do git, permanentemente.
- `'key' => '3ec39713-9f0e-4667-bd17-923ff0e58c66'` e a `url` derivada — identificador real de
  signatário no ambiente sandbox da Clicksign.

O valor de fixture (provar a **forma** do payload do Gate #9) é integralmente preservado com
valores sintéticos: o que importa são as chaves e os tipos, não os literais.

Os demais campos estão corretos: `fake()->name()`, `fake()->safeEmail()` (domínio `example.*`,
não entregável) e `fake()->numerify('###.###.###-##')`, que gera CPF com dígito verificador
inválido — bom, é PII sintética por construção.

**Fix:**

```php
public function assinou(): static
{
    // Forma REAL do payload do Gate #9, com valores SINTÉTICOS: o que a
    // fixture prova são as chaves e os tipos, nunca os literais da sessão
    // de sandbox (IP e signer key reais não entram no repositório).
    $signerKey = fake()->uuid();
    $ip        = fake()->ipv4();

    return $this->state(fn (array $attributes) => [
        'situacao'    => ContratoAssinaturaSignatario::SITUACAO_ASSINOU,
        'assinado_em' => now()->subMinutes(5),
        'ip_address'  => $ip,
        'auths'       => ['email'],
        'evidencia_signer' => [
            // ...
            'key'     => $signerKey,
            'address' => $ip,
            'url'     => "https://sandbox.clicksign.com/notarial/widget/signatures/{$signerKey}/redirect",
            // ...
        ],
    ]);
}
```

`ContratoAssinaturaSignatarioModelTest::round_trip_evidencia_signer` precisa acompanhar: em vez
do array literal duplicado (linhas 53-74), asserir as **chaves** e a coerência
(`assertSame($fresh->ip_address, $fresh->evidencia_signer['address'])`,
`assertSame(array_keys($esperado), array_keys($fresh->evidencia_signer))`) — o que aliás torna o
teste mais forte, porque hoje ele só reproduz a constante da factory.

---

### WR-08: `rascunho` ocupa o slot exclusivo da D-01 e não existe caminho de limpeza — rascunho abandonado trava a empresa indefinidamente

**Arquivo:** `app/Models/ContratoAssinatura.php:93-96`,
`database/migrations/2026_08_10_100000_...:59`

**Issue:** `STATUS_EM_ANDAMENTO` inclui `rascunho`, que é o **default** da coluna
(migration linha 59) e o default da factory. Consequência operacional: gerar um contrato e não
enviá-lo ocupa o slot único da empresa. E nesta fase não existe:

- `expira_em` (D-03, é da Fase 127 — então nada expira rascunho);
- fluxo de cancelamento (Fase 130+);
- nenhum job de limpeza.

Logo, entre a Fase 126 (que passa a criar rascunhos) e a Fase 127/130, um rascunho abandonado
— criado por engano, por duplo clique, ou por um envio à Clicksign que falhou antes de virar
`erro` — **bloqueia a empresa permanentemente**, e a única saída é `UPDATE` manual no banco.

Isso não é um pedido para mudar a D-01. É um pedido para que a restrição fique visível para
quem escreve a Fase 126.

**Fix:** documentar a dívida no model, junto da constante, e abrir o item na Fase 127:

```php
/**
 * Estados que ocupam o slot do índice único `ca_company_andamento_uniq` (D-01).
 *
 * ⚠️ `rascunho` é o DEFAULT da coluna e ocupa o slot: gerar e não enviar já
 * trava a empresa. Enquanto a Fase 127 não trouxer `expira_em` e a Fase 130
 * o cancelamento, NÃO existe caminho de liberação — a saída é UPDATE manual.
 * Quem criar rascunho (Fase 126) deve cancelá-lo no catch de falha de envio.
 */
public const STATUS_EM_ANDAMENTO = [...];
```

---

### WR-09: `company_id_em_andamento` está em `$fillable` mas é sempre descartado pelo hook

**Arquivo:** `app/Models/ContratoAssinatura.php:48-59` (linha 51)

**Issue:** a coluna é derivada e o hook `saving()` a sobrescreve incondicionalmente. Mantê-la em
`$fillable` cria três problemas pequenos que se somam:

- `ContratoAssinatura::create(['company_id_em_andamento' => 5, ...])` aceita o valor e o
  **descarta em silêncio** — o autor da Fase 126 pode achar que controla a coluna;
- é superfície de mass assignment sem nenhum ganho, contra o próprio racional escrito na
  linha 47 (*"`$fillable` explícito — nunca `$guarded = []`"*);
- a factory já documenta corretamente (linhas 23-24) que não se deve setar a coluna — o
  `$fillable` contradiz a própria factory.

**Fix:** remover a linha 51 do `$fillable` e anotar o motivo:

```php
protected $fillable = [
    'company_id',
    'status',
    // `company_id_em_andamento` NÃO entra: é coluna derivada, escrita
    // exclusivamente pelo hook `saving()` (D-01). Ver booted().
    'clicksign_envelope_id',
    // ...
];
```

---

### WR-10: `emAndamentoDaEmpresa()` é check-then-act (TOCTOU) e o docblock promete mais do que entrega

**Arquivo:** `app/Models/ContratoAssinatura.php:120-129`

**Issue:** o docblock diz que quem for criar contrato deve chamar este método antes *"para o
usuário ver 'esta empresa já tem contrato em andamento' em vez de um 500 de constraint do
banco"*. O guard reduz a frequência, mas **não elimina** o 500: entre o `SELECT` e o `INSERT`
existe uma janela, e o cenário que mais dispara essa constraint é justamente o duplo clique —
duas requisições concorrentes que leem `null` e inserem as duas. O comentário da migration
(linha 15-17) reforça o mesmo mal-entendido: *"índice único no banco (garantia real, sobrevive a
duplo clique e retry de fila)"* — o índice sobrevive, sim, mas a **experiência** do duplo clique
continua sendo um `QueryException`.

Somado ao CR-02: hoje o método consulta a coluna espelho, então também herda o defeito de
sincronização.

**Fix:** deixar registrado que o `catch` é obrigatório no consumidor, e não opcional:

```php
/**
 * Guard de código da D-01 (mensagem amigável). ⚠️ NÃO elimina a corrida:
 * entre este SELECT e o INSERT há janela — duplo clique/retry de fila
 * ainda batem no índice único. O consumidor (Fase 126) DEVE envolver a
 * criação em try/catch de QueryException e traduzir o 23000 na mesma
 * mensagem, tratando este método como atalho do caso feliz.
 */
```

e, no consumidor:

```php
try {
    $contrato = ContratoAssinatura::create([...]);
} catch (\Illuminate\Database\QueryException $e) {
    if ($e->getCode() === '23000') {
        return back()->withErrors(['contrato' => 'Esta empresa já tem contrato em andamento.']);
    }
    throw $e;
}
```

---

### WR-11: `erro_mensagem` nasce como destino de resposta bruta da Clicksign, sem limite nem política de scrub

**Arquivo:** `database/migrations/2026_08_10_100000_...:84-86`,
`app/Models/ContratoAssinatura.php:57`

**Issue:** a coluna é `text` livre, descrita como *"detalhe da falha TÉCNICA (status = erro)"*.
Na prática, o padrão do projeto para esse tipo de campo é gravar a resposta da API. Respostas de
erro da Clicksign para envelope/signer **ecoam os dados enviados** — nome, e-mail e
frequentemente o documento do signatário. Resultado: uma segunda cópia de PII, num campo sem
retenção, sem tamanho máximo, e provavelmente exibido inteiro na tela de diagnóstico da Fase
131.

É o mesmo raciocínio que justificou corretamente manter `servicos_snapshot` fora do
`activity_log` (linhas 134-136 do model) e não usar `LogsActivity` no signatário — só que aqui
a segunda cópia é criada dentro da própria tabela.

**Fix:** fixar a regra na migration, enquanto a coluna ainda está vazia:

```php
// DADOS-04 — detalhe da falha TÉCNICA (status = erro), separado de recusa
// do cliente (status = recusado).
// ⚠️ Fase 126: gravar aqui APENAS código + mensagem curta da Clicksign
// (ex.: "422 — signer email inválido"). NUNCA o corpo bruto da resposta:
// os erros da Clicksign ecoam nome/e-mail/documento do signatário e este
// campo viraria uma segunda cópia de PII, sem retenção e exibida na tela
// da Fase 131. Mesmo racional que manteve servicos_snapshot fora do
// activity_log.
$table->text('erro_mensagem')->nullable();
```

---

### WR-12: Defaults de banco hardcoded fora das constantes + `cpf` sem formato canônico e com folga zero

**Arquivos:** `database/migrations/2026_08_10_100000_...:59`,
`database/migrations/2026_08_10_100001_...:79` e `:88`,
`database/factories/ContratoAssinaturaSignatarioFactory.php:27`

**Issue:** dois pontos independentes, ambos de "vai divergir depois":

1. Os defaults `'rascunho'` (migration 1, linha 59) e `'pendente'` (migration 2, linha 88) são
   literais soltos. Como o valor canônico vive em `ContratoAssinatura::STATUS_RASCUNHO` e
   `ContratoAssinaturaSignatario::SITUACAO_PENDENTE`, renomear a constante deixa o default do
   banco apontando para um valor morto, e nada detecta (nenhum dos 5 testes compara os dois).

2. `cpf` é `string(14)`, que é **exatamente** o comprimento da máscara `000.000.000-00`, e
   nenhum lugar define o formato canônico. A factory (linha 27) estabelece "mascarado" de fato,
   mas a Fase 126 recebe o CPF da Clicksign/HubSpot em formato desconhecido. Se vier com espaço,
   prefixo ou qualquer variação, o MariaDB **trunca em silêncio** no modo não-estrito (o SQLite
   dos testes nem reclama do tamanho) e o CPF gravado na evidência jurídica fica errado. Folga
   zero num campo cujo propósito é ser prova.

**Fix:**

```php
// migration 1
$table->string('status', 40)->default(\App\Models\ContratoAssinatura::STATUS_RASCUNHO);
// migration 2
$table->string('situacao', 20)->default(\App\Models\ContratoAssinaturaSignatario::SITUACAO_PENDENTE);

// migration 2 — CPF: 20 dá folga para máscara + variação de origem.
// FORMATO CANÔNICO: apenas dígitos (11 chars), normalizado na escrita
// pela Fase 126 — preg_replace('/\D/', '', $cpf). A máscara é
// responsabilidade da camada de exibição.
$table->string('cpf', 20)->nullable();
```

(usar as constantes na migration cria acoplamento migration→model; se o projeto preferir evitar
isso, a alternativa é um teste que compare `Schema::getColumns()['status']['default']` com a
constante.)

---

### WR-13: States da factory dependem da ordem de aplicação e produzem evidência contraditória em silêncio

**Arquivo:** `database/factories/ContratoAssinaturaSignatarioFactory.php:45-56`, `92-99`

**Issue:** o state `assinou()` monta `evidencia_signer` a partir de
`$attributes['email'] ?? fake()->safeEmail()` e `$attributes['nome'] ?? fake()->name()`. Como os
states do Laravel são aplicados na ordem em que foram encadeados:

- `->daEcf($user)->assinou()` → evidência coerente com as colunas (é o que os testes usam);
- `->assinou()->daEcf($user)` → `evidencia_signer['email']` fica com o e-mail **fake** do
  `definition()`, enquanto a coluna `email` fica com o do usuário. A fixture nasce
  auto-contraditória, sem nenhum aviso.

Numa tabela cujo propósito é ser prova, uma fixture que discorda de si mesma vai gerar um teste
de Fase 129 que passa por acidente (ou falha por motivo errado).

**Fix:** resolver a evidência a partir do model já hidratado, com `afterMaking`, em vez de
depender da ordem:

```php
public function assinou(): static
{
    return $this->state(fn (array $attributes) => [
        'situacao'    => ContratoAssinaturaSignatario::SITUACAO_ASSINOU,
        'assinado_em' => now()->subMinutes(5),
        'ip_address'  => $ip = fake()->ipv4(),
        'auths'       => ['email'],
    ])->afterMaking(function (ContratoAssinaturaSignatario $s) {
        // Lido do model já montado: imune à ordem dos states encadeados.
        $s->evidencia_signer = array_merge($this->evidenciaBase($s->ip_address), [
            'email' => $s->email,
            'name'  => $s->nome,
        ]);
    });
}
```

Alternativa mínima: documentar no docblock que `assinou()` deve ser **sempre o último** state
encadeado, e adicionar teste que prove
`assertSame($sig->email, $sig->evidencia_signer['email'])`.

---

## Info

### IN-01: Testes tautológicos — asserções sobre constantes, não sobre o sistema

**Arquivos:** `tests/Feature/Phase125/ContratoAssinaturaModelTest.php:42-62`,
`tests/Feature/Phase125/ContratoAssinaturaSignatarioModelTest.php:139-152`

**Issue:** `recusado_e_expirado_nunca_resolvem_para_cancelado_nem_erro` abre com quatro
`assertNotSame` entre literais de constantes distintas — sempre verdadeiro por definição do PHP
— e fecha com `assertSame(4, count(array_unique($valores)))`, que é consequência aritmética das
quatro asserções anteriores. Nenhum comportamento do sistema (nenhuma transição, nenhum colapso
de estado) é exercitado. `papel_usa_vocabulario_interno` tem o mesmo formato:
`assertNotContains('sign', PAPEL_TODOS)` sobre um array literal de 3 strings conhecidas.

Isso não é errado, mas dá impressão de cobertura maior do que existe: o nome promete provar que
`recusado` nunca "resolve" para `cancelado`, e não há nenhum código de resolução de estado nesta
fase para provar isso.

**Fix:** manter (custam nada e travam renomeações de constante) mas renomear para o que de fato
provam — `constantes_de_status_sao_distintas_entre_si`, `papel_todos_tem_os_tres_valores` — e
deixar o nome "nunca colapsa" reservado para a Fase 129, onde existirá o mapeamento
Clicksign→status que pode de fato colapsar estados.

---

### IN-02: `todo_nome_de_indice_cabe_em_64_caracteres` pode passar com zero asserções

**Arquivo:** `tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php:139-169`

**Issue:** diferente dos dois testes vizinhos, este não tem `assertNotEmpty($matches)`. Se o
regex deixar de casar (refactor da migration, ou o buraco do WR-01), o `foreach` não executa e o
teste passa verde sem nenhuma asserção.

**Fix:** adicionar `$this->assertNotEmpty($matches, "...")` como nos testes 2 e 3.

---

### IN-03: `clicksign_document_id` sem unique, assimétrico com `clicksign_envelope_id`

**Arquivo:** `database/migrations/2026_08_10_100000_...:72-73`, `108`

**Issue:** `clicksign_envelope_id` ganhou unique (`ca_clicksign_envelope_uniq`) com a
justificativa "um envelope nunca pertence a dois contratos"; `clicksign_document_id`, criado na
linha seguinte, não tem nem unique nem índice. Se a Fase 129 precisar casar webhook→contrato
pelo document (o evento `sign` é do **documento**, não do envelope — conforme a própria
documentação do Gate #9 citada no topo da migration 2), a busca será full scan e não há garantia
de unicidade.

**Fix:** avaliar na Fase 126/129 se o casamento de webhook usa document; se sim, adicionar
`$table->unique('clicksign_document_id', 'ca_clicksign_doc_uniq')` na migration desta fase
(ainda vazia, custo zero) ou registrar a decisão de não fazê-lo.

---

### IN-04: `Company::contratoAssinaturas()` sem return type, divergindo do código novo da fase

**Arquivo:** `app/Models/Company.php:408-412`

**Issue:** todo o código novo da fase tipa retornos (`: BelongsTo`, `: HasMany`, `: bool`,
`: ?self`); a relação adicionada em `Company` não tipa. É consistente com o arquivo antigo, mas
inconsistente com a fase. Baixíssimo impacto.

**Fix:** `public function contratoAssinaturas(): \Illuminate\Database\Eloquent\Relations\HasMany`

---

### IN-05: Falta cobertura para os caminhos que quebram a D-01 (liga ao CR-02)

**Arquivo:** `tests/Feature/Phase125/ContratoAssinaturaModelTest.php:80-105`

**Issue:** os testes cobrem bem os dois caminhos que **passam** pelo hook (colisão de dois em
andamento; liberação após encerrar). Nenhum teste exercita os caminhos que o pulam — que são
justamente onde a invariante morre. Um teste que hoje **falharia** e provaria o CR-02:

```php
#[Test]
public function bulk_update_de_status_nao_pode_deixar_a_empresa_travada(): void
{
    $company  = Company::factory()->create();
    $contrato = ContratoAssinatura::factory()->emAndamento()->create(['company_id' => $company->id]);

    // Padrão que a Fase 127 (expiração por prazo) vai querer usar.
    ContratoAssinatura::where('id', $contrato->id)->update(['status' => ContratoAssinatura::STATUS_EXPIRADO]);

    // Hoje: falha. A coluna espelho continua ocupada e a empresa está travada.
    $this->assertNull($contrato->fresh()->company_id_em_andamento);
    ContratoAssinatura::factory()->emAndamento()->create(['company_id' => $company->id]);
}
```

**Fix:** adicionar este teste junto do fix do CR-02, e um simétrico para `updateQuietly()`.

---

_Revisado: 2026-08-10_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_

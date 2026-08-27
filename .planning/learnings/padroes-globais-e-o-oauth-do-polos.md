# Padrões Globais do Onboarding e o OAuth de Polos

Descobertas de 27/08/2026, ao pôr o `{link_oauth}` na mensagem de boas-vindas
(commit `069d6b9b`). Nenhuma é dedutível do código.

## 1. Mudar a constante do texto padrão NÃO muda produção

`MlbConfiguracao::implementacaoPadroes()` faz `array_replace_recursive($base,
$defaults)`, onde `$defaults` é `mlb_configuracoes.implementacao_defaults`. Em
produção **a mensagem de boas-vindas está salva ali** (2163 chars em 27/08), o
que significa que `MENSAGEM_BOAS_VINDAS_PADRAO` é letra morta: editar a constante
só afeta instalação nova e a suíte de testes.

Vale para **qualquer** chave de `implementacao_defaults` — tutoriais, links admin,
`grants_por_polo`. Se a mudança precisa chegar ao cliente, alguém tem que colar o
texto em **Onboarding → Padrões Globais** depois do deploy. Deploy verde com
texto velho na tela é o sintoma.

Antes de prometer que um texto novo "já está no ar", confira:

```
php artisan tinker --execute="print_r(array_keys(App\Models\MlbConfiguracao::get()->implementacao_defaults ?? []));"
```

## 2. `grants_por_polo` está com a chave antiga na constante

`MlbImplementacao::ONB_POLO_OPCOES` tem `'Serra Gaúcha'` (renomeado da planilha de
2026-07), mas `MlbConfiguracao::GRANTS_POR_POLO_PADRAO` ainda tem
`'Bento Gonçalves'`. Consequência: `Phase33OnboardingFichaTest:467` falha no HEAD
limpo — **não é regressão sua**. Em produção o admin já cadastrou "Serra Gaúcha"
no banco (120 empresas), então só a constante e o teste estão defasados; pelo
item 1, corrigir a constante não conserta produção nem quebra nada lá.

## 3. Por que o OAuth de Polos não guarda token

`ml_tokens.company_id` é UNIQUE e Polos **não vive em `companies`**: 535 de 539
`mlb_empresas` sem `company_id`, 437 cust_ids distintos e só 17 casando com uma
company existente (`companies` tem 199 linhas — o universo de Gestão). Persistir
o token exigiria criar ~440 Companies, e `companies` é o pivô de Desempenho,
carteira e NPS (`NpsElegibilidadeService` parte de `Company::where('active',
true)`). O ganho seria acesso contínuo à API do ML, que para Polos já vem do
Adman/CSV.

Por isso o fluxo é de **identificação**: troca o code, lê o `user_id` do
`/oauth/token` (é o Seller ID = Cust ID), grava em `mlb_empresas.cust_id` e
carimba `mlb_implementacoes.dados['ml_oauth']`. O token é descartado.

Se um dia alguém quiser conexão de verdade para Polos, a decisão a tomar primeiro
é **onde mora o token**, não como chamar a API — e criar Company por empresa de
Polos é a opção com maior raio de explosão, não a mais óbvia.

## 4. O link "que não expira" não é a URL do Mercado Livre

A URL do ML carrega um `state` que vive 7 dias no cache (`STATE_TTL`). Mandar ela
por WhatsApp significa link vencido para todo cliente que demora. O que se manda
é uma rota nossa com o token permanente — `/implementacao/{token}/conectar/ml`
para Polos, `/onboarding-cliente/{token}/conectar/ml` para Gestão — e a URL do ML
nasce no clique. Qualquer link de autorização novo deve seguir essa forma.

## 5. OAuth do app da ECF ≠ Grant do Partners

`{link_grant}` aponta para `partners.mercadolivre.com.br` — programa de Partners
do Mercado Livre, **um por polo**, igual para a região inteira, e por isso incapaz
de dizer quem preencheu. `{link_oauth}` é o app da ECF, por empresa. Um não
substitui o outro; os dois convivem na mensagem.

## 6. Testar em worktree: junction de `vendor/` mente

Apontar `vendor/` do worktree para o checkout principal com `mklink /J` faz o
autoloader do Composer resolver `__DIR__` pelo caminho real e carregar as classes
do **outro** checkout — a suíte passa medindo a árvore errada. Rode
`composer install` de verdade no worktree, com
`--ignore-platform-req=php-64bit` (o lock pede PHP 8.3 e o XAMPP local é 8.2).

---
quick_id: 260910-l7k
slug: teto-do-contrato-na-convencao
date: 2026-09-10
type: quick
status: done
---

# O teto lido do contrato entra na convencao da casa — e passa pela validacao — Summary

**Uma linha:** a confirmacao da leitura do Clicksign deixou de ser a unica porta de escrita de
cobranca sem validacao — agora o teto lido entra na convencao da casa (,99) e a tabela inteira
passa pelas MESMAS regras do cadastro manual antes de gravar; e um comando dry-run corrige os 75
tetos que ja entraram fora da convencao.

## O que foi feito

### T2 — App\Support\FaixaFaturamento::tetoGravado() (commit e841b9fe)

Espelho PHP de tetoGravado() de resources/js/lib/faixasFaturamento.js (quick 260910-j1u), citado no
docblock. 500000.00 -> 499999.99, 499999.99 -> 499999.99 (**idempotente**), null -> null.
Comparacao de centavos por inteiro ((int) round($v * 100) % 100), nunca == em float — sem a
idempotencia, reprocessar uma proposta ja normalizada viraria 499.999,98 e moveria empresa de faixa.

Um caso a mais que o plano nao previa e o codigo agora trata: **teto 0 ou negativo devolve intacto**
em vez de virar -0,01. Teto zero nao existe em contrato nenhum; subtrair um centavo ali so produziria
uma mensagem de erro sem sentido para quem esta conferindo.

### T1 — App\Services\Fechamento\ValidadorTabelaFaixas (commit 725fc0f8)

Extracao **pura** de SalvarFaixasFaturamentoRequest::withValidator(): mesmas quatro regras (ordem
unica; no maximo uma faixa sem teto e ela e a de maior ordem; valor_e_piso so na faixa sem teto;
tetos estritamente crescentes), mesma ordem, mesmas mensagens em pt-BR, mesma disciplina (para no
primeiro conflito, campo aponta para o indice ORIGINAL do payload).

SalvarFaixasFaturamentoRequest::withValidator() agora so delega e mapeia {campo, mensagem} em
$v->errors()->add(). rules() e messages() ficaram onde estavam.

**Prova de que nada mudou:** Phase137 + Phase138 + Phase142 (204 testes, 960 assercoes) passaram
verdes **sem editar uma linha de teste**.

### T3 — TabelasContratoController::confirmar() (commit 53385cc6)

No ramo TIPO_TABELA, e **antes** de qualquer escrita: (1) normaliza cada limite_superior com
FaixaFaturamento::tetoGravado(); (2) roda ValidadorTabelaFaixas; (3) se houver erro, abort(422) —
nada gravado, proposta segue pendente, transacao nem comeca; (4) so entao gravar() com as faixas
normalizadas.

Copy sem jargao, uma mensagem so para qualquer das quatro regras:

> A tabela que a leitura automatica encontrou neste contrato nao fecha: os valores de uma linha para
> a outra estao fora de ordem. Nada foi gravado. Confira o contrato e cadastre a tabela desta
> empresa a mao, na ficha dela — ou descarte esta leitura.

O detalhe tecnico (qual regra falhou, qual proposta, qual empresa) vai para Log::warning com o
prefixo [TabelasContrato] — quem confere le a copy, quem depura le o log.

Ramos valor_fixo / indefinido / ilegivel: **intocados**, continuam confirmando sem criar faixa.

### T4 — php artisan fechamento:normalizar-tetos-contrato (commit 2b2f61ca)

No molde de MaterializarTabelasFechamento: **dry-run por padrao**, escreve so com --aplicar.

- Alvo: empresas com linhas origem = 'contrato' cujo teto nao termina em ,99.
- Reescreve **so o limite_superior** — ordem, valor e valor_e_piso passam intactos.
- Grava pela porta unica (GravarTabelaEmpresaService::gravar()) com feitoDe = 'normalizacao_teto' —
  UMA entrada de activity_log por empresa, com a tabela inteira antes e depois.
- **servico_origem_id preservado**: lido das linhas atuais e devolvido igual no parametro de
  gravar(), senao a correcao apagaria o vinculo em silencio.
- Tabela que **falharia** na validacao do T1 e **pulada e nomeada** no relatorio, com o motivo —
  nunca corrigida pela metade. E o caso da MAXIGOLD (#234) e da EZIOFREDIANI (#256).
- Imprime, por empresa: nome, quantos tetos mudam e o antes/depois de cada um, em R$.
- Idempotente por construcao: rodar duas vezes nao tira outro centavo nem gera segunda entrada de
  auditoria (tem teste).

## Gate

    C:\xampp\php\php.exe artisan test --filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Quick260909|Quick260910"
    EXIT_CODE=0
    Tests: 632 passed (2914 assertions)

Partia de **605 testes / 2833 assercoes / 0 falhas** -> **632 / 2914 / 0 falhas**. +27 testes novos:

| arquivo | testes |
|---|---|
| tests/Unit/Support/Quick260910TetoGravadoTest.php | 5 |
| tests/Unit/Services/Quick260910ValidadorTabelaFaixasTest.php | 7 |
| tests/Feature/Quick260910/ConfirmacaoTetoEValidacaoTest.php | 7 |
| tests/Feature/Quick260910/NormalizarTetosContratoTest.php | 8 |

Nenhum teste existente foi editado.

## Commits

| hash | assunto |
|---|---|
| e841b9fe | feat(260910-l7k): teto do contrato entra na convencao da casa (FaixaFaturamento::tetoGravado) |
| 725fc0f8 | refactor(260910-l7k): regras compostas da tabela de faixas saem do FormRequest |
| 53385cc6 | fix(260910-l7k): confirmar contrato normaliza o teto e valida a tabela antes de gravar |
| 2b2f61ca | feat(260910-l7k): comando fechamento:normalizar-tetos-contrato (dry-run por padrao) |

## O que NAO foi feito (de proposito)

- **FechamentoFaixaResolver.php nao foi tocado.** Nem uma linha. A conversao acontece na borda
  (confirmacao, digitacao, comando de correcao), nunca dentro do motor de cobranca.
- **Conteudo das tabelas de MAXIGOLD (#234) e EZIOFREDIANI (#256) nao foi corrigido** — fora de
  escopo, depende de ler o contrato real. O comando as pula e as nomeia; a partir de agora nenhuma
  outra entra por essa porta.
- **Nada rodou contra producao.** plink/pscp/deploy.sh sao bloqueados dentro de subagente. O comando
  esta pronto; quem roda contra o VPS e o orquestrador.
- **Nenhum .jsx mudou** — nao foi preciso npm run build.
- Nada de .env, nada de deploy, e a chave fechamento_tabela_por_empresa_ativa nao foi tocada.

## Proximo passo (para o orquestrador, contra o VPS)

    php artisan fechamento:normalizar-tetos-contrato            # dry-run: conferir as 13 empresas / 75 tetos
    php artisan fechamento:normalizar-tetos-contrato --aplicar  # so depois de conferir a lista

Conferencia oficial e por **reconsulta ao banco**, nunca pelo stdout da rodada:

    SELECT COUNT(*) FROM empresa_faixas_faturamento
    WHERE origem = 'contrato' AND limite_superior IS NOT NULL
      AND ROUND(limite_superior * 100) % 100 <> 99;
    -- esperado depois do --aplicar: so o que sobrou das 2 tabelas puladas

Nenhuma competencia congelada e reescrita por isto (o comando nunca toca em fechamento_snapshots).
A correcao vale para a cobranca daqui pra frente.

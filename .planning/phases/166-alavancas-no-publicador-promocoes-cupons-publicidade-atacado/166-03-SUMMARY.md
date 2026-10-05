---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 03
subsystem: publicador
tags: [alavancas, escritor, trava, historico, assinatura-previa, contrato-acao]
requires: [166-01, 166-02]
provides:
  - RequisicaoMl (objeto de valor da chamada ao ML)
  - AcaoAlavanca (contrato abstrato de toda ação)
  - AssinaturaDaPrevia (HMAC da prévia, D-04)
  - EscritorAlavancas (único caminho de escrita)
affects: [166-04, 166-11]
key-files:
  created:
    - app/Services/Publicador/Alavancas/RequisicaoMl.php
    - app/Services/Publicador/Alavancas/Acoes/AcaoAlavanca.php
    - app/Services/Publicador/Alavancas/AssinaturaDaPrevia.php
    - app/Services/Publicador/Alavancas/EscritorAlavancas.php
    - tests/Feature/Publicador/Alavancas/Fakes/AcaoDeTeste.php
    - tests/Unit/Publicador/Alavancas/AssinaturaDaPreviaTest.php
    - tests/Unit/Publicador/Alavancas/UnicoCaminhoDeEscritaTest.php
    - tests/Feature/Publicador/Alavancas/TravaEscritaTest.php
    - tests/Feature/Publicador/Alavancas/HistoricoEscritaTest.php
    - tests/Feature/Publicador/Alavancas/RobustezTest.php
metrics:
  completed: 2026-10-04
  tasks: 3
---

# Fase 166 Plano 03: Escritor único das Alavancas Summary

Um executor de escrita (`EscritorAlavancas`) que aplica a trava das Alavancas e confere o vendedor do token antes de qualquer HTTP, grava a linha PENDENTE com `enviado_em` antes do envio, nunca repete às cegas e guarda a resposta crua sem token; mais o contrato `AcaoAlavanca`, a `RequisicaoMl` e a assinatura HMAC da prévia.

## Commits

| Task | Commit | Descrição |
|---|---|---|
| 1 | ver `git log` (`feat(166-03): contrato das ações e assinatura da prévia`) | RequisicaoMl, AcaoAlavanca, AssinaturaDaPrevia + teste |
| 2 | `feat(166-03): executor único de escrita das Alavancas` | EscritorAlavancas, AcaoDeTeste, Trava/Historico |
| 3 | `test(166-03): robustez da escrita e guarda do caminho único` | RobustezTest, UnicoCaminhoDeEscritaTest |

## Contrato `AcaoAlavanca` (para as ondas seguintes)

```php
abstract class AcaoAlavanca {
    final public function __construct(protected ContaAlavanca $conta, protected array $dados) {}
    abstract public static function nome(): string;            // <= 32 caracteres
    abstract public static function regras(): array;           // Validator, UM item de `itens`
    abstract public function alavanca(): string;               // promocao|cupom|atacado|exclusao
    public function dados(): array;  public function conta(): ContaAlavanca;
    public function itemId(): ?string; promotionType(): ?string; promotionId(): ?string; // de $dados
    abstract public function validar(): void;                  // lança RegraViolada; pode LER (GET)
    abstract public function resumo(): array;                  // item_id, titulo, acao_rotulo, preco_atual, preco_promocao,
                                                               // desconto_percentual, prazo, ml_banca, linhas, avisos, analise
    abstract public function escrita(): RequisicaoMl;          // método != GET
    public function preparo(): ?RequisicaoMl;                  // GET imediatamente antes (padrão null)
    public function aplicarPreparo(RespostaMl $r): void;
    public function sucesso(RespostaMl $r): bool;              // padrão $r->ok()
    public function promotionIdCriada(RespostaMl $r): ?string; // padrão null
}
```

`RequisicaoMl(metodo, caminho, query = [], corpo = null, cabecalhos = [])`: recusa método fora de GET/POST/PUT/DELETE, caminho sem `/` inicial, com `?` ou `://`, e escrita em `/advertising`; `ehEscrita()`, `paraHistorico()` (sem Authorization).

`EscritorAlavancas(ClienteMlPublicador, LeitorContaAlavancas, CacheAlavancas, ?Closure $dormir)`: `abrirLinha($acao, $ator, $lote)`, `executar($acao, $ator, $lote, $linha)`, `consultaPorPost($conta, $caminho, $corpo)` e `CONSULTAS_POR_POST`.

`AssinaturaDaPrevia::canonico($acao, $itens)`, `gerar($canonico, $chaveTela, $userId, ?$expiraEm)`, `confere($assinatura, $canonico, $chaveTela, $userId)`. O controller (166-11) normaliza preços com `round((float) $v, 2)` e queima a assinatura com `Cache::add` (uso único, 409 no reuso).

## Mutação (learnings §5)

Comentando as duas chamadas `AlavancasLiberadas::exigir($conta->conta)` do `EscritorAlavancas` (em `executar` e em `consultaPorPost`), o `TravaEscritaTest` falhou em 4 dos 7 testes (conta fora da lista, liberada só na publicação, MlbEmpresa não liberada, consulta por POST). Arquivo restaurado; sem diff.

## Testes

- `tests/Unit/Publicador`: 193 testes, 614 asserções, exit 0 (antes do plano: 181).
- `tests/Feature/Publicador`: 282 testes, 1640 asserções, exit 0 (antes do plano: 255). Sem falha nova contra o baseline (0 falhas).
- Novos: AssinaturaDaPrevia 9, TravaEscrita 7, HistoricoEscrita 10, Robustez 10, UnicoCaminhoDeEscrita 3.

## Decisões

- O vendedor é conferido uma vez por conta por instância do escritor (memo por `chaveConta()`); `/users/me` fresco, comparado com `ml_user_id` do token e com `sellerId` da âncora.
- `RegraViolada` no preparo/validar vira RECUSADA com `enviado_em` nulo; exceção depois de `enviado_em` vira INCERTO, antes vira ERRO `ALAV-INTERNO`.
- Preparo (GET) não-OK vira ERRO com a resposta crua e nenhum POST.
- A guarda de fonte varre `app/Services/Publicador/Alavancas/**`, `MlbAlavancas*.php` e `ExecutarLoteAlavancaJob.php` com `token_get_all` (sem comentários); `Http::` e `enviarFoto(` também são proibidos ali.

## Deviations from Plan

None - plano executado como escrito. Observações: o fixture de erro do `HistoricoEscritaTest` usa o formato real da doc (`cause[].error_code`), e o hash de commit das tarefas não foi copiado aqui para evitar autorreferência (conferir no `git log`).

## Known Stubs

Nenhum (`AcaoDeTeste` é fake de teste, intencional).

## Notas

Nenhuma chamada de rede ao ML (tudo `Http::fake`); STATE.md e ROADMAP.md intocados; sem push nem deploy.

## Self-Check: PASSED

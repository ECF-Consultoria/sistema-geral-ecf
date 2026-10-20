<?php

namespace App\Services\Publicador;

use App\Models\MlAcervoItem;
use App\Models\PubTarefa;

/**
 * O CONTRATO DO SERVIDOR para o header global do Publicador (Fase 177, v26.0).
 *
 * Monta a prop `cabecalho` que TODA página interna do Publicador recebe:
 * identidade da conta, estado das integrações e os contadores das abas. Existe
 * para separar DADO de DESENHO — HEAD-10 manda que todo contador seja REAL,
 * vindo do servidor, e que nenhum número fique fixo no JSX.
 *
 * ═══ O que este serviço NÃO faz ══════════════════════════════════════════════
 *
 * - **Não resolve a conta de novo.** Recebe `$alvo` (de
 *   `ProgramasPublicadorService::resolver()`) e `$empresa` (de
 *   `empresaParaTela()`) já calculados pelo controller. Recalcular aqui daria
 *   DUAS resoluções de conta por request, e na tela do Produto/editor a conta do
 *   PRODUTO (WR-B01) é diferente da conta da empresa resolvida — o header
 *   mostraria a conta errada.
 * - **Nenhuma chamada HTTP ao Mercado Livre** (D-05). Tudo o que o header
 *   mostra já está gravado.
 * - **Não coleta nada.** "Atualizar agora" segue sendo a rota que já existe
 *   (`mlb.anuncios.meus.atualizar`), que ENFILEIRA (D-05/REG-06).
 *
 * ═══ Duas honestidades que o contrato precisa sustentar ══════════════════════
 *
 * 1. **O ERP é só DECLARADO, nunca "conectado".** Não existe integração com ERP
 *    nenhum neste sistema — a empresa apenas DIZ, no onboarding, qual usa. Por
 *    isso a única chave de `integracoes.erp` é `declarado` (o nome ou `null`).
 *    Não existe `erp.conectado`, não existe "sincronizado há N min", e o header
 *    diz "Declarado: X" ou "Não informado". A fonte é a MESMA estática que a
 *    lista de empresas e a Visão geral usam
 *    (`ProgramasPublicadorService::erpDeclarado()`) — ver o docblock de
 *    `PainelVisaoGeralService::integracoes()`. O segundo sinal de conexão real
 *    do header é o **Portal do Cliente**, que é estado medido
 *    (`$empresa['portal']`).
 * 2. **"Nunca medimos" não é "medimos e é zero".** Acervo nunca coletado devolve
 *    `publicacoes = null` e `publicacoes_pedem_acao = null` — NUNCA `0`, que
 *    mentiria "esta conta não tem anúncio". Mesma disciplina do
 *    `AcervoTriagemService::defasagem()` e dos indicadores da Visão geral.
 *
 * ⚠️ **O contador do dot vermelho se chama `publicacoes_pedem_acao`, não "com
 * erro".** O protótipo do layout novo fala de "publicações com erro", mas
 * `MlAcervoItem.status` só assume `active|paused|under_review|closed`: **não
 * existe status de erro no acervo**. O número real e acionável é "publicação
 * com 1 ou mais motivos de triagem" (`severidade > 0`), exatamente o que a tela
 * de Publicações já conta hoje. É uma divergência DECLARADA de rótulo (FID-02)
 * com restrição concreta — a ausência da coluna —, não preferência de estilo.
 */
class CabecalhoPublicadorService
{
    /** As abas que o header conhece. `aba` é sempre LITERAL do controller — nunca vem do usuário. */
    public const ABAS = ['visao-geral', 'produtos', 'publicacoes', 'alavancas', 'configuracoes'];

    public function __construct(
        private ProgramasPublicadorService $programas,
        private AcervoTriagemService $triagem,
    ) {}

    /**
     * A prop `cabecalho` de uma página.
     *
     * @param  array  $alvo  saída de `ProgramasPublicadorService::resolver()` (nunca null aqui)
     * @param  array  $empresa  saída de `empresaParaTela()` que o controller JÁ calculou
     * @param  string  $aba  uma de `self::ABAS` — literal do controller
     * @param  ?array  $defasagem  `AcervoTriagemService::defasagem()` já calculado pela action
     *                             (`visaoGeral()` e `meus()` têm o valor em mãos): passar evita
     *                             repetir a consulta
     * @return array{
     *     aba: string,
     *     abas_ocultas: list<string>,
     *     empresa: array,
     *     company_id: ?int,
     *     contadores: array{produtos: int, publicacoes: ?int, publicacoes_pedem_acao: ?int, alavancas: int},
     *     integracoes: array{
     *         mercado_livre: array{estado: string, conta_nome: ?string, conta_ml_id: ?string, link_reconexao: ?string},
     *         erp: array{declarado: ?string},
     *         portal: array,
     *         coleta: array{coletado_em: ?string, horas: ?int, defasado: bool, nunca_coletado: bool}
     *     }
     * }
     */
    public function para(array $alvo, array $empresa, string $aba, ?array $defasagem = null): array
    {
        $e = $alvo['mlb_empresa'] ?? null;
        $c = $alvo['company'] ?? null;

        // D23: sem Company não há acervo para medir nem defasagem para carimbar. A
        // ausência é EXPLÍCITA (`nunca_coletado = true`), para a tela aplicar o literal
        // "Disponível só para empresas cadastradas no sistema" em vez de desenhar um
        // zero que não foi medido.
        $coleta = $c === null
            ? ['coletado_em' => null, 'horas' => null, 'defasado' => false, 'nunca_coletado' => true]
            : ($defasagem ?? $this->triagem->defasagem($c));

        $nuncaColetado = (bool) ($coleta['nunca_coletado'] ?? true);
        $semAcervo = $c === null || $nuncaColetado;

        return [
            'aba' => $aba,
            // 20/10/2026: as abas que o header esconde (`publicador.abas_ocultas`). Produtos nunca some.
            'abas_ocultas' => self::abasOcultas(),
            // Intacto: o header lê nome, chave, identificador, programa, token e portal daqui.
            'empresa' => $empresa,
            'company_id' => $c?->id,
            'contadores' => [
                'produtos' => $this->contadorProdutos($alvo),
                // null = o acervo NUNCA foi coletado. Ver a honestidade 2 no docblock da classe.
                'publicacoes' => $semAcervo ? null : MlAcervoItem::where('company_id', $c->id)->count(),
                // "Pedem ação" = `severidade > 0` = tem 1 ou mais motivos de triagem. É o
                // MESMO número que `AcervoTriagemService::triagem()['total']` devolve para a
                // tela de Publicações — mesmo escopo (`escopo()`), por isso o dot do header
                // nunca discorda do topo daquela tela.
                'publicacoes_pedem_acao' => $semAcervo
                    ? null
                    : $this->triagem->escopo($c, '', 'acionaveis')->where('severidade', '>', 0)->count(),
                // Já existe e já é resistente a tabela ausente (devolve 0 com warning no log).
                'alavancas' => PubTarefa::abertasDaConta($e, $c),
            ],
            'integracoes' => [
                'mercado_livre' => [
                    // O MESMO estado que o `SeloConta` mostra hoje: ativo / expirado / sem_token.
                    // Nenhuma chamada ao ML para descobrir isso (D-05) e nenhum prazo de
                    // renovação inventado: o contrato não sabe quando o token renova.
                    'estado' => (string) ($empresa['token'] ?? 'sem_token'),
                    'conta_nome' => $empresa['conta_nome'] ?? null,
                    'conta_ml_id' => $empresa['conta_ml_id'] ?? null,
                    'link_reconexao' => $empresa['link_reconexao'] ?? null,
                ],
                // ⚠️ SÓ `declarado`. Ver a honestidade 1 no docblock da classe.
                'erp' => ['declarado' => ProgramasPublicadorService::erpDeclarado($e?->implementacao)],
                'portal' => $empresa['portal'] ?? ['situacao' => 'sem_portal', 'novas' => 0, 'sincronizado_em' => null],
                'coleta' => [
                    'coletado_em' => $coleta['coletado_em'] ?? null,
                    'horas' => $coleta['horas'] ?? null,
                    'defasado' => (bool) ($coleta['defasado'] ?? false),
                    'nunca_coletado' => $nuncaColetado,
                ],
            ],
        ];
    }

    /**
     * As abas que o header esconde (decisão do usuário, 20/10/2026: "vai ficar apenas Produtos").
     * Só as três que podem sumir; chave desconhecida e 'produtos' são ignoradas — a conta sempre
     * tem por onde entrar.
     *
     * @return list<string>
     */
    public static function abasOcultas(): array
    {
        $podemSumir = ['visao-geral', 'publicacoes', 'alavancas'];

        return array_values(array_intersect($podemSumir, array_map('strval', (array) config('publicador.abas_ocultas', []))));
    }

    /**
     * Só os contadores das abas — para quem precisa recontar sem remontar a identidade.
     *
     * @return array{produtos: int, publicacoes: ?int, publicacoes_pedem_acao: ?int, alavancas: int}
     */
    public function contadores(array $alvo, ?array $defasagem = null): array
    {
        return $this->para($alvo, [], 'visao-geral', $defasagem)['contadores'];
    }

    /**
     * Produtos da conta. Reusa `ProgramasPublicadorService::produtosQuery()`, que já
     * agrupa os dois `orWhere` dentro de `where(function…)`.
     *
     * ⚠️ O agrupamento NÃO é estilo: `orWhere` solto sobe ao topo do WHERE e anula o
     * escopo da conta — o header passaria a contar produto de outra empresa (T-134-01,
     * documentado em `AcervoTriagemService::escopo()`). Sem âncora nenhuma o builder já
     * aplica `1 = 0` e o contador é 0.
     */
    private function contadorProdutos(array $alvo): int
    {
        return $this->programas
            ->produtosQuery($alvo['mlb_empresa'] ?? null, $alvo['company'] ?? null)
            ->count();
    }
}

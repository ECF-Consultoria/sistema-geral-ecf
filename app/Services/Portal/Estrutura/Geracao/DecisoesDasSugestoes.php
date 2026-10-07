<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaSugestaoDescartada;
use App\Models\EstruturaTipoProduto;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * As escritas da tela de sugestões (Fase 168): aceitar, descartar, restaurar e
 * definir o tipo/quantidades do produto.
 *
 * Empresa e ator vêm sempre do chamador (contexto do portal), nunca do request.
 * Toda escrita deixa rastro com a origem (cliente ou interno) em `RegistroEstrutura`.
 */
class DecisoesDasSugestoes
{
    public function __construct(
        private SugestoesService $sugestoes,
        private EstruturaOfertaService $ofertas,
    ) {}

    /**
     * Aceita sugestões: o navegador manda só chave+nome+sku; o servidor regera e
     * reconstrói a composição — sem isso, id de outra empresa viraria componente.
     * A oferta nasce pela mesma regra da Lista SKUs; a espera é varrida uma vez no fim
     * e o erro de uma sugestão não impede as outras.
     *
     * @param  list<array{chave: string, nome?: ?string, sku?: ?string}>  $pedidos
     * @return array{criadas: list<array{chave: string, sku: string, oferta_id: int}>, ja_existiam: list<string>, erros: list<array{chave: string, mensagem: string}>}
     */
    public function aceitar(Company $empresa, array $pedidos, AtorDoPortal $ator): array
    {
        $limite = (int) config('estrutura_geracao.lote_aceite');
        if (count($pedidos) > $limite) {
            throw ValidationException::withMessages(['sugestoes' => "Você pode aceitar até {$limite} de uma vez."]);
        }

        return DB::transaction(function () use ($empresa, $pedidos, $ator) {
            // Serializa aceites concorrentes da mesma empresa; a geração abaixo já enxerga o que eles criaram.
            Company::whereKey($empresa->id)->lockForUpdate()->first();

            $vigentes = [];
            foreach ($this->sugestoes->gerar($empresa)['sugestoes'] as $s) {
                if (! $s['descartada']) {
                    $vigentes[$s['chave']] = $s;
                }
            }

            $criadas = [];
            $jaExistiam = [];
            $erros = [];
            $skus = [];

            foreach ($pedidos as $pedido) {
                $chave = (string) ($pedido['chave'] ?? '');

                if (! ChaveDeComposicao::valida($chave) || ! isset($vigentes[$chave])) {
                    $jaExistiam[] = $chave;
                    continue;
                }

                $s = $vigentes[$chave];
                unset($vigentes[$chave]); // a mesma chave duas vezes no lote cria uma só

                $sku  = trim((string) ($pedido['sku'] ?? ''));
                $nome = trim((string) ($pedido['nome'] ?? ''));

                try {
                    [$oferta] = $this->ofertas->criar($empresa, [
                        'sku'         => $sku !== '' ? $sku : $s['sku'],
                        'nome'        => $nome !== '' ? $nome : $s['nome'],
                        'fase'        => $s['fase'],
                        'componentes' => array_map(
                            fn ($i) => ['id' => $i['oferta_id'], 'quantidade' => $i['quantidade']],
                            $s['itens']
                        ),
                    ], $ator, varrerEspera: false);
                } catch (ValidationException $e) {
                    $erros[] = ['chave' => $chave, 'mensagem' => (string) collect($e->errors())->flatten()->first()];
                    continue;
                }

                $skus[] = $oferta->sku;
                $criadas[] = ['chave' => $chave, 'sku' => $oferta->sku, 'oferta_id' => $oferta->id];
            }

            if ($skus !== []) {
                $this->ofertas->varrerEspera($empresa, $skus);
            }

            RegistroEstrutura::registrar($ator, $empresa, null, 'sugestoes_aceitas',
                count($criadas).' sugestão(ões) aceita(s)',
                ['criadas' => count($criadas), 'ja_existiam' => count($jaExistiam), 'erros' => count($erros)]);

            return ['criadas' => $criadas, 'ja_existiam' => $jaExistiam, 'erros' => $erros];
        });
    }

    /**
     * Descarta (idempotente). Só vale chave que a geração conhece: vigente ou já descartada.
     *
     * @param  list<string>  $chaves
     * @return array{descartadas: int}
     */
    public function descartar(Company $empresa, array $chaves, AtorDoPortal $ator): array
    {
        $conhecidas = [];
        foreach ($this->sugestoes->gerar($empresa)['sugestoes'] as $s) {
            $conhecidas[$s['chave']] = $s['fase'];
        }

        $agora = now();
        $linhas = [];
        foreach (array_unique($chaves) as $chave) {
            if (is_string($chave) && ChaveDeComposicao::valida($chave) && isset($conhecidas[$chave])) {
                $linhas[] = [
                    'company_id' => $empresa->id, 'chave' => $chave, 'fase' => $conhecidas[$chave],
                    'created_at' => $agora, 'updated_at' => $agora,
                ];
            }
        }

        if ($linhas !== []) {
            EstruturaSugestaoDescartada::query()->insertOrIgnore($linhas);
        }

        RegistroEstrutura::registrar($ator, $empresa, null, 'sugestoes_descartadas',
            count($linhas).' sugestão(ões) descartada(s)', ['descartadas' => count($linhas)]);

        return ['descartadas' => count($linhas)];
    }

    /**
     * @param  list<string>  $chaves
     * @return array{restauradas: int, ja_existem: int}
     */
    public function restaurar(Company $empresa, array $chaves, AtorDoPortal $ator): array
    {
        $validas = array_values(array_unique(array_filter(
            $chaves, fn ($c) => is_string($c) && ChaveDeComposicao::valida($c)
        )));

        $existentes = $this->sugestoes->gerar($empresa)['retrato']['existentes'] ?? [];
        $jaExistem = count(array_filter($validas, fn ($c) => isset($existentes[$c])));

        $restauradas = $validas === [] ? 0 : EstruturaSugestaoDescartada::query()
            ->where('company_id', $empresa->id)
            ->whereIn('chave', $validas)
            ->delete();

        RegistroEstrutura::registrar($ator, $empresa, null, 'sugestoes_restauradas',
            "{$restauradas} sugestão(ões) restaurada(s)", ['restauradas' => $restauradas, 'ja_existem' => $jaExistem]);

        return ['restauradas' => $restauradas, 'ja_existem' => $jaExistem];
    }

    /**
     * Tipo e quantidades do produto na tabela 1:1 (a ficha da 167 não muda).
     * Vazio = herda o padrão do tipo; '0' = nenhuma (o ConvertEmptyStringsToNull
     * transforma '' em null).
     *
     * @return array{produto_id: int, tipo: ?string, qtd_combo: ?string, qtd_combit: ?string}
     */
    public function definirGeracao(Company $empresa, int $produtoId, ?int $tipoId, ?string $qtdCombo, ?string $qtdCombit, AtorDoPortal $ator): array
    {
        $produto = EstruturaProduto::where('company_id', $empresa->id)->findOrFail($produtoId);

        $tipo = null;
        if ($tipoId !== null) {
            $tipo = EstruturaTipoProduto::find($tipoId);
            if (! $tipo) {
                throw ValidationException::withMessages(['tipo_id' => 'Tipo inválido.']);
            }
        }

        $combo  = Quantidades::paraTexto(Quantidades::ler($qtdCombo, 'qtd_combo'));
        $combit = Quantidades::paraTexto(Quantidades::ler($qtdCombit, 'qtd_combit'));

        if ($tipo === null && $combo === null && $combit === null) {
            EstruturaProdutoGeracao::where('produto_id', $produto->id)->delete();
        } else {
            EstruturaProdutoGeracao::updateOrCreate(
                ['produto_id' => $produto->id],
                ['company_id' => $empresa->id, 'tipo_id' => $tipo?->id, 'qtd_combo' => $combo, 'qtd_combit' => $combit]
            );
        }

        RegistroEstrutura::registrar($ator, $empresa, $produto, 'tipo_do_produto_definido',
            "Tipo do produto {$produto->nome} definido para as sugestões",
            ['tipo' => $tipo?->slug, 'qtd_combo' => $combo, 'qtd_combit' => $combit]);

        return ['produto_id' => $produto->id, 'tipo' => $tipo?->slug, 'qtd_combo' => $combo, 'qtd_combit' => $combit];
    }
}

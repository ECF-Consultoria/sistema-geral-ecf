<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Services\Portal\Estrutura\EstruturaAnuncioService;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Support\Portal\AtorDoPortal;

/**
 * O exemplo preenchido da planilha `Mapeamento_Estrutural_Sellers_Projeto_Polos_2026_1.xlsx`
 * (cadeira + mesa, "da aula"), montado pelos MESMOS services que a tela usa.
 *
 * É o gabarito: os números que a planilha calculou sobre este exemplo são a
 * régua do sistema. Se o sistema não os reproduzir, o sistema está errado.
 *
 * Painel da planilha (K5:K15, sem K10 — kit virtual deixou de ser fase):
 *   9 ofertas · 2 simples / 5 combos / 1 kit / 1 combit
 *   18 necessários · 4 publicados · 14 a publicar · 1 completa · 22,2%
 */
trait GabaritoDaPlanilhaEstrutural
{
    use EntraNoPortal;

    protected function empresaDoGabarito(): Company
    {
        return Company::create([
            'name'   => 'Seller Gabarito '.uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    protected function atorCliente(Company $empresa): AtorDoPortal
    {
        return AtorDoPortal::cliente($this->clienteDoPortal($empresa));
    }

    /**
     * As 9 linhas da aba "Lista SKUs", na ordem dela.
     *
     * @return array<string, EstruturaOferta> por SKU
     */
    protected function listaDoGabarito(Company $empresa, AtorDoPortal $ator): array
    {
        $svc = app(EstruturaOfertaService::class);
        $o = [];

        $criar = function (array $dados) use ($svc, $empresa, $ator, &$o) {
            [$oferta] = $svc->criar($empresa, $dados, $ator);
            $o[$oferta->sku] = $oferta;
        };

        $criar(['sku' => 'CAD-01', 'fase' => 'simples', 'nome' => 'Cadeira 01', 'logistica' => 'mercado_envios']);
        foreach ([2, 3, 4, 5, 6] as $n) {
            $criar([
                'sku' => "CAD-01-CB{$n}", 'fase' => 'combo', 'nome' => "Combo {$n} Cadeiras 01",
                'logistica' => $n <= 4 ? 'mercado_envios' : 'transportadora_me1',
                'observacoes' => $n === 6 ? 'Cliente de atacado' : null,
                'componentes' => [['id' => $o['CAD-01']->id, 'quantidade' => $n]],
            ]);
        }
        $criar(['sku' => 'MSA-MR', 'fase' => 'simples', 'nome' => 'Mesa Marfim', 'logistica' => 'combinar',
            'observacoes' => 'Mesa grande: não faz combo']);
        $criar(['sku' => 'MSA-MR+CAD-01-KIT', 'fase' => 'kit', 'nome' => 'Kit Mesa Marfim + 1 Cadeira 01',
            'logistica' => 'kit_virtual', 'observacoes' => 'Multivolume',
            'componentes' => [['id' => $o['MSA-MR']->id, 'quantidade' => 1], ['id' => $o['CAD-01']->id, 'quantidade' => 1]]]);
        $criar(['sku' => 'MSA-MR+CAD-01-CBT4', 'fase' => 'combit', 'nome' => 'Combit Mesa Marfim + 4 Cadeiras 01',
            'logistica' => 'transportadora_me1', 'observacoes' => 'Conjunto de jantar',
            'componentes' => [['id' => $o['MSA-MR']->id, 'quantidade' => 1], ['id' => $o['CAD-01']->id, 'quantidade' => 4]]]);

        return $o;
    }

    /** As 4 linhas da aba "Anúncios", cadastradas uma a uma. */
    protected function anunciosDoGabarito(array $ofertas, AtorDoPortal $ator): void
    {
        $svc = app(EstruturaAnuncioService::class);

        $svc->cadastrar($ofertas['CAD-01'], ['tipo' => 'classico', 'codigo_mlb' => 'MLB0000000001',
            'titulo' => 'Cadeira de Jantar Estofada Madeira Maciça', 'catalogo' => false, 'status' => 'ativo'], $ator);
        $svc->cadastrar($ofertas['CAD-01'], ['tipo' => 'premium', 'codigo_mlb' => 'MLB0000000002',
            'titulo' => 'Cadeira Sala de Jantar Moderna Assento Estofado', 'catalogo' => false, 'status' => 'ativo'], $ator);
        $svc->cadastrar($ofertas['CAD-01-CB2'], ['tipo' => 'classico', 'codigo_mlb' => 'MLB0000000003',
            'titulo' => 'Kit 2 Cadeiras de Jantar Estofadas Madeira', 'catalogo' => false, 'status' => 'ativo'], $ator);
        $svc->cadastrar($ofertas['MSA-MR'], ['tipo' => 'premium', 'codigo_mlb' => 'MLB0000000004',
            'titulo' => 'Mesa de Jantar Marfim 6 Lugares Tampo MDF', 'catalogo' => true, 'status' => 'ativo'], $ator);
    }

    /** A aba "Anúncios" como ela sai quando se copia do Excel, com o cabeçalho. */
    protected function abaAnunciosColada(): string
    {
        return implode("\n", [
            "SKU\tCÓDIGO MLB\tTÍTULO DO ANÚNCIO\tTIPO\tCATÁLOGO?\tSTATUS",
            "CAD-01\tMLB0000000001\tCadeira de Jantar Estofada Madeira Maciça\tClássico\tNão\tAtivo",
            "CAD-01\tMLB0000000002\tCadeira Sala de Jantar Moderna Assento Estofado\tPremium\tNão\tAtivo",
            "CAD-01-CB2\tMLB0000000003\tKit 2 Cadeiras de Jantar Estofadas Madeira\tClássico\tNão\tAtivo",
            "MSA-MR\tMLB0000000004\tMesa de Jantar Marfim 6 Lugares Tampo MDF\tPremium\tSim\tAtivo",
        ]);
    }
}

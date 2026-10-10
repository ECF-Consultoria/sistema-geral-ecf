<?php

namespace App\Support\Portal;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\EstruturaOferta;

/**
 * Quais submódulos do Mapeamento Estrutural aparecem no menu (09/10/2026).
 *
 * Pedido do usuário: "vou usar realmente produtos, planejamento e precificação — e
 * mapeamento para saber o que está pendente; o resto vai ser meio inútil". Por isso:
 *
 * - O CLIENTE vê, por padrão, Produtos, Planejamento, Precificação e Mapeamento
 *   ({@see self::PADRAO_CLIENTE}).
 * - A EQUIPE (entrada de equipe no portal, `AtorDoPortal->equipe`) vê todos, sempre.
 * - Exceção automática: empresa com oferta simples SEM produto ligado (importou as
 *   ofertas, como a #131) continua vendo Lista SKUs e Anúncios — é por lá que ela
 *   trabalha essas ofertas.
 * - Sem deploy, em `configuracoes` (o mesmo padrão de `creative_engine_usuarios`: lista
 *   em texto, separada por vírgula):
 *   - `portal_estrutura_submodulos_cliente` troca a lista padrão do cliente;
 *   - `portal_estrutura_submodulos_empresa_{id}` dá a lista EXATA daquela empresa (vence a
 *     padrão e a exceção automática); `todos` = todos.
 *   Chave desconhecida é ignorada; uma lista que fica vazia vale como ausente.
 *
 * Esconder NÃO remove: rotas, allowlist e controllers continuam. A página escondida abre
 * por link direto e, enquanto a pessoa está nela, o item aparece no menu marcado (`oculto`),
 * para o título e a trilha dizerem onde ela está.
 */
final class VisibilidadeDoMapeamento
{
    public const CHAVE_PADRAO = 'portal_estrutura_submodulos_cliente';

    public const PREFIXO_EMPRESA = 'portal_estrutura_submodulos_empresa_';

    /** O que o cliente vê sem nenhuma configuração. */
    public const PADRAO_CLIENTE = ['produtos', 'sugestoes', 'precificacao', 'mapeamento'];

    /** O que a empresa que importou ofertas (sem produto) continua vendo. */
    public const DA_IMPORTACAO = ['lista', 'anuncios'];

    /**
     * Os submódulos visíveis para quem está no portal; null = todos (equipe ou sem ator).
     *
     * @param  list<string>  $existentes  as chaves que o módulo tem (para descartar lixo da configuração)
     * @return list<string>|null
     */
    public static function visiveis(Company $company, ?AtorDoPortal $ator, array $existentes): ?array
    {
        if ($ator === null || $ator->equipe) {
            return null;
        }

        $daEmpresa = trim((string) Configuracao::get(self::PREFIXO_EMPRESA.$company->id, ''));
        if (mb_strtolower($daEmpresa) === 'todos') {
            return null;
        }
        $lista = self::ler($daEmpresa, $existentes);
        if ($lista !== []) {
            return $lista;
        }

        $padrao = self::ler((string) Configuracao::get(self::CHAVE_PADRAO, ''), $existentes);
        $lista = $padrao !== [] ? $padrao : array_values(array_intersect(self::PADRAO_CLIENTE, $existentes));

        if (self::importouOfertas($company)) {
            $lista = array_values(array_unique([...$lista, ...array_intersect(self::DA_IMPORTACAO, $existentes)]));
        }

        return $lista;
    }

    /** Tem oferta simples sem produto ligado (as importadas, como as 500 da #131)? */
    public static function importouOfertas(Company $company): bool
    {
        return EstruturaOferta::query()
            ->where('company_id', $company->id)
            ->where('fase', EstruturaOferta::FASE_SIMPLES)
            ->whereNull('variacao_id')
            ->exists();
    }

    /**
     * "produtos, sugestoes,  lista" → ['produtos', 'sugestoes', 'lista'], só as que existem.
     *
     * @param  list<string>  $existentes
     * @return list<string>
     */
    private static function ler(string $csv, array $existentes): array
    {
        if (trim($csv) === '') {
            return [];
        }

        $lidas = array_map(fn ($c) => mb_strtolower(trim($c)), explode(',', $csv));

        return array_values(array_unique(array_filter($lidas, fn ($c) => in_array($c, $existentes, true))));
    }
}

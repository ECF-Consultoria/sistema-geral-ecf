<?php

namespace App\Support\Portal;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\EstruturaOferta;

/**
 * Quais submódulos do Mapeamento Estrutural aparecem no menu.
 *
 * Pedido do usuário (09/10/2026): "vou usar realmente produtos, planejamento e precificação — e
 * mapeamento para saber o que está pendente; o resto vai ser meio inútil". E em 10/10/2026, vendo
 * o portal como equipe: "Lista SKUs e Anúncios eu não vou usar, pode tirar — fica Produtos,
 * Planejamento, Precificação e Mapeamento". Por isso:
 *
 * - TODOS — cliente e equipe (`AtorDoPortal->equipe`) — veem Produtos, Planejamento, Precificação e
 *   Mapeamento ({@see self::PADRAO_CLIENTE}). Até 10/10 a equipe via os 7 e a empresa que importou
 *   ofertas (como a #131) via também Lista SKUs e Anúncios; as duas exceções saíram. O Cronograma
 *   (`planejamento`) também fica fora: não está na lista do usuário.
 * - Sem deploy, em `configuracoes` (o mesmo padrão de `creative_engine_usuarios`: lista
 *   em texto, separada por vírgula):
 *   - `portal_estrutura_submodulos_cliente` troca a lista padrão (vale para todos);
 *   - `portal_estrutura_submodulos_empresa_{id}` dá a lista EXATA daquela empresa (vence a
 *     padrão); `todos` = todos.
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

    /** O que todos (cliente e equipe) veem sem nenhuma configuração. */
    public const PADRAO_CLIENTE = ['produtos', 'sugestoes', 'precificacao', 'mapeamento'];

    /**
     * Os submódulos visíveis para quem está no portal; null = todos (sem ator, ou `todos` na
     * configuração da empresa). Cliente e equipe seguem a MESMA régua (10/10/2026).
     *
     * @param  list<string>  $existentes  as chaves que o módulo tem (para descartar lixo da configuração)
     * @return list<string>|null
     */
    public static function visiveis(Company $company, ?AtorDoPortal $ator, array $existentes): ?array
    {
        if ($ator === null) {
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

        return $padrao !== [] ? $padrao : array_values(array_intersect(self::PADRAO_CLIENTE, $existentes));
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

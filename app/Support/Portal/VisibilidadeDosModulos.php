<?php

namespace App\Support\Portal;

use App\Models\Company;
use App\Models\Configuracao;

/**
 * Quais MÓDULOS do Portal aparecem no menu.
 *
 * Pedido do usuário (13/10/2026): "no portal do cliente, ocultar tudo e deixar apenas mapeamento
 * estrutural". Por isso, sem nenhuma configuração, o menu tem só o Mapeamento Estrutural
 * ({@see self::PADRAO}); Início, Onboarding, Calculadora de Custo e PPA ficam escondidos.
 *
 * - Cliente e equipe (`AtorDoPortal->equipe`) seguem a MESMA régua, como nos submódulos
 *   ({@see VisibilidadeDoMapeamento}): quem confere o portal pela porta da equipe precisa ver o
 *   que o cliente vê.
 * - Sem deploy, em `configuracoes` (lista em texto, separada por vírgula, com as chaves de
 *   {@see ModulosPortal}: `inicio`, `onboarding`, `calculadora`, `ppa`, `estrutura`):
 *   - `portal_modulos_cliente` troca a lista padrão (vale para todos);
 *   - `portal_modulos_empresa_{id}` dá a lista EXATA daquela empresa (vence a padrão).
 *   Nas duas, `todos` = todos os módulos. Chave desconhecida é ignorada; uma lista que fica
 *   vazia vale como ausente (o portal nunca fica sem menu).
 *
 * Esconder NÃO remove: rotas, allowlist e controllers continuam. A página escondida abre por
 * link direto e, enquanto a pessoa está nela, o item aparece no menu marcado (`oculto`). A única
 * exceção é o Início, que é para onde o login manda: escondido, ele leva ao primeiro módulo do
 * menu ({@see ModulosPortal::entrada()}), senão a pessoa cairia numa tela que "não existe".
 *
 * Sem ator (o portal antigo, por token, que já não abre) nada é escondido.
 */
final class VisibilidadeDosModulos
{
    public const CHAVE_PADRAO = 'portal_modulos_cliente';

    public const PREFIXO_EMPRESA = 'portal_modulos_empresa_';

    public const TODOS = 'todos';

    /** O que todos (cliente e equipe) veem sem nenhuma configuração. */
    public const PADRAO = [ModulosPortal::ESTRUTURA];

    /**
     * Os módulos visíveis para quem está no portal; null = todos.
     *
     * @param  list<string>  $existentes  as chaves que o portal tem (para descartar lixo da configuração)
     * @return list<string>|null
     */
    public static function visiveis(Company $company, ?AtorDoPortal $ator, array $existentes): ?array
    {
        if ($ator === null) {
            return null;
        }

        foreach ([self::PREFIXO_EMPRESA.$company->id, self::CHAVE_PADRAO] as $chave) {
            $valor = trim((string) Configuracao::get($chave, ''));
            if (mb_strtolower($valor) === self::TODOS) {
                return null;
            }
            $lista = self::ler($valor, $existentes);
            if ($lista !== []) {
                return $lista;
            }
        }

        return array_values(array_intersect(self::PADRAO, $existentes));
    }

    /**
     * "ppa, estrutura,  nada" → ['ppa', 'estrutura'], só as que existem.
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

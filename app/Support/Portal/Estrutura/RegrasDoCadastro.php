<?php

namespace App\Support\Portal\Estrutura;

use App\Models\EstruturaProduto;
use Illuminate\Http\Request;

/**
 * As regras de leitura do cadastro de produtos que valem nas DUAS portas que gravam o catálogo: a do Portal
 * (`PortalEstruturaProdutosController`) e a da equipe na trilha (`Trilha\CadastroDaTrilhaController`, 3ª entrega,
 * 02/11/2026). Duas cópias da mesma regra já custaram caro: a trilha usa esta, e o Portal passou a usá-la também
 * (extração mecânica, comportamento igual).
 *
 * Só leitura de requisição: nada aqui grava, consulta o banco ou sabe de qual porta veio o pedido.
 */
final class RegrasDoCadastro
{
    /**
     * Teto de opções num campo multivalor da ficha técnica (os chips). Não é regra de negócio: é só para um corpo
     * absurdo não chegar ao serviço. A lista de opções de uma categoria não passa disso.
     */
    public const MAX_OPCOES_MULTIVALOR = 60;

    /**
     * As linhas como o navegador mandou. No contrato de linha `null` explícito LIMPA
     * o campo (custo, eixo, valor, família) e texto vazio é "não mexi"; o middleware
     * global `ConvertEmptyStringsToNull` transformaria todo `''` em `null` e um campo
     * vazio por engano apagaria o dado. Por isso o JSON é lido cru — a estrutura é a
     * mesma que o `validate` de quem chama já conferiu.
     *
     * @return array<int, mixed>
     */
    public static function linhasComoVieram(Request $request): array
    {
        if ($request->isJson()) {
            $corpo = json_decode((string) $request->getContent(), true);
            if (is_array($corpo) && is_array($corpo['linhas'] ?? null)) {
                return array_values($corpo['linhas']);
            }
        }

        return (array) $request->input('linhas');
    }

    // ═══ Ficha técnica ══════════════════════════════════════════════════════

    /**
     * A FORMA do corpo da ficha técnica (gravar substitui: é a ficha inteira). Quem confere se o campo existe na
     * categoria, se aceita lista e se cada opção é válida é o `FichaTecnicaDoProduto` — contra a definição da
     * categoria. Aqui só se barra o que não é nenhum dos formatos aceitos.
     *
     * @return array<string, mixed>
     */
    public static function fichaTecnica(): array
    {
        return [
            'atributos'           => 'present|array|max:300',
            'atributos.*'         => 'array',
            'atributos.*.id'      => 'required|string|max:80',
            'atributos.*.unidade' => 'nullable|string|max:20',
            // "Não se aplica" vem por marcador próprio, nunca pelo valor (ver FichaTecnicaDoProduto::NAO_SE_APLICA).
            'atributos.*.nao_se_aplica' => 'nullable|boolean',
            // O valor veio do "Outro" de uma lista (11/10/2026): texto escrito à mão, nunca id de opção.
            // Na lista de várias opções, `proprios` diz quais itens de `valor` são texto.
            'atributos.*.proprio' => 'nullable|boolean',
            'atributos.*.proprios' => 'nullable|array|max:50',
            'atributos.*.proprios.*' => 'string|max:255',
            // Escalar, ou a LISTA de opções de um campo multivalor (os chips). Quem confere se
            // o campo aceita lista, e se cada opção existe, é o serviço — contra a definição
            // da categoria. Aqui só se barra o que não é nenhum dos dois (objeto, lista aninhada).
            'atributos.*.valor'   => ['nullable', function (string $campo, mixed $valor, \Closure $falhou) {
                if (is_scalar($valor)) {
                    return;
                }
                if (is_array($valor) && count($valor) <= self::MAX_OPCOES_MULTIVALOR && ! array_filter($valor, fn ($v) => ! is_scalar($v))) {
                    return;
                }
                $falhou('Valor inválido.');
            }],
        ];
    }

    /**
     * As mensagens de {@see fichaTecnica()} e {@see iaDaFicha()} em português — o idioma do framework nesta instalação
     * é o inglês, e a tela da trilha descarta a mensagem padrão dele. Por ora só a trilha as passa: o Portal segue com
     * as de sempre (comportamento igual). A do `valor` é a da própria regra ("Valor inválido.").
     *
     * @return array<string, string>
     */
    public static function mensagensDaFicha(): array
    {
        return [
            'atributos.present'        => 'Envie a ficha técnica.',
            'atributos.array'          => 'A ficha técnica veio em formato inválido.',
            'atributos.max'            => 'A ficha técnica pode ter no máximo 300 campos.',
            'atributos.*'              => 'Campo da ficha em formato inválido.',
            'atributos.*.id'           => 'Campo da ficha sem identificação.',
            'atributos.*.unidade'      => 'Unidade inválida.',
            'atributos.*.nao_se_aplica' => '“Não se aplica” em formato inválido.',
            'atributos.*.proprio'      => 'Texto escrito à mão em formato inválido.',
            'atributos.*.proprios'     => 'Texto escrito à mão em formato inválido.',
            'atributos.*.proprios.*'   => 'Texto escrito à mão em formato inválido.',
            'categoria'                => 'Categoria inválida.',
            'titulo'                   => 'O título pode ter no máximo 255 caracteres.',
            'descricao'                => 'A descrição pode ter no máximo 5.000 caracteres.',
        ];
    }

    /**
     * A forma do corpo do "Preencher com IA" da ficha (29/10/2026): o que está NA TELA agora (o que se digitou e não
     * salvou conta), mais a categoria que a tela tem aberta.
     *
     * @return array<string, mixed>
     */
    public static function iaDaFicha(): array
    {
        return [
            'categoria'   => 'nullable|string|max:20',
            'titulo'      => 'nullable|string|max:255',
            'descricao'   => 'nullable|string|max:5000',
            'atributos'   => 'present|array|max:300',
            'atributos.*' => 'array',
            'atributos.*.id' => 'required|string|max:80',
        ];
    }

    /**
     * Por que a IA da ficha não roda agora, ou null quando pode. Os campos que ela preenche são os da categoria SALVA:
     * sem categoria salva, ou com a tela numa categoria que ainda não foi salva, a sugestão viria para os campos
     * errados. Nenhuma chamada sai antes desta conferência.
     */
    public static function recusaDaIa(EstruturaProduto $produto, ?string $categoriaDaTela): ?string
    {
        if (! filled($produto->categoria_ml_id)) {
            return 'Escolha a categoria e salve o produto antes de usar a IA.';
        }
        if (filled($categoriaDaTela) && $categoriaDaTela !== $produto->categoria_ml_id) {
            return 'A categoria mudou: salve o produto antes de usar a IA.';
        }

        return null;
    }

    /**
     * Os campos da ficha que a tela mandou, por id (em maiúsculas; o último repetido vence) — a entrada que o
     * `JardinagemDaFicha::sugerir` espera.
     *
     * @param  array<int, mixed>  $atributos
     * @return array<string, array>
     */
    public static function atributosDaTela(array $atributos): array
    {
        $atuais = [];
        foreach ($atributos as $a) {
            $atuais[strtoupper(trim((string) ($a['id'] ?? '')))] = (array) $a;
        }

        return $atuais;
    }
}

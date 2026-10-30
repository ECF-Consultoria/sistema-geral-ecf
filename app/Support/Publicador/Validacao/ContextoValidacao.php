<?php

namespace App\Support\Publicador\Validacao;

use App\Support\Publicador\Payload\MontadorDePlano;

/**
 * O que a validação precisa saber além do rascunho e do schema: a conta (modelo,
 * tags, modos de envio), os metadados das fotos e as regras configuráveis
 * ([HIP] nunca vira regra fixa — `12`). O valor padrão de cada opção é o da
 * especificação; quem monta o contexto real é a camada de serviço, a partir de
 * `config/publicador.php`.
 */
final class ContextoValidacao
{
    /** `08` V-TIT-02: lista configurável. Telefone, e-mail e URL são detectados à parte. */
    public const TERMOS_PROIBIDOS_TITULO = ['frete gratis', 'parcelado', 'sem juros', 'novo', 'usado', 'promocao', 'oferta'];

    /**
     * @param  list<string>  $tagsDaConta  de `GET /users/me`
     * @param  list<string>|null  $modosEnvio  `shipping_preferences.modes`; nulo = não lido (não confere)
     * @param  array<string, array{mime?: string, bytes?: int, largura?: int, altura?: int, upload_status?: string, cmyk?: bool}>  $imagens
     * @param  list<array{maior: string, menor: string}>  $plausibilidade  V-ATT-11, por categoria
     * @param  ?int  $limiteFamilyName  teto extra do `family_name` (o 120 do [ML·S8] não apareceu no validate — N-14)
     */
    public function __construct(
        public readonly string $modelo = MontadorDePlano::UP,
        public readonly array $tagsDaConta = [],
        public readonly ?array $modosEnvio = null,
        public readonly array $imagens = [],
        public readonly bool $paraPublicar = false,
        public readonly array $plausibilidade = [],
        public readonly array $termosProibidosTitulo = self::TERMOS_PROIBIDOS_TITULO,
        public readonly ?int $limiteFamilyName = null,
        public readonly int $maxBytesImagem = 10 * 1024 * 1024,
        public readonly int $ladoMinimoImagem = 500,
        public readonly int $ladoRecomendadoImagem = 1200,
        public readonly int $avisarAcimaDeItens = 20,
        // Ficha técnica INTEIRA para ficar pronto (29/10/2026, decisão do usuário): `publicador.exigir_ficha_inteira`.
        public readonly bool $fichaInteira = true,
    ) {}

    public function multiDeposito(): bool
    {
        return in_array('warehouse_management', $this->tagsDaConta, true);
    }
}

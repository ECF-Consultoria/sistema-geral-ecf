<?php

namespace App\Support\Publicador\Validacao;

use App\Support\Publicador\Imagem\OpcoesImagem;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Payload\MontadorDePlano;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\RegrasDoTitulo;
use App\Support\Publicador\Schema\AtributoClassificado as A;
use App\Support\Publicador\Schema\SchemaClassificado;
use App\Support\Publicador\Schema\ValorAtributo;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\Variante;

/**
 * Validação L1 + L2 (`08`): tudo o que dá para saber sem chamar o ML, com o
 * schema da categoria. Roda a cada salvamento; a L3 (`/attributes/conditional`
 * e `/items/validate`) só roda quando esta não tem bloqueio.
 *
 * Cada problema leva o ID da regra da spec e um `alvo` com a etapa (E2…E10) e
 * o campo — é o que a tela usa para agrupar e "ir para o campo" (`08` §3).
 *
 * Fora daqui por construção, não por checagem: atributo de sistema no payload
 * (V-ATT-07) e família UP inconsistente (V-VAR-19) — o montador não tem como
 * produzi-los. Preço igual entre variações (V-VAR-11) e limite de variações
 * (V-VAR-08) são do legado, que está fora da Fase 1 (D10).
 */
final class ValidadorRascunho
{
    /** A etapa do wizard onde cada seção do formulário vive (`02` §1). */
    public const ETAPA_DA_SECAO = [
        A::SECAO_PRINCIPAIS => 'E3', A::SECAO_CONDICAO => 'E3', A::SECAO_EIXO => 'E4',
        A::SECAO_VARIANTE => 'E5', A::SECAO_FICHA => 'E8', A::SECAO_AVANCADO => 'E8', A::SECAO_EMBALAGEM => 'E10',
    ];

    /** @var list<Problema> */
    private array $p = [];

    public function validar(RascunhoSnapshot $r, SchemaClassificado $s, ContextoValidacao $ctx): ResultadoValidacao
    {
        $this->p = [];

        $this->conta($ctx);
        $this->categoria($s, $ctx);
        $this->condicao($r, $s);
        $this->atributosDoProduto($r, $s, $ctx);
        $this->eixos($r, $s);
        $this->variantes($r, $s);
        $this->imagens($r, $s, $ctx);
        $this->titulos($r, $s, $ctx);
        $this->descricao($r, $s);
        $this->condicoesDeVenda($r, $s, $ctx);

        return new ResultadoValidacao($this->p);
    }

    // ═══ Conta e categoria ══════════════════════════════════════════════════

    private function conta(ContextoValidacao $ctx): void
    {
        if ($ctx->modelo !== MontadorDePlano::UP) {
            $this->p[] = Problema::bloqueio('D10', 'Esta conta do Mercado Livre usa o modelo antigo de variações, que o Publicador ainda não atende. Publique pelo Mercado Livre por enquanto.', ['etapa' => 'E0']);
        }
        // D11 (usuário, 01/10): conta multidepósito não é bloqueada — o estoque vai por depósito.
        if ($ctx->multiDeposito()) {
            $this->p[] = Problema::info('V-VAR-20', 'Nesta conta o estoque é controlado por depósito: informe a quantidade de cada um.', ['etapa' => 'E5']);
        }
    }

    private function categoria(SchemaClassificado $s, ContextoValidacao $ctx): void
    {
        $e2 = ['etapa' => 'E2'];
        if (! $s->flags['folha']) {
            $this->p[] = Problema::bloqueio('V-CAT-01', 'Esta categoria tem subcategorias — escolha a mais específica.', $e2);
        }
        if (! $s->flags['listing_allowed']) {
            $this->p[] = Problema::bloqueio('V-CAT-02', 'O Mercado Livre não aceita anúncios nesta categoria.', $e2);
        }
        if ($ctx->hashSchemaDoRascunho !== null && $ctx->hashSchemaDoRascunho !== $s->schemaHash) {
            $this->p[] = Problema::bloqueio('V-CAT-03', 'A categoria mudou no Mercado Livre desde o rascunho — revise os campos.', $e2);
        }
        foreach ($s->bloqueiosFase2 as $b) {
            $this->p[] = Problema::bloqueio('V-CAT-04', $b['mensagem'], [...$e2, 'motivo' => $b['motivo']]);
        }
    }

    private function condicao(RascunhoSnapshot $r, SchemaClassificado $s): void
    {
        $e3 = ['etapa' => 'E3', 'campo' => 'condicao'];
        $paraOMl = $r->condicao === 'refurbished' ? 'new' : $r->condicao;
        $aceitas = (array) ($s->limites['item_conditions'] ?? []);
        if ($aceitas !== [] && ! in_array($paraOMl, $aceitas, true)) {
            $this->p[] = Problema::bloqueio('V-CND-01', 'Esta categoria não aceita produto nessa condição.', $e3);
        }

        if ($r->condicao !== 'refurbished') {
            return;
        }
        $temRecondicionado = false;
        foreach ($s->atributo('ITEM_CONDITION')?->valores ?? [] as $v) {
            $temRecondicionado = $temRecondicionado || str_contains(ChaveCanonica::texto($v['name']), 'recondicionad');
        }
        if (! $temRecondicionado) {
            $this->p[] = Problema::bloqueio('H-04', 'Esta categoria não aceita produto recondicionado.', $e3);
        }
        if (self::diasDeGarantia($r->garantia) < 90) {
            $this->p[] = Problema::bloqueio('V-CND-02', 'Produto recondicionado exige garantia de pelo menos 90 dias.', ['etapa' => 'E10', 'campo' => 'garantia']);
        }
    }

    // ═══ Atributos ═══════════════════════════════════════════════════════════

    private function atributosDoProduto(RascunhoSnapshot $r, SchemaClassificado $s, ContextoValidacao $ctx): void
    {
        $deEixo = array_flip(array_filter(array_map(fn (Eixo $e) => $e->attributeId(), $r->eixos)));

        foreach ($s->atributos as $id => $a) {
            if (! $a->editavel() || in_array($a->secao, [A::SECAO_OCULTO, A::SECAO_EIXO, A::SECAO_VARIANTE], true)) {
                continue;
            }
            $alvo = ['etapa' => self::ETAPA_DA_SECAO[$a->secao] ?? 'E8', 'atributo' => $id];
            $valor = $r->atributos[$id] ?? null;

            if ($valor !== null && ! ValorAtributo::vazio($valor)) {
                if ($prob = ValorAtributo::problema($a, $valor)) {
                    $this->p[] = Problema::bloqueio($prob['regra'], $prob['mensagem'], $alvo, 'L1');
                }
                if (! empty($valor['revisar'])) {
                    $this->p[] = Problema::aviso('V-ATT-09', "Confira «{$a->nome}»: o valor foi sugerido ou veio de outra categoria.", $alvo);
                }

                continue;
            }

            if ($a->obrigatorio()) {
                $this->p[] = match (true) {
                    $id === 'BRAND' => Problema::bloqueio('V-ATT-12', 'Informe a marca verdadeira do produto, ou «Genérica» se não tiver marca.', $alvo),
                    $a->podeSerEixo => Problema::bloqueio('V-VAR-18', "«{$a->nome}» é obrigatório: escolha como variação ou preencha um valor único.", $alvo),
                    default => Problema::bloqueio('V-ATT-01', "Preencha «{$a->nome}».", $alvo),
                };
            } elseif ($a->obrigatoriedade === A::RECOMMENDED) {
                $this->p[] = Problema::aviso('V-ATT-10', "Preencha «{$a->nome}»: o Mercado Livre usa para dar exposição ao anúncio.", $alvo);
            }
        }

        // Atributo que virou eixo não tem valor no produto (V-VAR-10, RN-51).
        foreach ($r->atributos as $id => $valor) {
            if (isset($deEixo[$id]) && ! ValorAtributo::vazio((array) $valor)) {
                $this->p[] = Problema::bloqueio('V-VAR-10', "«".($s->atributo($id)?->nome ?? $id)."» é variação: tire o valor único do produto.", ['etapa' => 'E4', 'atributo' => $id]);
            }
        }

        $this->plausibilidade($r, $s, $ctx);
    }

    /** V-ATT-11: comparações configuráveis por categoria (o print tinha altura máxima 22 < encosto 23). */
    private function plausibilidade(RascunhoSnapshot $r, SchemaClassificado $s, ContextoValidacao $ctx): void
    {
        foreach ($ctx->plausibilidade as $regra) {
            [$maior, $menor] = [self::medida($r->atributos[$regra['maior']] ?? null), self::medida($r->atributos[$regra['menor']] ?? null)];
            if ($maior === null || $menor === null || $maior['unidade'] !== $menor['unidade'] || $maior['valor'] >= $menor['valor']) {
                continue;
            }
            $nomeMaior = $s->atributo($regra['maior'])?->nome ?? $regra['maior'];
            $nomeMenor = $s->atributo($regra['menor'])?->nome ?? $regra['menor'];
            $this->p[] = Problema::aviso('V-ATT-11', "«{$nomeMaior}» está menor que «{$nomeMenor}». Confira as medidas.", ['etapa' => 'E8', 'atributo' => $regra['maior']]);
        }
    }

    // ═══ Variações ═══════════════════════════════════════════════════════════

    private function eixos(RascunhoSnapshot $r, SchemaClassificado $s): void
    {
        $customizados = 0;
        foreach (Eixo::ordenar($r->eixos) as $e) {
            $alvo = ['etapa' => 'E4', 'eixo' => $e->chave];
            $a = $e->ehCustomizado() ? null : $s->atributo($e->chave);

            if ($e->ehCustomizado() && ++$customizados > 1) {
                $this->p[] = Problema::bloqueio('V-VAR-02', 'O anúncio aceita só uma variação personalizada.', $alvo);
            }
            if (! $e->ehCustomizado() && (! $a || ! $a->podeSerEixo)) {
                $this->p[] = Problema::bloqueio('V-VAR-01', "«{$e->nome}» não pode ser variação nesta categoria.", $alvo);
            }
            if ($e->valores === []) {
                $this->p[] = Problema::bloqueio('V-VAR-05', "A variação «{$e->nome}» está sem valores.", $alvo);
            }

            $vistos = [];
            foreach ($e->valores as $v) {
                $chave = ChaveCanonica::texto($v->valueName);
                if (isset($vistos[$chave]) || isset($vistos[$v->chave()])) {
                    $this->p[] = Problema::bloqueio('V-VAR-03', "«{$v->valueName}» aparece duas vezes em «{$e->nome}».", $alvo);
                }
                $vistos[$chave] = $vistos[$v->chave()] = true;

                if ($v->valueId === ValorAtributo::NAO_SE_APLICA) {
                    $this->p[] = Problema::bloqueio('V-VAR-06', "A variação «{$e->nome}» não aceita «Não se aplica».", $alvo);
                } elseif ($a && ($prob = ValorAtributo::problema($a, ['value_id' => $v->valueId, 'value_name' => $v->valueName])) && $prob['regra'] === 'V-ATT-03') {
                    $this->p[] = Problema::bloqueio('V-VAR-07', "«{$v->valueName}» não está na lista do Mercado Livre para «{$e->nome}».", $alvo);
                }
            }
        }
    }

    private function variantes(RascunhoSnapshot $r, SchemaClassificado $s): void
    {
        $ativas = $r->variantesAtivas();
        if ($ativas === []) {
            $this->p[] = Problema::bloqueio('V-VAR-09', 'Ative ao menos uma variação.', ['etapa' => 'E5']);
        }

        $chaves = [];
        foreach ($r->variantes as $v) {
            if ($v->orfa) {
                $this->p[] = Problema::bloqueio('V-VAR-17', 'A variação '.$v->rotulo($r->eixos).' deixou de existir: descarte ou recupere os dados dela.', ['etapa' => 'E5', 'variante' => $v->chave]);

                continue;
            }
            if (isset($chaves[$v->chave])) {
                $this->p[] = Problema::bloqueio('V-VAR-04', 'A combinação '.$v->rotulo($r->eixos).' aparece duas vezes.', ['etapa' => 'E5', 'variante' => $v->chave]);
            }
            $chaves[$v->chave] = true;
        }

        $eixosComValor = array_map(fn (Eixo $e) => $e->chave, array_filter($r->eixos, fn (Eixo $e) => $e->valores !== []));
        $dadosDaVariante = array_filter($s->atributos, fn (A $a) => $a->papel === A::VARIANT_DATA && $a->editavel() && $a->secao !== A::SECAO_OCULTO);
        $skus = [];
        $gtins = [];

        foreach ($ativas as $v) {
            $rotulo = $v->rotulo($r->eixos);
            $alvo = fn (string $campo, array $mais = []) => ['etapa' => 'E5', 'variante' => $v->chave, 'campo' => $campo, ...$mais];

            foreach ($eixosComValor as $chaveEixo) {
                if (! isset($v->valores[$chaveEixo])) {
                    $this->p[] = Problema::bloqueio('V-VAR-05', "A variação {$rotulo} está sem valor em uma das variações do anúncio.", $alvo('eixos'));
                }
            }

            $this->estoque($v, $rotulo, $alvo('estoque'));

            // SKU: obrigatório e único no rascunho (V-VAR-13) — é a chave da reconciliação.
            $sku = trim((string) ($this->valorDaVariante($v, $r, 'SELLER_SKU')['value_name'] ?? ''));
            $normalizado = mb_strtolower($sku);
            if ($sku === '') {
                $this->p[] = Problema::bloqueio('V-VAR-13', "Informe o SKU de {$rotulo}.", $alvo('sku'));
            } elseif (isset($skus[$normalizado])) {
                $this->p[] = Problema::bloqueio('V-VAR-13', "O SKU «{$sku}» já está em outra variação.", $alvo('sku'));
            }
            $skus[$normalizado] = true;

            foreach ($dadosDaVariante as $id => $a) {
                if ($id === 'SELLER_SKU') {
                    continue;
                }
                $valor = $this->valorDaVariante($v, $r, $id);
                $alvoAttr = $alvo('atributo', ['atributo' => $id]);

                if ($valor !== null && ! ValorAtributo::vazio($valor)) {
                    if ($id === 'GTIN') {
                        $gtin = trim((string) ($valor['value_name'] ?? ''));
                        if (! Gtin::todosValidos($gtin)) {
                            $this->p[] = Problema::bloqueio('V-VAR-14', "Código universal (GTIN) inválido em {$rotulo}: confira os dígitos.", $alvoAttr, 'L1');
                        } elseif (isset($gtins[$gtin])) {
                            $this->p[] = Problema::aviso('V-VAR-15', "{$rotulo} usa o mesmo GTIN de outra variação.", $alvoAttr);
                        }
                        $gtins[$gtin] = true;
                    } elseif ($prob = ValorAtributo::problema($a, $valor)) {
                        $this->p[] = Problema::bloqueio($prob['regra'], $prob['mensagem'], $alvoAttr, 'L1');
                    }

                    continue;
                }

                if ($a->obrigatorio() && ! $this->gtinResolvido($id, $v, $r)) {
                    $this->p[] = Problema::bloqueio('V-ATT-01', $id === 'GTIN'
                        ? "Informe o código universal (GTIN) de {$rotulo}, ou o motivo de não ter."
                        : "Preencha «{$a->nome}» em {$rotulo}.", $alvoAttr);
                }
            }
        }
    }

    /** V-VAR-12: inteiro de 1 a 99.999 em variante ativa. Zero não: desativa-se a variante. */
    private function estoque(Variante $v, string $rotulo, array $alvo): void
    {
        $estoque = $v->dados['estoque'] ?? null;
        $inteiro = is_int($estoque) || (is_string($estoque) && ctype_digit($estoque));

        $mensagem = match (true) {
            ! $inteiro || (int) $estoque < 0 => "Estoque de {$rotulo} precisa ser um número inteiro.",
            (int) $estoque === 0 => "Estoque de {$rotulo} está zerado — se não vai vender essa variação, desative-a.",
            (int) $estoque > 99999 => "Estoque de {$rotulo} passa do limite de 99.999.",
            default => null,
        };
        if ($mensagem !== null) {
            $this->p[] = Problema::bloqueio('V-VAR-12', $mensagem, $alvo, 'L1');
        }
    }

    /** GTIN condicional se resolve com o motivo de não ter, e vice-versa (`03` §7). */
    private function gtinResolvido(string $id, Variante $v, RascunhoSnapshot $r): bool
    {
        $par = ['GTIN' => 'EMPTY_GTIN_REASON', 'EMPTY_GTIN_REASON' => 'GTIN'][$id] ?? null;
        $valor = $par ? $this->valorDaVariante($v, $r, $par) : null;

        return $valor !== null && ! ValorAtributo::vazio($valor);
    }

    /** Dado da variante; num produto sem eixos ele pode ter sido digitado no produto. */
    private function valorDaVariante(Variante $v, RascunhoSnapshot $r, string $id): ?array
    {
        $valor = $v->dados['atributos'][$id] ?? $r->atributos[$id] ?? null;

        return $valor === null ? null : (array) $valor;
    }

    // ═══ Imagens ═════════════════════════════════════════════════════════════

    private function imagens(RascunhoSnapshot $r, SchemaClassificado $s, ContextoValidacao $ctx): void
    {
        $resolucao = ResolvedorGruposImagem::resolver($r->variantes, $r->eixos, $r->imagens, new OpcoesImagem(
            OpcoesImagem::UP, $s->limites['max_pictures_per_item'] ?? null, $s->limites['max_pictures_per_item_var'] ?? null, $r->fotosPorVariante, $r->incluirGeral,
        ));
        foreach ($resolucao->problemas as $p) {
            $this->p[] = new Problema($p->regra, $p->severidade, $p->mensagem, $p->camada, ['etapa' => 'E6', ...$p->alvo]);
        }

        foreach (array_unique(array_column($r->imagens, 'imagem')) as $id) {
            $meta = $ctx->imagens[$id] ?? null;
            if ($meta === null) {
                continue;
            }
            array_push($this->p, ...ValidadorImagem::problemas((string) $id, $meta, $ctx));
            if ($ctx->paraPublicar && ($meta['upload_status'] ?? null) !== 'uploaded') {
                $this->p[] = Problema::bloqueio('V-IMG-08', 'Uma foto ainda não subiu para o Mercado Livre — aguarde ou envie de novo.', ['etapa' => 'E6', 'imagem' => (string) $id]);
            }
        }
    }

    // ═══ Título e descrição ══════════════════════════════════════════════════

    private function titulos(RascunhoSnapshot $r, SchemaClassificado $s, ContextoValidacao $ctx): void
    {
        $alvos = $r->alvosAtivos();
        if ($alvos === []) {
            $this->p[] = Problema::bloqueio('D1', 'Não há o que publicar: escolha Clássico, Premium ou os dois.', ['etapa' => 'E10']);

            return;
        }

        $limite = $s->limites['max_title_length'] ?? null;
        if ($ctx->limiteFamilyName !== null) {
            $limite = $limite === null ? $ctx->limiteFamilyName : min($limite, $ctx->limiteFamilyName);
        }

        $vistos = [];
        foreach ($alvos as $alvo) {
            $onde = ['etapa' => 'E7', 'alvo' => $alvo->listingTypeId];
            $titulo = trim((string) $alvo->titulo);
            $nome = self::nomeDoTipo($alvo);

            if ($titulo === '') {
                $this->p[] = Problema::bloqueio('V-TIT-01', "Escreva o título do {$nome}.", $onde, 'L1');

                continue;
            }
            if ($limite !== null && mb_strlen($titulo) > $limite) {
                $this->p[] = Problema::bloqueio('V-TIT-01', "O título do {$nome} tem ".mb_strlen($titulo)." caracteres; o limite desta categoria é {$limite}.", $onde, 'L1');
            }
            if ($termo = $this->termoProibido($titulo, $ctx)) {
                $this->p[] = Problema::aviso('V-TIT-02', "O título do {$nome} tem «{$termo}». O Mercado Livre não permite contato, frete, parcelamento ou condição no título.", $onde, 'L1');
            }

            // V-TIT-04 (10/10/2026, decisão do usuário; era o D1 "mesmo SKU, títulos diferentes"): o ML
            // barra dois anúncios com o mesmo nome. "Igual" é o critério do preparo pela IA
            // (`RegrasDoTitulo::mesmo`): mesmas palavras na mesma ordem, sem caixa, acento nem plural
            // simples — "Puffs Redondos" é igual a "puff redondo"; a ordem trocada já é outro título.
            foreach ($vistos as $outroTipo => $outroTitulo) {
                if (RegrasDoTitulo::mesmo($titulo, $outroTitulo)) {
                    $this->p[] = Problema::bloqueio('V-TIT-04', "O título do {$nome} é igual ao do {$outroTipo}: o Mercado Livre não aceita dois anúncios com o mesmo título. Mude ao menos uma palavra (ou a ordem delas) em um dos dois.", $onde);

                    break;
                }
            }
            $vistos[$nome] = $titulo;
        }
    }

    private function termoProibido(string $titulo, ContextoValidacao $ctx): ?string
    {
        $texto = ChaveCanonica::texto($titulo);
        foreach ($ctx->termosProibidosTitulo as $termo) {
            if (preg_match('/(^|\W)'.preg_quote(ChaveCanonica::texto($termo), '/').'($|\W)/u', $texto)) {
                return $termo;
            }
        }

        return match (true) {
            (bool) preg_match('/\(?\d{2}\)?\s?9?\d{4}[-\s]?\d{4}/', $titulo) => 'telefone',
            (bool) preg_match('/[\w.+-]+@[\w-]+\.\w+/u', $titulo) => 'e-mail',
            (bool) preg_match('/(https?:\/\/|www\.)/i', $titulo) => 'link',
            default => null,
        };
    }

    private function descricao(RascunhoSnapshot $r, SchemaClassificado $s): void
    {
        if ($r->descricao === null || trim($r->descricao) === '') {
            return;
        }
        $onde = ['etapa' => 'E9', 'campo' => 'descricao'];
        $limite = $s->limites['max_description_length'] ?? null;
        $texto = strip_tags($r->descricao);

        if ($limite !== null && mb_strlen($texto) > $limite) {
            $this->p[] = Problema::bloqueio('V-DES-01', "A descrição tem ".mb_strlen($texto)." caracteres; o limite é {$limite}.", $onde, 'L1');
        }
        if ($texto !== $r->descricao) {
            $this->p[] = Problema::aviso('V-DES-01', 'A descrição tem formatação (HTML), que o Mercado Livre não aceita: vai como texto simples.', $onde, 'L1');
        }
    }

    // ═══ Condições de venda ══════════════════════════════════════════════════

    private function condicoesDeVenda(RascunhoSnapshot $r, SchemaClassificado $s, ContextoValidacao $ctx): void
    {
        $minimo = $s->limites['minimum_price'] ?? null;
        $maximo = $s->limites['maximum_price'] ?? null;

        foreach ($r->alvosAtivos() as $alvo) {
            foreach ($r->variantesAtivas() as $v) {
                $onde = ['etapa' => 'E10', 'alvo' => $alvo->listingTypeId, 'variante' => $v->chave, 'campo' => 'preco'];
                $preco = $v->dados['precos'][$alvo->listingTypeId] ?? null;
                $rotulo = self::nomeDoTipo($alvo).($v->valores === [] ? '' : ' de '.$v->rotulo($r->eixos));

                if (! is_numeric($preco) || (float) $preco <= 0 || round((float) $preco, 2) != (float) $preco) {
                    $this->p[] = Problema::bloqueio('V-SAL-02', "Informe o preço do {$rotulo} (maior que zero, com até 2 casas).", $onde, 'L1');

                    continue;
                }
                if (($minimo !== null && $preco < $minimo) || ($maximo !== null && $preco > $maximo)) {
                    $this->p[] = Problema::bloqueio('V-SAL-03', "O preço do {$rotulo} está fora da faixa desta categoria (mínimo R$ ".number_format((float) $minimo, 2, ',', '.').').', $onde);
                }
                // V-SAL-08 (10/10/2026, decisão do usuário): o preço que VEIO do Portal (não digitado)
                // calculado sem frete — a Precificação conta frete zero e só marca — não vai ao ML calado.
                // O digitado é decisão da equipe e passa. Vale para a conferência e para publicar, porque
                // as duas validam pelo `comEfetivosDe` (é ele que grava as marcas lidas aqui).
                if (! empty($v->dados['preco_do_portal'][$alvo->listingTypeId]) && ! empty($v->dados['portal'][$alvo->listingTypeId]['sem_frete'])) {
                    $this->p[] = Problema::bloqueio('V-SAL-08', "O preço do {$rotulo} veio da Precificação do Portal calculado sem frete. Informe ou aceite o frete na Precificação do Portal, ou digite o preço aqui.", $onde);
                }
            }
        }

        $modo = (string) ($r->envio['modo'] ?? 'me2');
        if ($ctx->modosEnvio !== null && ! in_array($modo, $ctx->modosEnvio, true)) {
            $this->p[] = Problema::bloqueio('V-SAL-04', 'Sua conta do Mercado Livre não tem essa forma de envio.', ['etapa' => 'E10', 'campo' => 'envio']);
        }

        $this->garantia($r, $s);

        $itens = count($r->alvosAtivos()) * count($r->variantesAtivas());
        if ($itens > $ctx->avisarAcimaDeItens) {
            $this->p[] = Problema::info('L3-VOLUME', "Serão {$itens} anúncios: a conferência com o Mercado Livre pode levar alguns minutos.", ['etapa' => 'E11']);
        }
    }

    /** V-SAL-05: tipo da lista da categoria; tempo com unidade, menos em "sem garantia" (H-09). */
    private function garantia(RascunhoSnapshot $r, SchemaClassificado $s): void
    {
        $tipos = $s->garantia['tipos'] ?? [];
        if ($tipos === []) {
            return;
        }
        $onde = ['etapa' => 'E10', 'campo' => 'garantia'];
        $g = $r->garantia ?? [];
        $tipo = null;
        foreach ($tipos as $t) {
            if ($t['id'] === (string) ($g['tipo'] ?? '')) {
                $tipo = $t;
            }
        }

        if ($tipo === null) {
            $this->p[] = Problema::bloqueio('V-SAL-05', 'Escolha a garantia.', $onde);

            return;
        }
        if (str_contains(ChaveCanonica::texto($tipo['name']), 'sem garantia')) {
            return;
        }
        $unidades = $s->garantia['unidades'] ?? [];
        if (empty($g['tempo']) || (int) $g['tempo'] <= 0 || ($unidades !== [] && ! in_array((string) ($g['unidade'] ?? ''), $unidades, true))) {
            $this->p[] = Problema::bloqueio('V-SAL-05', 'Informe o tempo da garantia (dias, meses ou anos).', $onde);
        }
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private static function diasDeGarantia(?array $g): int
    {
        $tempo = (int) ($g['tempo'] ?? 0);

        return match ((string) ($g['unidade'] ?? 'dias')) {
            'meses' => $tempo * 30,
            'anos' => $tempo * 365,
            default => $tempo,
        };
    }

    /** "23 cm" → {valor: 23, unidade: cm}; nulo se não for medida. */
    private static function medida(?array $valor): ?array
    {
        if ($valor === null) {
            return null;
        }
        if (isset($valor['value_number']) && is_numeric($valor['value_number'])) {
            return ['valor' => (float) $valor['value_number'], 'unidade' => (string) ($valor['value_unit'] ?? '')];
        }
        if (preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*(\S*)\s*$/u', (string) ($valor['value_name'] ?? ''), $m)) {
            return ['valor' => (float) str_replace(',', '.', $m[1]), 'unidade' => $m[2]];
        }

        return null;
    }

    private static function nomeDoTipo(Alvo $alvo): string
    {
        return ['gold_special' => 'Clássico', 'gold_pro' => 'Premium'][$alvo->listingTypeId] ?? $alvo->listingTypeId;
    }
}

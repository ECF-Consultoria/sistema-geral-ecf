<?php

namespace App\Services\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\Acoes\AcaoAlavanca;
use App\Services\Publicador\Alavancas\Acoes\AlterarCampanha;
use App\Services\Publicador\Alavancas\Acoes\AlterarCupom;
use App\Services\Publicador\Alavancas\Acoes\AlterarNoConvite;
use App\Services\Publicador\Alavancas\Acoes\CriarCampanha;
use App\Services\Publicador\Alavancas\Acoes\CriarCupom;
use App\Services\Publicador\Alavancas\Acoes\CriarDescontoIndividual;
use App\Services\Publicador\Alavancas\Acoes\ExcluirCampanha;
use App\Services\Publicador\Alavancas\Acoes\ExcluirCupom;
use App\Services\Publicador\Alavancas\Acoes\GravarExclusaoDaConta;
use App\Services\Publicador\Alavancas\Acoes\GravarExclusaoDoItem;
use App\Services\Publicador\Alavancas\Acoes\GravarFaixasAtacado;
use App\Services\Publicador\Alavancas\Acoes\InscreverNoConvite;
use App\Services\Publicador\Alavancas\Acoes\RemoverDeTodas;
use App\Services\Publicador\Alavancas\Acoes\RemoverDescontoIndividual;
use App\Services\Publicador\Alavancas\Acoes\RemoverDoConvite;
use App\Support\Publicador\RegraViolada;

/**
 * A lista fechada das ações de escrita das Alavancas; o job de lote reconstrói a ação
 * pela coluna `acao` do histórico. Ação fora daqui nunca chega ao EscritorAlavancas.
 */
final class RegistroDeAcoes
{
    /** @var array<string, class-string<AcaoAlavanca>> */
    public const NOMES = [
        'convite.inscrever' => InscreverNoConvite::class,
        'convite.alterar' => AlterarNoConvite::class,
        'convite.remover' => RemoverDoConvite::class,
        'convite.remover_todas' => RemoverDeTodas::class,
        'desconto.criar' => CriarDescontoIndividual::class,
        'desconto.remover' => RemoverDescontoIndividual::class,
        'campanha.criar' => CriarCampanha::class,
        'campanha.alterar' => AlterarCampanha::class,
        'campanha.excluir' => ExcluirCampanha::class,
        'exclusao.conta' => GravarExclusaoDaConta::class,
        'exclusao.item' => GravarExclusaoDoItem::class,
        'cupom.criar' => CriarCupom::class,
        'cupom.alterar' => AlterarCupom::class,
        'cupom.excluir' => ExcluirCupom::class,
        'atacado.gravar' => GravarFaixasAtacado::class,
    ];

    /** @return class-string<AcaoAlavanca> */
    public static function classe(string $nome): string
    {
        return self::NOMES[$nome] ?? throw new RegraViolada('ALAV-ACAO', 'Ação desconhecida.');
    }
}

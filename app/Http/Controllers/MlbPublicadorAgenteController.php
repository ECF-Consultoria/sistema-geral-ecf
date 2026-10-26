<?php

namespace App\Http\Controllers;

use App\Models\PubAgenteExecucao;
use App\Models\PubProduto;
use App\Services\Publicador\Agente\AtividadeDoAgente;
use App\Services\Publicador\Agente\ConfiguracaoDoAgenteService;
use App\Services\Publicador\Agente\CopiaDeConfiguracaoDoAgente;
use App\Services\Publicador\Agente\ControleDoAgente;
use App\Services\Publicador\Agente\EntradaDoPreparo;
use App\Services\Publicador\Agente\LeituraDoAgente;
use App\Services\Publicador\CabecalhoPublicadorService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\RegraViolada;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A tela do Agente ECF Publicador de UMA loja (AG-07, 20/10/2026): Rotina e fila, Configuração essencial e
 * Atividade. Fica no grupo `auth`+`verified`+`role:admin` das rotas do Publicador.
 *
 * A conta vem SEMPRE do `{conta}` resolvido no servidor (`ProgramasPublicadorService::resolver`); produto de outra
 * loja é recusado pela política do agente antes de qualquer escrita. Nada aqui chama o ML ou a IA no request:
 * "Preparar agora" só ADMITE a execução — a IA roda no Job de sempre.
 *
 * A aprovação não mora aqui: os links levam à Aprovação que já existe (`meus.aprovacao?oferta=`).
 */
class MlbPublicadorAgenteController extends Controller
{
    public function __construct(private ProgramasPublicadorService $programas) {}

    public function mostrar(
        string $conta,
        Request $request,
        LeituraDoAgente $leitura,
        AtividadeDoAgente $atividade,
        CopiaDeConfiguracaoDoAgente $copia,
        CabecalhoPublicadorService $cabecalhos,
    ): Response|RedirectResponse {
        $alvo = $this->conta($conta);
        if ($alvo['chave'] !== $conta) {
            return redirect()->route('mlb.anuncios.publicador.agente', ['conta' => $alvo['chave']]);
        }
        $empresa = $this->programas->empresaParaTela($alvo);

        return Inertia::render('Mlb/Publicador/Agente', [
            'empresa' => $empresa,
            // ⚠️⚠️ **AS TRÊS PROPS PESADAS SÃO CLOSURES, e isso é o "carregamento por aba" da E1.**
            // O Inertia **não avalia** closure de prop que a recarga parcial não pediu, então
            // paginar a Atividade (`only: ['atividade']`) deixa de reconsultar configuração,
            // contadores, fila e os até 500 produtos do seletor. Valor direto seria recalculado
            // em TODA paginação — a tela ficaria lenta justamente no gesto mais repetido.
            'agente' => fn () => [
                ...$leitura->paraConta($alvo),
                // ⚠️⚠️ **COPIAR DE OUTRA LOJA É RASCUNHO, e por isso chega como PROP, não como
                // escrita.** `?copiar_de=` só faz o servidor LER a outra loja e devolver os valores
                // compatíveis; **nada é gravado**. Quem salva é a pessoa, com a `versao_esperada`
                // que ela viu — logo a cópia herda a trava de concorrência de sempre.
                //
                // ⚠️ **Por que na URL e não num POST:** assim recarregar, voltar no navegador ou
                // compartilhar o link mostra **o mesmo rascunho**, e **nenhum** desses gestos muda
                // loja nenhuma. Um POST que devolvesse o rascunho seria uma escrita aparente para
                // uma operação que não escreve.
                'copia' => $copia->rascunho($alvo, $request->query('copiar_de')),
                'origens_de_copia' => $copia->origensPossiveis($alvo),
            ],
            // Conta travada para publicar: o header mostra o selo, como nas outras telas da conta. O agente segue
            // preparando para a Aprovação; quem barra o envio é o `ContasLiberadas` na publicação.
            'liberada' => ContasLiberadas::libera(PubProduto::ancoraComToken($alvo['mlb_empresa'], $alvo['company'])),
            'produtos' => fn () => $this->programas->produtosQuery($alvo['mlb_empresa'], $alvo['company'])
                ->whereNull('produto_base_id')->orderBy('nome')->limit(500)->get(['id', 'nome', 'sku'])
                ->map(fn (PubProduto $p) => ['id' => $p->id, 'nome' => $p->nome, 'sku' => $p->sku])->values(),
            // O Registro de atividade (E1, 23/10/2026) — PROJEÇÃO das três tabelas que o agente e o
            // portão já escrevem, sem tabela nova (§5.5 do plano: *"dados novos apenas onde há
            // lacuna"*). Os filtros chegam pela URL, e filtro forjado é **descartado em silêncio**
            // no serviço — a mesma regra dos filtros da fila de Aprovação (187-06).
            'atividade' => fn () => $atividade->paraConta($alvo, [
                'de' => $request->query('de'),
                'ate' => $request->query('ate'),
                'fonte' => $request->query('fonte'),
                'origem' => $request->query('origem'),
                'resultado' => $request->query('resultado'),
                'produto_id' => $request->query('produto_id'),
            ], (int) $request->query('pagina', 1)),
            // `CabecalhoPublicadorService::ABAS` é lista FECHADA de cinco: o agente não é uma delas. `configuracoes`
            // é o valor que a casa usa para tela fora das quatro abas (nenhuma fica ativa) — mesmo papel que em
            // Configurações da conta.
            'cabecalho' => $cabecalhos->para($alvo, $empresa, 'configuracoes'),
        ]);
    }

    public function salvarConfiguracao(string $conta, Request $request, ConfiguracaoDoAgenteService $config): RedirectResponse
    {
        $alvo = $this->conta($conta);
        $dados = $request->validate([
            'versao_esperada' => ['required', 'integer', 'min:0'],
            'habilitado' => ['required', 'boolean'],
            'modalidade' => ['nullable', 'in:'.implode(',', ConfiguracaoDoAgenteService::MODALIDADES)],
            'estoque_minimo' => ['required', 'integer', 'min:0', 'max:100000'],
            'limite_diario' => ['required', 'integer', 'min:0', 'max:1000'],
            'teto_fila' => ['required', 'integer', 'min:0', 'max:1000'],
            'janela_inicio' => ['nullable', 'date_format:H:i'],
            'janela_fim' => ['nullable', 'date_format:H:i'],
            // ⚠️ O calendário (E1, 23/10/2026): ISO, 1 = segunda … 7 = domingo. Lista vazia é válida e
            // significa "todo dia" — o serviço a normaliza para `null`.
            // ⚠️ O ritmo (26/10/2026): nulo = padrão; 0 = sem espera / não repetir.
            'cooldown_minutos' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'tentativas_max' => ['nullable', 'integer', 'min:0', 'max:5'],
            'dias_semana' => ['nullable', 'array'],
            'dias_semana.*' => ['integer', 'between:1,7'],
        ]);

        return $this->executarRegra(function () use ($config, $alvo, $dados, $request) {
            $versao = (int) $dados['versao_esperada'];
            unset($dados['versao_esperada']);
            $config->salvar($alvo, $dados, $versao, $request->user());

            return 'Configuração do agente salva.';
        });
    }

    public function executar(string $conta, Request $request, EntradaDoPreparo $entrada): RedirectResponse
    {
        $alvo = $this->conta($conta);
        $dados = $request->validate(['produto_id' => ['required', 'integer']]);
        $produto = PubProduto::find($dados['produto_id']);
        abort_if($produto === null, 404);

        return $this->executarRegra(function () use ($entrada, $alvo, $produto, $request) {
            $r = $entrada->pedir($alvo, $produto, PubAgenteExecucao::ORIGEM_MANUAL, $request->user());
            $e = $r['execucao'];

            return match (true) {
                ! $r['nova'] => "\"{$produto->nome}\" já está com o agente ({$this->estadoLegivel($e->estado)}).",
                $e->estado === PubAgenteExecucao::BLOQUEADA => "\"{$produto->nome}\" não pôde ser preparado: faltam dados (veja a fila).",
                default => "Preparo de \"{$produto->nome}\" iniciado.",
            };
        });
    }

    public function pausar(string $conta, Request $request, ControleDoAgente $controle): RedirectResponse
    {
        $alvo = $this->conta($conta);
        $motivo = $request->validate(['motivo' => ['nullable', 'string', 'max:500']])['motivo'] ?? null;

        return $this->executarRegra(function () use ($controle, $alvo, $request, $motivo) {
            $controle->pausar($alvo, $request->user(), $motivo);

            return 'Agente pausado nesta loja. Anúncios no ar e ofertas na Aprovação não mudam.';
        });
    }

    public function suspender(string $conta, Request $request, ControleDoAgente $controle): RedirectResponse
    {
        $alvo = $this->conta($conta);
        $motivo = $request->validate(['motivo' => ['nullable', 'string', 'max:500']])['motivo'] ?? null;

        return $this->executarRegra(function () use ($controle, $alvo, $request, $motivo) {
            $controle->suspender($alvo, $request->user(), $motivo);

            return 'Agente suspenso nesta loja. Nenhuma etapa nova começa; anúncios no ar não mudam.';
        });
    }

    public function retomar(string $conta, Request $request, ControleDoAgente $controle): RedirectResponse
    {
        $alvo = $this->conta($conta);

        return $this->executarRegra(function () use ($controle, $alvo, $request) {
            $r = $controle->retomar($alvo, $request->user());

            return "Agente retomado. {$r['continuadas']} preparo(s) em andamento continuaram de onde pararam.";
        });
    }

    /** A conta do `{conta}`, resolvida no servidor; arquivada/sem programa = 404. */
    private function conta(string $conta): array
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        return $alvo;
    }

    /** Regra do agente recusou → volta com a mensagem dela; senão volta com o sucesso. */
    private function executarRegra(callable $acao): RedirectResponse
    {
        try {
            return back()->with('success', $acao());
        } catch (RegraViolada $e) {
            return back()->with('error', $e->getMessage())->withErrors(['agente' => $e->getMessage()]);
        }
    }

    private function estadoLegivel(string $estado): string
    {
        return match ($estado) {
            PubAgenteExecucao::ADMITIDA, PubAgenteExecucao::PREPARANDO => 'preparando',
            PubAgenteExecucao::AGUARDANDO_APROVACAO => 'aguardando aprovação',
            PubAgenteExecucao::CONCLUIDA => 'concluído',
            default => $estado,
        };
    }
}

<?php

namespace Tests\Feature\Publicador\Concerns;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubTarefa;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Permissions;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Publicações já concluídas, gravadas direto no banco (sem passar pelo ML), para os testes das
 * tarefas pós-publicação que não são do gatilho em si: fila, baixa automática, contagens, retroativo.
 * O gatilho de verdade (publicar → tarefa) é provado pelo `TarefasPosPublicacaoTest`, com o ML simulado.
 */
trait CenarioTarefas
{
    protected function produtoDa(Company|MlbEmpresa $ancora, string $nome = 'Puff Redondo'): PubProduto
    {
        return PubProduto::create([
            'company_id' => $ancora instanceof Company ? $ancora->id : null,
            'mlb_empresa_id' => $ancora instanceof MlbEmpresa ? $ancora->id : null,
            'sku' => 'SKU-'.Str::upper(Str::random(6)),
            'nome' => $nome,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    /**
     * @param  array<string, string>  $mlbs  MLB → listing_type_id dos itens CRIADOS
     */
    protected function publicacaoConcluida(
        Company|MlbEmpresa $ancora,
        array $mlbs = ['MLB1001' => 'gold_special', 'MLB1002' => 'gold_pro'],
        string $status = PubPublicacao::PUBLISHED,
        ?User $ator = null,
        ?Carbon $concluidaEm = null,
        ?PubProduto $produto = null,
    ): PubPublicacao {
        $produto ??= $this->produtoDa($ancora);
        $r = $produto->rascunho()->first() ?? (new RascunhoRepository())->criar($produto, [new Alvo('gold_special', $produto->nome)]);

        $p = PubPublicacao::create([
            'rascunho_id' => $r->id,
            'revisao' => 1,
            'modelo_publicacao' => 'user_products',
            'plano_hash' => str_repeat('a', 64),
            'status' => $status,
            'chave_idempotencia' => (string) Str::uuid(),
            'iniciada_em' => $concluidaEm ?? now(),
            'concluida_em' => $concluidaEm ?? now(),
            'ator' => ['equipe' => true, 'id' => $ator?->id, 'nome' => $ator?->name ?? 'Vitória',
                'conta' => ['chave' => $ancora->chaveContaMl(), 'seller' => '1555596317']],
        ]);

        $indice = 0;
        foreach ($mlbs as $mlb => $tipo) {
            PubPublicacaoItem::create([
                'publicacao_id' => $p->id,
                'indice' => $indice++,
                'listing_type_id' => $tipo,
                'variante_chave' => '__single__',
                'payload' => ['family_name' => $produto->nome],
                'status' => PubPublicacaoItem::CREATED,
                'ml_item_id' => $mlb,
                'avisos' => ['estado' => ['status' => 'active', 'permalink' => "https://produto.mercadolivre.com.br/{$mlb}-teste-_JM"]],
                'criado_em' => now(),
            ]);
        }

        return $p;
    }

    /** Uma tarefa aberta da conta, nascida pelo caminho real (`abrir`), sem aviso no sino. */
    protected function tarefaDa(Company|MlbEmpresa $ancora, array $mlbs = ['MLB1001' => 'gold_special', 'MLB1002' => 'gold_pro'], ?User $ator = null): PubTarefa
    {
        return app(\App\Services\Publicador\Tarefas\TarefasPosPublicacao::class)
            ->abrir($this->publicacaoConcluida($ancora, $mlbs, ator: $ator), avisar: false);
    }

    /** Usuário NÃO admin num setor com a chave dada (o caminho do "Caio"). */
    protected function comPermissao(string $chave = Permissions::MLB_ALAVANCAS, string $nome = 'Caio Alavancas'): User
    {
        $sufixo = Str::lower(Str::random(8));
        $setor = Setor::create(['nome' => "Setor {$chave} {$sufixo}", 'slug' => "setor-{$sufixo}", 'active' => true]);
        SetorPermissao::create(['setor_id' => $setor->id, 'permission_key' => $chave]);
        $u = User::factory()->create(['role' => 'consultor', 'name' => $nome]);
        $setor->membros()->attach($u->id, ['is_principal' => true, 'assigned_at' => now()]);

        return $u;
    }
}

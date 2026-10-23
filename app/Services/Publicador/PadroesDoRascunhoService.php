<?php

namespace App\Services\Publicador;

use App\Models\PubPublicacao;
use App\Models\PubRascunho;
use App\Support\Publicador\PadroesDoEditor;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Grava no rascunho os padrões que o editor aplicava sozinho ao abrir (N9, 22/10/2026 — ver `PadroesDoEditor`).
 *
 * Roda ANTES da 1ª conferência — no Sincronizar do Portal (botão e logo após o save), no preparo pela IA e no
 * início da conferência (a do editor, `ConferirRascunhoJob`, e a em lote) como rede de segurança. Por ser antes de
 * conferir, subir a `revisao` aqui é o certo: a conferência que vem depois vê o payload com os padrões. Nada muda
 * quando nada falta (rodar de novo não sobe a revisão), e nada sobrescreve escolha de ninguém.
 *
 * O frete grátis obrigatório é a MESMA regra do editor (`EditorRascunhoService::freteGratis`, que pergunta ao ML):
 * só com `comFrete` — quem chama decide se pode ir ao ML naquela hora (o Sincronizar do Portal não vai, D-10).
 *
 * Não marca o `EditorEmUso`: nada aqui é rota do editor (o "editando" é só de quem grava pela tela).
 */
class PadroesDoRascunhoService
{
    public function __construct(
        private RascunhoRepository $repo,
        private EditorRascunhoService $editor,
    ) {}

    /**
     * @param  bool  $comFrete  pergunta ao ML se a faixa de preço exige frete grátis (e liga)
     * @param  ?callable(): float  $sorteio  para o teste (o EAN gerado)
     * @return list<string> o que foi aplicado (vazio = nada faltava ou o rascunho não aceita escrita)
     */
    public function aplicar(PubRascunho $r, bool $comFrete = false, ?callable $sorteio = null): array
    {
        $r = $r->fresh();
        if ($r === null || ! self::aceitaPadroes($r)) {
            return [];
        }

        // O schema é lido FORA da trava (pode ir ao ML quando o guardado venceu); dentro dela, só o banco.
        [$schema] = $r->categoria_id ? $this->editor->schemaDe($r, $this->repo->snapshot($r)) : [null];

        $aplicados = DB::transaction(function () use ($r, $schema, $sorteio) {
            // A mesma trava do editor, da IA e da publicação: relê o que está gravado AGORA.
            $this->repo->travar($r);
            $r->refresh();
            if (! self::aceitaPadroes($r)) {
                return [];
            }
            $s = $this->repo->snapshot($r);
            $modos = $r->step_state['conta']['modosEnvio'] ?? null;
            $f = PadroesDoEditor::faltantes($s, $schema, is_array($modos) ? array_values($modos) : null, $sorteio);
            if ($f['aplicados'] === []) {
                return [];
            }

            if ($f['variantes'] !== null) {
                $this->repo->gravarVariacao($r, $s->eixos, $f['variantes']);
            }
            $campos = array_filter([
                'fotos_por_variante' => $f['fotos_por_variante'],
                'envio' => $f['envio'],
            ], fn ($x) => $x !== null);
            if ($campos !== []) {
                $r->update($campos);
            }
            // Antes da conferência: a revisão sobe e a conferência que vem a seguir confere o payload com os padrões.
            $this->repo->tocar($r);

            return $f['aplicados'];
        });

        if ($comFrete && $this->garantirFreteObrigatorio($r->fresh())) {
            $aplicados[] = 'envio.frete_gratis';
        }
        if ($aplicados !== []) {
            Log::info("[Publicador] Padrões do editor gravados no rascunho {$r->id} (antes da conferência): ".implode(', ', $aplicados).'.');
        }

        return $aplicados;
    }

    /**
     * A regra do editor antes de conferir (learnings §11): Mercado Envios, frete grátis desmarcado, conta lida e
     * categoria escolhida → pergunta ao ML se a faixa de preço exige frete grátis do vendedor e, se exigir, liga.
     * Sem resposta do ML, segue como está (a tela ainda pergunta e marca).
     */
    public function garantirFreteObrigatorio(PubRascunho $r): bool
    {
        $envio = (array) ($r->envio ?? []);
        $conta = (array) ($r->step_state['conta'] ?? []);
        if (! self::aceitaPadroes($r) || ($envio['modo'] ?? 'me2') !== 'me2' || ! empty($envio['frete_gratis'])
            || $conta === [] || isset($conta['erro']) || ! $r->categoria_id) {
            return false;
        }
        // Conta fora da lista não tem chamada ao ML (23/10/2026, P-17, D26): o "Conferir" dela é local, e esta consulta
        // do frete saía antes com o token do cliente.
        if (! \App\Support\Publicador\ContasLiberadas::libera($r->produto?->contaOuNula())) {
            return false;
        }
        try {
            $regra = $this->editor->freteGratis($r);
        } catch (RegraViolada $e) {
            Log::info("[Publicador] Frete grátis do rascunho {$r->id} sem consulta ({$e->regra}).");

            return false;
        }
        if (! ($regra['obrigatorio'] ?? false)) {
            return false;
        }

        return DB::transaction(function () use ($r) {
            $this->repo->travar($r);
            $r->refresh();
            $envio = (array) ($r->envio ?? []);
            // Alguém trocou a forma de envio ou já marcou enquanto o ML respondia: nada a fazer.
            if (($envio['modo'] ?? 'me2') !== 'me2' || ! empty($envio['frete_gratis'])) {
                return false;
            }
            $r->update(['envio' => [...$envio, 'frete_gratis' => true]]);
            $this->repo->tocar($r);

            return true;
        });
    }

    /** O mesmo "só leitura" da tela (`usePublicador`, `disabled`): publicado, ou publicação em andamento. */
    public static function aceitaPadroes(PubRascunho $r): bool
    {
        return ! in_array($r->status, [PubRascunho::PUBLISHED, PubRascunho::PUBLISHING], true)
            && ! $r->publicacoes()->where('status', PubPublicacao::RUNNING)->exists();
    }
}

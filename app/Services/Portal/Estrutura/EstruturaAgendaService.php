<?php

namespace App\Services\Portal\Estrutura;

use App\Models\Company;
use App\Models\EstruturaAgendaItem;
use App\Models\EstruturaOferta;
use App\Support\Portal\AtorDoPortal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A agenda — a aba "Planejamento" da planilha, sem os três blocos
 * (unitários/combos, kits, combits), que só existiam para caber SKU1..3 + QTD
 * na grade: fase e composição já estão na oferta.
 *
 * ### O ritmo da aula
 * "1 publicação por dia até zerar a lista. 7 dias depois, agende a
 * Jardinagem." A proposta {@see self::proposta()} aplica a primeira metade em
 * DIAS CORRIDOS — o exemplo da planilha agenda sábado 26/09 e domingo 27/09. A
 * segunda metade é sugerida pela tela ao concluir a publicação, com a data já
 * preenchida (`DIAS_ATE_JARDINAGEM`).
 *
 * ### Quando uma linha está feita
 * Publicação: quando a oferta tem Clássico e Premium que contam — derivado,
 * sem coluna (ver `EstruturaAgendaItem`). Jardinagem: `concluida_em`.
 */
class EstruturaAgendaService
{
    public function agendar(EstruturaOferta $oferta, string $data, string $acao, AtorDoPortal $ator): EstruturaAgendaItem
    {
        if (! array_key_exists($acao, EstruturaAgendaItem::ACOES)) {
            throw ValidationException::withMessages(['acao' => 'Escolha Publicação ou Jardinagem.']);
        }

        $item = $oferta->agenda()->create(['data' => $this->data($data), 'acao' => $acao]);

        RegistroEstrutura::registrar($ator, $oferta->company, $item, 'agenda_criada',
            EstruturaAgendaItem::ACOES[$acao]." de {$oferta->sku} agendada para {$item->data->format('d/m/Y')}");

        return $item;
    }

    public function remarcar(EstruturaAgendaItem $item, string $data, AtorDoPortal $ator): void
    {
        $antes = $item->data->format('d/m/Y');
        $item->update(['data' => $this->data($data)]);

        RegistroEstrutura::registrar($ator, $item->oferta->company, $item, 'agenda_remarcada',
            EstruturaAgendaItem::ACOES[$item->acao]." de {$item->oferta->sku} remarcada de {$antes} para {$item->data->format('d/m/Y')}");
    }

    public function excluir(EstruturaAgendaItem $item, AtorDoPortal $ator): void
    {
        RegistroEstrutura::registrar($ator, $item->oferta->company, $item->oferta, 'agenda_excluida',
            EstruturaAgendaItem::ACOES[$item->acao]." de {$item->oferta->sku} ({$item->data->format('d/m/Y')}) excluída");

        $item->delete();
    }

    /** Jardinagem feita / desfeita. Publicação não passa por aqui: ela é derivada. */
    public function marcarJardinagem(EstruturaAgendaItem $item, bool $feita, AtorDoPortal $ator): void
    {
        if ($item->acao !== EstruturaAgendaItem::ACAO_JARDINAGEM) {
            throw ValidationException::withMessages([
                'acao' => 'A publicação fica feita quando a oferta tem os anúncios Clássico e Premium — conclua cadastrando cada um.',
            ]);
        }

        $item->update(['concluida_em' => $feita ? now() : null]);

        RegistroEstrutura::registrar($ator, $item->oferta->company, $item, $feita ? 'jardinagem_feita' : 'jardinagem_desfeita',
            "Jardinagem de {$item->oferta->sku} ".($feita ? 'marcada como feita' : 'desmarcada'));
    }

    /**
     * "Pegue os buracos do mapeamento e agende": uma publicação por dia, a
     * partir do próximo dia livre, na ordem da lista.
     *
     * - Buraco é qualquer oferta que não está OK — inclusive a que tem só um
     *   dos lados. O exemplo da planilha deixou CB2 e MSA-MR de fora; aqui não.
     * - Oferta que já tem publicação agendada ainda pendente fica de fora:
     *   agendar de novo seria duplicar o compromisso.
     * - Dia livre = dia sem nenhuma publicação agendada, a partir de hoje.
     *
     * `$somente` restringe às ofertas escolhidas na prévia, e as datas são
     * recalculadas só para elas — desmarcar um item não deixa buraco na agenda.
     *
     * @param  array<int>|null  $somente
     * @return array<int, array{oferta_id: int, sku: string, nome: ?string, fase: string, situacao: string, data: string}>
     */
    public function proposta(Company $empresa, ?array $somente = null, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $conjunto = EstruturaConjunto::daEmpresa($empresa);

        $publicacoes = EstruturaAgendaItem::query()
            ->join('estrutura_ofertas as o', 'o.id', '=', 'estrutura_agenda.oferta_id')
            ->where('o.company_id', $empresa->id)
            ->where('estrutura_agenda.acao', EstruturaAgendaItem::ACAO_PUBLICACAO)
            ->get(['estrutura_agenda.oferta_id', 'estrutura_agenda.data']);

        // Com publicação agendada: a oferta ainda não está OK, então a linha
        // da agenda ainda está pendente — e já é o compromisso dela.
        $jaAgendadas = $publicacoes->pluck('oferta_id')->flip();
        $ocupados = $publicacoes
            ->filter(fn ($p) => $p->data->gte($hoje))
            ->mapWithKeys(fn ($p) => [$p->data->format('Y-m-d') => true])
            ->all();

        $dia = $hoje;
        $proposta = [];

        foreach ($conjunto->ofertas() as $o) {
            if ($o['situacao'] === ReguaEstrutura::SITUACAO_OK || isset($jaAgendadas[$o['id']])) {
                continue;
            }
            if ($somente !== null && ! in_array($o['id'], $somente, true)) {
                continue;
            }

            while (isset($ocupados[$dia->format('Y-m-d')])) {
                $dia = $dia->addDay();
            }

            $proposta[] = [
                'oferta_id' => $o['id'],
                'sku'       => $o['sku'],
                'nome'      => $o['nome'],
                'fase'      => $o['fase'],
                'situacao'  => $o['situacao'],
                'data'      => $dia->format('Y-m-d'),
            ];

            $ocupados[$dia->format('Y-m-d')] = true;
        }

        return $proposta;
    }

    /**
     * Grava a proposta — REFEITA aqui a partir das ofertas escolhidas; as
     * datas não vêm do navegador.
     *
     * @param  array<int>  $ofertas
     */
    public function agendarProposta(Company $empresa, array $ofertas, AtorDoPortal $ator): int
    {
        $ofertas = array_values(array_unique(array_map('intval', $ofertas)));

        return DB::transaction(function () use ($empresa, $ofertas, $ator) {
            $proposta = $this->proposta($empresa, $ofertas);

            foreach ($proposta as $p) {
                EstruturaAgendaItem::create([
                    'oferta_id' => $p['oferta_id'],
                    'data'      => $p['data'],
                    'acao'      => EstruturaAgendaItem::ACAO_PUBLICACAO,
                ]);
            }

            if ($proposta) {
                RegistroEstrutura::registrar($ator, $empresa, null, 'agenda_proposta_aplicada',
                    count($proposta).' publicações agendadas de '
                    .CarbonImmutable::parse($proposta[0]['data'])->format('d/m/Y').' a '
                    .CarbonImmutable::parse(end($proposta)['data'])->format('d/m/Y'));
            }

            return count($proposta);
        });
    }

    /**
     * `Y-m-d` estrito. O `createFromFormat` do PHP aceita 31/02 e devolve 03/03
     * sem reclamar — por isso o `checkdate`.
     */
    private function data(string $data): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw ValidationException::withMessages(['data' => 'Data inválida.']);
        }

        return $data;
    }
}

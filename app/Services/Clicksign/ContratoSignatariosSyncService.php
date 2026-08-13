<?php

namespace App\Services\Clicksign;

use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use Illuminate\Support\Facades\Log;

/**
 * ContratoSignatariosSyncService — Fase 129, plano 129-03 (D-06/D-07/D-09,
 * CLICK-05).
 *
 * Único lugar que traduz eventos do DOCUMENTO (`ClicksignClient::
 * listarEventosDoDocumento()`) em situação individual de cada
 * `ContratoAssinaturaSignatario`. Recebe a lista **já reconsultada** — este
 * service NÃO faz HTTP, quem chama (o job desta fase) é quem reconsulta —
 * assim a Fase 130 pode reusar exatamente este service no job de
 * reconciliação da REDE-04 sem duplicar a regra de negócio.
 *
 * Garantias que este service PRECISA manter (129-03-PLAN.md, Task 1):
 *  - Idempotente por definição: reprocessar a MESMA lista de eventos duas
 *    vezes produz o mesmo estado final. Escreve estado ABSOLUTO
 *    (`situacao = 'assinou'`/`'recusou'`), nunca incremento.
 *  - Imune a entrega fora de ordem (gate #11, permanentemente não medido):
 *    os eventos são percorridos em ordem CRESCENTE de `attributes.created`
 *    — a ordem que vale é a da Clicksign, não a de chegada dos webhooks.
 *  - NUNCA cria `ContratoAssinaturaSignatario` novo a partir de um evento —
 *    um `sign`/`refusal` de e-mail desconhecido vira `nao_reconhecidos`,
 *    nunca uma linha nova (T-129-16 — criar signatário a partir de webhook
 *    abriria caminho para forjar assinatura inventando um e-mail).
 *  - Allowlist de eventos tratados (`sign`, `refusal`), NUNCA denylist — a
 *    lista documentada de nomes de evento da Clicksign não é exaustiva
 *    (`update_block_after_refusal` foi MEDIDO e não aparece na doc,
 *    129-RESEARCH.md §1).
 *  - Nunca loga nome, e-mail, CPF ou IP de signatário (WR-11 da Fase 125) —
 *    só contagens e as CHAVES (`signer.key`) não reconhecidas.
 */
class ContratoSignatariosSyncService
{
    /**
     * Allowlist do que este service sabe tratar — nunca denylist (ver
     * docblock de classe).
     */
    private const EVENTOS_TRATADOS = ['sign', 'refusal'];

    /**
     * Aplica os eventos do documento (já reconsultados) ao estado dos
     * signatários do contrato.
     *
     * @param  array<int, array<string, mixed>>  $eventosDoDocumento  Forma
     *   de `ClicksignClient::listarEventosDoDocumento()`: lista JSON:API
     *   desembrulhada, cada item com `attributes.name`, `attributes.data`,
     *   `attributes.created`.
     * @return array{assinaram: int, recusaram: int, nao_reconhecidos: array<int, string>}
     */
    public function aplicar(ContratoAssinatura $contrato, array $eventosDoDocumento): array
    {
        $assinaram       = 0;
        $recusaram       = 0;
        $naoReconhecidos = [];

        $eventos = collect($eventosDoDocumento)
            ->filter(function (array $evento) {
                $nome = $evento['attributes']['name'] ?? null;

                return in_array($nome, self::EVENTOS_TRATADOS, true);
            })
            // D-06/D-07 — a ordem que vale é a do `created` da Clicksign,
            // nunca a ordem de chegada do webhook (gate #11 não medido).
            ->sortBy(fn (array $evento) => (string) ($evento['attributes']['created'] ?? ''))
            ->values();

        foreach ($eventos as $evento) {
            $nome       = $evento['attributes']['name'];
            $dados      = $evento['attributes']['data'] ?? [];
            $criadoEm   = $evento['attributes']['created'] ?? null;
            $signerData = is_array($dados) ? ($dados['signer'] ?? null) : null;

            if (!is_array($signerData)) {
                // Sem dados suficientes para identificar quem assinou/recusou.
                continue;
            }

            $signatario = $this->localizarSignatario($contrato, $signerData);

            if ($signatario === null) {
                // Nunca criar signatário a partir de webhook (T-129-16) —
                // só a CHAVE entra no retorno, nunca e-mail (WR-11).
                $naoReconhecidos[] = (string) ($signerData['key'] ?? '');

                continue;
            }

            if ($nome === 'sign') {
                $signatario->situacao         = ContratoAssinaturaSignatario::SITUACAO_ASSINOU;
                $signatario->assinado_em      = $criadoEm;
                $signatario->ip_address       = $signerData['address'] ?? null;
                $signatario->auths            = $signerData['auths'] ?? null;
                $signatario->evidencia_signer = $signerData;
                $signatario->save();

                $assinaram++;
            } elseif ($nome === 'refusal') {
                // Bloco `attributes.data` INTEIRO — a forma real do
                // `refusal` nunca foi medida (Pitfall C); não promover
                // `reasons`/`comment` a coluna própria nesta fase.
                $signatario->situacao         = ContratoAssinaturaSignatario::SITUACAO_RECUSOU;
                $signatario->evidencia_signer = $dados;
                $signatario->save();

                $recusaram++;
            }
        }

        Log::channel('ecf-webhooks')->info('[ContratoSignatariosSyncService] eventos do documento aplicados', [
            'contrato_id'          => $contrato->id,
            'company_id'           => $contrato->company_id,
            'assinaram'            => $assinaram,
            'recusaram'            => $recusaram,
            'nao_reconhecidos_qtd' => count($naoReconhecidos),
        ]);

        return [
            'assinaram'        => $assinaram,
            'recusaram'        => $recusaram,
            'nao_reconhecidos' => $naoReconhecidos,
        ];
    }

    /**
     * Localiza o signatário do contrato pela chave da Clicksign e, na
     * ausência dela, por e-mail (case-insensitive). Se achar por e-mail e a
     * chave local ainda estiver vazia, preenche — é a primeira vez que o
     * sistema sabe a chave daquele signatário.
     */
    private function localizarSignatario(ContratoAssinatura $contrato, array $signerData): ?ContratoAssinaturaSignatario
    {
        $key   = $signerData['key'] ?? null;
        $email = $signerData['email'] ?? null;

        if (filled($key)) {
            $signatario = $contrato->signatarios()->where('clicksign_signer_key', $key)->first();

            if ($signatario !== null) {
                return $signatario;
            }
        }

        if (filled($email)) {
            $signatario = $contrato->signatarios()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $email)])
                ->first();

            if ($signatario !== null) {
                if (blank($signatario->clicksign_signer_key) && filled($key)) {
                    $signatario->clicksign_signer_key = $key;
                    $signatario->save();
                }

                return $signatario;
            }
        }

        return null;
    }
}

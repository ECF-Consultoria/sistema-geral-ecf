<?php

namespace App\Console\Commands;

use App\Models\MlbImplementacao;
use Illuminate\Console\Command;

/**
 * Normaliza as colunas de catálogo das fichas de Onboarding (mlb_implementacoes).
 *
 * Por que um comando e não só encurtar a constante: o Painel Polos reinjeta no dropdown
 * TODO valor presente no banco (valoresPresentes em Polos/Painel.jsx), então enxugar
 * ONB_ME1_OPCOES de 10 para 5 não tira uma única opção da tela enquanto o dado não for
 * normalizado. Em 01/09/2026 a coluna ME1 tinha 12 variantes em 269 fichas, com caixa e
 * acento divergentes do catálogo, porque SyncPolosPlanilha copiava a planilha verbatim.
 *
 * A re-sujeira é barrada na origem: o sync passou a normalizar na INGESTÃO usando o MESMO
 * de-para (MlbImplementacao::normalizarMe1). Sem aquela mudança este comando seria um
 * one-shot — limparia hoje e o próximo `polos:sync-planilha --apply` desfaria tudo.
 */
class NormalizarCatalogosOnboarding extends Command
{
    protected $signature = 'onboarding:normalizar-catalogos
                            {--apply : Grava as alterações (sem esta flag roda em dry-run)}';

    protected $description = 'Normaliza ME1, Integradora e o HUB das fichas de Onboarding para os catálogos vigentes';

    /**
     * Textos livres do antigo HUB (campo `texto`) que têm destino óbvio no novo dropdown.
     * Só 3 fichas em 269 tinham o campo preenchido; 'Bling' é ERP e não HUB, e fica de fora
     * de propósito — o texto do cliente é preservado no campo de acesso.
     */
    private const HUB_DE_PARA = [
        'NAO UTILIZO'      => 'Não utilizo',
        'NAO IRA UTILIZAR' => 'Não utilizo',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply
            ? '=== onboarding:normalizar-catalogos — APLICANDO ==='
            : '=== onboarding:normalizar-catalogos — DRY-RUN (use --apply para gravar) ===');
        $this->newLine();

        $me1         = $this->normalizarColuna('me1', fn ($v) => MlbImplementacao::normalizarMe1($v));
        $integradora = $this->normalizarColuna('integradora', fn ($v) => MlbImplementacao::normalizarIntegradora($v));
        // Só a GRAFIA: o time digita 'Falta aceitar' à mão e a coluna acaba com duas
        // variantes do mesmo estado no dropdown. Valor fora do catálogo passa intocado —
        // 'E-mail enviado', 'Pedir novo acesso' e afins são informação real de operação.
        $acesso = $this->normalizarColuna(
            'acesso_colaborador',
            fn ($v) => MlbImplementacao::normalizarParaCatalogo($v, MlbImplementacao::ONB_ACESSO_COLABORADOR_OPCOES)
        );
        $decola = $this->normalizarColuna(
            'decola',
            fn ($v) => MlbImplementacao::normalizarParaCatalogo($v, MlbImplementacao::ONB_DECOLA_OPCOES)
        );
        $hub = $this->migrarHub();

        $this->relatorio('ME1', $me1);
        $this->relatorio('Integradora', $integradora);
        $this->relatorio('Acesso Colaborador (grafia)', $acesso);
        $this->relatorio('Decola (grafia)', $decola);
        $this->relatorio('HUB (dados.itens.hub)', $hub);

        $total = count($me1) + count($integradora) + count($acesso) + count($decola) + count($hub);

        if (! $apply) {
            $this->newLine();
            $this->warn("DRY-RUN: {$total} alterações seriam aplicadas. Nada foi gravado.");

            return self::SUCCESS;
        }

        $this->gravar($me1, $integradora, $acesso, $decola, $hub);

        $this->newLine();
        $this->info("{$total} alterações aplicadas.");

        return self::SUCCESS;
    }

    /**
     * Calcula (sem gravar) as mudanças de uma coluna de catálogo.
     *
     * @return array<int, array{id:int, empresa:string, de:?string, para:?string}>
     */
    private function normalizarColuna(string $coluna, callable $normalizador): array
    {
        $mudancas = [];

        MlbImplementacao::with('empresa')
            ->whereNotNull($coluna)
            ->where($coluna, '<>', '')
            ->chunkById(200, function ($fichas) use ($coluna, $normalizador, &$mudancas) {
                foreach ($fichas as $ficha) {
                    $de   = $ficha->{$coluna};
                    $para = $normalizador($de);

                    if ($de === $para) {
                        continue;
                    }

                    $mudancas[] = [
                        'id'      => $ficha->id,
                        'empresa' => $ficha->empresa?->nome ?? '(sem empresa)',
                        'de'      => $de,
                        'para'    => $para,
                    ];
                }
            });

        return $mudancas;
    }

    /**
     * Migra o HUB de texto livre (dados.itens.hub.acesso) para o dropdown (.valor).
     *
     * O texto só é limpo quando ele é uma REAFIRMAÇÃO do valor escolhido ('Não utilizo',
     * 'Não irá utilizar'). Texto sem destino no catálogo — 'Bling', que é ERP e não HUB —
     * fica intocado: nenhuma informação de cliente é descartada aqui.
     *
     * @return array<int, array{id:int, empresa:string, de:?string, para:?string}>
     */
    private function migrarHub(): array
    {
        $mudancas = [];

        MlbImplementacao::with('empresa')
            ->whereNotNull('dados')
            ->chunkById(200, function ($fichas) use (&$mudancas) {
                foreach ($fichas as $ficha) {
                    $hub    = $ficha->dados['itens']['hub'] ?? [];
                    $acesso = trim((string) ($hub['acesso'] ?? ''));
                    $valor  = trim((string) ($hub['valor'] ?? ''));

                    // Já migrado (ou nunca preenchido) — nada a fazer.
                    if ($acesso === '' || ($valor !== '' && $valor !== '---')) {
                        continue;
                    }

                    $destino = self::HUB_DE_PARA[MlbImplementacao::chaveCatalogo($acesso)] ?? null;

                    if ($destino === null) {
                        continue;
                    }

                    $mudancas[] = [
                        'id'      => $ficha->id,
                        'empresa' => $ficha->empresa?->nome ?? '(sem empresa)',
                        'de'      => $acesso,
                        'para'    => $destino,
                    ];
                }
            });

        return $mudancas;
    }

    /** @param array<int, array{id:int, empresa:string, de:?string, para:?string}> $mudancas */
    private function relatorio(string $titulo, array $mudancas): void
    {
        $this->line("<comment>── {$titulo} ──</comment>");

        if ($mudancas === []) {
            $this->line('  nada a normalizar');
            $this->newLine();

            return;
        }

        // Agrupa por de→para: o interesse é a regra aplicada, não a lista de 269 fichas.
        $porRegra = [];
        foreach ($mudancas as $m) {
            $chave            = ($m['de'] ?? '(vazio)') . ' → ' . ($m['para'] ?? '(limpar)');
            $porRegra[$chave] = ($porRegra[$chave] ?? 0) + 1;
        }
        arsort($porRegra);

        foreach ($porRegra as $regra => $n) {
            $this->line(sprintf('  %-58s %3d ficha(s)', $regra, $n));
        }

        $this->line(sprintf('  <info>total: %d</info>', count($mudancas)));
        $this->newLine();
    }

    /**
     * @param array<int, array{id:int, para:?string}> $me1
     * @param array<int, array{id:int, para:?string}> $integradora
     * @param array<int, array{id:int, para:?string}> $acesso
     * @param array<int, array{id:int, para:?string}> $decola
     * @param array<int, array{id:int, para:?string}> $hub
     */
    private function gravar(array $me1, array $integradora, array $acesso, array $decola, array $hub): void
    {
        foreach ([['me1', $me1], ['integradora', $integradora],
                  ['acesso_colaborador', $acesso], ['decola', $decola]] as [$coluna, $mudancas]) {
            foreach ($mudancas as $m) {
                // update() direto na coluna: não mexe em me1_manual, então a trava do
                // consultor e a regra automática das medidas continuam valendo.
                MlbImplementacao::whereKey($m['id'])->update([$coluna => $m['para']]);
            }
        }

        foreach ($hub as $m) {
            $ficha = MlbImplementacao::find($m['id']);

            if ($ficha === null) {
                continue;
            }

            $dados                           = MlbImplementacao::mesclarItensPadrao($ficha->dados ?? []);
            $dados['itens']['hub']['valor']  = $m['para'];
            $dados['itens']['hub']['acesso'] = '';

            $ficha->update(['dados' => $dados]);
        }
    }
}

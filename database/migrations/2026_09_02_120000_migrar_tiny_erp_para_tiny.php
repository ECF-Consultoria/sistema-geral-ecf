<?php

use App\Models\MlbImplementacao;
use Illuminate\Database\Migrations\Migration;

/**
 * 'Tiny ERP' saiu de ERP_OPCOES em 02/09/2026 e virou 'Tiny' (revisão da lista pelo
 * comercial). As fichas que já responderam guardam o texto antigo em
 * dados.itens.erp.valor — sem esta migração o select do link do cliente renderiza
 * vazio para elas e itemTemConteudo passa a travar o check do item.
 *
 * Percorre por Eloquent (e não com UPDATE ... JSON_REPLACE) porque o cast 'array'
 * reescreve o JSON inteiro do jeito que o app espera; o SQLite dos testes e o
 * MariaDB da VPS divergem no tratamento de JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->renomear('Tiny ERP', 'Tiny');
    }

    public function down(): void
    {
        $this->renomear('Tiny', 'Tiny ERP');
    }

    private function renomear(string $de, string $para): void
    {
        MlbImplementacao::query()->chunkById(200, function ($fichas) use ($de, $para) {
            foreach ($fichas as $ficha) {
                $dados = $ficha->dados ?? [];

                if (data_get($dados, 'itens.erp.valor') !== $de) {
                    continue;
                }

                data_set($dados, 'itens.erp.valor', $para);
                $ficha->dados = $dados;
                $ficha->saveQuietly();
            }
        });
    }
};

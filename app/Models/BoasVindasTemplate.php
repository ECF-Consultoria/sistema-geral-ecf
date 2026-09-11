<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BoasVindasTemplate — o texto da mensagem de boas-vindas, um por serviço mais
 * um genérico (Fase 153, D-A/D-C).
 *
 * `servico_id` nulo é o **genérico**: o template usado por qualquer serviço que
 * não tenha o seu. Não existe coluna-sentinela nem string mágica — "template do
 * serviço X" e "template padrão" são a mesma coisa, com o FK preenchido ou não.
 *
 * ⚠️ **O índice único `bvt_servico_unique` NÃO garante genérico único.** MariaDB
 * (e o padrão SQL) permitem N linhas com `NULL` numa coluna com índice único.
 * Quem garante a linha única é {@see self::salvarGenerico()}, e é por isso que
 * ela existe em vez de cada chamador fazer o seu `updateOrCreate`. O SQLite dos
 * testes se comporta igual aqui, mas a diferença entre "o banco garante" e "a
 * aplicação garante" é real e está registrada em `153-DECISOES.md` D-C.
 *
 * Esta tabela **não** guarda a mensagem do Polos. Aquela continua em
 * `mlb_configuracoes.implementacao_defaults.mensagem_boas_vindas`, viva e
 * intocada (D-A) — esta fase não migra nem reescreve o que já roda em produção.
 */
class BoasVindasTemplate extends Model
{
    protected $table = 'boas_vindas_templates';

    protected $fillable = ['servico_id', 'texto', 'atualizado_por'];

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }

    /**
     * Autor da última edição. `withTrashed()` pela mesma razão da Fase 152:
     * usuário desligado não pode fazer o nome sumir do que ele escreveu.
     */
    public function atualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atualizado_por')->withTrashed();
    }

    /** O template genérico, ou `null` se ninguém cadastrou nenhum ainda. */
    public static function generico(): ?self
    {
        return self::whereNull('servico_id')->first();
    }

    /**
     * Ponto ÚNICO de escrita do genérico — ver o aviso do docblock da classe.
     * Passar por aqui é o que impede duas linhas genéricas coexistirem.
     */
    public static function salvarGenerico(string $texto, ?int $porUserId = null): self
    {
        return self::updateOrCreate(
            ['servico_id' => null],
            ['texto' => $texto, 'atualizado_por' => $porUserId]
        );
    }

    public static function salvarParaServico(int $servicoId, string $texto, ?int $porUserId = null): self
    {
        return self::updateOrCreate(
            ['servico_id' => $servicoId],
            ['texto' => $texto, 'atualizado_por' => $porUserId]
        );
    }

    /**
     * Texto padrão de partida do genérico — os 6 blocos do §4 na ordem do PDF.
     *
     * Neutro de propósito: não menciona projeto nem marketplace específico, para
     * servir qualquer serviço (D-A). Quem quiser tom próprio cria o template do
     * serviço.
     */
    public const TEXTO_GENERICO_PADRAO = <<<'TXT'
Olá, seja muito bem-vindo(a) à ECF Consultoria! 🚀
Estamos felizes em ter a {empresa} conosco. Para começarmos, seguem os acessos e os primeiros passos.

📧 E-mail colaborador criado para a sua operação:
{email_colaborador}

📊 Cadastro na plataforma Adman (acompanhamento dos números da sua operação):
{link_adman}

🔐 Autorização de acesso à sua conta do Mercado Livre — leva menos de um minuto e é feito na própria tela do Mercado Livre:
{link_oauth}

🔗 Sua área no sistema da ECF, onde você acompanha o andamento e envia o que precisarmos:
{link_sistema}

✅ O que precisamos de você agora:
1. Fazer o cadastro no Adman pelo link acima.
2. Autorizar o acesso da ECF à sua conta do Mercado Livre.
3. Entrar na sua área no sistema e preencher as informações solicitadas.

Qualquer dúvida em qualquer um dos passos, é só chamar aqui no grupo. Vamos construir essa operação juntos! 🤝
TXT;
}

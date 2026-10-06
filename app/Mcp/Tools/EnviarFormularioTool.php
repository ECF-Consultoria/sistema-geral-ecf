<?php

namespace App\Mcp\Tools;

use App\Mcp\Acoes\CatalogoDeAcoes;
use App\Mcp\ErroDaFerramenta;
use App\Mcp\Telas\LeitorDeDados;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * `enviar_formulario` — aciona QUALQUER formulário/botão de gravação do Admin
 * como o usuário (decisão de 06/10/2026: "pode editar o que quiser", gravando
 * direto). É o par de escrita do `ler_tela`: passa pelo kernel HTTP, com
 * validação, permissão e controller da própria tela.
 *
 * O que fica de fora está em {@see CatalogoDeAcoes::BLOQUEADAS}. Exclusão
 * (DELETE) exige `confirmo_exclusao` — a única trava a mais que a tela, porque
 * na tela o botão de excluir pede confirmação.
 */
#[Name('enviar_formulario')]
#[Title('Enviar um formulário do ECF Admin')]
#[Description(<<<'TXT'
Executa qualquer ação de gravação do ECF Admin como você — o mesmo formulário ou botão da tela: criar, editar, mudar status, marcar como feito, excluir. Valem as mesmas permissões e validações da tela.
Como usar:
1. Ache a ação com listar_acoes (busca/modulo) e veja os campos com listar_acoes {"acao": "<nome>"}.
2. Se precisar de ids ou valores possíveis, abra a tela correspondente com ler_tela.
3. Envie: {"acao": "<nome>", "parametros": {<parâmetros do endereço>}, "dados": {<campos do formulário>}}.
Na edição (PUT/PATCH), muitos formulários exigem de novo os campos obrigatórios: mande os valores atuais junto com o que muda.
Exclusão (método DELETE) só com "confirmo_exclusao": true — confirme com a pessoa antes, não dá para desfazer.
Para ticket e demanda dev prefira abrir_ticket, atuar_no_ticket, salvar_demanda e registrar_atualizacao_demanda. Não envia arquivo/anexo. Fora do MCP: sair/senha/perfil, conexões Google/Mercado Livre/Shopee e o módulo de anúncios do Mercado Livre.
TXT)]
class EnviarFormularioTool extends FerramentaDeEscrita
{
    protected function podeGravar(User $usuario): bool
    {
        // A trava é a do próprio formulário, aplicada ao enviá-lo.
        return true;
    }

    /** Pode excluir: o cliente deve tratar como ação destrutiva. */
    public function annotations(): array
    {
        return ['destructiveHint' => true] + parent::annotations();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'acao' => $schema->string()
                ->description('Nome da ação, como listar_acoes devolve (ex.: "ppa.store", "mlb.polos-ppa.update").')
                ->required(),
            'parametros' => $schema->object()
                ->description('Parâmetros do endereço, quando a ação pede (ex.: {"company": 123}, {"ppa": 45}).'),
            'dados' => $schema->object()
                ->description('Campos do formulário, ex.: {"titulo": "...", "status": "feito"}.'),
            'confirmo_exclusao' => $schema->boolean()
                ->description('Obrigatório (true) quando a ação é DELETE. Só depois de a pessoa confirmar a exclusão.'),
        ];
    }

    protected function gravar(Request $request, User $usuario): array
    {
        $acao = $this->texto($request, 'acao')
            ?? throw new ErroDaFerramenta('Informe a "acao" (use listar_acoes para ver os nomes).');

        $entrada = app(CatalogoDeAcoes::class)->achar($usuario, $acao)
            ?? throw new ErroDaFerramenta("Ação \"{$acao}\" não existe, está fora do MCP ou não está no seu perfil. Use listar_acoes para ver as disponíveis.");

        if ($entrada['metodo'] === 'DELETE' && ! $this->booleano($request, 'confirmo_exclusao')) {
            throw new ErroDaFerramenta("\"{$acao}\" EXCLUI e não tem volta. Confirme com a pessoa e repita com \"confirmo_exclusao\": true.");
        }

        $resultado = $this->executor()->enviar($usuario, $acao, $this->objeto($request, 'parametros'), $this->objeto($request, 'dados'));

        $leitor = app(LeitorDeDados::class);
        $dados  = $leitor->ocultarCredenciais($resultado['dados']);
        if (is_array($dados) && $leitor->bytes($dados) > LeitorDeDados::BYTES_MAX) {
            $dados = $leitor->resumir($dados)['valores'];
        }

        return array_filter([
            'feito'    => true,
            'acao'     => $resultado['acao'],
            'metodo'   => $resultado['metodo'],
            'endereco' => $resultado['endereco'],
            'mensagem' => $resultado['mensagem'] ?? 'A tela aceitou, sem mensagem. Confira com ler_tela se precisar.',
            'avisos'   => $resultado['avisos'] ?: null,
            'destino'  => $resultado['destino'],
            'resposta' => $dados,
        ], fn ($v) => $v !== null);
    }

    /** Resposta só em texto: o retorno de um formulário pode ser grande. */
    protected function responder(array $dados): Response|ResponseFactory
    {
        return Response::json($dados);
    }

    /** @return array<string, mixed> */
    private function objeto(Request $request, string $chave): array
    {
        $valor = $request->get($chave);
        if ($valor === null || $valor === '') {
            return [];
        }
        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }
        if (is_object($valor)) {
            $valor = json_decode((string) json_encode($valor), true);
        }
        if (! is_array($valor)) {
            throw new ErroDaFerramenta("\"{$chave}\" precisa ser um objeto, ex.: {\"titulo\": \"...\"}.");
        }

        return $valor;
    }
}

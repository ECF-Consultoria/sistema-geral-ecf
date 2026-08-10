<?php

namespace App\Exceptions;

/**
 * Fase 126 Plan 126-01 (CLICK-01, D-10) — exceção própria para qualquer
 * resposta de erro da API Clicksign v3.
 *
 * O construtor JAMAIS recebe, guarda ou concatena o token — só o que a
 * resposta de erro da própria Clicksign devolve (status HTTP + bloco
 * `errors[0]`). Mensagem final em pt-BR e linguagem simples (regra da
 * Fase 124), com tratamento explícito para 401 (token inválido/ausente,
 * medido) e 403 (e-mail do usuário da API não configurado no painel da
 * Clicksign — 403 ≠ 401, quem barrou foi a camada seguinte; ver
 * CLICKSIGN-SANDBOX-EMPIRICO.md §1) e 429 (rate limit, medido em §1).
 *
 * ⚠️ Quem loga o contexto desta exceção (ex.: `ClicksignClient::enviar()`)
 * nunca deve incluir o corpo bruto da resposta — ele pode ecoar nome e
 * e-mail do signatário que a própria requisição enviou (achado WR-11 do
 * review da Fase 125). Aqui só ficam os três campos nomeados abaixo.
 */
class ClicksignException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $codigoApi = null,
        public readonly ?string $ponteiro = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Monta a exceção a partir do status HTTP e do corpo JSON:API de erro
     * devolvido pela Clicksign (`errors[0].code` / `.detail` / `.source.pointer`).
     *
     * @param  array<string, mixed>  $corpo  corpo decodificado da resposta de erro
     */
    public static function fromResponse(int $status, array $corpo): self
    {
        $primeiroErro = $corpo['errors'][0] ?? [];
        $codigoApi    = $primeiroErro['code'] ?? null;
        $detalhe      = $primeiroErro['detail'] ?? null;
        $ponteiro     = $primeiroErro['source']['pointer'] ?? null;

        $mensagem = match ($status) {
            401     => 'Não foi possível autenticar na Clicksign: token de acesso inválido ou ausente.',
            403     => 'A Clicksign recusou a chamada: o e-mail do usuário da API não está configurado no painel da Clicksign (Configurações → API).',
            429     => 'A Clicksign atingiu o limite de requisições. Tente novamente em instantes.',
            default => '[Clicksign] ' . ($detalhe ?? 'Erro desconhecido ao chamar a API da Clicksign.'),
        };

        return new self($mensagem, $status, $codigoApi, $ponteiro);
    }
}

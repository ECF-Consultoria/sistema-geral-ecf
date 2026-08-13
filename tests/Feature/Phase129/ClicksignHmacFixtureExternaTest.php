<?php

namespace Tests\Feature\Phase129;

use App\Support\Clicksign\ClicksignHmacVarredura;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova da fórmula do gate A1 contra um hex de origem EXTERNA (Pitfall E,
 * 129-RESEARCH.md; SC1 do ROADMAP).
 *
 * Um teste que calcula o HMAC esperado chamando `ClicksignHmacVarredura::
 * calcular()` — a mesma função do código de produção — dá **falso verde**:
 * prova só que o código é consistente consigo mesmo, nada mais. Por isso
 * `FIXTURE_EXTERNA` abaixo NÃO foi gerada por este código. Foi gerada uma
 * única vez, isolada, rodando no terminal:
 *
 *   php -r '
 *   $secret = "fixture-secret-129-02-nao-e-real-jamais-usar-em-producao";
 *   $body = "{\"event\":{\"name\":\"sign\",\"data\":{\"id\":\"fixture-envelope-externo\"},\"occurred_at\":\"2026-08-13T10:00:00-03:00\"}}";
 *   echo "sha256=" . hash_hmac("sha256", $body, $secret) . PHP_EOL;
 *   '
 *
 * O resultado impresso por esse comando (`8...81dc`, 64 hex) foi colado
 * abaixo como string literal. Qualquer pessoa pode reconferir rodando o
 * mesmo comando, sem precisar confiar no nosso código.
 *
 * FIXTURE_SECRET é um segredo de TESTE, literal, criado só para este
 * arquivo — nunca o `CLICKSIGN_WEBHOOK_SECRET` real, nunca lido do `.env`
 * (T-129-11).
 */
class ClicksignHmacFixtureExternaTest extends TestCase
{
    private const FIXTURE_SECRET = 'fixture-secret-129-02-nao-e-real-jamais-usar-em-producao';

    private const FIXTURE_BODY = '{"event":{"name":"sign","data":{"id":"fixture-envelope-externo"},"occurred_at":"2026-08-13T10:00:00-03:00"}}';

    /**
     * Calculado FORA deste código — ver docblock da classe para o comando
     * `php -r` exato usado para gerar este valor.
     */
    private const FIXTURE_EXTERNA = 'sha256=872cd227226418ae41310ba03e31efaacfbcb128cb302adc0a3799df0ffd81dc';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.clicksign.webhook_secret' => self::FIXTURE_SECRET]);
    }

    #[Test]
    public function confere_aceita_o_hex_calculado_fora_do_codigo_de_producao(): void
    {
        $this->assertTrue(
            ClicksignHmacVarredura::confere(self::FIXTURE_BODY, self::FIXTURE_SECRET, self::FIXTURE_EXTERNA)
        );
    }

    #[Test]
    public function confere_recusa_o_hex_externo_com_um_caractere_alterado(): void
    {
        $hexAlterado = substr(self::FIXTURE_EXTERNA, 0, -1) . (self::FIXTURE_EXTERNA[-1] === '0' ? '1' : '0');

        $this->assertFalse(
            ClicksignHmacVarredura::confere(self::FIXTURE_BODY, self::FIXTURE_SECRET, $hexAlterado)
        );
    }

    #[Test]
    public function calcular_com_a_formula_confirmada_bate_exatamente_com_a_fixture_externa(): void
    {
        $calculado = ClicksignHmacVarredura::calcular(
            self::FIXTURE_BODY,
            self::FIXTURE_SECRET,
            ClicksignHmacVarredura::FORMULA_CONFIRMADA
        );

        $this->assertSame(self::FIXTURE_EXTERNA, $calculado);
    }
}

<?php

namespace App\Mcp;

use RuntimeException;

/**
 * Erro previsto de uma ferramenta do MCP ("CUST não encontrado", "sem
 * permissão", "ECF Drive fora do ar"). A mensagem vai para o Claude como está,
 * então precisa ser legível e em pt-BR — nada de stack trace nem SQL.
 */
class ErroDaFerramenta extends RuntimeException
{
}

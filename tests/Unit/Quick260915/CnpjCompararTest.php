<?php

namespace Tests\Unit\Quick260915;

use App\Support\Cnpj;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Quick 260915-mtj — a regra única de comparação CNPJ do contrato × CNPJ da empresa.
 */
class CnpjCompararTest extends TestCase
{
    #[Test]
    public function digitos_tira_mascara_e_trata_null(): void
    {
        $this->assertSame('12345678000199', Cnpj::digitos('12.345.678/0001-99'));
        $this->assertSame('', Cnpj::digitos(null));
        $this->assertSame('', Cnpj::digitos(''));
    }

    #[Test]
    public function raiz_so_existe_com_14_digitos(): void
    {
        $this->assertSame('12345678', Cnpj::raiz('12.345.678/0001-99'));
        $this->assertNull(Cnpj::raiz('1234567800019'));
        $this->assertNull(Cnpj::raiz(null));
    }

    #[Test]
    public function os_cinco_estados(): void
    {
        $this->assertSame(Cnpj::COMPARACAO_IGUAL, Cnpj::comparar('12345678000199', '12.345.678/0001-99'));
        $this->assertSame(Cnpj::COMPARACAO_MESMA_EMPRESA_OUTRA_UNIDADE, Cnpj::comparar('93734150000100', '93.734.150/0005-33'));
        $this->assertSame(Cnpj::COMPARACAO_DIFERENTE, Cnpj::comparar('08040574000103', '11.222.333/0001-81'));
        $this->assertSame(Cnpj::COMPARACAO_EMPRESA_SEM_CNPJ, Cnpj::comparar('08040574000103', null));
        $this->assertSame(Cnpj::COMPARACAO_EMPRESA_SEM_CNPJ, Cnpj::comparar('08040574000103', ''));
        $this->assertSame(Cnpj::COMPARACAO_CONTRATO_SEM_CNPJ, Cnpj::comparar(null, '11.222.333/0001-81'));
        $this->assertSame(Cnpj::COMPARACAO_CONTRATO_SEM_CNPJ, Cnpj::comparar('', null));

        $this->assertCount(5, Cnpj::COMPARACOES);
    }

    #[Test]
    public function contrato_com_cnpj_incompleto_conta_como_nao_lido(): void
    {
        $this->assertSame(Cnpj::COMPARACAO_CONTRATO_SEM_CNPJ, Cnpj::comparar('0804057400010', '11.222.333/0001-81'));
    }

    #[Test]
    public function cnpj_da_empresa_malformado_nao_passa_como_igual(): void
    {
        $this->assertSame(Cnpj::COMPARACAO_DIFERENTE, Cnpj::comparar('08040574000103', '0804057400'));
    }
}

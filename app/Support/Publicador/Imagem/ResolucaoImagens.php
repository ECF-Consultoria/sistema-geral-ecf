<?php

namespace App\Support\Publicador\Imagem;

use App\Support\Publicador\Validacao\Problema;

/** O que {@see ResolvedorGruposImagem::resolver()} devolve. Calculado, nunca gravado (`02` E6). */
final class ResolucaoImagens
{
    /**
     * @param  list<array{chave: string, rotulo: string, definePicture: bool, variantes: list<string>, usadaEm: list<string>, imagens: list<string>}>  $grupos  as colunas da tela
     * @param  list<string>  $geral  a galeria geral, na ordem
     * @param  array<string, list<string>>  $porVariante  chave da variante ativa → fotos na ordem final
     * @param  list<string>  $uniaoLegado  `item.pictures` do legado (vazio no UP)
     * @param  list<Problema>  $problemas
     */
    public function __construct(
        public readonly array $grupos,
        public readonly array $geral,
        public readonly array $porVariante,
        public readonly array $uniaoLegado,
        public readonly array $problemas,
    ) {}
}

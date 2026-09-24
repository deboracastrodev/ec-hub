<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Regra de empate do bin/compare-knn-rewrite.php (Desafio 2 do
 * LEARNING_JOURNAL.md), fora do script para poder ser testada sozinha.
 */
final class KnnTieCheck
{
    public const EPSILON = 1e-9;

    /**
     * As duas respostas são k-NN igualmente válidos? Sim quando têm o mesmo
     * tamanho e, posição a posição, a mesma distância ao alvo (dentro de
     * EPSILON), cada lado medido pela própria implementação. Então toda
     * troca, de ordem ou de conjunto, é só desempate entre equidistantes.
     *
     * @param list<float> $beforeDistances distâncias da versão manual, na ordem devolvida
     * @param list<float> $afterDistances  distâncias da versão atual, na ordem devolvida
     */
    public static function explained(array $beforeDistances, array $afterDistances): bool
    {
        if (count($beforeDistances) !== count($afterDistances)) {
            return false;
        }
        foreach ($beforeDistances as $i => $distance) {
            if (abs($distance - $afterDistances[$i]) >= self::EPSILON) {
                return false;
            }
        }

        return true;
    }
}

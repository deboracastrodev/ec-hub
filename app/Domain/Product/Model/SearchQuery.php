<?php

declare(strict_types=1);

namespace App\Domain\Product\Model;

/**
 * Product search query (Story 8.5, FR109).
 *
 * The single text rule of the search: the raw text the user typed (trimmed,
 * cut to 100 characters) and the terms derived from it (lowercase, pt-BR
 * accents removed, split on anything that is not a letter or digit, terms
 * shorter than 2 characters dropped, no duplicates, at most 8).
 *
 * normalize() is the same transformation, exposed so the ranker and the
 * in-memory repository compare product text exactly like the terms.
 */
final readonly class SearchQuery
{
    public const MAX_LENGTH = 100;
    public const MAX_TERMS = 8;
    public const MIN_TERM_LENGTH = 2;

    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];

    /**
     * @param list<string> $terms
     */
    private function __construct(
        private string $raw,
        private array $terms,
    ) {
    }

    public static function fromRaw(string $raw): self
    {
        $valid = mb_check_encoding($raw, 'UTF-8');
        // Invalid UTF-8 yields zero terms; the displayed text is scrubbed so
        // the view never receives a malformed string.
        $text = mb_substr(trim($valid ? $raw : mb_scrub($raw, 'UTF-8')), 0, self::MAX_LENGTH, 'UTF-8');

        return new self($text, $valid ? self::extractTerms($text) : []);
    }

    /** The trimmed, length-capped text, as the user typed it (for display). */
    public function raw(): string
    {
        return $this->raw;
    }

    /** @return list<string> */
    public function terms(): array
    {
        return $this->terms;
    }

    public function hasTerms(): bool
    {
        return $this->terms !== [];
    }

    /**
     * Lowercase + accents removed. Invalid UTF-8 normalizes to ''.
     *
     * The pt-BR map always applies; when ext-intl is present, any other
     * combining mark (e.g. 'š', 'ý') is stripped too, so the ranker agrees
     * with MySQL's accent-insensitive utf8mb4_unicode_ci collation beyond
     * the pt-BR set.
     */
    public static function normalize(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return '';
        }

        $normalized = strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($normalized, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $normalized = $decomposed;
            }
        }

        // Sem ext-intl, entradas já decompostas (a + U+0302) também perdem a marca em vez de virar separador.
        return (string) preg_replace('/\p{Mn}+/u', '', $normalized);
    }

    /** @return list<string> */
    private static function extractTerms(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        $terms = [];
        foreach ($parts as $part) {
            if (mb_strlen($part, 'UTF-8') < self::MIN_TERM_LENGTH || in_array($part, $terms, true)) {
                continue;
            }
            $terms[] = $part;
            if (count($terms) === self::MAX_TERMS) {
                break;
            }
        }

        return $terms;
    }
}

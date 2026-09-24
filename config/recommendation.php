<?php

return [
    // Story 8.1: active RecommendationStrategy ('knn' default, 'collaborative').
    // Passed raw: RecommendationSettings normalizes it (trim + lowercase,
    // empty -> 'knn') and fails fast on unknown values. Cast, not ?:, so a
    // value like "0" still reaches validation.
    'algorithm' => (string) getenv('RECOMMENDATION_ALGORITHM'),
    // Story 8.2: A/B test between two algorithms ("knn,collaborative").
    // Passed raw: RecommendationSettings splits, normalizes and validates it
    // (empty -> A/B off; otherwise exactly 2 distinct algorithms, else fail-fast).
    'ab_test' => (string) getenv('RECOMMENDATION_AB_TEST'),
    'fallback' => [
        'strategy' => getenv('RECOMMENDATION_FALLBACK_STRATEGY') ?: 'hybrid',
        'min_products_for_ml' => (int) (getenv('RECOMMENDATION_MIN_PRODUCTS_FOR_ML') ?: 5),
        'scores' => [
            'category_min' => 60.0,
            'category_max' => 70.0,
            'popularity_min' => 50.0,
            'popularity_max' => 60.0,
        ],
    ],
];

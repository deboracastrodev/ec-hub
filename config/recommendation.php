<?php

return [
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

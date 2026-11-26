<?php

return [
    'cache' => [
        'default'   => 3600,    // 1 hour
        'search'    => 600,     // 10 minutes
        'locations' => 604800,  // 7 days (Wikidata)
    ],

    'chat' => [
        'session_ttl'  => 1800, // 30 minutes
        'max_message'  => 500,
    ],

    'pagination' => [
        'default_per' => 21,
        'top_rated'   => 12,
        'entity_per'  => 10,
        'quick_per'   => 20,
        'min_per'     => 1,
        'max_per'     => 50,
        'min_page'    => 1,
        'max_page'    => 1000,
    ],

    'limits' => [
        'home_row'       => 20,
        'recommended'    => 10,
        'similar'        => 12,
        'locations'      => 25,
        'max_query_len'  => 200,
        'max_short_q'    => 100,
        'max_filter_cnt' => 20,
    ],

    'defaults' => [
        'min_year'           => 1950,
        'max_year_fallback'  => 9999,
        'rank_fallback'      => 1000000,
        'best_rating'        => 10,
        'worst_rating'       => 1,
        'recommended_rating' => 8,
        'long_running_min'   => 2,
        'zero'               => 0,
        'one'                => 1,
    ],

    'search' => [
        'weights' => [
            'title_prefix' => 6,
            'title_word'   => 5,
            'title_part'   => 3,
            'genre_prefix' => 4,
            'genre_part'   => 2,
            'cast_prefix'  => 3,
            'cast_part'    => 2,
            'meta'         => 1.5,
            'phrase_title' => 6,
            'phrase_cast'  => 4,
            'year_exact'   => 3,
            'year_decade'  => 2,
        ],
    ],

    'similarity' => [
        'genre_weight' => 2,
        'kw_weight'    => 3,
        'cast_weight'  => 2,
        'cast_cap'     => 3,
        'rating_div'   => 100,
    ],
];
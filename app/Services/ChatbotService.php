<?php

namespace App\Services;

class ChatbotService
{
    private const ASIAN_COUNTRIES = [
        'china', 'hong kong', 'india', 'indonesia', 'japan', 'malaysia',
        'philippines', 'singapore', 'south korea', 'taiwan', 'thailand', 'vietnam',
    ];

    private const WESTERN_COUNTRIES = [
        'Australia', 'Austria', 'Belgium', 'Canada', 'Denmark', 'France',
        'Germany', 'Ireland', 'Italy', 'Netherlands', 'New Zealand', 'Norway',
        'Portugal', 'Spain', 'Sweden', 'Switzerland', 'United Kingdom', 'United States',
    ];

    private const FAMILY_CONTENT_RATINGS = [
        'SU', 'TV-Y', 'G', 'TV-G', 'TV-PG',
    ];

    private const FAMILY_BLOCKED_TERMS = [
        'crime', 'thriller', 'gore', 'narcissism', 'terminal illness',
        'alcohol', 'alcoholic', 'drinking', 'drink', 'drinks', 'drank', 'drunk',
        'drunken', 'booze', 'smoking', 'smoke', 'smoker', 'cigarette', 'cigarettes',
        'tobacco', 'dark comedy', 'cynicism', 'cynical', 'cynical humor',
        'profanity', 'profane', 'swearing', 'swear words', 'curse words',
        'strong language', 'explicit language', 'offensive language', 'foul language',
        'vulgar language', 'coarse language', 'war', 'warfare', 'battle', 'combat', 'military',
        'holocaust', 'violence', 'violent', 'gun violence', 'gun fight', 'fight',
        'fighting', 'blood', 'murder', 'murderous', 'assassin', 'assassination',
        'nudity', 'nude', 'explicit', 'sexual', 'sex', 'innuendo', 'suggestive',
        'adult humor', 'adult content', 'mature themes', 'crude humor',
        'drug', 'drugs',
        'substance abuse', 'addiction', 'narkoba', 'alkohol', 'kekerasan',
        'ketelanjangan', 'adegan seksual', 'sexual violence', 'sexual assault',
        'rape', 'drug abuse', 'drug addiction',
    ];

    private const FAMILY_PREFERENCE_EXCLUDED_TERMS = [
        'lgbtq+', 'lgbtq', 'lgbt', 'lesbian', 'homosexual', 'transgender',
        'queer', 'gay', 'same-sex', 'same sex', 'coming out', 'gender identity',
        'sexual orientation', 'gay theme', 'lesbian relationship', 'gay parent',
        'gay activist', 'sexual minority',
    ];

    public function respond(string $message, array $shows, array $state = []): array
    {
        $query = $this->normalizeQuery($message);
        $shows = $this->uniqueShows($shows);
        $state = $this->normalizeState($state);
        $premiseTitle = $this->findPremiseTitle($query, $shows);
        if ($this->isOutOfScopeTechnicalQuestion($query)) {
            return [
                'answer' => 'Chatbot ini khusus membantu pertanyaan dan rekomendasi serial TV di situs ITV, jadi belum bisa menjawab pertanyaan di luar katalog. Mau cari serial berdasarkan judul, genre, atau rating?',
                'shows' => [],
                'searchUrl' => null,
            ];
        }
        if (preg_match('/\b(?:mulai dari awal|reset(?: pencarian)?|hapus semua filter|bersihkan pencarian)\b/u', $query)) {
            return [
                'answer' => 'Oke, kriteria pencarian sudah direset. Mau cari serial berdasarkan apa?',
                'shows' => [],
                'searchUrl' => null,
                'state' => $this->emptyState(),
            ];
        }
        if (preg_match('/\b(?:kamu bisa apa|bisa cari apa|fitur kamu|help|bantuan)\b/u', $query)) {
            return [
                'answer' => 'Aku bisa mencari serial dari judul, genre, tahun, negara, platform, rating, durasi episode (maksimal 30 menit), status, pemeran, pencipta, dan sinopsis jika datanya tersedia.',
                'shows' => [],
                'searchUrl' => null,
            ];
        }
        if (! preg_match('/\b(?:berita|cuaca|saham|skor|pertandingan|sepak bola|basket|olahraga|politik|resep)\b/u', $query)
            && ! preg_match('/\b(?:series|serial|show|tv|televisi|nonton|tontonan|film|rekomendasi|rekomendasikan|carikan|cari|filter|tampilkan|genre|rating|platform|negara|country|season|musim|episode|tahun|rilis|aktor|pemeran|pencipta|sinopsis|mirip|tertinggi|terendah|terbaru|terlama)\b/u', $query)
            && ! $this->findGenres($query, $shows)
            && ! $this->findTitle($query, $shows)
            && ! $this->findCountry($query, $shows)
            && ! $this->findPlatform($query, $shows)
            && $this->findRating($query)['value'] === null
            && ! $premiseTitle
            && ! preg_match('/\b(?:hapus|hilangkan|remove|clear|ganti|ubah|set)\b/u', $query)) {
            return [
                'answer' => 'Chatbot ini khusus membantu pertanyaan dan rekomendasi serial TV di situs ITV, jadi belum bisa menjawab pertanyaan di luar katalog. Mau cari serial berdasarkan judul, genre, atau rating?',
                'shows' => [],
                'searchUrl' => null,
            ];
        }
        if (preg_match('/\b(?:berita|cuaca|saham|skor|pertandingan|sepak bola|basket|olahraga|politik|resep)\b/u', $query)) {
            return [
                'answer' => 'Chatbot ini khusus membantu pertanyaan dan rekomendasi serial TV di situs ITV, jadi belum bisa menjawab pertanyaan di luar katalog. Mau cari serial berdasarkan judul, genre, atau rating?',
                'shows' => [],
                'searchUrl' => null,
            ];
        }
        $familyFriendly = $this->isFamilyFriendlyIntent($query);
        if ($familyFriendly && $this->hasConflictingMatureFamilyIntent($query)) {
            return [
                'answer' => 'Permintaan ini mencampur batas ramah keluarga dengan kekerasan atau tema dewasa. Kamu ingin tetap membatasi hasil agar ramah keluarga, atau mencari serial aksi dewasa tanpa filter keluarga?',
                'shows' => [],
                'searchUrl' => null,
                'state' => $this->emptyState(),
            ];
        }
        $title = $this->findTitle($query, $shows);
        $plotIntent = $title === null && $premiseTitle !== null;
        $title ??= $premiseTitle;
        $genres = $this->findGenres($query, $shows);
        $excludeGenres = $this->findExcludedGenres($query, $shows);
        $genres = array_values(array_diff($genres, $excludeGenres));
        $platform = $this->findPlatform($query, $shows);
        $excludePlatform = $this->findExcludedPlatform($query, $shows);
        if ($platform && in_array(mb_strtolower($platform), array_map('mb_strtolower', $excludePlatform), true)) {
            $platform = null;
        }
        $country = $this->findCountry($query, $shows);
        $excludeCountry = $this->findExcludedCountries($query, $shows);
        $country = array_values(array_diff($country, $excludeCountry));
        $ratingConstraint = $this->findRating($query);
        $rating = $ratingConstraint['value'];
        $ratingExclusive = $ratingConstraint['exclusive'];
        $yearRange = $this->findYearRange($query);
        $year = $yearRange['min'];
        $yearInclusive = $yearRange['min_inclusive'];
        $yearMax = $yearRange['max'];
        $yearMaxInclusive = $yearRange['max_inclusive'];
        $moods = $this->findMoods($query);
        $seasonRange = $this->findSeasonRange($query);
        $oneSeason = preg_match('/\b(?:satu|one|1)\s*(?:season|musim)\b|\b(?:season|musim)\s*(?:satu|one|1)\b/u', $query) === 1;
        if ($oneSeason) {
            $seasonRange = ['min' => 1, 'max' => 1];
        }
        $endedOnly = preg_match('/\b(?:sudah\s+tamat|telah\s+tamat|tamat|ended|completed|finished)\b/u', $query) === 1;
        $westernOnly = preg_match('/\b(?:barat|western)\b/u', $query) === 1;
        $shortEpisodes = preg_match('/\b(?:pendek|short|singkat|singakt)\b/u', $query) === 1
            && preg_match('/\b(?:episode|episodenya|episodes|episod)\b/u', $query) === 1;
        $durationMax = $this->findDurationLimit($query);
        $shortEpisodes = $shortEpisodes || $durationMax !== null;
        $countQuestion = preg_match('/\b(?:berapa|how many|jumlah|total)\b/u', $query) === 1;
        $filterRequest = preg_match('/\b(?:tampilkan|tunjukkan|cari(?:in)?|carikan|filter|show|find|list)\b/u', $query) === 1;
        $similarIntent = preg_match('/\b(?:mirip|seperti|similar|like|vibe|nuansa)\b/u', $query) === 1;
        $similarReference = $similarIntent ? $this->findSimilarityReference($query) : null;
        $mentionedActors = $this->findMentionedActors($query, $shows);
        $excludedTitles = $this->findExcludedTitles($query, $shows);

        if ($title && $excludedTitles && preg_match('/\b(?:pemeran|aktor|aktris|cast)\b.{0,60}\b(?:sama|serupa)\b|\b(?:sama|serupa)\b.{0,50}\b(?:pemeran|aktor|aktris|cast)\b/u', $query)) {
            return $this->respondToRelatedCastExcludingTitle($title, $excludedTitles, $genres, $shows);
        }

        if ($mentionedActors && $excludedTitles) {
            return $this->respondToExcludedActorTitles($mentionedActors, $excludedTitles, $genres, $shows);
        }
        if ($excludedTitles) {
            $excludedIds = array_map(fn (array $show) => (string) ($show['id'] ?? ''), $excludedTitles);
            if ($title && in_array((string) ($title['id'] ?? ''), $excludedIds, true)) {
                $title = null;
            }
        }

        if ($title && $familyFriendly && preg_match('/\b(?:apakah|apa|is|cocok|termasuk|aman|safe)\b/u', $query) === 1) {
            $reason = $this->familyFriendlyExclusionReason($title);
            $answer = $reason
                ? "{$title['name']} tidak masuk rekomendasi ramah keluarga: {$reason}."
                : "{$title['name']} lolos batas rating ramah keluarga (".trim((string) $title['contentRating']).').';
            $alternatives = array_values(array_filter($shows, fn (array $show) => $show['id'] !== $title['id'] && $this->isFamilyFriendlyShow($show)
            ));
            usort($alternatives, fn (array $a, array $b) => (float) ($b['aggregateRating'] ?? 0) <=> (float) ($a['aggregateRating'] ?? 0));
            if ($alternatives) {
                $answer .= ' Alternatif yang memenuhi batas tersebut: '.implode(', ', array_column(array_slice($alternatives, 0, 3), 'name')).'.';
            }

            return [
                'answer' => $answer,
                'shows' => array_map(fn (array $show) => $this->card($show, 'Rating usia '.trim((string) $show['contentRating'])), array_slice($alternatives, 0, 4)),
                'searchUrl' => null,
            ];
        }

        if ($title && preg_match('/\b(?:pemeran|cast|aktor|actors?)\b/u', $query) === 1) {
            return $this->castAnswer($title, $shows);
        }

        if ($title && preg_match('/\b(?:pencipta|pembuat|creator|created by|showrunner|penulis)\b/u', $query) === 1) {
            return $this->creatorAnswer($title, $shows);
        }

        if ($title && preg_match('/\b(?:platform|streaming|tayang di mana|where to watch)\b/u', $query) === 1) {
            $platforms = (array) ($title['platform'] ?? []);
            $answer = $platforms
                ? "{$title['name']} tersedia di ".implode(', ', $platforms).'.'
                : "Data platform {$title['name']} belum tersedia.";

            return ['answer' => $answer, 'shows' => [$this->card($title)], 'searchUrl' => null];
        }

        if ($title && preg_match('/\b(?:sinopsis|ceritakan|ceritain|plot|overview|spoiler)\b/u', $query) === 1) {
            $description = trim((string) ($title['description'] ?? ''));
            $summary = $this->firstSentences($description, 2);
            if ($summary === '') {
                $answer = "Aku belum menemukan sinopsis {$title['name']} di data serial.";
            } else {
                $answer = "{$title['name']}: {$summary}";
            }

            return ['answer' => $answer, 'shows' => [$this->card($title)], 'searchUrl' => null];
        }

        if ($title && preg_match('/\b(?:status|content rating|rating usia|rating konten|klasifikasi usia)\b/u', $query) === 1) {
            $facts = [];
            if (! empty($title['status'])) {
                $facts[] = 'status '.$title['status'];
            }
            if (! empty($title['contentRating'])) {
                $facts[] = 'content rating '.$title['contentRating'];
            }
            $answer = $facts
                ? "{$title['name']} tercatat dengan ".implode(' dan ', $facts).'.'
                : "Data status dan content rating {$title['name']} belum tersedia.";

            return ['answer' => $answer, 'shows' => [$this->card($title)], 'searchUrl' => null];
        }

        if ($title && preg_match('/\b(?:berapa|jumlah|total|how many)\b/u', $query) === 1
            && preg_match('/\b(?:season|musim|episode|episodes|episod)\b/u', $query) === 1
            && ! preg_match('/\b(?:lebih dari|kurang dari|minimal|maksimal|paling banyak|di atas|rating|filter|cari|tampilkan|setidaknya)\b/u', $query)) {
            $seasons = $title['numberOfSeasons'] ?? null;
            $episodes = $title['numberOfEpisodes'] ?? null;
            $parts = [];
            if ($seasons !== null && $seasons !== '') {
                $parts[] = "{$seasons} season";
            }
            if ($episodes !== null && $episodes !== '') {
                $parts[] = "{$episodes} episode";
            }
            $answer = $parts
                ? "{$title['name']} memiliki ".implode(' dan ', $parts).'.'
                : "Data jumlah season dan episode {$title['name']} belum tersedia.";

            return ['answer' => $answer, 'shows' => [$this->card($title)], 'searchUrl' => null];
        }

        if ($rating !== null && ($rating < 0 || $rating > 10)) {
            return [
                'answer' => 'Rating harus berada pada rentang 0 sampai 10. Coba masukkan batas rating yang valid, ya.',
                'shows' => [],
                'searchUrl' => null,
            ];
        }

        if ($plotIntent || $this->shouldStartFreshSearch($query, $state, $genres, $title, $similarIntent)) {
            $state = $this->emptyState();
        }
        $sort = $this->findSort($query) ?? $state['sort'];
        $state = $this->mergeState($state, compact(
            'genres', 'excludeGenres', 'platform', 'excludePlatform', 'country', 'excludeCountry', 'rating', 'ratingExclusive', 'year', 'yearInclusive',
            'yearMax', 'yearMaxInclusive', 'seasonRange', 'endedOnly', 'westernOnly', 'durationMax',
            'shortEpisodes', 'familyFriendly', 'sort'
        ), $query);
        $genres = $state['genres'];
        $excludeGenres = $state['excludeGenres'];
        $platform = $state['platform'];
        $excludePlatform = $state['excludePlatform'];
        $country = $state['country'];
        $excludeCountry = $state['excludeCountry'];
        $rating = $state['rating'];
        $ratingExclusive = $state['ratingExclusive'];
        $year = $state['year'];
        $yearInclusive = $state['yearInclusive'];
        $yearMax = $state['yearMax'];
        $yearMaxInclusive = $state['yearMaxInclusive'];
        $seasonRange = $state['seasonRange'];
        $endedOnly = $state['endedOnly'];
        $westernOnly = $state['westernOnly'];
        $shortEpisodes = $state['shortEpisodes'];
        $durationMax = $state['durationMax'];
        $familyFriendly = $state['familyFriendly'];
        $sort = $state['sort'];

        $filters = compact('genres', 'excludeGenres', 'platform', 'excludePlatform', 'country', 'excludeCountry', 'rating', 'ratingExclusive', 'year', 'yearInclusive', 'yearMax', 'yearMaxInclusive', 'seasonRange', 'endedOnly', 'westernOnly', 'shortEpisodes', 'durationMax', 'familyFriendly');
        $filters['excludeTitles'] = array_map(fn (array $show) => (string) ($show['id'] ?? ''), $excludedTitles);
        $matches = array_values(array_filter($shows, fn (array $show) => $this->matchesFilters($show, $filters)));

        if ($title && ! $similarIntent) {
            $matches = array_values(array_filter($matches, fn (array $show) => ($show['id'] ?? null) === ($title['id'] ?? null)));
        }

        if ($similarIntent && $title) {
            $matches = array_values(array_filter(
                $matches,
                fn (array $show) => ($show['id'] ?? null) !== ($title['id'] ?? null)
            ));
        }

        if ($similarIntent && $title) {
            $matches = $this->rankSimilar($title, $matches, $moods);
        } elseif ($similarIntent) {
            $this->sortUnknownSimilar($matches, $similarReference, $genres, $moods);
        } else {
            $this->sortShows($matches, $sort, $moods);
        }

        $results = array_slice($matches, 0, 4);
        $criteria = $this->criteria($genres, $excludeGenres, $platform, $excludePlatform, $country, $excludeCountry, $rating, $ratingExclusive, $year, $yearInclusive, $yearMax, $yearMaxInclusive, $seasonRange, $endedOnly, $westernOnly, $shortEpisodes, $durationMax, $moods);
        if ($familyFriendly) {
            array_unshift($criteria, 'ramah keluarga');
        }
        $isCount = $countQuestion && ($genres || $excludeGenres || $country || $platform || $year !== null || $yearMax !== null || $rating !== null || $familyFriendly || $endedOnly || $westernOnly || $seasonRange['min'] !== null || $seasonRange['max'] !== null || $shortEpisodes || $durationMax !== null);

        $fallbackMessage = null;
        if (! $matches && ! $isCount && ! $plotIntent) {
            [$fallbackMatches, $fallbackMessage] = $this->findFallbackResults($shows, $filters, $genres, $moods);
            if ($fallbackMatches) {
                $matches = $fallbackMatches;
                if ($similarIntent && ! $title) {
                    $this->sortUnknownSimilar($matches, $similarReference, $genres, $moods);
                } else {
                    $this->sortFallbackResults($matches, $filters, $genres, $moods);
                }
                $results = array_slice($matches, 0, 4);
            }
        }

        $plotAlternative = $plotIntent && $title && ! $matches;
        if ($plotAlternative) {
            $results = [$title];
        }

        if ($isCount) {
            $answer = 'Ada '.count($matches).' serial yang cocok';
            if ($criteria) {
                $answer .= ' dengan kriteria '.implode(', ', $criteria);
            }
            $answer .= '.';
        } elseif ($plotIntent && $title) {
            $answer = $plotAlternative
                ? "Premis cerita itu mirip dengan {$title['name']} berdasarkan sinopsis katalog, tetapi judul tersebut tidak memenuhi semua kriteria".($criteria ? ' yang kamu minta ('.implode(', ', $criteria).')' : '').'.'
                : "Berdasarkan premis dan sinopsis katalog, serial yang paling sesuai adalah {$title['name']}.";
        } elseif (! $matches) {
            $answer = $familyFriendly
                ? 'Tidak ditemukan serial yang memenuhi kriteria ramah keluarga yang kamu minta'.($criteria ? ' ('.implode(', ', $criteria).')' : '').'. Aku tidak akan menyarankan judul yang gagal pemeriksaan rating usia atau metadata konten. Coba ubah salah satu kriterianya.'
                : 'Tidak ditemukan serial di katalog yang memenuhi kriteria'.($criteria ? ' ('.implode(', ', $criteria).')' : '').'. Aku tidak akan menggantinya dengan genre atau negara yang tidak sesuai. Coba ubah salah satu kriterianya.';
        } else {
            if ($similarIntent && ! $title) {
                $reference = $similarReference
                    ? 'judul '.mb_convert_case($similarReference, MB_CASE_TITLE, 'UTF-8')
                    : 'judul yang kamu maksud';
                $answer = "Aku belum menemukan {$reference} di katalog. Berikut alternatif berdasarkan kecocokan metadata genre, keyword, dan sinopsis yang tersedia".($criteria ? ' serta '.implode(', ', $criteria) : '').':';
            } else {
                $answer = $title && ! $similarIntent
                    ? "Aku menemukan {$title['name']} di katalog SeriesTV:"
                    : $this->recommendationAnswer($results, $criteria, $similarIntent ? $title : null);
            }
        }
        if ($fallbackMessage !== null && $matches) {
            $reference = $similarIntent && ! $title
                ? 'Judul '.mb_convert_case($similarReference ?? 'yang kamu maksud', MB_CASE_TITLE, 'UTF-8').' belum ada di katalog. '
                : 'Belum ada judul yang memenuhi semua kriteria persis. ';
            $answer = str_starts_with($fallbackMessage, 'genre_proxy:')
                ? 'Katalog belum memiliki judul berlabel animasi yang cocok. Berikut beberapa pilihan Family/Adventure terdekat berdasarkan rating'.($familyFriendly ? ', dengan batas ramah keluarga tetap dijaga' : '').'.'
                : $reference.'Berikut beberapa pilihan terdekat berdasarkan genre, rating, dan durasi'.($familyFriendly ? ', dengan batas ramah keluarga tetap dijaga' : '').'.';
        }
        if (! $isCount && $matches && ! $similarIntent && $sort !== 'best_match') {
            $answer .= ' Urutan: '.$this->sortLabel($sort).'.';
        }

        $hasSearchFilters = $genres || $excludeGenres || $platform || $excludePlatform || $country || $excludeCountry || $rating !== null || $year !== null || $yearMax !== null || $seasonRange['min'] !== null || $seasonRange['max'] !== null;
        $canRepresentAllFilters = ! $excludeGenres && ! $excludePlatform && ! $excludeCountry && ! $shortEpisodes && ! $endedOnly && ! $westernOnly && ! $familyFriendly && ! $ratingExclusive
            && in_array($sort, ['best_match', 'rating_desc', 'newest', 'oldest'], true);
        $searchUrl = $filterRequest && $hasSearchFilters && $canRepresentAllFilters
            ? $this->searchUrl($genres, $platform, $country, $rating, $year, $yearInclusive, $yearMax, $yearMaxInclusive, $seasonRange, $sort)
            : null;

        return [
            'answer' => $answer,
            'shows' => array_map(fn (array $show) => $this->card(
                $show,
                $plotAlternative
                    ? 'Premis mirip, tetapi tidak memenuhi semua kriteria'
                    : ($fallbackMessage !== null
                    ? $this->alternativeReason($show)
                    : $this->reasonFor($show, $criteria, $similarIntent ? $title : null, $moods))
            ), $results),
            'searchUrl' => $searchUrl,
            'state' => $state,
        ];
    }

    private function emptyState(): array
    {
        return [
            'genres' => [],
            'excludeGenres' => [],
            'platform' => null,
            'excludePlatform' => [],
            'country' => [],
            'excludeCountry' => [],
            'rating' => null,
            'ratingExclusive' => false,
            'year' => null,
            'yearInclusive' => true,
            'yearMax' => null,
            'yearMaxInclusive' => true,
            'seasonRange' => ['min' => null, 'max' => null],
            'endedOnly' => false,
            'westernOnly' => false,
            'shortEpisodes' => false,
            'durationMax' => null,
            'familyFriendly' => false,
            'sort' => 'best_match',
        ];
    }

    private function normalizeState(array $state): array
    {
        return array_replace($this->emptyState(), $state, [
            'genres' => array_values((array) ($state['genres'] ?? [])),
            'excludeGenres' => array_values((array) ($state['excludeGenres'] ?? [])),
            'excludePlatform' => array_values((array) ($state['excludePlatform'] ?? [])),
            'country' => array_values((array) ($state['country'] ?? [])),
            'excludeCountry' => array_values((array) ($state['excludeCountry'] ?? [])),
            'seasonRange' => array_replace(
                ['min' => null, 'max' => null],
                (array) ($state['seasonRange'] ?? [])
            ),
        ]);
    }

    private function mergeState(array $state, array $parsed, string $query): array
    {
        $clear = [
            'genres' => '/\b(?:hapus|hilangkan|remove|clear|tidak jadi|jangan pakai|batal(?:kan)?)\s+(?:filter\s+)?(?:genre|genres)\b/u',
            'platform' => '/\b(?:hapus|hilangkan|remove|clear|tidak jadi|jangan pakai|batal(?:kan)?)\s+(?:filter\s+)?platform\b/u',
            'country' => '/\b(?:hapus|hilangkan|remove|clear|tidak jadi|jangan pakai|batal(?:kan)?)\s+(?:filter\s+)?(?:negara|country)\b/u',
            'rating' => '/\b(?:hapus|hilangkan|remove|clear|tidak jadi|jangan pakai|batal(?:kan)?)\s+(?:filter\s+)?rating\b/u',
            'year' => '/\b(?:hapus|hilangkan|remove|clear|tidak jadi|jangan pakai|batal(?:kan)?)\s+(?:filter\s+)?(?:tahun|rilis)\b/u',
            'seasonRange' => '/\b(?:hapus|hilangkan|remove|clear|tidak jadi|jangan pakai|batal(?:kan)?)\s+(?:filter\s+)?(?:season|musim)\b/u',
            'shortEpisodes' => '/\b(?:hapus|hilangkan|remove|clear|tidak jadi|jangan pakai|batal(?:kan)?)\s+(?:filter\s+)?(?:durasi|episode pendek)\b/u',
            'durationMax' => '/\b(?:hapus|hilangkan|remove|clear|tidak jadi|jangan pakai|batal(?:kan)?)\s+(?:filter\s+)?(?:durasi|runtime|episode pendek)\b/u',
            'familyFriendly' => '/\b(?:hapus|hilangkan|remove|clear|tidak jadi|jangan pakai|batal(?:kan)?)\s+(?:filter\s+)?(?:ramah keluarga|family friendly|semua orang)\b/u',
            'endedOnly' => '/\b(?:hapus|hilangkan|remove|clear|tidak jadi|jangan pakai|batal(?:kan)?)\s+(?:filter\s+)?(?:status|tamat)\b/u',
            'westernOnly' => '/\b(?:hapus|hilangkan|remove|clear|tidak jadi|jangan pakai|batal(?:kan)?)\s+(?:filter\s+)?(?:wilayah|barat)\b/u',
        ];
        foreach ($clear as $key => $pattern) {
            if (preg_match($pattern, $query)) {
                $empty = $this->emptyState();
                $state[$key] = $empty[$key];
                if ($key === 'genres') {
                    $state['excludeGenres'] = [];
                } elseif ($key === 'platform') {
                    $state['excludePlatform'] = [];
                } elseif ($key === 'country') {
                    $state['excludeCountry'] = [];
                }
            }
        }

        if ($parsed['genres']) {
            $state['genres'] = $parsed['genres'];
            $state['excludeGenres'] = array_values(array_filter(
                $state['excludeGenres'],
                fn (string $genre) => ! in_array(mb_strtolower($genre), array_map('mb_strtolower', $parsed['genres']), true)
            ));
        }
        if ($parsed['excludeGenres']) {
            $state['excludeGenres'] = array_values(array_unique(array_merge($state['excludeGenres'], $parsed['excludeGenres'])));
        }
        if ($parsed['platform']) {
            $state['platform'] = $parsed['platform'];
            $state['excludePlatform'] = array_values(array_filter(
                $state['excludePlatform'],
                fn (string $platform) => mb_strtolower($platform) !== mb_strtolower($parsed['platform'])
            ));
        }
        if ($parsed['excludePlatform']) {
            $state['excludePlatform'] = array_values(array_unique(array_merge($state['excludePlatform'], $parsed['excludePlatform'])));
        }
        if ($parsed['country']) {
            $state['country'] = $parsed['country'];
            $state['excludeCountry'] = array_values(array_filter(
                $state['excludeCountry'],
                fn (string $country) => ! in_array(mb_strtolower($country), array_map('mb_strtolower', $parsed['country']), true)
            ));
        }
        if ($parsed['excludeCountry']) {
            $state['excludeCountry'] = array_values(array_unique(array_merge($state['excludeCountry'], $parsed['excludeCountry'])));
        }
        if ($state['platform'] && in_array(mb_strtolower($state['platform']), array_map('mb_strtolower', $state['excludePlatform']), true)) {
            $state['platform'] = null;
        }
        $state['country'] = array_values(array_filter(
            $state['country'],
            fn (string $country) => ! in_array(mb_strtolower($country), array_map('mb_strtolower', $state['excludeCountry']), true)
        ));
        if ($parsed['rating'] !== null) {
            $state['rating'] = $parsed['rating'];
            $state['ratingExclusive'] = $parsed['ratingExclusive'];
        }
        if ($parsed['year'] !== null || $parsed['yearMax'] !== null) {
            $state['year'] = $parsed['year'];
            $state['yearInclusive'] = $parsed['yearInclusive'];
            $state['yearMax'] = $parsed['yearMax'];
            $state['yearMaxInclusive'] = $parsed['yearMaxInclusive'];
        }
        if ($parsed['seasonRange']['min'] !== null || $parsed['seasonRange']['max'] !== null) {
            $state['seasonRange'] = $parsed['seasonRange'];
        }
        foreach (['endedOnly', 'westernOnly', 'shortEpisodes', 'familyFriendly'] as $key) {
            if ($parsed[$key]) {
                $state[$key] = true;
            }
        }
        if ($parsed['durationMax'] !== null) {
            $state['durationMax'] = $parsed['durationMax'];
            $state['shortEpisodes'] = true;
        }
        if ($parsed['sort']) {
            $state['sort'] = $parsed['sort'];
        }

        return $state;
    }

    private function uniqueShows(array $shows): array
    {
        $unique = [];
        foreach ($shows as $index => $show) {
            $key = trim((string) ($show['id'] ?? ''));
            if ($key === '') {
                $key = mb_strtolower(trim((string) ($show['name'] ?? '')));
            }
            if ($key === '') {
                $key = 'row-'.$index;
            }
            if (! array_key_exists($key, $unique)) {
                $unique[$key] = $show;
            }
        }

        return array_values($unique);
    }

    private function normalizeQuery(string $message): string
    {
        $query = mb_strtolower(trim($message));
        $replacements = [
            'nggak' => 'tidak',
            'gak' => 'tidak',
            'ga' => 'tidak',
            'enggak' => 'tidak',
            'acar' => 'acara',
            'aanak' => 'anak',
            'dibawha' => 'di bawah',
            'dibawah' => 'di bawah',
            'meni' => 'menit',
            'komde' => 'komedi',
            'pny' => 'punya',
            'bgs' => 'bagus',
            'aja' => 'saja',
            'dong' => '',
            'sih' => '',
        ];

        foreach ($replacements as $from => $to) {
            $query = preg_replace('/(?<![\pL\pN])'.preg_quote($from, '/').'(?![\pL\pN])/u', $to, $query) ?? $query;
        }

        $query = preg_replace('/\s+/', ' ', $query);

        return trim($query);
    }

    private function isFamilyFriendlyIntent(string $query): bool
    {
        $intent = '/\b(?:family[\s-]?friendly|untuk anak(?:-anak)?|anak-anak|anak(?:-anak)?\s+di bawah umur|di bawah umur|anak\s+(?:usia\s+)?(?:sd|sekolah dasar)|balita|umur\s+\d+\s+tahun|usia\s+\d+\s+tahun|(?:ditonton|ditonton bersama)\s+(?:oleh\s+)?anak(?:-anak)?|ramah anak|ramah keluarga|(?:buat|untuk)\s+(?:ditonton|tontonan|nonton)\s+keluarga|santai bareng keluarga|nonton bersama keluarga|ditonton bersama keluarga|animasi ringan|semua orang|semua umur|segala usia|siapa saja)\b/u';
        if (! preg_match($intent, $query)) {
            return false;
        }

        return ! preg_match('/\b(?:bukan|tidak|jangan|tanpa|not|non)\s+(?:yang\s+)?(?:family[\s-]?friendly|ramah anak|ramah keluarga|untuk anak(?:-anak)?|anak-anak|semua orang|semua umur|segala usia|siapa saja)\b/u', $query);
    }

    private function hasConflictingMatureFamilyIntent(string $query): bool
    {
        return preg_match('/\b(?:brutal|dewas\w*|adult|mafia|tembak(?:-tembakan)?|shoot(?:ing)?|perang|war|gore|sadis|vulgar|makian kasar|seksual|sexual)\b/u', $query) === 1;
    }

    private function shouldStartFreshSearch(string $query, array $state, array $genres, ?array $title, bool $similarIntent): bool
    {
        if (! $this->hasActiveFilters($state)) {
            return false;
        }

        $refinement = preg_match('/\b(?:yang tadi|hasil tadi|pencarian tadi|kalau yang|kalau ada|tambahkan|tambah|ganti|ubah|hapus|hilangkan|jangan pakai|tidak jadi|bukan itu|versi lain|juga|lebih|lanjutkan)\b/u', $query) === 1;
        if ($refinement) {
            return false;
        }

        if ($this->isFamilyFriendlyIntent($query)) {
            return true;
        }

        $newSearch = preg_match('/\b(?:cari(?:in)?|carikan|rekomendasikan|rekomendasi|tampilkan|tunjukkan|aku mau|saya mau|ada rekomendasi)\b/u', $query) === 1;
        if ($newSearch) {
            return true;
        }

        $previousGenres = array_map('mb_strtolower', $state['genres']);
        $incomingGenres = array_map('mb_strtolower', $genres);
        if ($incomingGenres && (! $previousGenres || ! array_intersect($incomingGenres, $previousGenres))) {
            return true;
        }

        return $similarIntent || (bool) ($title && $state['familyFriendly'] && ! $this->isFamilyFriendlyIntent($query));
    }

    private function hasActiveFilters(array $state): bool
    {
        return (bool) (
            $state['genres'] || $state['excludeGenres'] || $state['platform'] || $state['excludePlatform']
            || $state['country'] || $state['excludeCountry'] || $state['rating'] !== null
            || $state['year'] !== null || $state['yearMax'] !== null
            || $state['seasonRange']['min'] !== null || $state['seasonRange']['max'] !== null
            || $state['endedOnly'] || $state['westernOnly'] || $state['shortEpisodes']
            || $state['familyFriendly']
        );
    }

    private function isFamilyFriendlyShow(array $show): bool
    {
        return $this->familyFriendlyExclusionReason($show) === null;
    }

    private function familyFriendlyExclusionReason(array $show): ?string
    {
        $contentRating = mb_strtoupper(trim((string) ($show['contentRating'] ?? '')));
        $contentRating = preg_replace('/\s+/', '', $contentRating) ?? $contentRating;
        if (! in_array($contentRating, self::FAMILY_CONTENT_RATINGS, true)) {
            return $contentRating === ''
                ? 'content rating tidak tersedia'
                : "content rating {$contentRating} tidak termasuk rating yang diizinkan";
        }

        $genres = trim((string) ($show['genre'] ?? ''));
        $keywords = array_values(array_filter(array_map(
            static fn ($keyword): string => trim((string) $keyword),
            (array) ($show['keywords'] ?? [])
        )));
        $overview = trim((string) ($show['description'] ?? ''));
        $metadata = mb_strtolower(implode(' ', [$genres, implode(' ', $keywords), $overview]));
        if ($genres === '' || $keywords === [] || $overview === '') {
            return 'genre, keyword, atau overview katalog belum lengkap untuk memeriksa konten';
        }

        $metadata .= ' '.mb_strtolower((string) ($show['name'] ?? ''));
        foreach (self::FAMILY_BLOCKED_TERMS as $term) {
            if (preg_match('/(?<![\pL\pN])'.preg_quote($term, '/').'(?![\pL\pN])/u', $metadata)) {
                return "metadata katalog memuat peringatan konten \"{$term}\"";
            }
        }
        foreach (self::FAMILY_PREFERENCE_EXCLUDED_TERMS as $term) {
            if (preg_match('/(?<![\pL\pN])'.preg_quote($term, '/').'(?![\pL\pN])/u', $metadata)) {
                return 'metadata memuat topik yang dikecualikan pada preferensi keluarga ini';
            }
        }

        return null;
    }

    private function findTitle(string $query, array $shows): ?array
    {
        $found = null;
        foreach ($shows as $show) {
            $name = mb_strtolower((string) ($show['name'] ?? ''));
            $titlePattern = '/(?<![\pL\pN])'.preg_quote($name, '/').'(?![\pL\pN])/u';
            if ($name !== '' && preg_match($titlePattern, $query) && (! $found || mb_strlen($name) > mb_strlen($found['name']))) {
                $found = $show;
            }
        }

        return $found;
    }

    private function isOutOfScopeTechnicalQuestion(string $query): bool
    {
        return preg_match('/\b(?:gimana|bagaimana|cara|tutorial|setting|mengatur|install|instal|konfigurasi|configure)\b/u', $query) === 1
            && preg_match('/\b(?:postgres(?:ql)?|xampp|database|basis data|phpmyadmin|coding|programming|pemrograman)\b/u', $query) === 1;
    }

    private function findPremiseTitle(string $query, array $shows): ?array
    {
        $breakingBadPremise = '/\b(?:heisenberg|guru kimia|chemistry teacher|masak sabu|memasak sabu|produksi sabu|gembong narkoba|meth cook(?:ing)?)\b/u';
        if (preg_match($breakingBadPremise, $query)) {
            return $this->findTitle('breaking bad', $shows);
        }

        $strangerThingsClues = [
            '/\b(?:remaja|anak-anak|sekelompok anak)\b/u',
            '/\b(?:kota kecil|small town)\b/u',
            '/\b(?:teman(?:nya)? hilang|menghilang|anak hilang|missing friend|goes missing)\b/u',
            '/\b(?:monster|makhluk dari dimensi|dimensi lain|another dimension|upside down)\b/u',
            '/\b(?:tahun 80-an|1980-an|eighties|1980s)\b/u',
        ];
        $matchedClues = count(array_filter(
            $strangerThingsClues,
            fn (string $pattern) => preg_match($pattern, $query) === 1
        ));
        if ($matchedClues >= 3) {
            return $this->findTitle('stranger things', $shows);
        }

        return null;
    }

    private function findMentionedActors(string $query, array $shows): array
    {
        $mentioned = [];
        foreach ($shows as $show) {
            foreach ($this->list((string) ($show['actor'] ?? '')) as $actor) {
                if ($actor !== '' && preg_match('/(?<![\pL\pN])'.preg_quote(mb_strtolower($actor), '/').'(?![\pL\pN])/u', $query)) {
                    $mentioned[mb_strtolower($actor)] = $actor;
                }
            }
        }

        return array_values($mentioned);
    }

    private function findExcludedTitles(string $query, array $shows): array
    {
        $excluded = [];
        foreach ($shows as $show) {
            $name = trim((string) ($show['name'] ?? ''));
            if ($name === '' || ! preg_match_all(
                '/(?<![\pL\pN])'.preg_quote(mb_strtolower($name), '/').'(?![\pL\pN])/u',
                $query,
                $matches,
                PREG_OFFSET_CAPTURE
            )) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $prefix = substr($query, 0, $match[1]);
                if (preg_match('/\b(?:bukan|jangan|tidak mau|tidak ingin|selain|kecuali|not|except)(?:\s+[\pL\pN-]+){0,5}\s*$/u', $prefix)) {
                    $excluded[(string) ($show['id'] ?? $name)] = $show;
                    break;
                }
            }
        }

        return array_values($excluded);
    }

    private function respondToExcludedActorTitles(array $actors, array $excludedTitles, array $genres, array $shows): array
    {
        $actorShows = array_values(array_filter($shows, fn (array $show) => $this->intersects(
            $this->list((string) ($show['actor'] ?? '')),
            $actors
        )));
        $excludedIds = array_map(fn (array $show) => (string) ($show['id'] ?? ''), $excludedTitles);
        $remaining = array_values(array_filter(
            $actorShows,
            fn (array $show) => ! in_array((string) ($show['id'] ?? ''), $excludedIds, true)
        ));
        $genreMatches = array_values(array_filter($remaining, function (array $show) use ($genres): bool {
            $showGenres = array_map('mb_strtolower', $this->list((string) ($show['genre'] ?? '')));

            return ! $genres || count(array_intersect(array_map('mb_strtolower', $genres), $showGenres)) === count($genres);
        }));
        usort($genreMatches, fn (array $a, array $b) => (float) ($b['aggregateRating'] ?? 0) <=> (float) ($a['aggregateRating'] ?? 0));
        usort($remaining, fn (array $a, array $b) => (float) ($b['aggregateRating'] ?? 0) <=> (float) ($a['aggregateRating'] ?? 0));
        $results = $genreMatches ?: $remaining;
        $actorLabel = implode(', ', $actors);
        $catalogTitles = array_map(
            fn (array $show) => $show['name'].' ('.implode(', ', $this->list((string) ($show['genre'] ?? ''))).')',
            $actorShows
        );
        $answer = $catalogTitles
            ? "Di katalog, {$actorLabel} tercatat membintangi ".implode(' dan ', $catalogTitles).'. '
            : "Data serial {$actorLabel} di katalog belum tersedia. ";

        if ($genres && ! $genreMatches) {
            $answer .= 'Setelah judul yang kamu kecualikan dihapus, tidak ada serial dengan genre '.implode('/', $genres).' yang cocok.';
            if ($results) {
                $answer .= ' Alternatif dengan genre berbeda: '.implode(', ', array_map(
                    fn (array $show) => $show['name'].' ('.implode(', ', $this->list((string) ($show['genre'] ?? ''))).')',
                    array_slice($results, 0, 4)
                )).'.';
            }
        } elseif ($results) {
            $answer .= count($results) === 1
                ? ' Berdasarkan data katalog, hanya serial ini yang tersisa setelah pengecualian'.($genres ? ' dengan genre '.implode('/', $genres) : '').':'
                : ' Selain judul yang dikecualikan, berikut serial yang cocok'.($genres ? ' dengan genre '.implode('/', $genres) : '').':';
        } else {
            $answer .= 'Tidak ada serial lain yang cocok setelah judul tersebut dikecualikan.';
        }

        return [
            'answer' => $answer,
            'shows' => array_map(
                fn (array $show) => $this->card(
                    $show,
                    $genres && ! $genreMatches
                        ? 'Alternatif genre berbeda: '.implode(', ', $this->list((string) ($show['genre'] ?? '')))
                        : 'Dibintangi '.$actorLabel
                ),
                array_slice($results, 0, 4)
            ),
            'searchUrl' => null,
            'state' => $this->emptyState(),
        ];
    }

    private function respondToRelatedCastExcludingTitle(array $title, array $excludedTitles, array $genres, array $shows): array
    {
        $titleCast = $this->list((string) ($title['actor'] ?? ''));
        $excludedIds = array_map(fn (array $show) => (string) ($show['id'] ?? ''), $excludedTitles);
        $related = array_values(array_filter($shows, function (array $show) use ($title, $titleCast, $excludedIds): bool {
            return (string) ($show['id'] ?? '') !== (string) ($title['id'] ?? '')
                && ! in_array((string) ($show['id'] ?? ''), $excludedIds, true)
                && $this->intersects($this->list((string) ($show['actor'] ?? '')), $titleCast);
        }));
        $genreMatches = array_values(array_filter($related, function (array $show) use ($genres): bool {
            $showGenres = array_map('mb_strtolower', $this->list((string) ($show['genre'] ?? '')));

            return ! $genres || count(array_intersect(array_map('mb_strtolower', $genres), $showGenres)) === count($genres);
        }));
        usort($genreMatches, fn (array $a, array $b) => (float) ($b['aggregateRating'] ?? 0) <=> (float) ($a['aggregateRating'] ?? 0));
        usort($related, fn (array $a, array $b) => (float) ($b['aggregateRating'] ?? 0) <=> (float) ($a['aggregateRating'] ?? 0));
        $results = $genreMatches ?: $related;
        $excludedNames = implode(', ', array_column($excludedTitles, 'name'));
        $answer = "Aku mengecualikan {$excludedNames} sesuai permintaan. Katalog tidak mencatat peran utama, jadi aku mencocokkan serial lain berdasarkan pemeran yang sama dengan {$title['name']}. ";

        if ($genres && ! $genreMatches) {
            $answer .= 'Tidak ada kecocokan pemeran lain dengan genre '.implode('/', $genres).' di katalog.';
            if ($results) {
                $answer .= ' Alternatif terdekat berdasarkan pemeran yang sama memiliki genre berbeda: '.implode(', ', array_map(
                    fn (array $show) => $show['name'].' ('.implode(', ', $this->list((string) ($show['genre'] ?? ''))).')',
                    array_slice($results, 0, 4)
                )).'.';
            }
        } elseif (count($results) === 1) {
            $answer .= 'Berdasarkan data yang tersedia, hanya ada satu serial lain yang cocok'.($genres ? ' dengan genre '.implode('/', $genres) : '').':';
        } elseif ($results) {
            $answer .= 'Berikut serial lain yang cocok'.($genres ? ' dengan genre '.implode('/', $genres) : '').':';
        } else {
            $answer .= 'Aku tidak menemukan serial lain dengan pemeran yang sama di katalog.';
        }

        return [
            'answer' => $answer,
            'shows' => array_map(
                fn (array $show) => $this->card(
                    $show,
                    $genres && ! $genreMatches
                        ? 'Alternatif pemeran sama; genre berbeda: '.implode(', ', $this->list((string) ($show['genre'] ?? '')))
                        : 'Pemeran sama dengan '.$title['name']
                ),
                array_slice($results, 0, 4)
            ),
            'searchUrl' => null,
            'state' => $this->emptyState(),
        ];
    }

    private function findSimilarityReference(string $query): ?string
    {
        if (! preg_match('/\b(?:mirip|seperti|similar(?:\s+to)?|like)\s+(.+?)(?=\s+(?:dengan|yang|dan|tapi|namun|rating|durasi|genre|negara|country|di atas|lebih dari|rilis|platform)\b|[?.!,]|$)/u', $query, $match)) {
            return null;
        }

        $reference = trim($match[1], " \t\n\r\0\x0B\"'");

        return $reference !== '' ? $reference : null;
    }

    private function sortUnknownSimilar(array &$shows, ?string $reference, array $genres, array $moods): void
    {
        $referenceTokens = preg_split('/[^\pL\pN]+/u', mb_strtolower((string) $reference), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $referenceTokens = array_values(array_diff($referenceTokens, ['a', 'an', 'the', 'show', 'series', 'serial', 'tv']));
        usort($shows, function (array $a, array $b) use ($referenceTokens, $genres, $moods): int {
            $score = function (array $show) use ($referenceTokens, $genres, $moods): array {
                $text = mb_strtolower(implode(' ', [
                    (string) ($show['name'] ?? ''),
                    (string) ($show['genre'] ?? ''),
                    implode(' ', (array) ($show['keywords'] ?? [])),
                    (string) ($show['description'] ?? ''),
                ]));
                $referenceOverlap = 0;
                foreach ($referenceTokens as $token) {
                    if (mb_strlen($token) > 2 && preg_match('/(?<![\pL\pN])'.preg_quote($token, '/').'(?![\pL\pN])/u', $text)) {
                        $referenceOverlap++;
                    }
                }
                $showGenres = array_map('mb_strtolower', $this->list($show['genre'] ?? ''));
                $genreOverlap = count(array_intersect(array_map('mb_strtolower', $genres), $showGenres));

                return [$referenceOverlap, $genreOverlap, $this->moodScore($show, $moods), (float) ($show['aggregateRating'] ?? 0)];
            };

            return $score($b) <=> $score($a);
        });
    }

    private function findGenres(string $query, array $shows): array
    {
        $available = [];
        foreach ($shows as $show) {
            foreach ($this->list($show['genre'] ?? '') as $genre) {
                $available[mb_strtolower($genre)] = $genre;
            }
        }
        $aliases = [
            'animasi' => 'animation', 'kartun' => 'animation',
            'dokumenter' => 'documentary',
            'horor' => 'horror', 'seram' => 'horror',
            'komedi' => 'comedy', 'sitkom' => 'comedy', 'sitcom' => 'comedy', 'lucu' => 'comedy', 'funny' => 'comedy',
            'drama' => 'drama', 'fiksi ilmiah' => 'sci-fi', 'sci-fi' => 'sci-fi',
            'thriller' => 'thriller', 'tegang' => 'thriller', 'misteri' => 'mystery',
            'crime' => 'crime', 'kriminal' => 'crime', 'fantasi' => 'fantasy',
            'aksi' => 'action', 'action' => 'action', 'romansa' => 'romance', 'romantis' => 'romance',
            'romantic comedy' => 'comedy', 'rom-com' => 'comedy',
            'bikin tegang' => 'thriller', 'yang lucu' => 'comedy',
        ];
        $found = [];
        foreach ($aliases as $alias => $genre) {
            if (preg_match('/(?<![\pL\pN])'.preg_quote($alias, '/').'(?![\pL\pN])/u', $query)) {
                $found[mb_strtolower($available[$genre] ?? ucfirst($genre))] = $available[$genre] ?? ucfirst($genre);
            }
        }
        $tokens = preg_split('/[^\pL\pN-]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $fuzzyAliases = [
            'comedy' => ['comedy', 'komedi'],
            'animation' => ['animation', 'animasi'],
            'mystery' => ['mystery', 'misteri'],
            'romance' => ['romance', 'romansa'],
            'fantasy' => ['fantasy', 'fantasi'],
            'horror' => ['horror', 'horor'],
            'action' => ['action', 'aksi'],
            'thriller' => ['thriller'],
            'drama' => ['drama'],
            'crime' => ['crime', 'kriminal'],
            'sci-fi' => ['sci-fi', 'scifi', 'sciencefiction'],
        ];
        foreach ($tokens as $token) {
            foreach ($fuzzyAliases as $canonical => $variants) {
                foreach ($variants as $variant) {
                    $distance = levenshtein($token, $variant);
                    $maxDistance = max(mb_strlen($token), mb_strlen($variant)) >= 8 ? 2 : 1;
                    if (mb_strlen($token) >= 5 && $distance > 0 && $distance <= $maxDistance && $distance / max(mb_strlen($token), mb_strlen($variant)) <= 0.2) {
                        $genre = $available[$canonical] ?? ucfirst($canonical);
                        $found[mb_strtolower($genre)] = $genre;
                    }
                }
            }
        }
        foreach ($available as $key => $genre) {
            if (preg_match('/(?<![\pL\pN])'.preg_quote($key, '/').'(?![\pL\pN])/u', $query)) {
                $found[$key] = $genre;
            }
        }
        foreach (['thriller', 'comedy', 'drama', 'horror', 'animation', 'crime', 'mystery', 'fantasy', 'action', 'romance', 'sci-fi'] as $genre) {
            if (preg_match('/(?<![\pL\pN])'.preg_quote($genre, '/').'(?![\pL\pN])/u', $query)) {
                $found[$genre] = $available[$genre] ?? ucfirst($genre);
            }
        }

        return array_values($found);
    }

    private function findExcludedGenres(string $query, array $shows): array
    {
        $genres = $this->findGenres($query, $shows);
        $excluded = [];
        foreach ($genres as $genre) {
            $name = preg_quote(mb_strtolower($genre), '/');
            if (preg_match(
                '/\b(?:tidak|jangan|tanpa|bukan|selain|exclude|except)\s+(?:mau\s+)?(?:yang\s+)?(?:(?:genre|bergenre)\s+)?'.$name.'(?![\pL\pN])/u',
                $query
            )) {
                $excluded[] = $genre;

                continue;
            }

            if (preg_match_all(
                '/\b(?:tidak|jangan|tanpa|bukan|selain|exclude|except)\s+(?:mau\s+)?(?:yang\s+)?(?:(?:genre|bergenre)\s+)?([^,.!?;]+)/u',
                $query,
                $clauses
            )) {
                foreach ($clauses[1] as $clause) {
                    if (preg_match('/(?<![\pL\pN])'.$name.'(?![\pL\pN])/u', $clause)) {
                        $excluded[] = $genre;
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($excluded));
    }

    private function isNegatedValue(string $query, string $value, array $aliases = []): bool
    {
        foreach (array_unique(array_merge([$value], $aliases)) as $candidate) {
            $pattern = '/\b(?:tidak|jangan|tanpa|bukan|selain|exclude|except)(?:\s+(?:mau|yang|dari|di|pakai|platform|negara|country)){0,3}\s+'.preg_quote(mb_strtolower($candidate), '/').'(?![\pL\pN])/u';
            if ($candidate !== '' && preg_match($pattern, $query)) {
                return true;
            }
        }

        return false;
    }

    private function findExcludedPlatform(string $query, array $shows): array
    {
        $platform = $this->findPlatform($query, $shows);

        return $platform && $this->isNegatedValue($query, $platform) ? [$platform] : [];
    }

    private function findExcludedCountries(string $query, array $shows): array
    {
        $countries = $this->findCountry($query, $shows);
        $excluded = [];
        foreach ($countries as $country) {
            $aliases = match (mb_strtolower($country)) {
                'south korea' => ['korea', 'korea selatan'],
                'united states' => ['amerika', 'amerika serikat', 'usa', 'us'],
                'united kingdom' => ['inggris', 'uk', 'british'],
                'china' => ['cina'],
                'japan' => ['jepang'],
                default => [],
            };
            if ($this->isNegatedValue($query, $country, $aliases)) {
                $excluded[] = $country;
            }
        }

        if (count($countries) > 1 && $this->isNegatedValue($query, 'asia', ['asian', 'asia timur', 'east asia'])) {
            return $countries;
        }
        if (count($countries) > 1 && $this->isNegatedValue($query, 'barat', ['western'])) {
            return $countries;
        }

        return $excluded;
    }

    private function findPlatform(string $query, array $shows): ?string
    {
        foreach ($shows as $show) {
            foreach ((array) ($show['platform'] ?? []) as $platform) {
                $name = mb_strtolower((string) $platform);
                if ($name !== '' && mb_strpos($query, $name) !== false) {
                    return (string) $platform;
                }
            }
        }

        return null;
    }

    private function findCountry(string $query, array $shows): array
    {
        if (preg_match('/\b(?:barat|western)\b/u', $query)) {
            return self::WESTERN_COUNTRIES;
        }
        if (preg_match('/\b(?:asia|asian|asia timur|east asia)\b/u', $query)) {
            return self::ASIAN_COUNTRIES;
        }
        $knownCountries = [
            'japan' => 'Japan', 'jepang' => 'Japan', 'korea selatan' => 'South Korea',
            'south korea' => 'South Korea', 'korea' => 'South Korea', 'china' => 'China',
            'cina' => 'China', 'turki' => 'Turkey', 'türkiye' => 'Turkey',
            'amerika' => 'United States', 'amerika serikat' => 'United States',
            'us' => 'United States', 'usa' => 'United States', 'united states' => 'United States',
            'inggris' => 'United Kingdom', 'uk' => 'United Kingdom', 'british' => 'United Kingdom',
            'kanada' => 'Canada', 'australia' => 'Australia', 'india' => 'India',
            'indonesia' => 'Indonesia', 'thailand' => 'Thailand', 'taiwan' => 'Taiwan',
        ];
        foreach ($knownCountries as $needle => $country) {
            if (preg_match('/(?<![\pL\pN])'.preg_quote($needle, '/').'(?![\pL\pN])/u', $query)) {
                return [$country];
            }
        }
        foreach ($shows as $show) {
            foreach ($this->list($show['countryOfOrigin'] ?? '') as $country) {
                if ($country !== '' && mb_strpos($query, mb_strtolower($country)) !== false) {
                    return [$country];
                }
            }
        }

        return [];
    }

    private function findRating(string $query): array
    {
        $number = '(\\d(?:[.,]\\d)?)(?!\\s*(?:season|seasons|musim|tahun|episode|episodes|menit|minutes|min\\b))';
        if (preg_match('/(?:rating|nilai|imdb)[^\\d]{0,25}'.$number.'/u', $query, $match)) {
            return [
                'value' => (float) str_replace(',', '.', $match[1]),
                'exclusive' => (bool) preg_match('/(?:di atas|lebih dari|above|over|greater than|>)/u', $match[0]),
            ];
        }
        if (preg_match('/(?:di atas|lebih dari|above|over|greater than|at least|minimal|>=|>)\\s*(?:rating\\s*)?'.$number.'/u', $query, $match)) {
            return [
                'value' => (float) str_replace(',', '.', $match[1]),
                'exclusive' => (bool) preg_match('/(?:di atas|lebih dari|above|over|greater than|>)/u', $match[0]),
            ];
        }
        if (preg_match('/\\b'.$number.'\\s*(?:ke atas|and above|\\+)/u', $query, $match)) {
            return ['value' => (float) str_replace(',', '.', $match[1]), 'exclusive' => false];
        }
        if (preg_match('/\b(?:rating|nilai|imdb)\b.{0,20}\b(?:bagus|baik|tinggi|good|high)\b/u', $query)) {
            return ['value' => 8.0, 'exclusive' => false];
        }

        return ['value' => null, 'exclusive' => false];
    }

    private function findDurationLimit(string $query): ?int
    {
        $number = '(\d{1,3})';
        if (preg_match('/(?:kurang dari|lebih sedikit dari|di bawah|less than|under|<)\s*'.$number.'\s*(?:menit|min(?:ute)?s?)\b/u', $query, $match)) {
            return max(0, (int) $match[1] - 1);
        }
        if (preg_match('/(?:maksimal|paling banyak|hingga|sampai|max(?:imum)?|at most|up to|<=)\s*'.$number.'\s*(?:menit|min(?:ute)?s?)\b/u', $query, $match)
            || preg_match('/(?:durasi|runtime|episode(?:nya)?|episod(?:e|enya)?)[^\d]{0,20}'.$number.'\s*(?:menit|min(?:ute)?s?)\b/u', $query, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    private function findSeasonRange(string $query): array
    {
        $range = ['min' => null, 'max' => null];
        $number = '(\\d{1,2})';

        if (preg_match('/(?:lebih dari|lebih banyak dari|more than|greater than|>)\\s*'.$number.'\\s*(?:season|seasons|musim)\\b/u', $query, $match)
            || preg_match('/(?:season|seasons|musim)[\\pL\\s-]{0,24}(?:lebih dari|lebih banyak dari|more than|greater than|>)\\s*'.$number.'/u', $query, $match)) {
            $range['min'] = (int) $match[1] + 1;
        } elseif (preg_match('/(?:minimal|setidaknya|at least|>=)\\s*'.$number.'\\s*(?:season|seasons|musim)\\b/u', $query, $match)
            || preg_match('/(?:season|seasons|musim)[\\pL\\s-]{0,24}(?:minimal|setidaknya|at least|>=)\\s*'.$number.'/u', $query, $match)) {
            $range['min'] = (int) $match[1];
        }

        if (preg_match('/(?:kurang dari|lebih sedikit dari|less than|fewer than|<)\\s*'.$number.'\\s*(?:season|seasons|musim)\\b/u', $query, $match)
            || preg_match('/(?:season|seasons|musim)[\\pL\\s-]{0,24}(?:kurang dari|lebih sedikit dari|less than|fewer than|<)\\s*'.$number.'/u', $query, $match)) {
            $range['max'] = max(0, (int) $match[1] - 1);
        } elseif (preg_match('/(?:maksimal|paling banyak|at most|<=)\\s*'.$number.'\\s*(?:season|seasons|musim)\\b/u', $query, $match)
            || preg_match('/(?:season|seasons|musim)[\\pL\\s-]{0,24}(?:maksimal|paling banyak|at most|<=)\\s*'.$number.'/u', $query, $match)) {
            $range['max'] = (int) $match[1];
        }

        return $range;
    }

    private function isEnded(string $status): bool
    {
        return in_array(mb_strtolower(trim($status)), ['ended', 'completed', 'finished', 'canceled', 'cancelled'], true);
    }

    private function matchesFilters(array $show, array $filters): bool
    {
        if (in_array((string) ($show['id'] ?? ''), $filters['excludeTitles'] ?? [], true)) {
            return false;
        }
        if ($filters['familyFriendly'] && ! $this->isFamilyFriendlyShow($show)) {
            return false;
        }
        $showGenres = array_map('mb_strtolower', $this->list($show['genre'] ?? ''));
        foreach ($filters['genres'] as $genre) {
            if (! in_array(mb_strtolower($genre), $showGenres, true)) {
                return false;
            }
        }
        foreach ($filters['excludeGenres'] as $genre) {
            if (in_array(mb_strtolower($genre), $showGenres, true)) {
                return false;
            }
        }
        if ($filters['platform'] && ! $this->intersects((array) ($show['platform'] ?? []), [$filters['platform']])) {
            return false;
        }
        if ($filters['excludePlatform'] && $this->intersects((array) ($show['platform'] ?? []), $filters['excludePlatform'])) {
            return false;
        }
        if ($filters['country'] && ! $this->intersects($this->list($show['countryOfOrigin'] ?? ''), $filters['country'])) {
            return false;
        }
        if ($filters['excludeCountry'] && $this->intersects($this->list($show['countryOfOrigin'] ?? ''), $filters['excludeCountry'])) {
            return false;
        }
        if ($filters['rating'] !== null) {
            $showRating = (float) ($show['aggregateRating'] ?? 0);
            if ($filters['ratingExclusive'] ? $showRating <= $filters['rating'] : $showRating < $filters['rating']) {
                return false;
            }
        }
        $showYear = (int) ($show['startDate'] ?? 0);
        if ($filters['year'] !== null) {
            if ($showYear < $filters['year'] || ($showYear === $filters['year'] && ! ($filters['yearInclusive'] ?? true))) {
                return false;
            }
        }
        if (($filters['yearMax'] ?? null) !== null) {
            if ($showYear > $filters['yearMax'] || ($showYear === $filters['yearMax'] && ! ($filters['yearMaxInclusive'] ?? true))) {
                return false;
            }
        }

        $seasons = (int) ($show['numberOfSeasons'] ?? 0);
        if ($filters['seasonRange']['min'] !== null && $seasons < $filters['seasonRange']['min']) {
            return false;
        }
        if ($filters['seasonRange']['max'] !== null && (! $seasons || $seasons > $filters['seasonRange']['max'])) {
            return false;
        }
        if ($filters['endedOnly'] && ! $this->isEnded($show['status'] ?? '')) {
            return false;
        }
        if ($filters['westernOnly'] && ! $this->intersects($this->list($show['countryOfOrigin'] ?? ''), self::WESTERN_COUNTRIES)) {
            return false;
        }
        if ($filters['shortEpisodes']) {
            $maxDuration = (int) ($filters['maxDuration'] ?? $filters['durationMax'] ?? 30);
            if (! ($show['duration'] ?? 0) || (int) $show['duration'] > $maxDuration) {
                return false;
            }
        }

        return true;
    }

    private function findFallbackResults(array $shows, array $filters, array $genres, array $moods): array
    {
        $relaxed = [];
        $findMatches = fn (array $candidateFilters): array => array_values(array_filter(
            $shows,
            fn (array $show) => $this->matchesFilters($show, $candidateFilters)
        ));
        $tryRelaxed = function (array $candidateFilters) use (&$relaxed, $findMatches): array {
            $matches = $findMatches($candidateFilters);
            if ($matches) {
                return [$matches, implode('; ', $relaxed)];
            }

            return [[], null];
        };
        $working = $filters;

        if ($working['rating'] !== null && $working['rating'] > 0.2) {
            $originalRating = (float) $working['rating'];
            for ($threshold = round($originalRating - 0.1, 1); $threshold >= round($originalRating - 0.2, 1); $threshold = round($threshold - 0.1, 1)) {
                $working['rating'] = $threshold;
                $working['ratingExclusive'] = false;
                [$matches, $message] = $tryRelaxed($working);
                if ($matches) {
                    $relaxed[] = 'rating';

                    return [$matches, implode('; ', $relaxed)];
                }
                if ($threshold <= round($originalRating - 0.2, 1)) {
                    break;
                }
            }
        }

        if ($working['shortEpisodes'] && $working['durationMax'] === null) {
            foreach ([40, 50] as $durationLimit) {
                $working['maxDuration'] = $durationLimit;
                [$matches] = $tryRelaxed($working);
                if ($matches) {
                    $relaxed[] = 'duration';

                    return [$matches, implode('; ', $relaxed)];
                }
            }
        }

        foreach ([
            ['year' => null, 'yearMax' => null],
            ['seasonRange' => ['min' => null, 'max' => null]],
            ['endedOnly' => false],
            ['westernOnly' => false],
        ] as $changes) {
            $wasActive = false;
            foreach ($changes as $key => $value) {
                $wasActive = $wasActive || ($working[$key] !== $value);
                $working[$key] = $value;
            }
            if (! $wasActive) {
                continue;
            }
            [$matches] = $tryRelaxed($working);
            if ($matches) {
                $relaxed[] = (string) array_key_first($changes);

                return [$matches, implode('; ', $relaxed)];
            }
        }

        if ($working['genres']) {
            $working['genres'] = [];
            [$matches] = $tryRelaxed($working);
            $matches = array_values(array_filter($matches, function (array $show) use ($genres): bool {
                $showGenres = array_map('mb_strtolower', $this->list($show['genre'] ?? ''));

                return (bool) array_intersect(array_map('mb_strtolower', $genres), $showGenres);
            }));
            if ($matches) {
                $this->sortByRequestedGenreOverlap($matches, $genres, $moods);
                $relaxed[] = 'genre';

                return [$matches, implode('; ', $relaxed)];
            }

            $genreAlternatives = $this->relatedGenresForMissingCatalogGenre($genres, $shows);
            if ($genreAlternatives) {
                $proxyFilters = $working;
                $proxyFilters['familyFriendly'] = true;
                $alternativeMatches = array_values(array_filter($findMatches($proxyFilters), function (array $show) use ($genreAlternatives): bool {
                    $showGenres = array_map('mb_strtolower', $this->list($show['genre'] ?? ''));

                    return (bool) array_intersect(array_map('mb_strtolower', $genreAlternatives), $showGenres);
                }));
                if ($alternativeMatches) {
                    $this->sortByRequestedGenreOverlap($alternativeMatches, $genreAlternatives, $moods);

                    return [$alternativeMatches, 'genre_proxy:'.implode(',', $genreAlternatives)];
                }
            }
        }

        return [[], null];
    }

    private function relatedGenresForMissingCatalogGenre(array $genres, array $shows): array
    {
        $substitutions = [
            'animation' => ['Family', 'Adventure'],
        ];
        $availableGenres = [];
        foreach ($shows as $show) {
            foreach ($this->list($show['genre'] ?? '') as $genre) {
                $availableGenres[mb_strtolower($genre)] = $genre;
            }
        }

        $related = [];
        foreach ($genres as $genre) {
            $key = mb_strtolower($genre);
            if (! isset($substitutions[$key]) || isset($availableGenres[$key])) {
                continue;
            }
            foreach ($substitutions[$key] as $alternative) {
                if (isset($availableGenres[mb_strtolower($alternative)])) {
                    $related[] = $availableGenres[mb_strtolower($alternative)];
                }
            }
        }

        return array_values(array_unique($related));
    }

    private function sortByRequestedGenreOverlap(array &$shows, array $genres, array $moods): void
    {
        usort($shows, function (array $a, array $b) use ($genres, $moods): int {
            $overlap = function (array $show) use ($genres): int {
                $showGenres = array_map('mb_strtolower', $this->list($show['genre'] ?? ''));

                return count(array_intersect(array_map('mb_strtolower', $genres), $showGenres));
            };
            $genreComparison = $overlap($b) <=> $overlap($a);
            if ($genreComparison !== 0) {
                return $genreComparison;
            }

            return ($this->moodScore($b, $moods) <=> $this->moodScore($a, $moods))
                ?: ((float) ($b['aggregateRating'] ?? 0) <=> (float) ($a['aggregateRating'] ?? 0));
        });
    }

    private function sortFallbackResults(array &$shows, array $filters, array $genres, array $moods): void
    {
        $cost = function (array $show) use ($filters, $genres, $moods): float {
            $showGenres = array_map('mb_strtolower', $this->list($show['genre'] ?? ''));
            $genreMisses = count(array_diff(array_map('mb_strtolower', $genres), $showGenres));
            $score = $genreMisses * 1000;

            if ($filters['rating'] !== null) {
                $rating = (float) ($show['aggregateRating'] ?? 0);
                $score += max(0, (float) $filters['rating'] - $rating) * 10;
            }
            if ($filters['shortEpisodes']) {
                $duration = (int) ($show['duration'] ?? 0);
                $durationLimit = (int) ($filters['durationMax'] ?? 30);
                $score += $duration > 0 ? max(0, $duration - $durationLimit) : 1000;
            }

            return $score - $this->moodScore($show, $moods) * 0.1 - (float) ($show['aggregateRating'] ?? 0) * 0.001;
        };
        usort($shows, fn (array $a, array $b) => $cost($a) <=> $cost($b));
    }

    private function alternativeReason(array $show): string
    {
        $genres = array_slice($this->list($show['genre'] ?? ''), 0, 2);
        $reason = $genres ? 'Genre '.implode(', ', $genres) : 'Pilihan katalog';
        $rating = (float) ($show['aggregateRating'] ?? 0);
        if ($rating > 0) {
            $reason .= ' - rating '.number_format($rating, 1);
        }

        return $reason;
    }

    private function findYearRange(string $query): array
    {
        $range = [
            'min' => null,
            'min_inclusive' => true,
            'max' => null,
            'max_inclusive' => true,
        ];

        if (preg_match('/(?:mulai|since|from|setelah|after|lebih dari|more than|greater than|>)\s*(20\d{2}|19\d{2})/u', $query, $match)) {
            $range['min'] = (int) $match[1];
            $range['min_inclusive'] = preg_match('/(?:mulai|since|from|>=|minimal|setidaknya|at least)/u', $match[0]) === 1 || preg_match('/\b(?:mulai|since|from)\b/u', $match[0]) === 1;
            if (preg_match('/(?:setelah|after|lebih dari|more than|greater than|>)/u', $match[0])) {
                $range['min_inclusive'] = false;
            }
        }

        if (preg_match('/(?:sebelum|before|prior to|<)\s*(20\d{2}|19\d{2})/u', $query, $match)) {
            $range['max'] = (int) $match[1];
            $range['max_inclusive'] = false;
        }

        if (preg_match('/\b(20\d{2}|19\d{2})\s*(?:ke atas|and above|onwards|hingga|sampai|up to|until)\b/u', $query, $match)) {
            $range['min'] = $range['min'] ?? (int) $match[1];
            $range['min_inclusive'] = true;
        }

        if (preg_match('/\b(?:hingga|sampai|until|up to|max(?:imum)?|paling banyak|maksimal)\s*(20\d{2}|19\d{2})\b/u', $query, $match)) {
            $range['max'] = (int) $match[1];
            $range['max_inclusive'] = true;
        }

        if (preg_match('/\b(20\d{2}|19\d{2})\s*(?:ke atas|and above|onwards)\b/u', $query, $match)) {
            $range['min'] = (int) $match[1];
            $range['min_inclusive'] = true;
        }

        return $range;
    }

    private function findYear(string $query): ?int
    {
        $range = $this->findYearRange($query);

        return $range['min'];
    }

    private function findMoods(string $query): array
    {
        $moods = [
            'tegang' => ['thriller', 'suspense', 'tense', 'crime'],
            'menegangkan' => ['thriller', 'suspense', 'tense', 'crime'],
            'tense' => ['thriller', 'suspense', 'tense', 'crime'],
            'thriller' => ['thriller', 'suspense', 'tense', 'crime'],
            'suspenseful' => ['thriller', 'suspense', 'tense'],
            'kelam' => ['dark', 'gritty', 'crime', 'thriller'],
            'gelap' => ['dark', 'gritty', 'crime', 'thriller'],
            'ringan' => ['comedy', 'family', 'romance', 'feel-good'],
            'santai' => ['comedy', 'family', 'romance', 'feel-good'],
            'misterius' => ['mystery', 'mysterious', 'suspense'],
            'seram' => ['horror', 'supernatural'],
            'romantis' => ['romance', 'romantic'],
            'epic' => ['fantasy', 'adventure', 'epic'],
            'futuristik' => ['sci-fi', 'science fiction', 'future'],
        ];
        $found = [];
        foreach ($moods as $label => $terms) {
            if (mb_strpos($query, $label) !== false) {
                $found[$label] = $terms;
            }
        }

        return $found;
    }

    private function findSort(string $query): ?string
    {
        if (preg_match('/\b(?:rating|nilai).{0,20}\b(?:terendah|paling rendah|ascending|asc)\b|\b(?:rating|nilai)\s*↑/u', $query)) {
            return 'rating_asc';
        }
        if (preg_match('/\b(?:tahun|rilis).{0,20}\b(?:terlama|paling lama|oldest)\b|\bjudul\s+a\s*(?:-|sampai|to)\s*z\b/u', $query)) {
            return preg_match('/\bjudul\b/u', $query) ? 'title_asc' : 'oldest';
        }
        if (preg_match('/\b(?:tahun|rilis).{0,20}\b(?:terbaru|paling baru|newest)\b/u', $query)) {
            return 'newest';
        }
        if (preg_match('/\b(?:rating|nilai).{0,20}\b(?:tertinggi|paling tinggi|bagus|terbaik|descending|desc)\b|\b(?:urutkan|urutin|sortir)\s+(?:berdasarkan\s+)?rating\b/u', $query)) {
            return 'rating_desc';
        }

        return null;
    }

    private function sortShows(array &$shows, string $sort, array $moods): void
    {
        usort($shows, function (array $a, array $b) use ($sort, $moods): int {
            $ratingComparison = ((float) ($b['aggregateRating'] ?? 0) <=> (float) ($a['aggregateRating'] ?? 0))
                ?: ((int) ($b['interactionCount'] ?? 0) <=> (int) ($a['interactionCount'] ?? 0));

            return match ($sort) {
                'rating_asc' => ((float) ($a['aggregateRating'] ?? 0) <=> (float) ($b['aggregateRating'] ?? 0))
                    ?: ((int) ($b['interactionCount'] ?? 0) <=> (int) ($a['interactionCount'] ?? 0)),
                'rating_desc' => $ratingComparison,
                'newest' => ((int) ($b['startDate'] ?? 0) <=> (int) ($a['startDate'] ?? 0)) ?: $ratingComparison,
                'oldest' => ((int) ($a['startDate'] ?? 9999) <=> (int) ($b['startDate'] ?? 9999)) ?: $ratingComparison,
                'title_asc' => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')),
                default => ($this->moodScore($b, $moods) <=> $this->moodScore($a, $moods)) ?: $ratingComparison,
            };
        });
    }

    private function sortLabel(string $sort): string
    {
        return match ($sort) {
            'rating_asc' => 'rating terendah',
            'newest' => 'tahun terbaru',
            'oldest' => 'tahun terlama',
            'title_asc' => 'judul A-Z',
            default => 'rating tertinggi',
        };
    }

    private function moodScore(array $show, array $moods): int
    {
        $terms = array_merge(...array_values($moods ?: [[]]));
        $text = mb_strtolower(implode(' ', [
            (string) ($show['genre'] ?? ''),
            implode(' ', (array) ($show['keywords'] ?? [])),
            (string) ($show['description'] ?? ''),
        ]));
        $score = 0;
        foreach (array_unique($terms) as $term) {
            if (mb_strpos($text, $term) !== false) {
                $score++;
            }
        }

        return $score;
    }

    private function castAnswer(array $title, array $shows): array
    {
        $cast = $this->list($title['actor'] ?? '');
        $related = array_values(array_filter($shows, fn (array $show) => $show['id'] !== $title['id'] && $this->intersects($this->list($show['actor'] ?? ''), $cast)
        ));
        usort($related, fn (array $a, array $b) => (float) ($b['aggregateRating'] ?? 0) <=> (float) ($a['aggregateRating'] ?? 0));
        $names = array_slice($cast, 0, 8);
        $answer = $names
            ? "Pemeran yang tercatat di {$title['name']}: ".implode(', ', $names).'.'
            : "Data pemeran {$title['name']} belum tersedia.";
        if ($related) {
            $answer .= ' Beberapa serial lain yang mereka bintangi: '.implode(', ', array_column(array_slice($related, 0, 5), 'name')).'.';
        }

        return [
            'answer' => $answer,
            'shows' => array_map(fn (array $show) => $this->card($show, 'Punya pemeran yang sama'), array_slice($related, 0, 5)),
            'searchUrl' => null,
        ];
    }

    private function creatorAnswer(array $title, array $shows): array
    {
        $creators = (array) ($title['author'] ?? []);
        $related = array_values(array_filter($shows, fn (array $show) => $show['id'] !== $title['id'] && $this->intersects((array) ($show['author'] ?? []), $creators)
        ));
        usort($related, fn (array $a, array $b) => (float) ($b['aggregateRating'] ?? 0) <=> (float) ($a['aggregateRating'] ?? 0));
        $answer = $creators
            ? "Pencipta yang tercatat untuk {$title['name']}: ".implode(', ', $creators).'.'
            : "Data pencipta {$title['name']} belum tersedia.";
        if ($related) {
            $answer .= ' Serial lain dari pencipta yang sama: '.implode(', ', array_column(array_slice($related, 0, 5), 'name')).'.';
        }

        return [
            'answer' => $answer,
            'shows' => array_map(fn (array $show) => $this->card($show, 'Pencipta yang sama'), array_slice($related, 0, 5)),
            'searchUrl' => null,
        ];
    }

    private function rankSimilar(array $title, array $matches, array $moods): array
    {
        $anchorGenres = array_map('mb_strtolower', $this->list($title['genre'] ?? ''));
        $anchorCast = array_map('mb_strtolower', $this->list($title['actor'] ?? ''));
        usort($matches, function (array $a, array $b) use ($anchorGenres, $anchorCast, $moods): int {
            $score = fn (array $show) => count(array_intersect($anchorGenres, array_map('mb_strtolower', $this->list($show['genre'] ?? '')))) * 2
                + count(array_intersect($anchorCast, array_map('mb_strtolower', $this->list($show['actor'] ?? '')))) * 3
                + $this->moodScore($show, $moods);

            return ($score($b) <=> $score($a)) ?: ((float) ($b['aggregateRating'] ?? 0) <=> (float) ($a['aggregateRating'] ?? 0));
        });

        return $matches;
    }

    private function recommendationAnswer(array $results, array $criteria, ?array $title): string
    {
        if ($title && $results) {
            return "Kalau suka {$title['name']}, coba serial berikut. Aku mengurutkannya berdasarkan kemiripan genre/pemeran dan rating.";
        }
        $reason = $criteria ? 'Aku pilih yang cocok dengan '.implode(', ', $criteria) : 'Berikut pilihan dengan rating terbaik di katalog';

        return $reason.':';
    }

    private function reasonFor(array $show, array $criteria, ?array $title, array $moods): string
    {
        if ($moods && $this->moodScore($show, $moods) > 0) {
            return 'Vibe '.implode('/', array_keys($moods));
        }
        if ($title) {
            $sharedGenres = array_intersect(
                array_map('mb_strtolower', $this->list($title['genre'] ?? '')),
                array_map('mb_strtolower', $this->list($show['genre'] ?? ''))
            );
            if ($sharedGenres) {
                return 'Genre serupa: '.implode(', ', array_slice($sharedGenres, 0, 2));
            }
        }
        if ($criteria) {
            return 'Cocok: '.implode(', ', $criteria);
        }

        return 'Rating '.number_format((float) ($show['aggregateRating'] ?? 0), 1);
    }

    private function criteria(array $genres, array $excludeGenres, ?string $platform, array $excludePlatform, array $country, array $excludeCountry, ?float $rating, bool $ratingExclusive, ?int $year, bool $yearInclusive, ?int $yearMax, bool $yearMaxInclusive, array $seasonRange, bool $endedOnly, bool $westernOnly, bool $shortEpisodes, ?int $durationMax, array $moods): array
    {
        $criteria = [];
        if ($genres) {
            $criteria[] = 'genre '.implode('/', $genres);
        }
        if ($excludeGenres) {
            $criteria[] = 'tanpa genre '.implode('/', $excludeGenres);
        }
        if ($platform) {
            $criteria[] = "platform {$platform}";
        }
        if ($excludePlatform) {
            $criteria[] = 'bukan platform '.implode('/', $excludePlatform);
        }
        if ($country) {
            $criteria[] = count($country) > 1
                ? (in_array('Japan', $country, true) ? 'Asia' : 'wilayah yang dipilih')
                : 'asal '.$country[0];
        }
        if ($excludeCountry) {
            $criteria[] = 'bukan asal '.implode('/', $excludeCountry);
        }
        if ($rating !== null) {
            $criteria[] = $ratingExclusive ? "rating di atas {$rating}" : "rating minimal {$rating}";
        }
        if ($year !== null && $yearMax !== null) {
            $criteria[] = $yearInclusive ? 'rilis mulai '.$year : 'rilis setelah '.$year;
            $criteria[] = $yearMaxInclusive ? 'hingga '.$yearMax : 'sebelum '.$yearMax;
        } elseif ($year !== null) {
            $criteria[] = $yearInclusive ? 'rilis mulai '.$year : 'rilis setelah '.$year;
        } elseif ($yearMax !== null) {
            $criteria[] = $yearMaxInclusive ? 'rilis hingga '.$yearMax : 'rilis sebelum '.$yearMax;
        }
        if ($seasonRange['min'] !== null && $seasonRange['max'] !== null && $seasonRange['min'] === $seasonRange['max']) {
            $criteria[] = $seasonRange['min'].' season';
        } elseif ($seasonRange['min'] !== null) {
            $criteria[] = 'minimal '.$seasonRange['min'].' season';
        } elseif ($seasonRange['max'] !== null) {
            $criteria[] = 'maksimal '.$seasonRange['max'].' season';
        }
        if ($endedOnly) {
            $criteria[] = 'sudah tamat';
        }
        if ($westernOnly) {
            $criteria[] = 'serial barat';
        }
        if ($shortEpisodes) {
            $criteria[] = 'episode maksimal '.($durationMax ?? 30).' menit';
        }
        if ($moods) {
            $criteria[] = 'vibe '.implode('/', array_keys($moods));
        }

        return $criteria;
    }

    private function searchUrl(array $genres, ?string $platform, array $country, ?float $rating, ?int $year, bool $yearInclusive, ?int $yearMax, bool $yearMaxInclusive, array $seasonRange, string $sort): string
    {
        $params = [];
        if ($genres) {
            $params['genre'] = implode('|', $genres);
        }
        if ($platform) {
            $params['platform'] = $platform;
        }
        if ($country) {
            $params['country'] = implode('|', $country);
        }
        if ($rating !== null) {
            $params['rating'] = $rating;
        }
        if ($year !== null) {
            $params['from'] = $year + ($yearInclusive ? 0 : 1);
        }
        if ($yearMax !== null) {
            $params['to'] = $yearMax + ($yearMaxInclusive ? 0 : -1);
        }
        if ($seasonRange['min'] !== null) {
            $params['seasons'] = $seasonRange['min'];
        }
        if ($seasonRange['max'] !== null) {
            $params['smax'] = $seasonRange['max'];
        }
        $params['sort'] = match ($sort) {
            'newest' => 'newest',
            'oldest' => 'oldest',
            default => 'rating',
        };

        return '/search?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function card(array $show, ?string $reason = null): array
    {
        return [
            'id' => $show['id'],
            'name' => $show['name'],
            'rating' => (float) ($show['aggregateRating'] ?? 0),
            'year' => $show['startDate'] ?? '',
            'genre' => $show['genre'] ?? '',
            'image' => $show['image'] ?? '',
            'reason' => $reason ?? implode(', ', array_slice($this->list($show['genre'] ?? ''), 0, 2)),
            'contentRating' => trim((string) ($show['contentRating'] ?? '')),
        ];
    }

    private function firstSentences(string $text, int $limit): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, $limit + 1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', array_slice($sentences ?: [], 0, $limit));
    }

    private function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function intersects(array $left, array $right): bool
    {
        $left = array_map('mb_strtolower', $left);
        $right = array_map('mb_strtolower', $right);

        return (bool) array_intersect($left, $right);
    }
}

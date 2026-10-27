<?php

namespace Tests\Unit;

use App\Services\ChatbotService;
use Tests\TestCase;

class ChatbotServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    private function chatbot(): ChatbotService
    {
        return app(ChatbotService::class);
    }

    private function shows(): array
    {
        return [
            [
                'id' => 'tt0000001', 'name' => 'Sample Thriller', 'aggregateRating' => 9.1,
                'interactionCount' => 1000, 'startDate' => '2022', 'numberOfSeasons' => 1,
                'numberOfEpisodes' => 8, 'duration' => 25, 'description' => 'A tense detective follows a mysterious case.',
                'genre' => 'Thriller, Drama', 'actor' => 'Actor One', 'countryOfOrigin' => 'Japan',
                'platform' => ['AMC'], 'image' => '', 'status' => 'Ended', 'contentRating' => 'TV-MA',
                'author' => ['Creator One'],
                'keywords' => ['tense'],
            ],
            [
                'id' => 'tt0000002', 'name' => 'Another Thriller', 'aggregateRating' => 8.6,
                'interactionCount' => 500, 'startDate' => '2019', 'numberOfSeasons' => 2,
                'numberOfEpisodes' => 20, 'duration' => 45, 'description' => 'A suspenseful story.',
                'genre' => 'Thriller', 'actor' => 'Actor Two', 'countryOfOrigin' => 'United States',
                'platform' => ['Netflix'], 'image' => '', 'author' => ['Creator Two'],
            ],
            [
                'id' => 'tt0000003', 'name' => 'Animated Show', 'aggregateRating' => 8.8,
                'interactionCount' => 700, 'startDate' => '2023', 'numberOfSeasons' => 1,
                'numberOfEpisodes' => 10, 'duration' => 22, 'description' => 'An animated adventure.',
                'genre' => 'Animation, Comedy', 'actor' => 'Actor One', 'countryOfOrigin' => 'Japan',
                'platform' => ['AMC'], 'image' => '', 'author' => ['Creator One'],
            ],
            [
                'id' => 'tt0000004', 'name' => 'Chernobyl', 'aggregateRating' => 9.3,
                'interactionCount' => 900, 'startDate' => '2019', 'genre' => 'Drama, Thriller',
                'keywords' => ['terminal illness'], 'contentRating' => 'TV-MA',
            ],
            [
                'id' => 'tt0000005', 'name' => 'Safe Mystery', 'aggregateRating' => 9.8,
                'interactionCount' => 800, 'startDate' => '2024', 'genre' => 'Mystery, Family',
                'keywords' => ['adventure'], 'description' => 'A family-friendly adventure about friendship.',
                'contentRating' => 'TV-PG',
            ],
            [
                'id' => 'tt0000006', 'name' => 'Blocked Crime', 'aggregateRating' => 10,
                'interactionCount' => 2000, 'startDate' => '2024', 'genre' => 'Family, Crime',
                'keywords' => ['adventure'], 'contentRating' => 'PG',
            ],
            [
                'id' => 'tt0000007', 'name' => 'Unrated Family', 'aggregateRating' => 10,
                'interactionCount' => 3000, 'startDate' => '2024', 'genre' => 'Family',
                'keywords' => ['adventure'], 'contentRating' => '',
            ],
            [
                'id' => 'tt0000008', 'name' => 'Family With Alcohol', 'aggregateRating' => 9.7,
                'interactionCount' => 2500, 'startDate' => '2024', 'genre' => 'Family, Drama',
                'keywords' => ['small town'], 'description' => 'The story includes alcohol use during family gatherings.',
                'contentRating' => 'TV-PG',
            ],
            [
                'id' => 'tt0000009', 'name' => 'Dark Comedy Family', 'aggregateRating' => 9.6,
                'interactionCount' => 2400, 'startDate' => '2024', 'genre' => 'Family, Comedy',
                'keywords' => ['dark comedy'], 'description' => 'A quirky family adventure.',
                'contentRating' => 'PG',
            ],
            [
                'id' => 'tt0000010', 'name' => 'Ended Western Comedy', 'aggregateRating' => 8.4,
                'startDate' => '2010', 'genre' => 'Comedy', 'numberOfSeasons' => 4,
                'duration' => 25, 'status' => 'Ended', 'countryOfOrigin' => 'United States',
            ],
            [
                'id' => 'tt0000011', 'name' => 'Ongoing Western Comedy', 'aggregateRating' => 9.5,
                'startDate' => '2010', 'genre' => 'Comedy', 'numberOfSeasons' => 4,
                'duration' => 25, 'status' => 'Returning Series', 'countryOfOrigin' => 'United States',
            ],
            [
                'id' => 'tt0000012', 'name' => 'Ended Turkish Comedy', 'aggregateRating' => 9.7,
                'startDate' => '2021', 'genre' => 'Comedy', 'numberOfSeasons' => 4,
                'duration' => 25, 'status' => 'Ended', 'countryOfOrigin' => 'Turkey',
            ],
            [
                'id' => 'tt0000013', 'name' => 'Japan Horror', 'aggregateRating' => 8.2,
                'startDate' => '2020', 'genre' => 'Horror', 'status' => 'Ended',
                'countryOfOrigin' => 'Japan',
            ],
            [
                'id' => 'tt0000014', 'name' => 'US Horror', 'aggregateRating' => 9.5,
                'startDate' => '2020', 'genre' => 'Horror', 'status' => 'Ended',
                'countryOfOrigin' => 'United States',
            ],
            [
                'id' => 'tt0000015', 'name' => 'Sci-Fi Mind-Blowing', 'aggregateRating' => 8.4,
                'startDate' => '2022', 'genre' => 'Sci-Fi', 'keywords' => ['mind-blowing'],
            ],
            [
                'id' => 'tt0000016', 'name' => 'Sci-Fi Threshold Exact', 'aggregateRating' => 8.5,
                'startDate' => '2022', 'genre' => 'Sci-Fi',
            ],
            [
                'id' => 'tt0000017', 'name' => 'Sci-Fi Above Threshold', 'aggregateRating' => 8.6,
                'startDate' => '2022', 'genre' => 'Sci-Fi',
            ],
        ];
    }

    public function test_recommends_and_exposes_matching_filter_results(): void
    {
        $response = $this->chatbot()->respond('Tampilkan serial thriller di atas rating 8.5 dari AMC', $this->shows());

        self::assertSame('tt0000001', $response['shows'][0]['id']);
        self::assertStringContainsString('rating minimal 8.5', $response['answer']);
        self::assertStringContainsString('platform=AMC', $response['searchUrl']);
    }

    public function test_answers_cast_relations_with_other_shows(): void
    {
        $response = $this->chatbot()->respond('Siapa pemeran Sample Thriller?', $this->shows());

        self::assertStringContainsString('Actor One', $response['answer']);
        self::assertSame('tt0000003', $response['shows'][0]['id']);
    }

    public function test_excluding_a_title_in_actor_search_returns_truthful_other_genre_alternative(): void
    {
        $catalog = [
            ['id' => 'friends', 'name' => 'Friends', 'aggregateRating' => 8.8,
                'genre' => 'Comedy, Romance', 'actor' => 'Jennifer Aniston'],
            ['id' => 'morning-show', 'name' => 'The Morning Show', 'aggregateRating' => 8.2,
                'genre' => 'Drama', 'actor' => 'Jennifer Aniston'],
        ];

        $response = $this->chatbot()->respond(
            'Cari serial komedi romantis Jennifer Aniston, tapi bukan Friends',
            $catalog
        );

        self::assertSame(['morning-show'], array_column($response['shows'], 'id'));
        self::assertStringContainsString('Friends (Comedy, Romance)', $response['answer']);
        self::assertStringContainsString('tidak ada serial dengan genre Comedy/Romance', $response['answer']);
        self::assertStringContainsString('The Morning Show (Drama)', $response['answer']);
        self::assertStringNotContainsString('Cocok: genre Comedy/Romance', $response['shows'][0]['reason']);
    }

    public function test_actor_search_excludes_negated_title_when_another_requested_genre_matches(): void
    {
        $catalog = [
            ['id' => 'friends', 'name' => 'Friends', 'aggregateRating' => 8.8,
                'genre' => 'Comedy, Romance', 'actor' => 'Jennifer Aniston'],
            ['id' => 'morning-show', 'name' => 'The Morning Show', 'aggregateRating' => 8.2,
                'genre' => 'Drama', 'actor' => 'Jennifer Aniston'],
        ];

        $response = $this->chatbot()->respond(
            'Cari drama Jennifer Aniston, bukan Friends',
            $catalog
        );

        self::assertSame(['morning-show'], array_column($response['shows'], 'id'));
        self::assertStringContainsString('dengan genre Drama', $response['answer']);
        self::assertStringNotContainsString('Friends (Comedy, Romance)', implode(' ', array_column($response['shows'], 'name')));
    }

    public function test_shared_cast_search_excludes_the_anchor_and_explains_single_catalog_match(): void
    {
        $catalog = [
            ['id' => 'happy-valley', 'name' => 'Happy Valley', 'aggregateRating' => 8.5,
                'genre' => 'Crime, Drama, Thriller', 'actor' => 'Sarah Lancashire, Siobhan Finneran'],
            ['id' => 'last-tango', 'name' => 'Last Tango in Halifax', 'aggregateRating' => 8.3,
                'genre' => 'Drama, Thriller', 'actor' => 'Sarah Lancashire, Nicola Walker'],
            ['id' => 'other-thriller', 'name' => 'Other Thriller', 'aggregateRating' => 8.9,
                'genre' => 'Thriller', 'actor' => 'Different Actor'],
        ];

        $response = $this->chatbot()->respond(
            'Cari serial thriller yang dibintangi aktris utama yang sama dengan serial Happy Valley, tapi tolong jangan rekomendasikan Happy Valley ya',
            $catalog
        );

        self::assertSame(['last-tango'], array_column($response['shows'], 'id'));
        self::assertStringContainsString('Aku mengecualikan Happy Valley', $response['answer']);
        self::assertStringContainsString('hanya ada satu serial lain yang cocok', $response['answer']);
        self::assertStringNotContainsString('Happy Valley', implode(' ', array_column($response['shows'], 'name')));
        self::assertStringNotContainsString('genre Thriller', $response['shows'][0]['reason']);
    }

    public function test_known_plot_premise_maps_to_catalog_title_instead_of_unrelated_fallback(): void
    {
        $catalog = [
            ['id' => 'breaking-bad', 'name' => 'Breaking Bad', 'aggregateRating' => 9.5,
                'genre' => 'Crime, Drama, Thriller',
                'description' => 'A chemistry teacher enters the world of drugs and crime.'],
            ['id' => 'comedy', 'name' => 'High Rated Comedy', 'aggregateRating' => 9.8,
                'genre' => 'Comedy'],
        ];

        $direct = $this->chatbot()->respond('Guru kimia masak sabu', $catalog);
        $withConflict = $this->chatbot()->respond(
            'Guru kimia masak sabu dengan genre komedi dan rating di atas 8',
            $catalog
        );

        self::assertSame(['breaking-bad'], array_column($direct['shows'], 'id'));
        self::assertStringContainsString('premis dan sinopsis katalog', $direct['answer']);
        self::assertSame(['breaking-bad'], array_column($withConflict['shows'], 'id'));
        self::assertStringContainsString('Premis cerita itu mirip dengan Breaking Bad', $withConflict['answer']);
        self::assertStringContainsString('tidak memenuhi semua kriteria', $withConflict['answer']);
        self::assertStringNotContainsString('High Rated Comedy', json_encode($withConflict));
    }

    public function test_stranger_things_plot_description_maps_to_the_matching_catalog_title(): void
    {
        $catalog = [
            ['id' => 'stranger-things', 'name' => 'Stranger Things', 'aggregateRating' => 8.7,
                'genre' => 'Drama, Fantasy, Sci-Fi, Horror',
                'description' => 'A group of teens in a small town investigate a missing friend and monsters from another dimension.'],
            ['id' => 'action', 'name' => 'Unrelated Action', 'aggregateRating' => 9.5, 'genre' => 'Action'],
        ];

        $response = $this->chatbot()->respond(
            'Ada gak series tentang sekelompok anak remaja di kota kecil tahun 80-an yang temannya hilang dan melawan monster dari dimensi lain?',
            $catalog
        );

        self::assertSame(['stranger-things'], array_column($response['shows'], 'id'));
        self::assertStringContainsString('premis dan sinopsis katalog', $response['answer']);
        self::assertStringNotContainsString('Unrelated Action', json_encode($response));
    }

    public function test_us_sitcom_query_excludes_named_titles_and_keeps_remaining_matching_title(): void
    {
        $catalog = [
            ['id' => 'friends', 'name' => 'Friends', 'aggregateRating' => 8.8,
                'genre' => 'Comedy, Romance', 'countryOfOrigin' => 'United States', 'duration' => 22],
            ['id' => 'the-office', 'name' => 'The Office', 'aggregateRating' => 9.0,
                'genre' => 'Comedy', 'countryOfOrigin' => 'United States', 'duration' => 22],
            ['id' => 'seinfeld', 'name' => 'Seinfeld', 'aggregateRating' => 8.8,
                'genre' => 'Comedy', 'countryOfOrigin' => 'United States', 'duration' => 22],
            ['id' => 'uk-comedy', 'name' => 'UK Comedy', 'aggregateRating' => 9.5,
                'genre' => 'Comedy', 'countryOfOrigin' => 'United Kingdom', 'duration' => 22],
        ];

        $response = $this->chatbot()->respond(
            'Cari series komedi Amerika dengan rating minimal 8.5, durasi di bawah 30 menit per episode, tapi jangan tampilkan The Office atau Friends',
            $catalog
        );

        self::assertSame(['seinfeld'], array_column($response['shows'], 'id'));
        self::assertStringNotContainsString('The Office', json_encode($response));
        self::assertStringNotContainsString('Friends', json_encode($response));
        self::assertSame(29, $response['state']['durationMax']);
    }

    public function test_counts_catalog_matches_and_answers_episode_metadata(): void
    {
        $service = $this->chatbot();
        $count = $service->respond('Ada berapa serial komedi asal Asia setelah 2020?', $this->shows());
        $metadata = $service->respond('Berapa total season dan episode Sample Thriller?', $this->shows());

        self::assertStringContainsString('Ada 1 serial', $count['answer']);
        self::assertStringContainsString('1 season dan 8 episode', $metadata['answer']);
    }

    public function test_returns_source_synopsis_from_catalog_data_without_external_translation(): void
    {
        $response = $this->chatbot()->respond('Ceritain sinopsis Sample Thriller', $this->shows());

        self::assertStringContainsString('A tense detective follows a mysterious case.', $response['answer']);
    }

    public function test_answers_status_and_content_rating(): void
    {
        $response = $this->chatbot()->respond('Apa status dan rating usia Sample Thriller?', $this->shows());

        self::assertStringContainsString('Ended', $response['answer']);
        self::assertStringContainsString('TV-MA', $response['answer']);
    }

    public function test_answers_creator_and_platform_relationships(): void
    {
        $service = $this->chatbot();
        $creator = $service->respond('Siapa pencipta Sample Thriller?', $this->shows());
        $platform = $service->respond('Sample Thriller tayang di platform apa?', $this->shows());

        self::assertStringContainsString('Creator One', $creator['answer']);
        self::assertSame('tt0000003', $creator['shows'][0]['id']);
        self::assertStringContainsString('AMC', $platform['answer']);
    }

    public function test_recommendations_match_mood_against_overview_and_keywords(): void
    {
        $response = $this->chatbot()->respond('Tampilkan serial dengan vibe tegang', $this->shows());

        self::assertSame('tt0000001', $response['shows'][0]['id']);
        self::assertStringContainsString('Vibe tegang', $response['shows'][0]['reason']);
    }

    public function test_family_friendly_filter_uses_rating_allowlist_and_blacklist(): void
    {
        $response = $this->chatbot()->respond('Rekomendasi series TV yang family friendly', $this->shows());

        self::assertSame(['tt0000005'], array_column($response['shows'], 'id'));
        self::assertSame('TV-PG', $response['shows'][0]['contentRating']);
        self::assertStringContainsString('ramah keluarga', $response['answer']);
        self::assertStringNotContainsString('tt0000004', json_encode($response));
        self::assertStringNotContainsString('tt0000008', json_encode($response));
        self::assertStringNotContainsString('tt0000009', json_encode($response));
        self::assertNull($response['searchUrl']);
    }

    public function test_child_friendly_intent_excludes_blocked_genres_and_unknown_ratings(): void
    {
        $response = $this->chatbot()->respond('Cari tontonan untuk anak-anak', $this->shows());

        self::assertSame(['tt0000005'], array_column($response['shows'], 'id'));
    }

    public function test_family_filter_scans_sensitive_terms_in_overview_and_keywords(): void
    {
        $response = $this->chatbot()->respond('Cari series santai bareng keluarga', $this->shows());
        $resultIds = array_column($response['shows'], 'id');

        self::assertContains('tt0000005', $resultIds);
        self::assertNotContains('tt0000008', $resultIds);
        self::assertNotContains('tt0000009', $resultIds);
    }

    public function test_combines_ended_western_and_short_episode_constraints(): void
    {
        $response = $this->chatbot()->respond('Lagi cari series barat yang statusnya sudah tamat dan episodenya pendek-pendek', $this->shows());

        self::assertSame(['tt0000010'], array_column($response['shows'], 'id'));
        self::assertStringContainsString('sudah tamat', $response['answer']);
        self::assertNull($response['searchUrl']);
    }

    public function test_applies_origin_country_filter_with_genre(): void
    {
        $response = $this->chatbot()->respond('Cariin serial horor produksi Jepang', $this->shows());

        self::assertSame(['tt0000013'], array_column($response['shows'], 'id'));
    }

    public function test_season_count_does_not_get_parsed_as_rating(): void
    {
        $response = $this->chatbot()->respond('Filter-in dong TV series genre komedi yang jumlah season-nya lebih dari 3', $this->shows());

        self::assertEqualsCanonicalizing(['tt0000010', 'tt0000011', 'tt0000012'], array_column($response['shows'], 'id'));
        self::assertStringContainsString('minimal 4 season', $response['answer']);
        self::assertStringNotContainsString('rating minimal 3', $response['answer']);
    }

    public function test_zero_results_return_transparent_rating_relaxed_alternatives(): void
    {
        $catalog = array_values(array_filter($this->shows(), fn (array $show) => ! in_array($show['id'], ['tt0000016', 'tt0000017'], true)));
        $response = $this->chatbot()->respond('Rekomendasikan serial sci-fi mind-blowing dengan rating IMDb di atas 8.5', $catalog);

        self::assertSame(['tt0000015'], array_column($response['shows'], 'id'));
        self::assertStringContainsString('belum ada judul yang memenuhi semua kriteria persis', mb_strtolower($response['answer']));
        self::assertStringContainsString('rating', mb_strtolower($response['answer']));
        self::assertStringContainsString('pilihan terdekat', mb_strtolower($response['answer']));
        self::assertStringNotContainsString('batas rating dilepas', mb_strtolower($response['answer']));
        self::assertStringNotContainsString('Alternatif;', $response['shows'][0]['reason']);
    }

    public function test_zero_results_never_relax_an_explicit_duration_limit_or_family_safety(): void
    {
        $catalog = [
            ['id' => 'clean-nearby', 'name' => 'Clean Future', 'aggregateRating' => 9.8,
                'genre' => 'Animation, Sci-Fi', 'contentRating' => 'TV-PG', 'duration' => 38,
                'keywords' => ['future', 'adventure'], 'description' => 'A family adventure in the future.'],
            ['id' => 'unsafe-nearby', 'name' => 'Adult Future', 'aggregateRating' => 9.9,
                'genre' => 'Animation, Sci-Fi', 'contentRating' => 'TV-14', 'duration' => 25,
                'keywords' => ['future'], 'description' => 'A lighthearted family adventure.'],
            ['id' => 'warning-nearby', 'name' => 'Drinking Future', 'aggregateRating' => 9.9,
                'genre' => 'Animation, Sci-Fi', 'contentRating' => 'TV-PG', 'duration' => 25,
                'keywords' => ['drinking'], 'description' => 'A family adventure in the future.'],
        ];

        $response = $this->chatbot()->respond(
            'Cari serial ramah keluarga sci-fi animasi rating di atas 9.9 durasi maksimal 10 menit',
            $catalog
        );

        self::assertSame([], array_column($response['shows'], 'id'));
        self::assertStringContainsString('tidak akan menyarankan judul yang gagal pemeriksaan', mb_strtolower($response['answer']));
        self::assertStringNotContainsString('Adult Future', json_encode($response));
        self::assertStringNotContainsString('Drinking Future', json_encode($response));
    }

    public function test_common_slang_typos_are_normalized_into_a_genre_search(): void
    {
        $catalog = [
            ['id' => 'comedy', 'name' => 'Comedy Series', 'aggregateRating' => 9.0, 'genre' => 'Comedy'],
            ['id' => 'lower-comedy', 'name' => 'Low Rated Comedy', 'aggregateRating' => 7.3, 'genre' => 'Comedy'],
            ['id' => 'drama', 'name' => 'Drama Series', 'aggregateRating' => 8.5, 'genre' => 'Drama'],
        ];

        $response = $this->chatbot()->respond('Acar TV komde pny rating bgs ad ga?', $catalog);

        self::assertSame(['comedy'], array_column($response['shows'], 'id'));
        self::assertSame(['Comedy'], $response['state']['genres']);
        self::assertSame(8.0, $response['state']['rating']);
        self::assertSame('rating_desc', $response['state']['sort']);
    }

    public function test_fallback_keeps_requested_genre_and_limits_rating_relaxation_to_point_two(): void
    {
        $catalog = [
            ['id' => 'comedy-79', 'name' => 'Comedy Near Match', 'aggregateRating' => 7.9, 'genre' => 'Comedy'],
            ['id' => 'comedy-73', 'name' => 'Low Rated Comedy', 'aggregateRating' => 7.3, 'genre' => 'Comedy'],
            ['id' => 'drama-99', 'name' => 'Unrelated Drama', 'aggregateRating' => 9.9, 'genre' => 'Drama'],
        ];

        $response = $this->chatbot()->respond('Cari series komedi dengan rating di atas 8', $catalog);

        self::assertSame(['comedy-79'], array_column($response['shows'], 'id'));
        self::assertStringNotContainsString('Unrelated Drama', json_encode($response));
        self::assertStringContainsString('pilihan terdekat', mb_strtolower($response['answer']));
        self::assertStringNotContainsString('diperlonggar menjadi', mb_strtolower($response['answer']));
        self::assertStringNotContainsString('rating dilepas', mb_strtolower($response['answer']));
        self::assertStringNotContainsString('batas rating', mb_strtolower($response['shows'][0]['reason']));
    }

    public function test_unknown_reference_title_returns_catalog_based_similar_alternatives(): void
    {
        $catalog = [
            ['id' => 'future', 'name' => 'Future Stories', 'aggregateRating' => 8.8,
                'genre' => 'Sci-Fi, Drama', 'keywords' => ['technology', 'future'],
                'description' => 'An anthology exploring technology and the future.'],
            ['id' => 'romance', 'name' => 'Summer Romance', 'aggregateRating' => 9.5,
                'genre' => 'Romance', 'keywords' => ['love'],
                'description' => 'A romantic story about a summer relationship.'],
        ];

        $response = $this->chatbot()->respond('Ada series mirip Black Mirror dengan sci-fi?', $catalog);

        self::assertSame(['future'], array_column($response['shows'], 'id'));
        self::assertStringContainsString('judul Black Mirror di katalog', $response['answer']);
        self::assertStringContainsString('metadata genre, keyword, dan sinopsis', $response['answer']);
    }

    public function test_similarity_recommendations_exclude_the_reference_title_itself(): void
    {
        $catalog = [
            ['id' => 'breaking-bad', 'name' => 'Breaking Bad', 'aggregateRating' => 9.5,
                'genre' => 'Crime, Drama', 'actor' => 'Bryan Cranston'],
            ['id' => 'similar-crime', 'name' => 'Similar Crime Drama', 'aggregateRating' => 8.8,
                'genre' => 'Crime, Drama, Thriller', 'actor' => 'Aaron Paul'],
            ['id' => 'other', 'name' => 'Other Series', 'aggregateRating' => 9.2, 'genre' => 'Comedy'],
        ];

        $response = $this->chatbot()->respond('Serial thriller mirip Breaking Bad', $catalog);

        self::assertNotContains('breaking-bad', array_column($response['shows'], 'id'));
        self::assertSame(['similar-crime'], array_column($response['shows'], 'id'));
    }

    public function test_explicit_episode_duration_limit_is_not_relaxed(): void
    {
        $catalog = [
            ['id' => 'short', 'name' => 'Short Episode', 'aggregateRating' => 8.7,
                'genre' => 'Animation, Sci-Fi', 'duration' => 18],
            ['id' => 'long', 'name' => 'Long Episode', 'aggregateRating' => 9.8,
                'genre' => 'Animation, Sci-Fi', 'duration' => 38],
        ];

        $response = $this->chatbot()->respond(
            'Cari serial sci-fi animasi rating di atas 9.9 durasi maksimal 10 menit',
            $catalog
        );

        self::assertSame([], $response['shows']);
        self::assertSame(10, $response['state']['durationMax']);
        self::assertStringContainsString('tidak ditemukan', mb_strtolower($response['answer']));
    }

    public function test_less_than_episode_runtime_is_strict_and_keeps_comedy_country_filters(): void
    {
        $catalog = [
            ['id' => 'friends', 'name' => 'Friends', 'aggregateRating' => 8.8,
                'genre' => 'Comedy, Romance', 'countryOfOrigin' => 'United States', 'duration' => 22],
            ['id' => 'the-office-uk', 'name' => 'The Office', 'aggregateRating' => 8.5,
                'genre' => 'Comedy, Drama', 'countryOfOrigin' => 'United Kingdom', 'duration' => 30],
            ['id' => 'as-if', 'name' => 'As If', 'aggregateRating' => 8.8,
                'genre' => 'Comedy', 'countryOfOrigin' => 'United Kingdom', 'duration' => 22],
            ['id' => 'clarkson', 'name' => "Clarkson's Farm", 'aggregateRating' => 9.0,
                'genre' => 'Documentary, Comedy', 'countryOfOrigin' => 'United Kingdom', 'duration' => 25],
            ['id' => 'taskmaster', 'name' => 'Taskmaster', 'aggregateRating' => 9.0,
                'genre' => 'Comedy, Game Show', 'countryOfOrigin' => 'United Kingdom', 'duration' => 45],
        ];

        $response = $this->chatbot()->respond(
            'Cari sitkom komedi Amerika rating di atas 8.5 durasi kurang dari 25 menit',
            $catalog
        );

        self::assertSame(['friends'], array_column($response['shows'], 'id'));
        self::assertSame(24, $response['state']['durationMax']);
        self::assertSame(['United States'], $response['state']['country']);
        self::assertSame(['Comedy'], $response['state']['genres']);

        $symbolResponse = $this->chatbot()->respond(
            'Cari sitkom komedi Amerika rating di atas 8.5 durasi <25 menit',
            $catalog
        );

        self::assertSame(['friends'], array_column($symbolResponse['shows'], 'id'));
        self::assertSame(24, $symbolResponse['state']['durationMax']);
    }

    public function test_exact_informal_us_sitcom_request_parses_below_duration_and_does_not_inherit_family_filter(): void
    {
        $catalog = [
            ['id' => 'friends', 'name' => 'Friends', 'aggregateRating' => 8.8,
                'genre' => 'Comedy, Romance', 'countryOfOrigin' => 'United States', 'duration' => 22],
            ['id' => 'office-uk', 'name' => 'The Office', 'aggregateRating' => 8.5,
                'genre' => 'Comedy, Drama', 'countryOfOrigin' => 'United Kingdom', 'duration' => 30],
        ];
        $service = $this->chatbot();
        $familyState = $service->respond(
            'Cari serial ramah keluarga',
            $catalog
        )['state'];

        $response = $service->respond(
            'Cariin sitkom komedi Amerika yang ratingnya wajib di atas 8.5 dan durasi per episodenya di bawah 25 meni',
            $catalog,
            $familyState
        );

        self::assertSame(['friends'], array_column($response['shows'], 'id'));
        self::assertSame(8.5, $response['state']['rating']);
        self::assertTrue($response['state']['ratingExclusive']);
        self::assertSame(24, $response['state']['durationMax']);
        self::assertSame(['United States'], $response['state']['country']);
        self::assertSame(['Comedy'], $response['state']['genres']);
        self::assertFalse($response['state']['familyFriendly']);
    }

    public function test_animation_genre_proxy_excludes_adult_age_rating_and_unsafe_content(): void
    {
        $catalog = [
            ['id' => 'safe-family', 'name' => 'Safe Family Adventure', 'aggregateRating' => 8.7,
                'genre' => 'Family, Adventure', 'contentRating' => 'TV-PG',
                'keywords' => ['friendship'], 'description' => 'A family story about friendship and adventure.'],
            ['id' => 'adult-adventure', 'name' => 'Game of Thrones', 'aggregateRating' => 9.2,
                'genre' => 'Adventure, Drama, Fantasy', 'contentRating' => 'TV-MA',
                'keywords' => ['war', 'violence'], 'description' => 'A brutal story of war and violence.'],
        ];

        $response = $this->chatbot()->respond('Tampilkan animasi rating di atas 8.5', $catalog);

        self::assertSame(['safe-family'], array_column($response['shows'], 'id'));
        self::assertStringContainsString('Family/Adventure', $response['answer']);
        self::assertStringNotContainsString('Game of Thrones', json_encode($response));
    }

    public function test_rating_above_is_exclusive_and_does_not_capture_season_numbers(): void
    {
        $response = $this->chatbot()->respond('Cari sci-fi dengan rating di atas 8.5', $this->shows());

        self::assertSame(['tt0000017'], array_column($response['shows'], 'id'));
    }

    public function test_explains_why_chernobyl_is_not_family_friendly(): void
    {
        $response = $this->chatbot()->respond('Apakah Chernobyl termasuk family friendly?', $this->shows());

        self::assertStringContainsString('content rating TV-MA', $response['answer']);
        self::assertStringContainsString('Safe Mystery', $response['answer']);
        self::assertSame(['tt0000005'], array_column($response['shows'], 'id'));
    }

    public function test_short_title_is_not_matched_inside_the_word_series(): void
    {
        $catalog = [
            ['id' => 'er', 'name' => 'ER', 'aggregateRating' => 8.5, 'genre' => 'Drama'],
            ['id' => 'show', 'name' => 'A Different Show', 'aggregateRating' => 9.0, 'genre' => 'Comedy'],
        ];

        $response = $this->chatbot()->respond('Cari series dengan rating minimal 8', $catalog);

        self::assertStringNotContainsString('Kalau suka ER', $response['answer']);
        self::assertSame(['show', 'er'], array_column($response['shows'], 'id'));
    }

    public function test_screenshot_style_request_applies_age_duration_and_rating_filters_without_duplicates(): void
    {
        $candidate = [
            'id' => 'tt9000001', 'name' => 'Weekend Family', 'aggregateRating' => 9.0,
            'startDate' => '2023', 'duration' => 25, 'genre' => 'Animation, Family',
            'contentRating' => 'TV-PG', 'image' => '', 'keywords' => ['friendship'],
            'description' => 'A warmhearted family adventure.',
        ];
        $catalog = [
            ['id' => 'tt9000002', 'name' => 'ER', 'aggregateRating' => 8.7,
                'startDate' => '1994', 'duration' => 47, 'genre' => 'Drama',
                'contentRating' => 'TV-PG', 'image' => ''],
            $candidate,
            $candidate,
            ['id' => 'tt9000003', 'name' => 'Breaking Bad', 'aggregateRating' => 9.5,
                'startDate' => '2008', 'duration' => 47, 'genre' => 'Crime, Drama',
                'contentRating' => 'TV-MA', 'image' => ''],
        ];

        $response = $this->chatbot()->respond(
            'aku mau nonton di akhir pekan nanti kira-kira ada gak series dengan durasi singkat per episodenya, genre yang bisa ditonton semua orang dan ratingnya di atas 8?',
            $catalog
        );

        self::assertStringNotContainsString('Kalau suka ER', $response['answer']);
        self::assertSame(['tt9000001'], array_column($response['shows'], 'id'));
        self::assertStringContainsString('rating di atas 8', $response['answer']);
        self::assertStringContainsString('maksimal 30 menit', $response['answer']);
    }

    public function test_conversation_state_keeps_filters_replaces_country_and_removes_only_requested_filter(): void
    {
        $catalog = [
            ['id' => 'korea-thriller', 'name' => 'Korea Thriller', 'aggregateRating' => 8.8,
                'genre' => 'Thriller', 'countryOfOrigin' => 'South Korea', 'startDate' => '2022'],
            ['id' => 'japan-thriller', 'name' => 'Japan Thriller', 'aggregateRating' => 9.1,
                'genre' => 'Thriller', 'countryOfOrigin' => 'Japan', 'startDate' => '2023'],
            ['id' => 'japan-comedy', 'name' => 'Japan Comedy', 'aggregateRating' => 9.9,
                'genre' => 'Comedy', 'countryOfOrigin' => 'Japan', 'startDate' => '2024'],
        ];
        $service = $this->chatbot();

        $first = $service->respond('Cari thriller Korea', $catalog);
        $second = $service->respond('rating minimal 8', $catalog, $first['state']);
        $third = $service->respond('Ganti jadi Jepang', $catalog, $second['state']);
        $fourth = $service->respond('Hapus filter negara', $catalog, $third['state']);

        self::assertSame(['korea-thriller'], array_column($first['shows'], 'id'));
        self::assertSame(['South Korea'], $first['state']['country']);
        self::assertSame(['korea-thriller'], array_column($second['shows'], 'id'));
        self::assertSame(['Japan'], $third['state']['country']);
        self::assertSame(['japan-thriller'], array_column($third['shows'], 'id'));
        self::assertSame([], $fourth['state']['country']);
        self::assertSame(['japan-thriller', 'korea-thriller'], array_column($fourth['shows'], 'id'));
        self::assertSame(['Thriller'], $fourth['state']['genres']);
        self::assertSame(8.0, $fourth['state']['rating']);
    }

    public function test_new_search_replaces_stale_topic_filters_while_refinement_keeps_them(): void
    {
        $catalog = [
            ['id' => 'anime-jp', 'name' => 'Japanese Anime', 'aggregateRating' => 9.0,
                'genre' => 'Animation, Drama', 'countryOfOrigin' => 'Japan', 'startDate' => '2024'],
            ['id' => 'animation-high', 'name' => 'High Rated Animation', 'aggregateRating' => 8.7,
                'genre' => 'Animation', 'countryOfOrigin' => 'United States'],
            ['id' => 'space-doc', 'name' => 'Space Documentary', 'aggregateRating' => 8.6,
                'genre' => 'Documentary', 'countryOfOrigin' => 'United States', 'startDate' => '2050'],
            ['id' => 'sitcom-us', 'name' => 'American Sitcom', 'aggregateRating' => 8.8,
                'genre' => 'Comedy', 'countryOfOrigin' => 'United States', 'startDate' => '2023'],
        ];
        $service = $this->chatbot();
        $first = $service->respond('Cari anime Jepang rating di atas 8.5', $catalog);
        $newTopic = $service->respond('Cari dokumenter luar angkasa mulai 2050', $catalog, $first['state']);
        $sitcom = $service->respond('Cari sitkom komedi Amerika rating di atas 8.5', $catalog, $newTopic['state']);
        $familyState = $service->respond('Cari series ramah keluarga', $catalog)['state'];
        $animation = $service->respond('Tampilkan animasi rating di atas 8.5', $catalog, $familyState);
        $refinement = $service->respond('Kalau yang tadi versi animasi ada gak?', $catalog, $first['state']);

        self::assertSame(['space-doc'], array_column($newTopic['shows'], 'id'));
        self::assertSame(['Documentary'], $newTopic['state']['genres']);
        self::assertSame([], $newTopic['state']['country']);
        self::assertNull($newTopic['state']['rating']);
        self::assertSame(['sitcom-us'], array_column($sitcom['shows'], 'id'));
        self::assertSame(['Comedy'], $sitcom['state']['genres']);
        self::assertSame(['United States'], $sitcom['state']['country']);
        self::assertSame(8.5, $sitcom['state']['rating']);
        self::assertEqualsCanonicalizing(['anime-jp', 'animation-high'], array_column($animation['shows'], 'id'));
        self::assertFalse($animation['state']['familyFriendly']);
        self::assertSame([], $animation['state']['country']);
        self::assertSame(['anime-jp'], array_column($refinement['shows'], 'id'));
        self::assertSame(['Japan'], $refinement['state']['country']);
    }

    public function test_similar_title_search_does_not_inherit_a_previous_family_filter(): void
    {
        $catalog = [
            ['id' => 'safe', 'name' => 'Safe Family Show', 'aggregateRating' => 8.5,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-PG',
                'keywords' => ['friendship'], 'description' => 'A family story about friendship.'],
            ['id' => 'breaking-bad', 'name' => 'Breaking Bad', 'aggregateRating' => 9.5,
                'genre' => 'Crime, Drama', 'contentRating' => 'TV-MA'],
            ['id' => 'thriller', 'name' => 'Crime Thriller', 'aggregateRating' => 8.7,
                'genre' => 'Thriller, Crime', 'contentRating' => 'TV-MA'],
        ];
        $service = $this->chatbot();
        $family = $service->respond('Cari series ramah keluarga', $catalog);
        $similar = $service->respond('Serial thriller mirip Breaking Bad', $catalog, $family['state']);

        self::assertFalse($similar['state']['familyFriendly']);
        self::assertSame(['thriller'], array_column($similar['shows'], 'id'));
        self::assertStringNotContainsString('ramah keluarga', mb_strtolower($similar['answer']));
    }

    public function test_mature_action_request_conflicting_with_family_intent_asks_for_clarification(): void
    {
        $response = $this->chatbot()->respond(
            'Cari serial aksi tembak-tembakan perang brutal dengan adegan dewasa buat tontonan keluarga',
            $this->shows()
        );

        self::assertSame([], $response['shows']);
        self::assertStringContainsString('mencampur batas ramah keluarga', $response['answer']);
        self::assertStringContainsString('Kamu ingin', $response['answer']);
        self::assertFalse($response['state']['familyFriendly']);
    }

    public function test_rating_fallback_does_not_recommend_titles_far_below_requested_threshold(): void
    {
        $catalog = [
            ['id' => 'sitcom-82', 'name' => 'American Sitcom', 'aggregateRating' => 8.2,
                'genre' => 'Comedy', 'countryOfOrigin' => 'United States'],
            ['id' => 'sitcom-79', 'name' => 'Lower Rated Sitcom', 'aggregateRating' => 7.9,
                'genre' => 'Comedy', 'countryOfOrigin' => 'United States'],
        ];

        $response = $this->chatbot()->respond('Cari sitkom komedi Amerika rating di atas 8.5', $catalog);

        self::assertSame([], $response['shows']);
        self::assertStringNotContainsString('American Sitcom', json_encode($response));
        self::assertStringContainsString('tidak ditemukan', mb_strtolower($response['answer']));
    }

    public function test_missing_animation_genre_returns_nearby_family_adventure_without_unrelated_comedy(): void
    {
        $catalog = [
            ['id' => 'family-adventure', 'name' => 'Family Adventure', 'aggregateRating' => 8.4,
                'genre' => 'Family, Adventure', 'contentRating' => 'TV-PG',
                'keywords' => ['friendship'], 'description' => 'A family story about friendship and adventure.'],
            ['id' => 'lower-adventure', 'name' => 'Lower Rated Adventure', 'aggregateRating' => 8.2,
                'genre' => 'Adventure'],
            ['id' => 'high-comedy', 'name' => 'High Rated Comedy', 'aggregateRating' => 9.8,
                'genre' => 'Comedy'],
        ];

        $response = $this->chatbot()->respond('Tampilkan animasi rating di atas 8.5', $catalog);

        self::assertSame(['family-adventure'], array_column($response['shows'], 'id'));
        self::assertStringContainsString('katalog belum memiliki judul berlabel animasi', mb_strtolower($response['answer']));
        self::assertStringNotContainsString('High Rated Comedy', json_encode($response));
        self::assertStringNotContainsString('Lower Rated Adventure', json_encode($response));
    }

    public function test_sorting_and_out_of_scope_requests_are_handled_explicitly(): void
    {
        $catalog = [
            ['id' => 'lower', 'name' => 'Lower Rated', 'aggregateRating' => 7.5, 'startDate' => '2024'],
            ['id' => 'higher', 'name' => 'Higher Rated', 'aggregateRating' => 9.5, 'startDate' => '2019'],
        ];
        $service = $this->chatbot();
        $sorted = $service->respond('Urutkan rating tertinggi', $catalog);
        $outOfScope = $service->respond('Siapa juara pertandingan besok?', $catalog);

        self::assertSame(['higher', 'lower'], array_column($sorted['shows'], 'id'));
        self::assertSame([], $outOfScope['shows']);
        self::assertStringContainsString('khusus membantu pertanyaan dan rekomendasi serial TV di situs ITV', $outOfScope['answer']);
    }

    public function test_technical_how_to_question_is_explicitly_out_of_scope_even_if_it_mentions_films(): void
    {
        $response = $this->chatbot()->respond(
            'Gimana caranya setting database PostgreSQL di local pake XAMPP buat nyimpen data film',
            $this->shows()
        );

        self::assertSame([], $response['shows']);
        self::assertSame(
            'Chatbot ini khusus membantu pertanyaan dan rekomendasi serial TV di situs ITV, jadi belum bisa menjawab pertanyaan di luar katalog. Mau cari serial berdasarkan judul, genre, atau rating?',
            $response['answer']
        );
        self::assertStringNotContainsString('Belum ada serial', $response['answer']);
    }

    public function test_borrowing_money_request_does_not_fall_through_to_generic_zero_results(): void
    {
        $response = $this->chatbot()->respond(
            'Bro, enaknya pinjam uang ke mana ya buat beli tiket konser?',
            $this->shows()
        );

        self::assertSame([], $response['shows']);
        self::assertSame(
            'Chatbot ini khusus membantu pertanyaan dan rekomendasi serial TV di situs ITV, jadi belum bisa menjawab pertanyaan di luar katalog. Mau cari serial berdasarkan judul, genre, atau rating?',
            $response['answer']
        );
    }

    public function test_recipe_question_uses_same_out_of_scope_template_as_borrowing_request(): void
    {
        $service = $this->chatbot();
        $borrow = $service->respond('Bro, enaknya pinjam uang ke mana ya buat beli tiket konser?', $this->shows());
        $recipe = $service->respond('Buatkan resep nasi goreng yang enak dong', $this->shows());

        self::assertSame($borrow['answer'], $recipe['answer']);
        self::assertSame([], $recipe['shows']);
    }

    public function test_multiple_negated_genres_are_all_excluded(): void
    {
        $catalog = [
            ['id' => 'drama', 'name' => 'Drama Only', 'aggregateRating' => 9.2,
                'genre' => 'Drama', 'startDate' => '2018'],
            ['id' => 'scifi', 'name' => 'Sci-Fi Drama', 'aggregateRating' => 9.3,
                'genre' => 'Drama, Sci-Fi', 'startDate' => '2018'],
            ['id' => 'fantasy', 'name' => 'Fantasy Drama', 'aggregateRating' => 9.4,
                'genre' => 'Drama, Fantasy', 'startDate' => '2018'],
        ];

        $response = $this->chatbot()->respond(
            'Tampilkan serial drama rilisan tahun 2015 ke atas yang punya rating di atas 9.0, tapi pastikan bukan bergenre sci-fi atau fantasy',
            $catalog
        );

        self::assertSame(['drama'], array_column($response['shows'], 'id'));
        self::assertSame(['Sci-Fi', 'Fantasy'], $response['state']['excludeGenres']);
    }

    public function test_family_intent_with_mature_content_requests_clarification_for_school_age_children(): void
    {
        $response = $this->chatbot()->respond(
            'Cari sitkom komedi yang paling vulgar, penuh makian kasar, dan ada adegan dewasanya, tapi pastikan itu lolos sensor lembaga sensor untuk anak SD.',
            $this->shows()
        );

        self::assertSame([], $response['shows']);
        self::assertStringContainsString('mencampur batas ramah keluarga', $response['answer']);
    }

    public function test_reset_clears_conversation_filters(): void
    {
        $state = $this->chatbot()->respond('Cari thriller Korea', $this->shows())['state'];
        $response = $this->chatbot()->respond('Mulai dari awal', $this->shows(), $state);

        self::assertSame([], $response['shows']);
        self::assertSame([], $response['state']['genres']);
        self::assertSame([], $response['state']['country']);
        self::assertNull($response['state']['rating']);
    }

    public function test_informal_negative_genre_and_positive_genre_are_applied_together(): void
    {
        $catalog = [
            ['id' => 'comedy', 'name' => 'Comedy Series', 'aggregateRating' => 8.5, 'genre' => 'Comedy'],
            ['id' => 'horror', 'name' => 'Horror Series', 'aggregateRating' => 9.5, 'genre' => 'Horror'],
            ['id' => 'both', 'name' => 'Mixed Series', 'aggregateRating' => 9.0, 'genre' => 'Comedy, Horror'],
        ];

        $response = $this->chatbot()->respond('Ga mau horror, cari yg lucu', $catalog);

        self::assertSame(['comedy'], array_column($response['shows'], 'id'));
        self::assertSame(['Horror'], $response['state']['excludeGenres']);
        self::assertSame(['Comedy'], $response['state']['genres']);
        self::assertStringContainsString('tanpa genre Horror', $response['answer']);
    }

    public function test_negative_platform_filter_is_preserved_when_country_changes(): void
    {
        $catalog = [
            ['id' => 'korea-netflix', 'name' => 'Korea Netflix', 'aggregateRating' => 9.0,
                'genre' => 'Thriller', 'countryOfOrigin' => 'South Korea', 'platform' => ['Netflix']],
            ['id' => 'korea-hulu', 'name' => 'Korea Hulu', 'aggregateRating' => 8.8,
                'genre' => 'Thriller', 'countryOfOrigin' => 'South Korea', 'platform' => ['Hulu']],
            ['id' => 'japan-hulu', 'name' => 'Japan Hulu', 'aggregateRating' => 9.2,
                'genre' => 'Thriller', 'countryOfOrigin' => 'Japan', 'platform' => ['Hulu']],
        ];
        $service = $this->chatbot();

        $first = $service->respond('Cari thriller Korea', $catalog);
        $second = $service->respond('Jangan yang dari Netflix', $catalog, $first['state']);
        $third = $service->respond('Ganti jadi Jepang', $catalog, $second['state']);
        $fourth = $service->respond('Tidak jadi filter negara', $catalog, $third['state']);

        self::assertSame(['korea-netflix', 'korea-hulu'], array_column($first['shows'], 'id'));
        self::assertSame(['korea-hulu'], array_column($second['shows'], 'id'));
        self::assertSame(['Netflix'], $second['state']['excludePlatform']);
        self::assertSame(['japan-hulu'], array_column($third['shows'], 'id'));
        self::assertSame([], $fourth['state']['country']);
        self::assertSame(['japan-hulu', 'korea-hulu'], array_column($fourth['shows'], 'id'));
    }

    public function test_country_exclusion_uses_canonical_dataset_value(): void
    {
        $catalog = [
            ['id' => 'us', 'name' => 'US Comedy', 'aggregateRating' => 9.0,
                'genre' => 'Comedy', 'countryOfOrigin' => 'United States'],
            ['id' => 'jp', 'name' => 'Japan Comedy', 'aggregateRating' => 8.5,
                'genre' => 'Comedy', 'countryOfOrigin' => 'Japan'],
        ];

        $response = $this->chatbot()->respond('Cari comedy selain Amerika', $catalog);

        self::assertSame(['jp'], array_column($response['shows'], 'id'));
        self::assertSame(['United States'], $response['state']['excludeCountry']);
    }

    public function test_short_family_friendly_recommendations_exclude_war_and_keep_only_matching_results(): void
    {
        $catalog = [
            ['id' => 'short-family', 'name' => 'Short Family Show', 'aggregateRating' => 8.8,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-PG', 'duration' => 24,
                'keywords' => ['friendship'], 'description' => 'A warmhearted family comedy.'],
            ['id' => 'short-war', 'name' => 'War Documentary', 'aggregateRating' => 9.5,
                'genre' => 'History, Documentary, War', 'contentRating' => 'TV-PG', 'duration' => 25],
            ['id' => 'long-family', 'name' => 'Long Family Show', 'aggregateRating' => 9.9,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-PG', 'duration' => 48],
            ['id' => 'short-unrated', 'name' => 'Unrated Short Show', 'aggregateRating' => 10,
                'genre' => 'Family', 'contentRating' => '', 'duration' => 20],
            ['id' => 'short-adult', 'name' => 'Adult Short Show', 'aggregateRating' => 9.7,
                'genre' => 'Drama, Thriller', 'contentRating' => 'TV-MA', 'duration' => 20],
        ];

        $response = $this->chatbot()->respond(
            'Aku mau nonton di akhir pekan nanti kira-kira ada gak series dengan durasi singakt per episodenya, genre yang bisa ditonton semua orang dan ratingnya di atas 8?',
            $catalog
        );

        self::assertSame(['short-family'], array_column($response['shows'], 'id'));
        self::assertStringContainsString('rating di atas 8', $response['answer']);
        self::assertStringContainsString('maksimal 30 menit', $response['answer']);
        self::assertStringContainsString('ramah keluarga', $response['answer']);
    }

    public function test_recommendations_show_fewer_than_four_when_only_a_few_fully_match(): void
    {
        $catalog = [
            ['id' => 'match-1', 'name' => 'Match One', 'aggregateRating' => 9.0,
                'genre' => 'Family', 'contentRating' => 'TV-PG', 'duration' => 20,
                'keywords' => ['friendship'], 'description' => 'A family story about friendship.'],
            ['id' => 'match-2', 'name' => 'Match Two', 'aggregateRating' => 8.7,
                'genre' => 'Family', 'contentRating' => 'TV-PG', 'duration' => 30,
                'keywords' => ['adventure'], 'description' => 'A lighthearted family adventure.'],
            ['id' => 'war-1', 'name' => 'War One', 'aggregateRating' => 9.9,
                'genre' => 'War', 'contentRating' => 'TV-PG', 'duration' => 20],
            ['id' => 'too-long', 'name' => 'Too Long', 'aggregateRating' => 9.8,
                'genre' => 'Family', 'contentRating' => 'TV-PG', 'duration' => 31],
        ];

        $response = $this->chatbot()->respond(
            'Rekomendasikan series dengan durasi episode pendek yang bisa ditonton semua orang rating di atas 8',
            $catalog
        );

        self::assertSame(['match-1', 'match-2'], array_column($response['shows'], 'id'));
    }

    public function test_family_filter_checks_lgbt_content_in_overview_even_when_rating_is_tv_pg(): void
    {
        $catalog = [
            ['id' => 'clean', 'name' => 'Family Comedy', 'aggregateRating' => 8.8,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-PG',
                'keywords' => ['friendship'], 'description' => 'A warmhearted story about friendship.'],
            ['id' => 'skam', 'name' => 'Skam', 'aggregateRating' => 8.5,
                'genre' => 'Drama, Romance', 'contentRating' => 'TV-PG',
                'keywords' => ['friendship'], 'description' => 'Teenagers navigate school life and LGBT themes.'],
            ['id' => 'adult', 'name' => 'Adult Show', 'aggregateRating' => 9.5,
                'genre' => 'Comedy', 'contentRating' => 'TV-MA',
                'keywords' => ['friendship'], 'description' => 'A lighthearted story.'],
        ];

        $response = $this->chatbot()->respond('Ada rekomendasi serial aman ditonton bersama keluarga?', $catalog);

        self::assertSame(['clean'], array_column($response['shows'], 'id'));
        self::assertStringNotContainsString('Skam', json_encode($response));
    }

    public function test_underage_viewing_request_applies_family_filter_and_clears_previous_topic(): void
    {
        $catalog = [
            ['id' => 'safe', 'name' => 'Safe Family Series', 'aggregateRating' => 8.5,
                'genre' => 'Family, Adventure', 'contentRating' => 'TV-PG',
                'keywords' => ['friendship'], 'description' => 'A warmhearted story about friendship.'],
            ['id' => 'crime', 'name' => 'Crime Thriller', 'aggregateRating' => 9.8,
                'genre' => 'Crime, Thriller', 'contentRating' => 'TV-MA',
                'keywords' => ['crime'], 'description' => 'A crime thriller.'],
            ['id' => 'adult-comedy', 'name' => 'Adult Comedy', 'aggregateRating' => 9.2,
                'genre' => 'Comedy', 'contentRating' => 'TV-MA',
                'keywords' => ['comedy'], 'description' => 'An adult comedy.'],
        ];
        $service = $this->chatbot();
        $previousState = $service->respond('Cari serial horror', $catalog)['state'];

        $response = $service->respond(
            'series yang cocok ditonton aanak dibawha umur',
            $catalog,
            $previousState
        );

        self::assertSame(['safe'], array_column($response['shows'], 'id'));
        self::assertTrue($response['state']['familyFriendly']);
        self::assertSame([], $response['state']['genres']);
        self::assertStringNotContainsString('Crime Thriller', json_encode($response));
        self::assertStringNotContainsString('Adult Comedy', json_encode($response));
    }

    public function test_family_filter_scans_genre_keywords_and_overview_for_content_warnings(): void
    {
        $catalog = [
            ['id' => 'genre-warning', 'name' => 'War Story', 'aggregateRating' => 9.0,
                'genre' => 'Family, War', 'contentRating' => 'TV-PG',
                'keywords' => ['friendship'], 'description' => 'A story about friends.'],
            ['id' => 'keyword-warning', 'name' => 'Quiet Story', 'aggregateRating' => 8.9,
                'genre' => 'Family, Drama', 'contentRating' => 'TV-PG',
                'keywords' => ['substance abuse'], 'description' => 'A story about friends.'],
            ['id' => 'overview-warning', 'name' => 'School Life', 'aggregateRating' => 8.7,
                'genre' => 'Family, Drama', 'contentRating' => 'TV-PG',
                'keywords' => ['friendship'], 'description' => 'The story includes violence and profanity.'],
            ['id' => 'clean', 'name' => 'Friendship Story', 'aggregateRating' => 8.5,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-PG',
                'keywords' => ['friendship', 'school life'], 'description' => 'A warmhearted school comedy.'],
        ];

        $response = $this->chatbot()->respond('Cari serial family friendly', $catalog);

        self::assertSame(['clean'], array_column($response['shows'], 'id'));
    }

    public function test_family_filter_rejects_restricted_age_ratings_even_when_metadata_is_clean(): void
    {
        $catalog = [
            ['id' => 'allowed-tv-pg', 'name' => 'Family Show', 'aggregateRating' => 8.5,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-PG',
                'keywords' => ['friendship'], 'description' => 'A warmhearted family comedy.'],
            ['id' => 'tv-14', 'name' => 'Teen Show', 'aggregateRating' => 9.0,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-14',
                'keywords' => ['friendship'], 'description' => 'A warmhearted family comedy.'],
            ['id' => 'pg-13', 'name' => 'Older Teen Show', 'aggregateRating' => 9.1,
                'genre' => 'Family, Comedy', 'contentRating' => 'PG-13',
                'keywords' => ['friendship'], 'description' => 'A warmhearted family comedy.'],
            ['id' => '13-plus', 'name' => 'Thirteen Plus Show', 'aggregateRating' => 9.2,
                'genre' => 'Family, Comedy', 'contentRating' => '13+',
                'keywords' => ['friendship'], 'description' => 'A warmhearted family comedy.'],
            ['id' => '7-plus', 'name' => 'Seven Plus Show', 'aggregateRating' => 9.3,
                'genre' => 'Family, Comedy', 'contentRating' => '7+',
                'keywords' => ['friendship'], 'description' => 'A warmhearted family comedy.'],
            ['id' => 'pg', 'name' => 'Parental Guidance Show', 'aggregateRating' => 9.4,
                'genre' => 'Family, Comedy', 'contentRating' => 'PG',
                'keywords' => ['friendship'], 'description' => 'A warmhearted family comedy.'],
            ['id' => 'tv-y7', 'name' => 'Seven Plus TV Show', 'aggregateRating' => 9.5,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-Y7',
                'keywords' => ['friendship'], 'description' => 'A warmhearted family comedy.'],
        ];

        $response = $this->chatbot()->respond('Cari serial family friendly', $catalog);

        self::assertSame(['allowed-tv-pg'], array_column($response['shows'], 'id'));
    }

    public function test_family_filter_detects_mature_content_word_variants_across_metadata(): void
    {
        $catalog = [
            ['id' => 'clean', 'name' => 'Friendship Story', 'aggregateRating' => 8.5,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-PG',
                'keywords' => ['friendship'], 'description' => 'A warmhearted story about friends.'],
            ['id' => 'alcohol', 'name' => 'Family Story', 'aggregateRating' => 9.0,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-PG',
                'keywords' => ['alcohol use'], 'description' => 'A story about friends.'],
            ['id' => 'smoking', 'name' => 'Quiet Show', 'aggregateRating' => 9.1,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-PG',
                'keywords' => ['smoking'], 'description' => 'A story about friends.'],
            ['id' => 'adult-humor', 'name' => 'Cynical Comedy', 'aggregateRating' => 9.2,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-PG',
                'keywords' => ['sitcom'], 'description' => 'Cynical humor with sexual references.'],
            ['id' => 'profanity', 'name' => 'Language Show', 'aggregateRating' => 9.3,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-PG',
                'keywords' => ['strong language'], 'description' => 'A story about friends.'],
        ];

        $response = $this->chatbot()->respond('Cari serial family friendly', $catalog);

        self::assertSame(['clean'], array_column($response['shows'], 'id'));
    }

    public function test_family_filter_fails_closed_for_unknown_rating_or_incomplete_content_metadata(): void
    {
        $catalog = [
            ['id' => 'unknown-rating', 'name' => 'Unknown Rating', 'aggregateRating' => 9.0,
                'genre' => 'Family', 'contentRating' => 'NR',
                'keywords' => ['friendship'], 'description' => 'A family story.'],
            ['id' => 'missing-overview-keywords', 'name' => 'Incomplete Metadata', 'aggregateRating' => 9.1,
                'genre' => 'Family', 'contentRating' => 'TV-PG',
                'keywords' => [], 'description' => ''],
            ['id' => 'allowed-tv-pg', 'name' => 'Family Friendly', 'aggregateRating' => 8.4,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-PG',
                'keywords' => ['friendship'], 'description' => 'A lighthearted story about school friends.'],
            ['id' => 'blocked-tv14', 'name' => 'Teen Friendly', 'aggregateRating' => 9.0,
                'genre' => 'Family, Comedy', 'contentRating' => 'TV-14',
                'keywords' => ['friendship'], 'description' => 'A lighthearted story about school friends.'],
        ];

        $response = $this->chatbot()->respond('Cari tontonan family friendly', $catalog);

        self::assertSame(['allowed-tv-pg'], array_column($response['shows'], 'id'));
    }
}

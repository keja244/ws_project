<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatbotFeedbackTest extends TestCase
{
    public function test_detail_response_does_not_clear_search_filters_in_the_session(): void
    {
        $catalog = [
            [
                's' => ['type' => 'uri', 'value' => 'http://example.org/tvseries/series/sample-comedy'],
                'nameValue' => ['type' => 'literal', 'value' => 'Sample Comedy'],
                'ratingValue' => ['type' => 'literal', 'value' => '9.5'],
                'genres' => ['type' => 'literal', 'value' => 'Comedy'],
                'actors' => ['type' => 'literal', 'value' => 'Sample Actor'],
            ],
            [
                's' => ['type' => 'uri', 'value' => 'http://example.org/tvseries/series/sample-drama'],
                'nameValue' => ['type' => 'literal', 'value' => 'Sample Drama'],
                'ratingValue' => ['type' => 'literal', 'value' => '9.7'],
                'genres' => ['type' => 'literal', 'value' => 'Drama'],
                'actors' => ['type' => 'literal', 'value' => 'Another Actor'],
            ],
        ];
        Http::fake(function ($request) use ($catalog) {
            parse_str($request->body(), $payload);
            $bindings = str_contains($payload['query'] ?? '', 'SAMPLE(DISTINCT ?name)')
                ? $catalog
                : [];

            return Http::response(['results' => ['bindings' => $bindings]]);
        });

        $this->postJson('/api/chat', ['message' => 'Cari comedy rating minimal 8'])->assertOk();
        $this->postJson('/api/chat', ['message' => 'Pemeran Sample Comedy'])->assertOk();
        $response = $this->postJson('/api/chat', ['message' => 'Rating minimal 9']);

        $response->assertOk()
            ->assertJsonPath('shows.0.id', 'sample-comedy')
            ->assertJsonCount(1, 'shows');
    }

    public function test_chat_state_is_isolated_between_conversation_ids_in_the_same_session(): void
    {
        $catalog = [
            [
                's' => ['type' => 'uri', 'value' => 'http://example.org/tvseries/series/sample-comedy'],
                'nameValue' => ['type' => 'literal', 'value' => 'Sample Comedy'],
                'ratingValue' => ['type' => 'literal', 'value' => '9.5'],
                'genres' => ['type' => 'literal', 'value' => 'Comedy'],
                'actors' => ['type' => 'literal', 'value' => 'Sample Actor'],
            ],
            [
                's' => ['type' => 'uri', 'value' => 'http://example.org/tvseries/series/sample-drama'],
                'nameValue' => ['type' => 'literal', 'value' => 'Sample Drama'],
                'ratingValue' => ['type' => 'literal', 'value' => '9.7'],
                'genres' => ['type' => 'literal', 'value' => 'Drama'],
                'actors' => ['type' => 'literal', 'value' => 'Another Actor'],
            ],
        ];
        Http::fake(function ($request) use ($catalog) {
            parse_str($request->body(), $payload);
            $bindings = str_contains($payload['query'] ?? '', 'SAMPLE(DISTINCT ?name)')
                ? $catalog
                : [];

            return Http::response(['results' => ['bindings' => $bindings]]);
        });
        $firstConversation = (string) Str::uuid();
        $secondConversation = (string) Str::uuid();

        $this->postJson('/api/chat', [
            'message' => 'Cari comedy',
            'conversationId' => $firstConversation,
        ])->assertOk();

        $second = $this->postJson('/api/chat', [
            'message' => 'Rating minimal 9',
            'conversationId' => $secondConversation,
        ]);
        $firstFollowUp = $this->postJson('/api/chat', [
            'message' => 'Rating minimal 9',
            'conversationId' => $firstConversation,
        ]);

        $second->assertOk()->assertJsonCount(2, 'shows');
        $firstFollowUp->assertOk()
            ->assertJsonCount(1, 'shows')
            ->assertJsonPath('shows.0.id', 'sample-comedy');
    }

    public function test_chat_state_expires_after_thirty_minutes_of_inactivity(): void
    {
        $catalog = [
            [
                's' => ['type' => 'uri', 'value' => 'http://example.org/tvseries/series/sample-comedy'],
                'nameValue' => ['type' => 'literal', 'value' => 'Sample Comedy'],
                'ratingValue' => ['type' => 'literal', 'value' => '9.5'],
                'genres' => ['type' => 'literal', 'value' => 'Comedy'],
            ],
            [
                's' => ['type' => 'uri', 'value' => 'http://example.org/tvseries/series/sample-drama'],
                'nameValue' => ['type' => 'literal', 'value' => 'Sample Drama'],
                'ratingValue' => ['type' => 'literal', 'value' => '9.7'],
                'genres' => ['type' => 'literal', 'value' => 'Drama'],
            ],
        ];
        Http::fake(function ($request) use ($catalog) {
            parse_str($request->body(), $payload);
            $bindings = str_contains($payload['query'] ?? '', 'SAMPLE(DISTINCT ?name)')
                ? $catalog
                : [];

            return Http::response(['results' => ['bindings' => $bindings]]);
        });
        $conversationId = (string) Str::uuid();

        $this->postJson('/api/chat', [
            'message' => 'Cari comedy',
            'conversationId' => $conversationId,
        ])->assertOk()->assertJsonCount(1, 'shows');

        $this->travel(30)->minutes();

        $this->postJson('/api/chat', [
            'message' => 'Rating minimal 9',
            'conversationId' => $conversationId,
        ])->assertOk()->assertJsonCount(2, 'shows');
    }

    public function test_negative_feedback_is_logged_with_a_reason(): void
    {
        Log::spy();

        $response = $this->postJson('/api/chat/feedback', [
            'responseId' => (string) Str::uuid(),
            'helpful' => false,
            'reason' => 'irrelevant',
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Terima kasih atas feedback-mu.']);
        Log::shouldHaveReceived('info')->once();
    }

    public function test_negative_feedback_requires_a_supported_reason(): void
    {
        $this->postJson('/api/chat/feedback', [
            'responseId' => (string) Str::uuid(),
            'helpful' => false,
        ])->assertStatus(422);

        $this->postJson('/api/chat/feedback', [
            'responseId' => (string) Str::uuid(),
            'helpful' => false,
            'reason' => 'other',
        ])->assertStatus(422);
    }
}

<?php
// tests/Unit/Services/StripeCallTest.php
namespace Tests\Unit\Services;

use Core\Services\StripeService;
use Tests\TestCase;

/**
 * What the gateway client says when something goes wrong.
 *
 * `call()` returned null for everything — a declined card, a revoked key, a
 * network outage, a price that does not exist — and logged the real reason
 * where only a server admin with a log tail would find it. That is right for a
 * checkout, where a customer must never be shown the gateway's wording, and it
 * is useless for the one screen whose entire job is answering "why can nobody
 * buy anything". A setup page cannot explain a null.
 *
 * ⚠ THE EVIDENCE THIS WAS WORTH DOING WAS IN A REAL SCREEN: the admin
 * integration test reported a Stripe failure as *"Check STRIPE_SECRET_KEY and
 * the PHP error log"* — sending an operator to hunt for a sentence Stripe had
 * already handed us.
 *
 * So failures hand their reason back out. These tests exist because the
 * decision of WHICH reason is a decision, and it was sitting in the same method
 * as a live HTTP call where nothing could reach it — `fetch()` was split off
 * for exactly that, and is the only line here that is stubbed.
 */
final class StripeCallTest extends TestCase
{
    private function stripe(string|false $body): FakeTransportStripe
    {
        $_ENV['STRIPE_SECRET_KEY'] = 'sk_test_call';
        $s = new FakeTransportStripe();
        $s->body = $body;

        return $s;
    }

    protected function tearDown(): void
    {
        $_ENV['STRIPE_SECRET_KEY'] = '';
        parent::tearDown();
    }

    public function test_stripes_own_words_come_back(): void
    {
        // "No such price: price_abc" is the whole answer to an operator's
        // question. Anything this app writes instead is a paraphrase of
        // something it does not know.
        $s = $this->stripe((string) json_encode([
            'error' => ['type' => 'invalid_request_error', 'message' => 'No such price: price_abc'],
        ]));

        $error = null;
        $out = $s->call('GET', '/v1/prices/price_abc', [], $error);

        $this->assertNull($out);
        $this->assertSame('No such price: price_abc', $error);
    }

    public function test_a_refusal_with_no_message_still_says_something(): void
    {
        $s = $this->stripe((string) json_encode(['error' => ['type' => 'card_error']]));

        $error = null;
        $s->call('POST', '/v1/refunds', [], $error);

        $this->assertNotNull($error, 'A refusal Stripe did not explain became an empty string.');
        $this->assertStringContainsString('card_error', $error);
    }

    public function test_an_outage_is_not_reported_as_a_refusal(): void
    {
        // The distinction the whole feature rests on: "Stripe said no" and
        // "Stripe did not answer" need different things done about them.
        $s = $this->stripe(false);

        $error = null;
        $s->call('GET', '/v1/account', [], $error);

        $this->assertSame('Stripe could not be reached.', $error);
    }

    public function test_a_reply_that_is_not_json_says_so(): void
    {
        $s = $this->stripe('<html>502 Bad Gateway</html>');

        $error = null;
        $s->call('GET', '/v1/account', [], $error);

        $this->assertNotNull($error);
        $this->assertStringContainsString('not JSON', $error);
    }

    public function test_having_no_key_is_a_reason_of_its_own(): void
    {
        // Before this, a missing key and a working one that failed were the
        // same null — so the setup page could not tell "you have not configured
        // this yet" from "what you configured is broken".
        $_ENV['STRIPE_SECRET_KEY'] = '';
        $s = new FakeTransportStripe();

        $error = null;
        $out = $s->call('GET', '/v1/account', [], $error);

        $this->assertNull($out);
        $this->assertNotNull($error, 'An unconfigured gateway failed with no reason at all.');
        $this->assertStringContainsString('No Stripe secret key', $error);
        $this->assertSame(0, $s->fetches, 'It tried to call Stripe with no key.');
    }

    public function test_a_success_clears_the_reason(): void
    {
        // The variable is reused across calls in a loop over plans. A stale
        // message left on a success would attach the previous plan's problem
        // to a price that is fine.
        $s = $this->stripe((string) json_encode(['id' => 'price_ok', 'active' => true]));

        $error = 'something from the last call';
        $out = $s->call('GET', '/v1/prices/price_ok', [], $error);

        $this->assertSame('price_ok', $out['id'] ?? null);
        $this->assertNull($error, 'A successful call left the previous failure in place.');
    }

    public function test_the_key_is_sent_and_is_never_in_the_reason(): void
    {
        // The reason is rendered on an admin page. Stripe echoes a masked key
        // in some messages; this app must not add an unmasked one of its own.
        $s = $this->stripe((string) json_encode([
            'error' => ['type' => 'authentication_error', 'message' => 'Invalid API Key provided: sk_test_***'],
        ]));

        $error = null;
        $s->call('GET', '/v1/account', [], $error);

        $this->assertStringContainsString('Authorization: Bearer sk_test_call', $s->lastHeader,
            'The key was not sent, so this proves nothing about what came back.');
        $this->assertStringNotContainsString('sk_test_call', (string) $error);
    }

    public function test_an_existing_caller_that_passes_no_error_is_unaffected(): void
    {
        // ⚠ THE PORT'S WHOLE SAFETY ARGUMENT. Every call site in both repos
        // uses the three-argument form; if the added parameter changed their
        // behaviour in any way this would be a breaking change dressed as an
        // improvement.
        $s = $this->stripe((string) json_encode(['id' => 'acct_1', 'object' => 'account']));

        $out = $s->call('GET', '/v1/account');

        $this->assertSame('acct_1', $out['id'] ?? null);

        $bad = $this->stripe((string) json_encode(['error' => ['message' => 'nope']]));
        $this->assertNull($bad->call('GET', '/v1/account'), 'A failure stopped returning null.');
    }
}

/** The real `call()`, with the one line that touches the network replaced. */
final class FakeTransportStripe extends StripeService
{
    public string|false $body = '{}';
    public int $fetches = 0;
    public string $lastHeader = '';
    public string $lastUrl = '';

    protected function fetch(string $url, array $ctx): string|false
    {
        $this->fetches++;
        $this->lastUrl    = $url;
        $this->lastHeader = (string) ($ctx['http']['header'] ?? '');

        return $this->body;
    }
}

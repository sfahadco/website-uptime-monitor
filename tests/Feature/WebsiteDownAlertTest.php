<?php

namespace Tests\Feature;

use App\Enums\WebsiteStatusEnum;
use App\Jobs\MonitorWebsiteBatchJob;
use App\Mail\WebsiteDownEmail;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebsiteDownAlertTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A site becoming unreachable is the trigger. Both UP and UNKNOWN are
     * "not currently down", so both must produce an alert.
     */
    #[DataProvider('statusesThatShouldAlert')]
    public function test_it_queues_an_alert_when_a_site_transitions_to_down(
        WebsiteStatusEnum $previousStatus
    ): void {
        Mail::fake();
        Http::fake(['*' => Http::response('', 503)]);

        $website = Website::factory()->create(['status' => $previousStatus]);

        $this->runJobFor($website);

        Mail::assertQueued(WebsiteDownEmail::class, function (WebsiteDownEmail $mail) use ($website) {
            return $mail->hasTo($website->client->email)
                && $mail->website->is($website);
        });
    }

    public static function statusesThatShouldAlert(): array
    {
        return [
            'was up'      => [WebsiteStatusEnum::UP],
            'never checked' => [WebsiteStatusEnum::UNKNOWN],
        ];
    }

    public function test_it_does_not_alert_again_while_a_site_stays_down(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response('', 503)]);

        $website = Website::factory()->create([
            'status' => WebsiteStatusEnum::DOWN,
        ]);

        $this->runJobFor($website);

        $this->assertSame(WebsiteStatusEnum::DOWN, $website->fresh()->status);

        Mail::assertNothingQueued();
    }

    public function test_it_does_not_alert_when_a_site_is_reachable(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response('OK', 200)]);

        $website = Website::factory()->create([
            'status' => WebsiteStatusEnum::UP,
        ]);

        $this->runJobFor($website);

        Mail::assertNothingQueued();
    }

    public function test_the_subject_and_body_both_state_the_url_is_down(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response('', 503)]);

        $website = Website::factory()->create([
            'url'    => 'https://example.test',
            'status' => WebsiteStatusEnum::UP,
        ]);

        $this->runJobFor($website);

        Mail::assertQueued(WebsiteDownEmail::class, function (WebsiteDownEmail $mail) {
            $mail->assertHasSubject('https://example.test is down!');
            $mail->assertSeeInHtml('https://example.test is down!');

            return true;
        });
    }

    public function test_the_alert_is_sent_from_the_no_reply_address(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $website = Website::factory()->create([
            'status' => WebsiteStatusEnum::UP,
        ]);

        $this->runJobFor($website);

        $messages = Mail::mailer()->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages);

        $from = $messages[0]->getOriginalMessage()->getFrom();

        $this->assertSame('do-not-reply@example.com', $from[0]->getAddress());
    }

    /**
     * The assertion above only proves the address `.env.testing` happens to
     * supply. The no-reply sender has to hold in an environment that never sets
     * `MAIL_FROM_ADDRESS`, so check the shipped default with the variable gone.
     */
    public function test_the_no_reply_sender_does_not_depend_on_the_environment(): void
    {
        $repository = Env::getRepository();
        $original = $repository->get('MAIL_FROM_ADDRESS');

        $repository->clear('MAIL_FROM_ADDRESS');

        try {
            $config = require config_path('mail.php');
        } finally {
            if ($original !== null) {
                $repository->set('MAIL_FROM_ADDRESS', $original);
            }
        }

        $this->assertSame('do-not-reply@example.com', $config['from']['address']);
    }

    private function runJobFor(Website $website): void
    {
        new MonitorWebsiteBatchJob([$website->id])->handle();
    }
}

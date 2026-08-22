<?php

namespace Tests\Unit;

use App\Enums\WebsiteStatusEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebsiteStatusEnumTest extends TestCase
{
    #[DataProvider('statusTransitions')]
    public function test_only_entering_the_down_state_starts_a_new_outage(
        ?WebsiteStatusEnum $previous,
        WebsiteStatusEnum $current,
        bool $expected,
    ): void {
        $this->assertSame($expected, $current->isNewOutageFrom($previous));
    }

    public static function statusTransitions(): array
    {
        return [
            // Reaching down from anything else is a new outage.
            'unknown -> down'       => [WebsiteStatusEnum::UNKNOWN, WebsiteStatusEnum::DOWN, true],
            'up -> down'            => [WebsiteStatusEnum::UP, WebsiteStatusEnum::DOWN, true],

            // Already down: the client was told when it started.
            'down -> down'          => [WebsiteStatusEnum::DOWN, WebsiteStatusEnum::DOWN, false],

            // Recovering is not an outage, and no "back up" email is sent.
            'down -> up'            => [WebsiteStatusEnum::DOWN, WebsiteStatusEnum::UP, false],

            // Nothing that ends anywhere but down can alert.
            'unknown -> up'            => [WebsiteStatusEnum::UNKNOWN, WebsiteStatusEnum::UP, false],
            'unknown -> unknown'       => [WebsiteStatusEnum::UNKNOWN, WebsiteStatusEnum::UNKNOWN, false],
            'up -> up'                 => [WebsiteStatusEnum::UP, WebsiteStatusEnum::UP, false],
            'up -> unknown'            => [WebsiteStatusEnum::UP, WebsiteStatusEnum::UNKNOWN, false],
        ];
    }

    public function test_a_continuing_outage_alerts_only_on_the_first_check(): void
    {
        $previous = WebsiteStatusEnum::UP;
        $alerts = 0;

        // Ten consecutive failed checks, as the scheduler would produce.
        for ($check = 0; $check < 10; $check++) {
            $current = WebsiteStatusEnum::DOWN;

            if ($current->isNewOutageFrom($previous)) {
                $alerts++;
            }

            $previous = $current;
        }

        $this->assertSame(1, $alerts);
    }

    public function test_recovering_re_arms_the_alert(): void
    {
        $this->assertTrue(WebsiteStatusEnum::DOWN->isNewOutageFrom(WebsiteStatusEnum::UP));
        $this->assertFalse(WebsiteStatusEnum::DOWN->isNewOutageFrom(WebsiteStatusEnum::DOWN));

        // Recovered
        $this->assertFalse(WebsiteStatusEnum::UP->isNewOutageFrom(WebsiteStatusEnum::DOWN));
    }
}

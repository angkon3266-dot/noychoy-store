<?php

namespace Tests\Unit;

use App\Services\DashboardInsights;
use PHPUnit\Framework\TestCase;

/**
 * How many billable parts a text is. The dashboard's SMS cost line
 * (owner, 2026-09-18: "add other analytical info on the dashboard") is
 * segments × the per-segment rate, so the count has to follow the gateway's
 * rules: GSM-7 at 160 (153 a part once split, extension characters costing
 * two) and UCS-2 at 70 (67 a part) the moment a Bangla letter or an emoji
 * appears.
 */
class SmsSegmentsTest extends TestCase
{
    public function test_gsm_text_is_one_part_up_to_160_characters_then_153_a_part(): void
    {
        $this->assertSame(0, DashboardInsights::smsSegments(''));
        $this->assertSame(1, DashboardInsights::smsSegments('Your order 10023 is on its way.'));
        $this->assertSame(1, DashboardInsights::smsSegments(str_repeat('a', 160)));
        $this->assertSame(2, DashboardInsights::smsSegments(str_repeat('a', 161)));
        $this->assertSame(2, DashboardInsights::smsSegments(str_repeat('a', 306)));
        $this->assertSame(3, DashboardInsights::smsSegments(str_repeat('a', 307)));
    }

    public function test_gsm_extension_characters_cost_two(): void
    {
        // 159 plain characters plus one brace is 161 septets: two parts.
        $this->assertSame(2, DashboardInsights::smsSegments(str_repeat('a', 159).'{'));
        $this->assertSame(1, DashboardInsights::smsSegments(str_repeat('a', 158).'€'));
    }

    public function test_any_bangla_or_emoji_makes_the_text_ucs2_at_70_a_part(): void
    {
        $this->assertSame(1, DashboardInsights::smsSegments('হ্যালো'));
        $this->assertSame(1, DashboardInsights::smsSegments(str_repeat('ক', 70)));
        $this->assertSame(2, DashboardInsights::smsSegments(str_repeat('ক', 71)));
        $this->assertSame(2, DashboardInsights::smsSegments(str_repeat('ক', 134)));
        $this->assertSame(3, DashboardInsights::smsSegments(str_repeat('ক', 135)));
        // One emoji in an otherwise-GSM text switches the whole message.
        $this->assertSame(2, DashboardInsights::smsSegments(str_repeat('a', 80).'🎉'));
    }
}

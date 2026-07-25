<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * WhatsApp needs the international 880… form while numbers are stored locally
 * as 01…, so the conversion happens when the link is built — the same shape as
 * SmsService converting for the SMS gateway.
 */
class WhatsAppLinkTest extends TestCase
{
    public function test_every_stored_or_typed_format_becomes_a_wa_me_number(): void
    {
        foreach (['01711195772', '1711195772', '01711-195772', '+880 1711-195772', '8801711195772'] as $input) {
            $this->assertSame('8801711195772', wa_phone($input), "failed for: {$input}");
        }
    }

    public function test_a_missing_number_yields_no_link(): void
    {
        $this->assertSame('', wa_phone(null));
        $this->assertNull(wa_link(null));
        $this->assertNull(wa_link(''));
    }

    public function test_the_link_carries_a_url_encoded_message(): void
    {
        $link = wa_link('01711195772', 'Hello Rahim, order #10001 & thanks!');

        $this->assertStringStartsWith('https://wa.me/8801711195772?text=', $link);
        // Ampersands must be encoded or they truncate the prefilled message.
        $this->assertStringContainsString('%26', $link);
        $this->assertStringNotContainsString(' ', $link);
    }
}

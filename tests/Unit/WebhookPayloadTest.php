<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Webhook\WebhookPayload;
use PHPUnit\Framework\TestCase;

final class WebhookPayloadTest extends TestCase
{
    public function testFromJsonFiscalized(): void
    {
        $json = json_encode([
            'event' => 'invoice.fiscalized',
            'pfr_broj' => 'ABC-123',
            'racun_id' => 42,
            'iznos' => 15000.50,
            'status' => 'success',
        ]);

        $payload = WebhookPayload::fromJson($json);

        $this->assertSame('invoice.fiscalized', $payload->event);
        $this->assertSame('ABC-123', $payload->pfrBroj);
        $this->assertSame(42, $payload->racunId);
        $this->assertSame(15000.50, $payload->iznos);
        $this->assertTrue($payload->isFiscalized());
        $this->assertFalse($payload->isFailed());
    }

    public function testFromJsonFailed(): void
    {
        $json = json_encode([
            'event' => 'invoice.failed',
            'racun_id' => 43,
            'status' => 'failed',
        ]);

        $payload = WebhookPayload::fromJson($json);

        $this->assertTrue($payload->isFailed());
        $this->assertFalse($payload->isFiscalized());
        $this->assertNull($payload->pfrBroj);
    }

    public function testFromArrayPreservesRawData(): void
    {
        $data = [
            'event' => 'invoice.fiscalized',
            'pfr_broj' => 'X-1',
            'custom_field' => 'extra',
        ];

        $payload = WebhookPayload::fromArray($data);

        $this->assertSame('extra', $payload->rawData['custom_field']);
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WebhookPayload::fromJson('not-json');
    }
}

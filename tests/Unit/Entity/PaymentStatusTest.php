<?php

namespace App\Tests\Unit\Entity;

use App\Entity\PaymentStatus;
use PHPUnit\Framework\TestCase;

class PaymentStatusTest extends TestCase
{
    public function testOnlyCreditedStatusesAreConsideredSuccessful(): void
    {
        foreach (PaymentStatus::credited() as $status) {
            self::assertTrue($status->isSuccessful(), $status->name . ' should be considered successful');
        }

        foreach (PaymentStatus::cases() as $status) {
            if (\in_array($status, PaymentStatus::credited(), true)) {
                continue;
            }
            self::assertFalse($status->isSuccessful(), $status->name . ' should not be considered successful');
        }
    }

    public function testEveryStatusHasALabelAndBadgeClass(): void
    {
        foreach (PaymentStatus::cases() as $status) {
            self::assertNotSame('', $status->label());
            self::assertStringStartsWith('badge--', $status->badgeClass());
        }
    }
}

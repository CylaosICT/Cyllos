<?php

namespace App\Tests\Unit\Entity;

use App\Entity\PaymentStatus;
use PHPUnit\Framework\TestCase;

class PaymentStatusTest extends TestCase
{
    public function testOnlySuccessAndSuccessAutoAreConsideredSuccessful(): void
    {
        self::assertTrue(PaymentStatus::Success->isSuccessful());
        self::assertTrue(PaymentStatus::SuccessAuto->isSuccessful());

        foreach (PaymentStatus::cases() as $status) {
            if ($status === PaymentStatus::Success || $status === PaymentStatus::SuccessAuto) {
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

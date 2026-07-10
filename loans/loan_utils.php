<?php

function calcMonthlyPayment(float $principal, float $annualRate, int $termMonths): float {
    if ($annualRate <= 0 || $termMonths <= 0) {
        return $termMonths > 0 ? round($principal / $termMonths, 2) : 0.0;
    }
    $r = $annualRate / 100 / 12;
    return round($principal * $r / (1 - pow(1 + $r, -$termMonths)), 2);
}

function getLoanFirstPaymentDate(array $loan): string {
    return date('Y-m-d', strtotime('+1 month', strtotime($loan['start_date'])));
}

function buildAmortizationSchedule(array $loan, string $firstPaymentDate): array {
    $r          = (float)$loan['annual_rate'] / 100 / 12;
    $payment    = (float)$loan['payment_amount'];
    $balance    = (float)$loan['original_amount'];
    $date       = $firstPaymentDate;
    $termMonths = (int)$loan['term_months'];
    $rows       = [];

    for ($i = 1; $i <= $termMonths && $balance > 0.005; $i++) {
        $interest  = round($balance * $r, 2);
        $principal = round($payment - $interest, 2);

        // Final payment: principal can't exceed remaining balance
        if ($principal >= $balance) {
            $principal    = round($balance, 2);
            $payment_this = $principal + $interest;
        } else {
            $payment_this = $payment;
        }

        $balance = round($balance - $principal, 2);
        if ($balance < 0.005) $balance = 0.0;

        $rows[] = [
            'num'       => $i,
            'date'      => $date,
            'payment'   => $payment_this,
            'principal' => $principal,
            'interest'  => $interest,
            'balance'   => $balance,
        ];

        $date = date('Y-m-d', strtotime('+1 month', strtotime($date)));
    }

    return $rows;
}

<?php
// src/SyncService.php

declare(strict_types=1);

class SyncService
{
    private PDO $pdo;
    private PcoClient $pco;

    public function __construct(PDO $pdo, PcoClient $pco)
    {
        $this->pdo = $pdo;
        $this->pco = $pco;
    }

    /**
     * Build a preview of Stripe donations to deposit, optionally skipping donation IDs.
     *
     * Online donations can be updated several days after they complete when PCO
     * receives the final Stripe payout/fee details. Use updated_at for the sync
     * window so those payout updates are not lost behind a completed_at cursor.
     * Returns aggregates split by weekly payout date and fund. A payout/fund
     * row becomes one QBO Deposit, so a deposit never mixes fund types.
     */
    public function buildDepositPreview(DateTimeImmutable $sinceUtc, ?DateTimeImmutable $untilUtc = null, array $skipDonationIds = []): array
    {
        $fundMappings = $this->loadFundMappings(); // [fund_id => row]
        $nowUtc       = $untilUtc ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $skipSet      = array_fill_keys($skipDonationIds, true);
        $donationIdsByLocation = [];

        $resp = $this->pco->listDonations([
            'per_page' => 100,
            'order'    => '-updated_at',
            'include'  => 'designations',
        ]);

        $fundTotals         = []; // keyed by fund_id
        $donationCount      = 0;
        $processedDonations = 0;
        $skippedOffline     = 0;
        $deferredForPayout  = 0;
        $skippedUnmapped    = [];
        $incompleteDonationIds = [];

        // Index "included" designations by type:id
        $includedByKey = [];
        if (!empty($resp['included']) && is_array($resp['included'])) {
            foreach ($resp['included'] as $inc) {
                $type = $inc['type'] ?? '';
                $id   = $inc['id']   ?? '';
                if ($type && $id) {
                    $includedByKey["{$type}:{$id}"] = $inc;
                }
            }
        }

        if (empty($resp['data']) || !is_array($resp['data'])) {
            return [
                'since'               => $sinceUtc,
                'until'               => $nowUtc,
                'funds'               => [],
                'total_gross'         => 0.0,
                'total_fee'           => 0.0,
                'total_net'           => 0.0,
                'donation_count'      => 0,
                'processed_donations' => 0,
                'skipped_offline'     => 0,
                'deferred_for_payout' => 0,
                'skipped_unmapped'    => $skippedUnmapped,
                'incomplete_donation_ids' => [],
                'donation_ids_by_location' => [],
            ];
        }

        foreach ($resp['data'] as $donation) {
            $id    = (string)($donation['id'] ?? '');
            $attrs = $donation['attributes'] ?? [];
            $rels  = $donation['relationships'] ?? [];

            $completedAtStr = $attrs['completed_at'] ?? null;
            $updatedAtStr   = $attrs['updated_at'] ?? $completedAtStr;
            if (!$completedAtStr || !$updatedAtStr) {
                continue;
            }
            try {
                $completedAt = new DateTimeImmutable($completedAtStr);
                $updatedAt   = new DateTimeImmutable($updatedAtStr);
            } catch (Throwable $e) {
                continue;
            }
            if ($updatedAt < $sinceUtc || $updatedAt > $nowUtc) {
                continue;
            }

            $paymentStatus = strtolower((string)($attrs['payment_status'] ?? ''));
            $paymentMethod = strtolower((string)($attrs['payment_method'] ?? ''));
            $refunded      = (bool)($attrs['refunded'] ?? false);
            if ($paymentStatus !== 'succeeded' || $refunded) {
                continue;
            }
            if (isset($skipSet[$id])) {
                continue;
            }

            $onlineMethods = ['card', 'ach'];
            if (!in_array($paymentMethod, $onlineMethods, true)) {
                $skippedOffline++;
                continue;
            }

            // This installation receives weekly Stripe payouts on Monday.
            // PCO updates the donation when the payout/fee details settle. Do
            // not post a successful gift immediately; wait for that update and
            // assign it to the date of that payout update.
            $earliestPayoutAt = $this->getEarliestWeeklyPayoutDate($completedAt);
            if ($nowUtc < $earliestPayoutAt || $updatedAt < $earliestPayoutAt) {
                $deferredForPayout++;
                continue;
            }
            // The actual PCO payout update date is the QBO transaction date.
            // This also handles a bank-holiday delay without forcing Monday.
            $payoutDate = $updatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');

            $donationCount++;

            $feeCentsAbs = abs((int)($attrs['fee_cents'] ?? 0));

            $designationRefs = $rels['designations']['data'] ?? [];
            if (empty($designationRefs) || !is_array($designationRefs)) {
                $skippedUnmapped[] = [
                    'donation_id'   => $id,
                    'reason'        => 'No designations',
                    'amount_cents'  => (int)($attrs['amount_cents'] ?? 0),
                    'payment_method'=> $paymentMethod,
                ];
                continue;
            }

            $designationDetails = [];
            $designationTotalCents = 0;
            foreach ($designationRefs as $desRef) {
                $dtype = $desRef['type'] ?? '';
                $did   = $desRef['id']   ?? '';
                $key   = "{$dtype}:{$did}";
                if (!isset($includedByKey[$key])) {
                    continue;
                }
                $des = $includedByKey[$key];
                $desAttrs = $des['attributes'] ?? [];
                $desAmt   = (int)($desAttrs['amount_cents'] ?? 0);
                $desFundId = $des['relationships']['fund']['data']['id'] ?? null;
                if ($desFundId === null || $desAmt === 0) {
                    continue;
                }
                $desFundId = (string)$desFundId;
                $designationDetails[] = [
                    'fund_id'      => $desFundId,
                    'amount_cents' => $desAmt,
                ];
                $designationTotalCents += $desAmt;
            }

            if ($designationTotalCents <= 0 || empty($designationDetails)) {
                $skippedUnmapped[] = [
                    'donation_id'   => $id,
                    'reason'        => 'Designations total zero or missing',
                    'amount_cents'  => (int)($attrs['amount_cents'] ?? 0),
                    'payment_method'=> $paymentMethod,
                ];
                continue;
            }

            $processedDonations++;

            $remainingFeeCents = $feeCentsAbs;
            $desCount          = count($designationDetails);
            $donationHasUnmappedFund = false;

            foreach ($designationDetails as $idx => $des) {
                $fundId = $des['fund_id'];
                $gross  = $des['amount_cents'];

                if ($gross <= 0) {
                    continue;
                }

                $feeShareCents = 0;
                if ($feeCentsAbs > 0) {
                    if ($idx === $desCount - 1) {
                        $feeShareCents = $remainingFeeCents;
                    } else {
                        $ratio         = $gross / $designationTotalCents;
                        $feeShareCents = (int)round($feeCentsAbs * $ratio);
                        $remainingFeeCents -= $feeShareCents;
                    }
                }

                if (!isset($fundMappings[$fundId])) {
                    $donationHasUnmappedFund = true;
                    $skippedUnmapped[] = [
                        'donation_id'   => $id,
                        'reason'        => "Fund {$fundId} not mapped",
                        'fund_id'       => $fundId,
                        'amount_cents'  => $gross,
                        'payment_method'=> $paymentMethod,
                    ];
                    continue;
                }

                $map = $fundMappings[$fundId];
                $key = $payoutDate . '|' . (string)$fundId;

                if (!isset($fundTotals[$key])) {
                    $fundTotals[$key] = [
                        'payout_date'       => $payoutDate,
                        'pco_fund_id'       => $fundId,
                        'pco_fund_name'     => $map['pco_fund_name'],
                        'qbo_class_name'    => $map['qbo_class_name'],
                        'qbo_location_name' => $map['qbo_location_name'],
                        'qbo_income_account_name' => $map['qbo_income_account_name'] ?? null,
                        'gross_cents'       => 0,
                        'fee_cents'         => 0,
                        'payment_methods'   => [],
                        'method_totals'     => [],
                        'donation_ids'      => [],
                    ];
                }

                $fundTotals[$key]['gross_cents'] += $gross;
                $fundTotals[$key]['fee_cents']   += $feeShareCents;
                if ($paymentMethod !== '') {
                    $fundTotals[$key]['payment_methods'][$paymentMethod] = true;
                }
                $methodKey = $paymentMethod !== '' ? $paymentMethod : '(unspecified)';
                if (!isset($fundTotals[$key]['method_totals'][$methodKey])) {
                    $fundTotals[$key]['method_totals'][$methodKey] = [
                        'gross_cents' => 0,
                        'fee_cents'   => 0,
                    ];
                }
                $fundTotals[$key]['method_totals'][$methodKey]['gross_cents'] += $gross;
                $fundTotals[$key]['method_totals'][$methodKey]['fee_cents']   += $feeShareCents;
                $fundTotals[$key]['donation_ids'][$id] = true;

                $locKey = $map['qbo_location_name'] ?? '__NO_LOCATION__';
                if (!isset($donationIdsByLocation[$locKey])) {
                    $donationIdsByLocation[$locKey] = [];
                }
                $donationIdsByLocation[$locKey][$id] = true;
            }

            if ($donationHasUnmappedFund) {
                $incompleteDonationIds[$id] = true;
            }
        }

        $totalGrossCents = 0;
        $totalFeeCents   = 0;

        $fundsOut = [];
        foreach ($fundTotals as $row) {
            $grossCents = (int)$row['gross_cents'];
            $feeCents   = (int)$row['fee_cents'];
            $netCents   = $grossCents - $feeCents;

            $totalGrossCents += $grossCents;
            $totalFeeCents   += $feeCents;

            $methodTotals = [];
            foreach ($row['method_totals'] ?? [] as $method => $totals) {
                $methodGrossCents = (int)($totals['gross_cents'] ?? 0);
                $methodFeeCents   = (int)($totals['fee_cents'] ?? 0);
                $methodTotals[$method] = [
                    'gross' => $methodGrossCents / 100.0,
                    'fee'   => $methodFeeCents / 100.0,
                    'net'   => ($methodGrossCents - $methodFeeCents) / 100.0,
                ];
            }

            $fundsOut[] = [
                'payout_date'       => $row['payout_date'],
                'pco_fund_id'       => $row['pco_fund_id'],
                'pco_fund_name'     => $row['pco_fund_name'],
                'qbo_class_name'    => $row['qbo_class_name'],
                'qbo_location_name' => $row['qbo_location_name'],
                'qbo_income_account_name' => $row['qbo_income_account_name'] ?? null,
                'gross'             => $grossCents / 100.0,
                'fee'               => $feeCents / 100.0,
                'net'               => $netCents / 100.0,
                'payment_methods'   => array_keys($row['payment_methods'] ?? []),
                'method_totals'     => $methodTotals,
                'donation_ids'      => array_keys($row['donation_ids'] ?? []),
            ];
        }

        usort($fundsOut, function (array $a, array $b): int {
            return [$a['payout_date'], $a['pco_fund_name']] <=> [$b['payout_date'], $b['pco_fund_name']];
        });

        return [
            'since'               => $sinceUtc,
            'until'               => $nowUtc,
            'funds'               => $fundsOut,
            'total_gross'         => $totalGrossCents / 100.0,
            'total_fee'           => $totalFeeCents / 100.0,
            'total_net'           => ($totalGrossCents - $totalFeeCents) / 100.0,
            'donation_count'      => $donationCount,
            'processed_donations' => $processedDonations,
            'skipped_offline'     => $skippedOffline,
            'deferred_for_payout' => $deferredForPayout,
            'skipped_unmapped'    => $skippedUnmapped,
            'incomplete_donation_ids' => array_keys($incompleteDonationIds),
            'donation_ids_by_location' => array_map('array_keys', $donationIdsByLocation),
        ];
    }

    /**
     * Return the earliest scheduled weekly payout date: the first Monday after
     * completion, at midnight UTC. The donation is held until PCO updates it on
     * or after this boundary; that update's date becomes the actual payout date.
     */
    private function getEarliestWeeklyPayoutDate(DateTimeImmutable $completedAt): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        return $completedAt
            ->setTimezone($utc)
            ->setTime(0, 0, 0)
            ->modify('next monday');
    }

    /**
     * Build a list of refunded Stripe donations updated within the window.
     */
    public function buildRefundPreview(DateTimeImmutable $sinceUtc, ?DateTimeImmutable $untilUtc = null, array $skipRefundIds = []): array
    {
        $fundMappings = $this->loadFundMappings();
        $nowUtc       = $untilUtc ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $skipSet      = array_fill_keys($skipRefundIds, true);

        $resp = $this->pco->listDonations([
            'per_page' => 100,
            'order'    => '-updated_at',
            'include'  => 'designations',
        ]);

        $includedByKey = [];
        if (!empty($resp['included']) && is_array($resp['included'])) {
            foreach ($resp['included'] as $inc) {
                $type = $inc['type'] ?? '';
                $id   = $inc['id']   ?? '';
                if ($type && $id) {
                    $includedByKey["{$type}:{$id}"] = $inc;
                }
            }
        }

        $refunds = [];
        $skippedUnmapped = [];
        $totalRefundCents = 0;

        foreach ($resp['data'] ?? [] as $donation) {
            $id    = (string)($donation['id'] ?? '');
            $attrs = $donation['attributes'] ?? [];
            $rels  = $donation['relationships'] ?? [];

            $updatedAtStr   = $attrs['updated_at'] ?? null;
            $completedAtStr = $attrs['completed_at'] ?? null;
            if (!$updatedAtStr || !$completedAtStr) {
                continue;
            }

            try {
                $updatedAt = new DateTimeImmutable($updatedAtStr);
            } catch (Throwable $e) {
                continue;
            }

            if ($updatedAt < $sinceUtc || $updatedAt > $nowUtc) {
                continue;
            }

            $refunded = (bool)($attrs['refunded'] ?? false);
            $paymentStatus = strtolower((string)($attrs['payment_status'] ?? ''));
            if (!$refunded || $paymentStatus !== 'succeeded') {
                continue;
            }
            if (isset($skipSet[$id])) {
                continue;
            }

            $designationRefs = $rels['designations']['data'] ?? [];
            if (empty($designationRefs) || !is_array($designationRefs)) {
                $skippedUnmapped[] = ['donation_id' => $id, 'reason' => 'No designations for refund'];
                continue;
            }

            $lines = [];
            foreach ($designationRefs as $desRef) {
                $dtype = $desRef['type'] ?? '';
                $did   = $desRef['id']   ?? '';
                $key   = "{$dtype}:{$did}";
                if (!isset($includedByKey[$key])) {
                    continue;
                }
                $des      = $includedByKey[$key];
                $desAttrs = $des['attributes'] ?? [];
                $desAmt   = (int)($desAttrs['amount_cents'] ?? 0);
                $fundId   = $des['relationships']['fund']['data']['id'] ?? null;
                if ($fundId === null || $desAmt === 0) {
                    continue;
                }
                $fundKey = (string)$fundId;
                $map     = $fundMappings[$fundKey] ?? null;
                if (!$map) {
                    $skippedUnmapped[] = ['donation_id' => $id, 'fund_id' => $fundKey, 'reason' => 'Fund not mapped'];
                    continue;
                }
                $lines[] = [
                    'fund_id'          => $fundKey,
                    'fund_name'        => $map['pco_fund_name'],
                    'qbo_class_name'   => $map['qbo_class_name'],
                    'qbo_location_name'=> $map['qbo_location_name'],
                    'amount'           => $desAmt / 100.0,
                ];
                $totalRefundCents += $desAmt;
            }

            if (!empty($lines)) {
                $refunds[] = [
                    'donation_id'   => $id,
                    'updated_at'    => $updatedAt,
                    'payment_method'=> strtolower((string)($attrs['payment_method'] ?? '')),
                    'lines'         => $lines,
                ];
            }
        }

        return [
            'since'            => $sinceUtc,
            'until'            => $nowUtc,
            'refunds'          => $refunds,
            'total_refund'     => $totalRefundCents / 100.0,
            'skipped_unmapped' => $skippedUnmapped,
            'refund_ids'       => array_column($refunds, 'donation_id'),
        ];
    }

    /**
     * Load fund mappings from DB keyed by pco_fund_id.
     */
    private function loadFundMappings(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM fund_mappings");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $fundId       = (string)$row['pco_fund_id'];
            $out[$fundId] = $row;
        }

        return $out;
    }
}

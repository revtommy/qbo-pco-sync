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
     * Returns fund aggregates plus donation IDs grouped by location.
     */
    public function buildDepositPreview(DateTimeImmutable $sinceUtc, ?DateTimeImmutable $untilUtc = null, array $skipDonationIds = []): array
    {
        $fundMappings = $this->loadFundMappings(); // [fund_id => row]
        $nowUtc       = $untilUtc ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $skipSet      = array_fill_keys($skipDonationIds, true);
        $donationIdsByLocation = [];

        $resp = $this->pco->listDonations([
            'per_page' => 100,
            'order'    => '-completed_at',
            'include'  => 'designations',
        ]);

        $fundTotals         = []; // keyed by fund_id
        $donationCount      = 0;
        $processedDonations = 0;
        $skippedOffline     = 0;
        $skippedUnmapped    = [];

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
                'skipped_unmapped'    => $skippedUnmapped,
                'donation_ids_by_location' => [],
            ];
        }

        foreach ($resp['data'] as $donation) {
            $id    = (string)($donation['id'] ?? '');
            $attrs = $donation['attributes'] ?? [];
            $rels  = $donation['relationships'] ?? [];

            $completedAtStr = $attrs['completed_at'] ?? null;
            if (!$completedAtStr) {
                continue;
            }
            try {
                $completedAt = new DateTimeImmutable($completedAtStr);
            } catch (Throwable $e) {
                continue;
            }
            if ($completedAt < $sinceUtc || $completedAt > $nowUtc) {
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
                $key = (string)$fundId;

                if (!isset($fundTotals[$key])) {
                    $fundTotals[$key] = [
                        'pco_fund_id'       => $fundId,
                        'pco_fund_name'     => $map['pco_fund_name'],
                        'qbo_class_name'    => $map['qbo_class_name'],
                        'qbo_location_name' => $map['qbo_location_name'],
                        'qbo_income_account_name' => $map['qbo_income_account_name'] ?? null,
                        'gross_cents'       => 0,
                        'fee_cents'         => 0,
                        'payment_methods'   => [],
                    ];
                }

                $fundTotals[$key]['gross_cents'] += $gross;
                $fundTotals[$key]['fee_cents']   += $feeShareCents;
                if ($paymentMethod !== '') {
                    $fundTotals[$key]['payment_methods'][$paymentMethod] = true;
                }

                $locKey = $map['qbo_location_name'] ?? '__NO_LOCATION__';
                if (!isset($donationIdsByLocation[$locKey])) {
                    $donationIdsByLocation[$locKey] = [];
                }
                $donationIdsByLocation[$locKey][$id] = true;
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

            $fundsOut[] = [
                'pco_fund_id'       => $row['pco_fund_id'],
                'pco_fund_name'     => $row['pco_fund_name'],
                'qbo_class_name'    => $row['qbo_class_name'],
                'qbo_location_name' => $row['qbo_location_name'],
                'qbo_income_account_name' => $row['qbo_income_account_name'] ?? null,
                'gross'             => $grossCents / 100.0,
                'fee'               => $feeCents / 100.0,
                'net'               => $netCents / 100.0,
                'payment_methods'   => array_keys($row['payment_methods'] ?? []),
            ];
        }

        usort($fundsOut, function (array $a, array $b): int {
            return strcmp($a['pco_fund_name'], $b['pco_fund_name']);
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
            'skipped_unmapped'    => $skippedUnmapped,
            'donation_ids_by_location' => array_map('array_keys', $donationIdsByLocation),
        ];
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

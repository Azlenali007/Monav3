<?php
/**
 * Mona SMM Panel v2 - Payment Gateway Manager & Atomic Wallet Creditor
 * Implements strict concurrency-safe processing, idempotency, and RBAC
 */

declare(strict_types=1);

namespace Mona\Gateways;

use PDO;
use Exception;

require_once __DIR__ . '/GatewayInterface.php';
require_once __DIR__ . '/AbstractGateway.php';
require_once __DIR__ . '/RazorpayGateway.php';
require_once __DIR__ . '/CashfreeGateway.php';
require_once __DIR__ . '/PhonePeGateway.php';
require_once __DIR__ . '/PayUGateway.php';
require_once __DIR__ . '/PayPalGateway.php';
require_once __DIR__ . '/StripeGateway.php';
require_once __DIR__ . '/TwoCheckoutGateway.php';
require_once __DIR__ . '/CryptomusGateway.php';
require_once __DIR__ . '/NowPaymentsGateway.php';
require_once __DIR__ . '/CoinPaymentsGateway.php';
require_once __DIR__ . '/BinancePayGateway.php';
require_once __DIR__ . '/ManualGateway.php';
require_once __DIR__ . '/../functions.php';

class GatewayManager {
    private static array $classMap = [
        'razorpay' => RazorpayGateway::class,
        'cashfree' => CashfreeGateway::class,
        'phonepe' => PhonePeGateway::class,
        'payu' => PayUGateway::class,
        'paypal' => PayPalGateway::class,
        'stripe' => StripeGateway::class,
        'twocheckout' => TwoCheckoutGateway::class,
        'cryptomus' => CryptomusGateway::class,
        'nowpayments' => NowPaymentsGateway::class,
        'coinpayments' => CoinPaymentsGateway::class,
        'binancepay' => BinancePayGateway::class,
        'manual' => ManualGateway::class
    ];

    /**
     * Retrieve all available gateway instances with current database configurations
     * 
     * @return array<string, GatewayInterface>
     */
    public static function getAllGateways(PDO $pdo): array {
        $stmt = $pdo->query("SELECT * FROM payment_gateways");
        $records = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $records[$row['code']] = $row;
        }

        $gateways = [];
        foreach (self::$classMap as $code => $className) {
            $record = $records[$code] ?? [
                'code' => $code,
                'name' => ucfirst($code),
                'type' => 'fiat',
                'status' => 0,
                'environment' => 'live',
                'credentials' => null,
                'fee_percentage' => 0.00,
                'min_amount' => 1.00,
                'max_amount' => 10000.00,
                'currency' => 'USD'
            ];
            $gateways[$code] = new $className($record);
        }

        return $gateways;
    }

    /**
     * Retrieve only enabled and properly configured gateways for customer checkout
     * 
     * @return array<string, GatewayInterface>
     */
    public static function getEnabledGateways(PDO $pdo): array {
        $all = self::getAllGateways($pdo);
        $enabled = [];

        foreach ($all as $code => $gateway) {
            $config = $gateway->getConfig();
            if (!empty($config['status']) && $gateway->isConfigured()) {
                $enabled[$code] = $gateway;
            }
        }

        return $enabled;
    }

    /**
     * Get a specific gateway instance by code
     */
    public static function getGateway(string $code, PDO $pdo): ?GatewayInterface {
        $all = self::getAllGateways($pdo);
        return $all[$code] ?? null;
    }

    /**
     * Safely save gateway settings and encrypt secrets using OpenSSL AES-256-GCM
     */
    public static function saveGatewayConfig(string $code, array $postData, PDO $pdo): bool {
        if (!isset(self::$classMap[$code])) {
            return false;
        }

        $gateway = self::getGateway($code, $pdo);
        if (!$gateway) {
            return false;
        }

        $fields = $gateway->getConfigFields();
        $existingCreds = [];
        $stmt = $pdo->prepare("SELECT credentials FROM payment_gateways WHERE code = :code");
        $stmt->execute([':code' => $code]);
        $existingRaw = $stmt->fetchColumn();
        if ($existingRaw) {
            $existingCreds = json_decode((string)$existingRaw, true) ?: [];
        }

        $newCreds = [];
        foreach ($fields as $fieldKey => $fieldMeta) {
            $isPassword = ($fieldMeta['type'] ?? '') === 'password';
            $inputVal = trim((string)($postData[$fieldKey] ?? ''));

            if ($inputVal === '' && $isPassword && isset($existingCreds[$fieldKey])) {
                // Keep existing encrypted value if left blank on edit
                $newCreds[$fieldKey] = $existingCreds[$fieldKey];
            } elseif ($inputVal !== '') {
                if ($isPassword) {
                    $newCreds[$fieldKey] = 'enc:' . encryptGatewaySecret($inputVal);
                } else {
                    $newCreds[$fieldKey] = $inputVal;
                }
            } else {
                $newCreds[$fieldKey] = '';
            }
        }

        $status = !empty($postData['status']) ? 1 : 0;
        $env = in_array($postData['environment'] ?? '', ['sandbox', 'live']) ? $postData['environment'] : 'live';
        $fee = (float)($postData['fee_percentage'] ?? 0);
        $min = (float)($postData['min_amount'] ?? 1);
        $max = (float)($postData['max_amount'] ?? 10000);
        $currency = strtoupper(trim((string)($postData['currency'] ?? 'USD')));
        $instructions = trim((string)($postData['instructions'] ?? ''));

        $query = "
            INSERT INTO payment_gateways 
            (name, code, type, credentials, fee_percentage, min_amount, max_amount, currency, environment, instructions, status)
            VALUES (:name, :code, :type, :creds, :fee, :min, :max, :curr, :env, :instr, :status)
            ON DUPLICATE KEY UPDATE
            credentials = :creds2,
            fee_percentage = :fee2,
            min_amount = :min2,
            max_amount = :max2,
            currency = :curr2,
            environment = :env2,
            instructions = :instr2,
            status = :status2
        ";

        $stmt = $pdo->prepare($query);
        $credsJson = json_encode($newCreds);

        return $stmt->execute([
            ':name' => $gateway->getName(),
            ':code' => $code,
            ':type' => $gateway->getType(),
            ':creds' => $credsJson,
            ':fee' => $fee,
            ':min' => $min,
            ':max' => $max,
            ':curr' => $currency,
            ':env' => $env,
            ':instr' => $instructions,
            ':status' => $status,
            ':creds2' => $credsJson,
            ':fee2' => $fee,
            ':min2' => $min,
            ':max2' => $max,
            ':curr2' => $currency,
            ':env2' => $env,
            ':instr2' => $instructions,
            ':status2' => $status
        ]);
    }

    /**
     * Atomic, Concurrency-Safe Automatic Wallet Crediting
     * Prevents double credits using row-level locking (SELECT ... FOR UPDATE) and atomic transactions
     */
    public static function processPaymentCredit(
        PDO $pdo,
        int $paymentId,
        string $gatewayRef,
        float $receivedAmount,
        string $receivedCurrency,
        array $auditMeta = []
    ): bool {
        try {
            $pdo->beginTransaction();

            // Lock payment row exclusively
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                $pdo->rollBack();
                error_log("Payment credit failed: Payment record #{$paymentId} not found");
                return false;
            }

            // IDEMPOTENCY CHECK: If already completed, return true without crediting again
            if ($payment['status'] === 'completed') {
                $pdo->rollBack();
                error_log("Payment credit idempotency: Payment #{$paymentId} was already credited.");
                return true;
            }

            // Strictly only process 'pending' payments
            if ($payment['status'] !== 'pending') {
                $pdo->rollBack();
                error_log("Payment credit skipped: Payment #{$paymentId} is in status {$payment['status']}");
                return false;
            }

            // Lock user row exclusively
            $userStmt = $pdo->prepare("SELECT id, balance, referred_by FROM users WHERE id = :id FOR UPDATE");
            $userStmt->execute([':id' => $payment['user_id']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $pdo->rollBack();
                error_log("Payment credit failed: User #{$payment['user_id']} not found");
                return false;
            }

            $creditAmount = (float)$payment['net_amount'];
            $userId = (int)$user['id'];
            $method = $payment['payment_method'];

            // 1. Update Payment Record to completed
            $updatePaymentStmt = $pdo->prepare("
                UPDATE payments 
                SET status = 'completed', 
                    gateway_ref = :gw_ref, 
                    credited_at = NOW(),
                    audit_data = :audit
                WHERE id = :id AND status = 'pending'
            ");
            
            $auditMeta['received_amount'] = $receivedAmount;
            $auditMeta['received_currency'] = $receivedCurrency;
            $auditMeta['credited_at'] = date('Y-m-d H:i:s');
            
            $updatePaymentStmt->execute([
                ':gw_ref' => $gatewayRef,
                ':audit' => json_encode($auditMeta),
                ':id' => $paymentId
            ]);

            if ($updatePaymentStmt->rowCount() === 0) {
                // Concurrent worker already updated the status!
                $pdo->rollBack();
                return true;
            }

            // 2. Atomically Credit User Wallet
            $updateUserStmt = $pdo->prepare("
                UPDATE users 
                SET balance = balance + :amount 
                WHERE id = :id
            ");
            $updateUserStmt->execute([
                ':amount' => $creditAmount,
                ':id' => $userId
            ]);

            // 3. Record Immutable Ledger Entry in Transactions
            $transStmt = $pdo->prepare("
                INSERT INTO transactions (user_id, type, amount, fee, currency, description, reference_id, created_at)
                VALUES (:user_id, 'credit', :amount, :fee, :currency, :desc, :ref, NOW())
            ");
            $transStmt->execute([
                ':user_id' => $userId,
                ':amount' => $creditAmount,
                ':fee' => (float)$payment['fee'],
                ':currency' => $payment['currency'],
                ':desc' => "Deposit via " . ucfirst($method) . " (#{$payment['transaction_id']})",
                ':ref' => $payment['transaction_id']
            ]);

            // 4. Referral Affiliate Commission (if applicable)
            if (!empty($user['referred_by'])) {
                self::processReferralCommission($pdo, (int)$user['referred_by'], $userId, $creditAmount);
            }

            $pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Critical payment credit exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process affiliate commission on user deposit
     */
    private static function processReferralCommission(PDO $pdo, int $referrerId, int $referredId, float $depositAmount): void {
        try {
            $stmt = $pdo->prepare("SELECT commission_rate FROM referrals WHERE referrer_id = :r_id AND referred_id = :ref_id LIMIT 1");
            $stmt->execute([':r_id' => $referrerId, ':ref_id' => $referredId]);
            $rate = $stmt->fetchColumn();

            $commissionRate = ($rate !== false && $rate !== null) ? (float)$rate : 5.00; // Default 5%
            $commission = round(($depositAmount * $commissionRate) / 100, 4);

            if ($commission > 0.0001) {
                // Credit referrer balance
                $pdo->prepare("UPDATE users SET balance = balance + :comm WHERE id = :id")
                    ->execute([':comm' => $commission, ':id' => $referrerId]);

                // Update referral statistics
                $pdo->prepare("UPDATE referrals SET total_earned = total_earned + :comm WHERE referrer_id = :r_id AND referred_id = :ref_id")
                    ->execute([':comm' => $commission, ':r_id' => $referrerId, ':ref_id' => $referredId]);

                // Log referrer transaction
                $pdo->prepare("
                    INSERT INTO transactions (user_id, type, amount, fee, currency, description, reference_id, created_at)
                    VALUES (:uid, 'credit', :amount, 0, 'USD', :desc, :ref, NOW())
                ")->execute([
                    ':uid' => $referrerId,
                    ':amount' => $commission,
                    ':desc' => "Affiliate Commission ({$commissionRate}%) from user #{$referredId}",
                    ':ref' => "ref_{$referredId}_" . time()
                ]);
            }
        } catch (Exception $e) {
            error_log("Referral commission error: " . $e->getMessage());
        }
    }
}
